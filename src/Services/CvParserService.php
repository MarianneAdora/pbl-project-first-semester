<?php
// src/Services/CvParserService.php — extract name/email/contact from a CV file.
//
// Flow: PDF text (smalot/pdfparser) or raw text (DOC/DOCX best-effort) -> Gemini
// extracts exactly {name, email, contact}. Regex pre-scan fills obvious hits first
// so empty Gemini fields fall back to something instead of blank.

require_once __DIR__ . '/GeminiService.php';

class CvParserService {

    /**
     * @param string $filePath     Local path of the CV (uploaded tmp file or downloaded URL).
     * @param string|null $originalName Original filename (for extension detection).
     * @param string|null $mimeType     MIME type (auto-detected when omitted).
     * @return array ['name'=>string,'email'=>string,'contact'=>string,'raw_text'=>string]
     */
    public function parseFile($filePath, $originalName = null, $mimeType = null) {
        $text = $this->extractText($filePath, $originalName, $mimeType);
        $text = trim(preg_replace('/[ \t]+/', ' ', (string) $text));
        if (mb_strlen($text) < 10) {
            throw new Exception('Could not read any text from this CV. Try a text-based PDF.');
        }
        // Keep the prompt bounded: first ~6000 chars hold the header/contact block.
        $excerpt = mb_substr($text, 0, 6000);

        $fallback = $this->regexScan($excerpt);
        try {
            $ai = $this->extractWithGemini($excerpt);
        } catch (Exception $e) {
            $ai = [];
        }
        // Gemini can invent values, so every value it returns must actually appear in the CV text.
        $name  = $this->pick($this->verifyName($ai['name'] ?? '', $text), $fallback['name'] ?? '');
        $email = $this->pick($this->verifyEmail($ai['email'] ?? '', $text), $fallback['email'] ?? '');
        $phone = $this->pick($this->verifyPhone($ai['contact'] ?? '', $text), $fallback['contact'] ?? '');

        $fullName = $this->normalizeName($name);
        $parts = $this->splitName($fullName);
        $result = [
            'name'        => $fullName,
            'first_name'  => $parts['first'],
            'middle_name' => $parts['middle'],
            'last_name'   => $parts['last'],
            'email'       => strtolower(trim($email)),
            'contact'     => $this->normalizePhone($phone),
            'raw_text'    => mb_substr($text, 0, 20000),
        ];
        $result['missing'] = $this->missingRequired($result);
        return $result;
    }

    /**
     * Which of the required details (full name, contact information, email address) are absent?
     * @return string[] keys from: name, contact, email  (empty array = CV meets the requirements)
     */
    public function missingRequired(array $parsed): array {
        $missing = [];
        $name = trim((string) ($parsed['name'] ?? ''));
        if ($name === '' || preg_match_all('/\p{L}/u', $name) < 2) $missing[] = 'name';
        if (!filter_var((string) ($parsed['email'] ?? ''), FILTER_VALIDATE_EMAIL)) $missing[] = 'email';
        if (strlen(preg_replace('/\D/', '', (string) ($parsed['contact'] ?? ''))) < 7) $missing[] = 'contact';
        return $missing;
    }

    /**
     * AI-free check used when the form is submitted (the file is checked again on the server,
     * because the browser check can be bypassed). Only email + contact number are checked here;
     * the full name needs the AI step done at upload time.
     * @return string[] missing keys
     */
    public function quickMissing($filePath, $originalName = null, $mimeType = null): array {
        $text = (string) $this->extractText($filePath, $originalName, $mimeType);
        $scan = $this->regexScan($text);
        $missing = [];
        if (!filter_var($scan['email'], FILTER_VALIDATE_EMAIL)) $missing[] = 'email';
        if ($scan['contact'] === '') $missing[] = 'contact';
        return $missing;
    }

    private function verifyEmail($email, $text) {
        $email = trim((string) $email);
        if ($email === '' || stripos($text, $email) === false) return '';
        return $email;
    }

