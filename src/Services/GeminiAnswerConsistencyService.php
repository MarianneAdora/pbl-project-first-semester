<?php
/**
 * src/Services/GeminiAnswerConsistencyService.php
 *
 * Uses Gemini to detect semantic contradictions and irrelevant/off-topic
 * answers across an applicant's questionnaire. Works with ANY question set
 * (admin can add/change/reorder questions) because it receives the live
 * question list + answers and reasons over them as text.
 */

require_once __DIR__ . '/GeminiService.php';

class GeminiAnswerConsistencyService
{
    private GeminiService $gemini;

    public function __construct()
    {
        $this->gemini = new GeminiService();
    }

    public function evaluate(array $questions, array $answers): array
    {
        $pairs = $this->buildPairs($questions, $answers);
        if (empty($pairs)) {
            return $this->fallback('No question/answer pairs available to evaluate.');
        }

        $lines = '';
        foreach ($pairs as $i => $p) {
            $n = $i + 1;
            $lines .= "Q{$n}: {$p['question']}\n";
            $lines .= "A{$n}: {$p['answer']}\n\n";
        }

        $prompt = <<<PROMPT
You are reviewing an applicant's screening questionnaire answers for internal consistency and relevance.

For each question below, the applicant gave the answer shown. Detect:
1. Direct contradictions between two or more answers (e.g. "not currently employed" but a notice period is given; "available immediately" but a start date 2 months out).
2. Answers that are irrelevant, off-topic, or do not address what the question asks.
3. Nonsensical, placeholder, or copy-pasted/generic content that does not reflect genuine understanding.

Do NOT invent facts beyond the provided Q&A. Base your judgment only on these answers.

Questionnaire:
{$lines}

Return ONLY a JSON object with these keys (no markdown, no extra text):
{
  "points": 0,
  "issues": ["short description of each problem found"]
}

Scoring for "points" (0 to 3):
- 0 = no contradictions or irrelevance found.
- 1 = one minor mismatch or slightly off-topic answer.
- 2 = one direct contradiction OR one clearly irrelevant answer.
- 3 = two or more direct contradictions OR severe irrelevance/placeholder content.

"issues" = list of concise strings (max 15 words each). Empty array [] when points is 0.
PROMPT;

        try {
            $raw = $this->gemini->generateText($prompt);
            return $this->parse($raw);
        } catch (Throwable $e) {
            error_log('GeminiAnswerConsistencyService error: ' . $e->getMessage());
            return $this->fallback('Gemini unavailable; consistency not evaluated.');
        }
    }

    private function buildPairs(array $questions, array $answers): array
    {
        $pairs = [];
        foreach ($questions as $q) {
            $qid   = (string) ($q['id'] ?? '');
            $text  = trim((string) ($q['text'] ?? ''));
            if ($qid === '' || $text === '') continue;

            $type  = (string) ($q['type'] ?? '');
            $given = $this->answerFor($answers, $qid);

            if ($given === null || $given === '' || $given === false) continue;
            if ($type === 'consent') continue;

            $answerStr = $this->formatAnswer($given, $q, $answers, $qid);

            $pairs[] = [
                'question' => $text,
                'answer'   => $answerStr,
            ];
        }
        return $pairs;
    }

    private function answerFor(array $answers, string $qid): mixed
    {
        $num = ltrim($qid, "qQ");
        $candidates = [$qid, $num, 'q' . $num, 'Q' . $num];
        foreach ($candidates as $k) {
            if (array_key_exists($k, $answers)) {
                return $answers[$k];
            }
        }
        return null;
    }

    private function formatAnswer(mixed $given, array $q, array $answers, string $qid): string
    {
        $num = ltrim($qid, "qQ");
        $type = (string) ($q['type'] ?? '');

        if (is_bool($given)) {
            $str = $given ? 'Yes' : 'No';
        } elseif (is_array($given)) {
            $str = json_encode($given, JSON_UNESCAPED_UNICODE);
        } else {
            $str = trim((string) $given);
        }

        if ($type === 'yesno_select') {
            $followKeys = [$qid . ':follow', $num . ':follow', 'q' . $num . ':follow', $qid . '_follow', $num . '_follow'];
            foreach ($followKeys as $fk) {
                if (array_key_exists($fk, $answers) && $answers[$fk] !== null && $answers[$fk] !== '') {
                    $followVal = $answers[$fk];
                    $followStr = is_array($followVal) ? json_encode($followVal, JSON_UNESCAPED_UNICODE) : trim((string) $followVal);
                    $str .= ' (follow-up: ' . $followStr . ')';
                    break;
                }
            }
        }

        if ($str === '') $str = '(no answer)';
        return $str;
    }

    private function parse($raw): array
    {
        if (!is_array($raw)) {
            return $this->fallback('Gemini returned an unexpected response; consistency not evaluated.');
        }

        $points = (int) ($raw['points'] ?? 0);
        $points = max(0, min(3, $points));

        $issues = [];
        if (isset($raw['issues']) && is_array($raw['issues'])) {
            foreach ($raw['issues'] as $i) {
                if (is_string($i) && trim($i) !== '') {
                    $issues[] = trim($i);
                }
            }
        }

        $reason = $points > 0
            ? (count($issues) ? implode(' ', $issues) : 'Inconsistent or irrelevant answers detected.')
            : 'No inconsistent or irrelevant answers detected.';

        return [
            'points' => $points,
            'reason' => $reason,
            'issues' => $issues,
        ];
    }

    private function fallback(string $reason): array
    {
        return [
            'points' => 0,
            'reason' => $reason,
            'issues' => [],
        ];
    }
}

