<?php
class GeminiService {
    private $apiKey;
    private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent";

    public function __construct() {
        $env = parse_ini_file(__DIR__ . '/../../.env');
        $this->apiKey = $env['GEMINI_API_KEY'] ?? '';
        if (empty($this->apiKey)) {
            throw new Exception("GEMINI_API_KEY is missing from .env");
        }
    }

    /**
     * Sends a prompt to Gemini and returns the text response.
     */
    public function generateText($prompt) {
        $url = $this->apiUrl . "?key=" . $this->apiKey;

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

        // Fast connectivity check so a blocked network cannot hang the request.
        $fp = @fsockopen('generativelanguage.googleapis.com', 443, $errno, $errstr, 2);
        if (!$fp) {
            throw new Exception('Gemini unreachable: ' . $errstr);
        }
        fclose($fp);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
        ]);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            // Extract the actual text from Gemini's response structure
            $textResponse = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return json_decode($textResponse, true); // Return as PHP array
        } else {
            throw new Exception("Gemini API Error: " . $response);
        }
    }
}