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

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode($data),
                'timeout' => 15,
            ],
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new Exception("Gemini API request failed.");
        }

        $httpCode = 200; // file_get_contents doesn't expose HTTP code directly; assume success if we got content

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