    private function verifyPhone($phone, $text) {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) < 7) return '';
        // The digits must appear in the CV in the same order (formatting like spaces/dashes is ignored).
        return strpos(preg_replace('/\D/', '', $text), $digits) !== false ? (string) $phone : '';
    }

    private function verifyName($name, $text) {
        $name = trim((string) $name);
        if ($name === '') return '';
        foreach (preg_split('/\s+/', $name) as $word) {
            $word = trim($word, " .,'-");
            if (mb_strlen($word) >= 2 && mb_stripos($text, $word) === false) return '';
        }
        return $name;
    }

    /** Pull raw text out of PDF (pdfparser) or plain-text files. */
    public function extractText($filePath, $originalName = null, $mimeType = null) {
        if (!is_file($filePath)) throw new Exception('CV file not found.');
        $ext = strtolower(pathinfo((string) ($originalName ?? $filePath), PATHINFO_EXTENSION));
        if (!$mimeType && function_exists('mime_content_type')) {
            $mimeType = @mime_content_type($filePath) ?: '';
        }
        if ($ext === 'pdf' || stripos((string) $mimeType, 'pdf') !== false) {
            require_once __DIR__ . '/../../vendor/autoload.php';
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            return (string) $pdf->getText();
        }
        if ($ext === 'txt' || stripos((string) $mimeType, 'text/') === 0) {
            $raw = @file_get_contents($filePath);
            if ($raw === false) throw new Exception('Could not read the CV file.');
            return $raw;
        }
        if ($ext === 'docx') return $this->extractDocx($filePath);
        return $this->extractLegacyDoc($filePath);
    }

    /** DOCX is a zip: read word/document.xml (+ header/footer, where many CVs keep contact details). */
    private function extractDocx($filePath) {
        if (!class_exists('ZipArchive')) {
            throw new Exception('This server cannot read DOCX files (enable the PHP zip extension). Upload a PDF instead.');
        }
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) throw new Exception('This DOCX file is damaged or not a real Word document.');
        $xml = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $n)) {
                $xml .= ' ' . (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        if ($xml === '') throw new Exception('Could not read any text from this DOCX file.');
        $xml = preg_replace('#</w:p>|<w:br\s*/?>|<w:tab\s*/?>#', "\n", $xml);
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Old binary .doc: best effort. Dropping NUL bytes turns UTF-16 text into readable ASCII. */
    private function extractLegacyDoc($filePath) {
        $raw = @file_get_contents($filePath);
        if ($raw === false) throw new Exception('Could not read the CV file.');
        $raw = str_replace("\0", '', $raw);
        $raw = preg_replace('/[^\x20-\x7E\r\n]/', ' ', $raw);
        return trim(preg_replace('/[ \t]+/', ' ', (string) $raw));
    }

    /** Ask Gemini for exactly {name, email, contact} as JSON. */
    private function extractWithGemini($cvText) {
        $gemini = new GeminiService();
        $prompt = "You extract contact details from a CV/resume.\n"
            . "Return ONLY a JSON object with exactly these keys: 'name', 'email', 'contact'.\n"
            . "Rules:\n"
            . "- 'name': the applicant's full name (person's name, not a company). Empty string if unsure.\n"
            . "- 'email': the applicant's email address. Empty string if none.\n"
            . "- 'contact': the applicant's phone/mobile number, digits with optional leading +, spaces or dashes (e.g. +63 912 345 6789). Empty string if none.\n"
            . "- Never invent values. Use \"\" for anything you cannot find.\n\n"
            . "CV TEXT:\n" . $cvText;
        $out = $gemini->generateText($prompt);
        return is_array($out) ? $out : [];
    }

    /** Cheap regex pre-scan: email + phone are reliable; name is a first-line guess. */
    private function regexScan($text) {
        $email = '';
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) $email = trim($m[0]);
        $contact = '';
        if (preg_match_all('/(\+?\d[\d\s().-]{6,}\d)/', $text, $all)) {
            foreach ($all[1] as $cand) {
                $cand = trim($cand);
                $digits = preg_replace('/\D/', '', $cand);
                if (strlen($digits) < 9 || strlen($digits) > 15) continue;
                // skip year ranges such as "2019 - 2023" or "2019-2023-2024"
                if (preg_match('/^((19|20)\d{2}[\s.\-\/]*)+$/', $cand)) continue;
                $contact = $cand;
                break;
            }
        }
        $name = '';
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'curriculum') !== false || stripos($line, 'resume') !== false || stripos($line, 'cv') === 0) continue;
            if (strpos($line, '@') !== false || preg_match('/\d{4,}/', $line)) continue;
            if (preg_match('/^[\p{L}\p{M} .\'-]{2,60}$/u', $line) && str_word_count($line) >= 2) { $name = $line; break; }
        }
        return ['name' => $name, 'email' => $email, 'contact' => $contact];
    }

    private function pick($ai, $fallback) {
        $ai = trim((string) $ai);
        $fallback = trim((string) $fallback);
        return $ai !== '' ? $ai : $fallback;
    }

    /**
     * Best-guess split of a full name into first / middle / last (HR can correct it in the form).
     * "Reyes, Maria Angelica" -> last Reyes; otherwise the last word is the last name
     * (kept together with a trailing Jr/Sr/II/III or a particle such as "dela", "de", "van").
     * @return array{first:string,middle:string,last:string}
     */
    public function splitName(string $fullName): array {
        $fullName = trim(preg_replace('/\s+/u', ' ', $fullName));
        if ($fullName === '') return ['first' => '', 'middle' => '', 'last' => ''];

        if (strpos($fullName, ',') !== false) {
            [$last, $rest] = array_map('trim', explode(',', $fullName, 2));
            $w = $rest === '' ? [] : preg_split('/\s+/u', $rest);
            $first = $w ? array_shift($w) : '';
            return ['first' => $first, 'middle' => implode(' ', $w), 'last' => $last];
        }

        $w = preg_split('/\s+/u', $fullName);
        if (count($w) === 1) return ['first' => $w[0], 'middle' => '', 'last' => ''];

        $suffixes  = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];
        $particles = ['de', 'del', 'dela', 'delos', 'di', 'du', 'la', 'le', 'van', 'von', 'san', 'santa'];
        $last = [array_pop($w)];
        if (count($w) > 1 && in_array(strtolower($last[0]), $suffixes, true)) array_unshift($last, array_pop($w));
        while (count($w) > 1 && in_array(strtolower(end($w)), $particles, true)) array_unshift($last, array_pop($w));

        $first = array_shift($w);
        return ['first' => $first, 'middle' => implode(' ', $w), 'last' => implode(' ', $last)];
    }

    /** Normalize a person's name: title-case with initials/suffixes preserved correctly. */
    private function normalizeName(string $name): string {
        $name = trim((string) $name);
        if ($name === '') return '';

        // Suffixes that should keep their case/format (Jr, Sr, II, III, IV, etc.)
        $suffixes = ['jr', 'sr', 'ii', 'iii', 'iv', 'v', 'vi', 'jr.', 'sr.'];

        // Particles that stay lowercase unless at the start (de, la, van, von, mc, mac, etc.)
        $lowercase_particles = ['de', 'del', 'di', 'du', 'la', 'le', 'van', 'von', 'mc', 'mac', 'o\'', 'd\'', 'l\''];

        // Split into words, keeping punctuation attached to the word for suffix detection
        $words = preg_split('/\s+/', $name);
        $result = [];
        $count = count($words);

        foreach ($words as $i => $word) {
            $lower = strtolower($word);
            $isLast = ($i === $count - 1);
            $isFirst = ($i === 0);

            // Suffix detection (last word matches a known suffix)
            if ($isLast && in_array($lower, $suffixes, true)) {
                // Preserve Roman numerals uppercase, title-case Jr/Sr
                if (preg_match('/^[ivxlcdm]+$/i', $lower) && $lower !== 'jr' && $lower !== 'sr') {
                    $result[] = strtoupper($word);
                } else {
                    $result[] = ucfirst(strtolower($word)); // Jr, Sr
                }
                continue;
            }

            // Initials: single letter with optional period (A., J.D., etc.)
            if (preg_match('/^[A-Z]\.?$/', $word) || preg_match('/^[A-Za-z]\.$/', $word)) {
                $result[] = strtoupper(preg_replace('/\.$/', '', $word)) . '.';
                continue;
            }

            // Double initials like J.D. or A.B. (letters with periods, no spaces)
            if (preg_match('/^[A-Z]\.[A-Z]\.?$/', $word)) {
                $result[] = strtoupper($word);
                continue;
            }

            // Lowercase particles (stay lowercase unless first word)
            if (in_array($lower, $lowercase_particles, true) && !$isFirst) {
                $result[] = $lower;
                continue;
            }

            // Everything else: title-case (first letter uppercase, rest lowercase)
            $result[] = ucfirst(strtolower($word));
        }

        return implode(' ', $result);
    }

    /** Normalize Philippine phone numbers: +63 -> 09, strip spaces/dashes/parens. */
    private function normalizePhone(string $phone): string {
        $phone = trim((string) $phone);
        if ($phone === '') return '';

        // Strip everything except digits and leading +
        $digits = preg_replace('/[^\d+]/', '', $phone);

        // If it starts with +63 or 63, convert to 09 format
        if (preg_match('/^\+?63(\d+)$/', $digits, $m)) {
            $rest = $m[1];
            // Philippine mobile: after 63 comes 9xx..., convert to 09xx...
            if (preg_match('/^9\d{9}$/', $rest)) {
                return '0' . $rest; // 639123456789 -> 09123456789
            }
            // Landline: 632xxxxxxx -> 02xxxxxxx
            if (preg_match('/^2\d{7,8}$/', $rest)) {
                return '0' . $rest;
            }
            return '0' . $rest;
        }

        // Already local format starting with 09
        if (preg_match('/^09\d{9}$/', $digits)) {
            return $digits;
        }

        // Local format starting with 0 (landline or mobile)
        if (preg_match('/^0\d{7,10}$/', $digits)) {
            return $digits;
        }

        // Just digits, 10-11 digits starting with 9 (assume mobile, prepend 0)
        if (preg_match('/^9\d{9}$/', $digits)) {
            return '0' . $digits;
        }

        // Fallback: return cleaned digits as-is
        return $digits;
    }
}