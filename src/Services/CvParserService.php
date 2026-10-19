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
        return [
            'name'     => $this->pick($ai['name'] ?? '', $fallback['name'] ?? ''),
            'email'    => $this->pick($ai['email'] ?? '', $fallback['email'] ?? ''),
            'contact'  => $this->pick($ai['contact'] ?? '', $fallback['contact'] ?? ''),
            'raw_text' => mb_substr($text, 0, 20000),
        ];
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
        // DOC/DOCX/TXT best-effort: strip tags; scanned images yield nothing (needs OCR).
        $raw = @file_get_contents($filePath);
        if ($raw === false) throw new Exception('Could not read the CV file.');
        if ($ext === 'txt' || stripos((string) $mimeType, 'text/') === 0) return $raw;
        $stripped = strip_tags($raw);
        $stripped = preg_replace('/[^\x20-\x7E\r\n@+().,_\-]/', ' ', $stripped);
        return trim(preg_replace('/\s+/', ' ', (string) $stripped));
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
        if (preg_match('/(\+?\d[\d\s().-]{6,}\d)/', $text, $m)) {
            $cand = trim($m[1]);
            if (preg_match_all('/\d/', $cand) >= 7) $contact = $cand;
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
}
