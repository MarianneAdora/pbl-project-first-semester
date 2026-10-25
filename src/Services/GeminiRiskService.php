<?php
/**
 * src/Services/GeminiRiskService.php
 *
 * Sends the computed risk-scoring result to Gemini and returns a short
 * HR-facing narrative: title, summary, favor[], watch[].
 *
 * The rubric numbers are already computed by RiskScoringService; Gemini's
 * job is only to write the human-readable explanation — it does NOT change
 * any scores or decisions.
 */

require_once __DIR__ . '/GeminiService.php';

class GeminiRiskService
{
    private GeminiService $gemini;

    public function __construct()
    {
        $this->gemini = new GeminiService();
    }

    /**
     * Generate the HR narrative for a scored applicant.
     *
     * @param array $applicant  [ name, email, ... ]
     * @param array $result     Return value of RiskScoringService::score()
     * @return array            [ title, summary, favor[], watch[] ]
     *                          Falls back to a rule-based narrative on any error.
     */
    public function narrative(array $applicant, array $result): array
    {
        // Gemini is invoked only when explicitly enabled and reachable; otherwise
        // a deterministic, rule-based narrative is returned so the HR evaluation
        // section stays functional without depending on external network access.
        if (!defined('GEMINI_ENABLED') || !GEMINI_ENABLED) {
            return $this->fallbackNarrative($applicant, $result);
        }
        try {
            $prompt = $this->buildPrompt($applicant, $result);
            $raw    = $this->gemini->generateText($prompt);
            return $this->parseResponse($raw, $applicant, $result);
        } catch (Throwable $e) {
            error_log('GeminiRiskService error: ' . $e->getMessage());
            return $this->fallbackNarrative($applicant, $result);
        }
    }

    // -------------------------------------------------------------------------

    private function buildPrompt(array $applicant, array $result): string
    {
        $name    = (string) ($applicant['name'] ?? 'the applicant');
        $points  = (int) $result['points'];
        $band    = (string) $result['band'];
        $outcome = (string) $result['outcome'];

        $factorLines = '';
        foreach ($result['factors'] as $f) {
            $factorLines .= '- ' . $f['label'] . ': ' . $f['points'] . '/' . $f['max'] . ' — ' . $f['reason'] . "\n";
        }

        $autoHigh = $result['autoWhy'] ? implode('; ', $result['autoWhy']) : 'none';

        $koLines = '';
        foreach ($result['knockouts'] as $k) {
            $koLines .= '- ' . $k['question'] . ' → answered "' . $k['given'] . '", passing answer "' . $k['expected'] . '", met: ' . ($k['met'] ? 'yes' : 'NO') . "\n";
        }

        return <<<PROMPT
You are an HR assistant reviewing a job applicant's automated screening result.
Write a concise, professional evaluation narrative for the HR team.

Applicant: {$name}
Risk score: {$points} points, band: {$band}
Outcome: {$outcome}   (passed = moves to interview, failed = knock-out question not met, review = HR must decide)
Automatic High triggers: {$autoHigh}

Risk factors:
{$factorLines}
Knock-out questions:
{$koLines}

Return ONLY a JSON object with these four keys (no markdown, no extra text):
{
  "title":   "one short sentence explaining the outcome",
  "summary": "two to three sentences for HR summarising the key findings",
  "favor":   ["bullet 1", "bullet 2"],
  "watch":   ["bullet 1", "bullet 2"]
}
"favor" = things that support the applicant.
"watch" = concerns HR should look at.
Keep each bullet under 15 words. Return an empty array [] when there is nothing to say.
PROMPT;
    }

    private function parseResponse($raw, array $applicant, array $result): array
    {
        if (!is_array($raw)) {
            return $this->fallbackNarrative($applicant, $result);
        }
        return [
            'title'   => (string) ($raw['title']   ?? ''),
            'summary' => (string) ($raw['summary']  ?? ''),
            'favor'   => array_values(array_filter((array) ($raw['favor'] ?? []), 'is_string')),
            'watch'   => array_values(array_filter((array) ($raw['watch'] ?? []), 'is_string')),
        ];
    }

    /**
     * Rule-based fallback — mirrors the logic in static-details.php details_evaluation().
     */
    private function fallbackNarrative(array $applicant, array $result): array
    {
        $outcome   = $result['outcome'];
        $band      = $result['band'];
        $points    = $result['points'];
        $knockouts = $result['knockouts'];
        $failed_ko = array_filter($knockouts, fn($k) => $k['met'] === false);
        $must      = count($knockouts);

        $favor = [];
        $watch = [];

        if ($must > 0 && !$failed_ko) {
            $favor[] = 'Met the passing answer of all ' . $must . ' knock-out question(s).';
        }
        foreach ($failed_ko as $k) {
            $watch[] = 'Did not meet knock-out: "' . $k['question'] . '". ' . $k['reason'];
        }
        $clean = count(array_filter($result['factors'], fn($f) => $f['points'] === 0 && $f['max'] > 0));
        if ($clean > 0) {
            $favor[] = $clean . ' risk factor(s) scored zero points.';
        }
        foreach ($result['factors'] as $f) {
            if ($f['points'] > 0 && $f['max'] > 0) {
                $watch[] = $f['label'] . ' (' . $f['points'] . '/' . $f['max'] . '): ' . $f['reason'];
            }
        }
        foreach ($result['autoWhy'] as $why) {
            $watch[] = 'Automatic High risk: ' . $why . '.';
        }

        if ($outcome === 'passed') {
            $title   = 'This applicant passed the screening.';
            $summary = 'The applicant met all knock-out requirements and the risk band is ' . $band
                     . ' (' . $points . ' points). They may proceed to the interview.';
        } elseif ($outcome === 'failed') {
            $title   = 'This applicant did not pass the screening.';
            $summary = 'The applicant failed ' . count($failed_ko) . ' of ' . $must
                     . ' knock-out question(s). Risk alone does not fail an applicant; the band is '
                     . $band . ' (' . $points . ' points).';
        } else {
            $title   = 'This applicant is held for HR review.';
            $summary = 'The risk band is High (' . $points . ' points). HR must review before any result is released. Risk alone never rejects an applicant.';
        }

        return compact('title', 'summary', 'favor', 'watch');
    }
}
