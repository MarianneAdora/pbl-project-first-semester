<?php
class GeminiService {
    private $apiKey;
    private $models = ['gemini-3.8-flash', 'gemini-3.6-flash'];

    public function __construct() {
        require_once __DIR__ . '/../Helpers/env.php';
        $this->apiKey = trim((string) (getenv('GEMINI_API_KEY') ?: ''));
        if (empty($this->apiKey)) {
            throw new Exception("GEMINI_API_KEY is missing from .env");
        }
    }

    /**
     * Sends a prompt to Gemini and returns the text response.
     */
    public function generateText($prompt) {
        $data = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt]
                    ]
                ]
            ],
            // Force Gemini to return pure JSON (great for CV parsing)
            "generationConfig" => [
                "responseMimeType" => "application/json"
            ]
        ];

        $response = null;
        foreach ($this->models as $index => $model) {
            $request = curl_init(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent"
            );
            if ($request === false) {
                throw new Exception("Could not initialize the Gemini API request.");
            }

            $configured = curl_setopt_array($request, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->apiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($data, JSON_THROW_ON_ERROR),
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (!$configured) {
                curl_close($request);
                throw new Exception("Could not configure the Gemini API request.");
            }

            $response = curl_exec($request);
            if ($response === false) {
                $errorNumber = curl_errno($request);
                $error = curl_error($request);
                curl_close($request);
                if ($errorNumber === CURLE_OPERATION_TIMEDOUT && $index < count($this->models) - 1) {
                    error_log("Gemini model {$model} timed out; retrying with a fallback model.");
                    continue;
                }
                throw new Exception("Gemini API request failed: " . $error);
            }

            $status = (int) curl_getinfo($request, CURLINFO_HTTP_CODE);
            curl_close($request);
            if ($status >= 200 && $status < 300) break;

            if ($status === 503 && $index < count($this->models) - 1) {
                error_log("Gemini model {$model} is unavailable; retrying with a fallback model.");
                continue;
            }

            $errorResponse = json_decode($response, true);
            $message = trim((string) ($errorResponse['error']['message'] ?? ''));
            if ($message !== '') $message = ' ' . substr($message, 0, 300);
            throw new Exception("Gemini API returned HTTP {$status}.{$message}");
        }

        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new Exception("Gemini API returned an unexpected response.");
        }
        $textResponse = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($textResponse) || trim($textResponse) === '') {
            throw new Exception("Gemini API returned no generated text.");
        }

        $decoded = json_decode($textResponse, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new Exception("Gemini API returned invalid JSON text.");
        }
        return $decoded;
    }
}