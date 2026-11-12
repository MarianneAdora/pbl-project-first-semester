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
        } catch (Throwable $e) {   // Throwable, not just Exception: a missing PHP extension (e.g. cURL) is an Error
            error_log('CV Gemini extraction failed: ' . get_class($e) . ': ' . $e->getMessage());
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
     * Which of the 4 required details are absent from the CV? The middle name is optional and never required.
     * Same rules as the form: first / last name need at least 2 letters, a valid email, a contact number.
     * @return string[] keys from: first_name, last_name, email, contact  (empty array = CV meets the requirements)
     */
    public function missingRequired(array $parsed): array {
        $missing = [];
        foreach (['first_name', 'last_name'] as $k) {
            if (preg_match_all('/\p{L}/u', trim((string) ($parsed[$k] ?? ''))) < 2) $missing[] = $k;
        }
        if (!filter_var((string) ($parsed['email'] ?? ''), FILTER_VALIDATE_EMAIL)) $missing[] = 'email';
        if (strlen(preg_replace('/\D/', '', (string) ($parsed['contact'] ?? ''))) < 7) $missing[] = 'contact';
        return $missing;
    }

    /** "first_name, email, contact" -> a sentence HR can read. */
    public function describeMissing(array $missing): string {
        $labels = ['first_name' => 'first name', 'last_name' => 'last name', 'email' => 'email address', 'contact' => 'contact number'];
        $names = [];
        foreach ($missing as $k) if (isset($labels[$k])) $names[] = $labels[$k];
        if (!$names) return '';
        $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : $names[0];
        return 'This CV is missing the applicant\'s ' . $list . '. Upload a CV that shows the full name, email address and contact number.';
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
        $words = array_values(array_filter(preg_split('/\s+/', $name), fn($w) => trim($w, " .,'-") !== ''));

        // 1) Every real word (2+ letters) must appear in the CV, otherwise Gemini invented the name.
        foreach ($words as $word) {
            $w = trim($word, " .,'-");
            if (mb_strlen($w) >= 2 && mb_stripos($text, $w) === false) return '';
        }

        // 2) A lone initial ("B.") is a middle name. It was never checked before, so Gemini could invent one
        //    (it was even primed with "B." in the prompt). Keep an initial only if the CV really prints it
        //    right next to a neighbouring name word, e.g. "Lourdes B." or "B. Castillo".
        $kept = [];
        foreach ($words as $i => $word) {
            $w = trim($word, " .,'-");
            if (mb_strlen($w) !== 1) { $kept[] = $word; continue; }
            $L    = preg_quote($w, '/');
            $prev = $i > 0 ? trim($words[$i - 1], " .,'-") : '';
            $next = $i < count($words) - 1 ? trim($words[$i + 1], " .,'-") : '';
            $ok = false;
            if ($prev !== '' && mb_strlen($prev) >= 2) {
                $ok = (bool) preg_match('/' . preg_quote($prev, '/') . '[\s.,]+' . $L . '(?![\p{L}])/iu', $text);
            }
            if (!$ok && $next !== '' && mb_strlen($next) >= 2) {
                $ok = (bool) preg_match('/(?<![\p{L}])' . $L . '\.?[\s,]+' . preg_quote($next, '/') . '/iu', $text);
            }
            if ($ok) $kept[] = $word;   // else: drop the invented initial, keep the rest of the name
        }
        return trim(implode(' ', $kept));
    }

    /**
     * Is this file a JOB DESCRIPTION (an employer describing a role) and not a resume/CV?
     * Used when HR attaches a PDF to a job position. Reads the text, scores job-description wording
     * (responsibilities, qualifications, reports to...) against resume wording (work experience, personal
     * email + phone, "curriculum vitae"...), and asks Gemini only when that is not clear-cut.
     * When $positionName is given, the job description must also be FOR that position (see matchesPosition).
     * Returns ['ok' => bool, 'type' => 'job_description'|'resume'|'other'|'wrong_position', 'message' => string].
     * Throws when no text can be read from the file.
     */
    public function classifyJobDescription($filePath, $originalName = null, $mimeType = null, ?string $positionName = null): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $this->extractText($filePath, $originalName, $mimeType)));
        if (mb_strlen($text) < 40) throw new Exception('Could not read any text from this PDF.');
        $excerpt = mb_substr($text, 0, 6000);
        $t    = mb_strtolower($excerpt);
        $head = mb_substr($t, 0, 400);

        $jdMarkers = ['job description', 'job summary', 'job title', 'job purpose', 'position summary', 'role overview',
            'about the role', 'key responsibilities', 'responsibilities', 'duties', 'qualifications', 'requirements',
            'required skills', 'preferred qualifications', 'minimum qualifications', 'reports to', 'employment type',
            'work schedule', 'we are looking for', 'the ideal candidate', 'the successful candidate', 'applicants must',
            'salary range', 'compensation'];
        $cvMarkers = ['curriculum vitae', 'career objective', 'personal information', 'personal data', 'date of birth',
            'birthdate', 'civil status', 'character reference', 'references available', 'i hereby certify',
            'work experience', 'employment history', 'professional experience', 'educational background',
            'seminars attended', 'trainings attended'];

        $jd = 0; foreach ($jdMarkers as $m) if (mb_strpos($t, $m) !== false) $jd++;
        $cv = 0; foreach ($cvMarkers as $m) if (mb_strpos($t, $m) !== false) $cv++;
        if (preg_match('/curriculum vitae|r[ée]sum[ée]/u', $head))                                   $cv += 3;  // the title says so
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $excerpt))                        $cv++;     // a personal email
        if (preg_match('/(?<!\d)(?:\+?63|0)\s?9\d{2}[\s-]?\d{3}[\s-]?\d{4}(?!\d)/', $excerpt))        $cv++;     // a mobile number

        $finish = function (array $res) use ($text, $positionName): array {
            if (!$res['ok'] || $positionName === null || trim($positionName) === '') return $res;
            if ($this->matchesPosition($text, $positionName)) return $res;
            return ['ok' => false, 'type' => 'wrong_position',
                'message' => 'This job description does not seem to be for "' . trim($positionName) . '". Check the job position name, or upload the PDF of the right job description.'];
        };

        $resumeMsg = 'This file looks like a resume/CV, not a job description. Upload the job description PDF of this position.';
        $otherMsg  = 'This file does not look like a job description. Upload the job description PDF of this position (its duties, qualifications and requirements).';

        if ($jd >= 3 && $cv <= 1) return $finish(['ok' => true,  'type' => 'job_description', 'message' => '']);
        if ($cv >= 3 && $cv > $jd) return ['ok' => false, 'type' => 'resume',          'message' => $resumeMsg];

        // not clear-cut: ask Gemini; if it cannot answer, go with whichever wording scored higher
        $type = '';
        try {
            $prompt = "Classify this document. Return ONLY a JSON object {\"type\": \"job_description\" | \"resume\" | \"other\"}.\n"
                . "- job_description: an employer describing a role (duties, qualifications, requirements).\n"
                . "- resume: a person's own CV (their name, contact details, education, work history).\n"
                . "- other: anything else.\n\nDOCUMENT TEXT:\n" . mb_substr($text, 0, 3500);
            $out  = (new GeminiService())->generateText($prompt);
            $type = is_array($out) ? strtolower(trim((string) ($out['type'] ?? ''))) : '';
        } catch (Throwable $e) {
            error_log('Job description classification (Gemini) failed: ' . $e->getMessage());
        }
        if (!in_array($type, ['job_description', 'resume', 'other'], true)) {
            $type = $jd > $cv ? 'job_description' : ($cv >= 2 ? 'resume' : 'other');
        }
        if ($type === 'job_description') return $finish(['ok' => true,  'type' => $type, 'message' => '']);
        return ['ok' => false, 'type' => $type, 'message' => $type === 'resume' ? $resumeMsg : $otherMsg];
    }

    /**
     * Is this job description FOR the given position? "Data Analyst Job Position" -> key words [data, analyst].
     * 1) all key words in the top of the document (where the job title is)  -> yes
     * 2) the key words together as a phrase anywhere                         -> yes
     * 3) otherwise Gemini decides (synonyms / abbreviations: "HR Associate" = "Human Resources Associate");
     *    if Gemini cannot answer, every key word must appear somewhere in the text.
     */
    private function matchesPosition(string $text, string $position): bool
    {
        $stop  = ['job', 'position', 'description', 'jd', 'the', 'of', 'and', 'for', 'a', 'an'];
        $words = preg_split('/[^\p{L}\p{N}+#]+/u', mb_strtolower($position), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_values(array_filter($words, fn($w) => !in_array($w, $stop, true) && mb_strlen($w) >= 2));
        $tokens = array_map(fn($w) => mb_strlen($w) > 3 ? preg_replace('/s$/u', '', $w) : $w, $tokens);   // "analysts" -> "analyst"
        if (!$tokens) return true;                                   // nothing meaningful to compare

        $t    = mb_strtolower($text);
        $head = mb_substr($t, 0, 600);
        $has  = fn($hay, $tok) => (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($tok, '/') . 's?(?![\p{L}\p{N}])/u', $hay);

        $allIn = function ($hay) use ($tokens, $has) { foreach ($tokens as $k) if (!$has($hay, $k)) return false; return true; };
        if ($allIn($head)) return true;
        $phrase = implode('[^\p{L}\p{N}]+', array_map(fn($k) => preg_quote($k, '/'), $tokens));
        if (preg_match('/(?<![\p{L}\p{N}])' . $phrase . 's?(?![\p{L}\p{N}])/u', $t)) return true;

        try {
            $prompt = "Is the document below the job description for the position \"" . $position . "\"?\n"
                . "Return ONLY a JSON object {\"matches\": true} or {\"matches\": false}.\n"
                . "Answer true only if the document describes that same role (synonyms and abbreviations count, e.g. HR = Human Resources). "
                . "Answer false if it describes a different role.\n\nDOCUMENT TEXT:\n" . mb_substr($text, 0, 3500);
            $out = (new GeminiService())->generateText($prompt);
            if (is_array($out) && array_key_exists('matches', $out)) {
                return filter_var($out['matches'], FILTER_VALIDATE_BOOLEAN);
            }
        } catch (Throwable $e) {
            error_log('Job position match (Gemini) failed: ' . $e->getMessage());
        }
        return $allIn($t);
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
            . "- 'name': the applicant's full name exactly as written at the top of the CV, keeping a middle initial ONLY if one is actually printed in the name; if the name has no initial, do not add one (person's name, not a company). Empty string if unsure.\n"
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
     *
     * The middle name is ONLY a lone initial ("B." in "Maria Lourdes B. Castillo").
     * A CV without an initial has no middle name: every given name goes into the first name
     * ("Maria Angelica Reyes" -> first "Maria Angelica", middle "", last "Reyes").
     *
     * "Castillo, Maria Lourdes B." -> last Castillo; otherwise the last word is the last name
     * (kept together with a trailing Jr/Sr/II/III or a particle such as "dela", "de", "van").
     * @return array{first:string,middle:string,last:string}
     */
    public function splitName(string $fullName): array {
        $fullName = trim(preg_replace('/\s+/u', ' ', $fullName));
        if ($fullName === '') return ['first' => '', 'middle' => '', 'last' => ''];

        if (strpos($fullName, ',') !== false) {
            [$last, $rest] = array_map('trim', explode(',', $fullName, 2));
            $given = $rest === '' ? [] : preg_split('/\s+/u', $rest);
            [$first, $middle] = $this->splitGivenNames($given);
            return ['first' => $first, 'middle' => $middle, 'last' => $last];
        }

        $w = preg_split('/\s+/u', $fullName);
        if (count($w) === 1) return ['first' => $w[0], 'middle' => '', 'last' => ''];

        $suffixes  = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];
        $particles = ['de', 'del', 'dela', 'delos', 'di', 'du', 'la', 'le', 'van', 'von', 'san', 'santa'];
        $last = [array_pop($w)];
        if (count($w) > 1 && in_array(strtolower($last[0]), $suffixes, true)) array_unshift($last, array_pop($w));
        while (count($w) > 1 && in_array(strtolower(end($w)), $particles, true)) array_unshift($last, array_pop($w));

        // $w now holds the given names: a trailing initial is the middle name, the rest is the first name
        [$first, $middle] = $this->splitGivenNames($w);
        return ['first' => $first, 'middle' => $middle, 'last' => implode(' ', $last)];
    }

    /** Given names -> [first, middle]. Middle is only a trailing initial; otherwise everything is the first name. */
    private function splitGivenNames(array $given): array {
        $given = array_values(array_filter($given, fn($g) => $g !== ''));
        if (!$given) return ['', ''];
        if (count($given) >= 2 && $this->isInitial((string) end($given))) {
            $initial = array_pop($given);
            return [implode(' ', $given), $this->formatInitial($initial)];
        }
        return [implode(' ', $given), ''];
    }

    /** "B", "B.", "j.d." -> true. A whole word such as "Ma." or "Lourdes" -> false. */
    private function isInitial(string $word): bool {
        return (bool) preg_match('/^\p{L}\.?$/u', $word) || (bool) preg_match('/^(?:\p{L}\.){2,}$/u', $word);
    }

    /** "b" -> "B.", "B." -> "B.", "j.d." -> "J.D." */
    private function formatInitial(string $word): string {
        $word = mb_strtoupper($word);
        return preg_match('/^\p{L}$/u', $word) ? $word . '.' : $word;
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