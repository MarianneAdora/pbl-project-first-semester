<?php
// src/Services/CloudinaryService.php

class CloudinaryService {
    private $cloudName;
    private $apiKey;
    private $apiSecret;

    public function __construct() {
        $env = parse_ini_file(__DIR__ . '/../../.env');
        $this->cloudName = $env['CLOUDINARY_CLOUD_NAME'] ?? '';
        $this->apiKey = $env['CLOUDINARY_API_KEY'] ?? '';
        $this->apiSecret = $env['CLOUDINARY_API_SECRET'] ?? '';

        if (empty($this->cloudName) || empty($this->apiKey) || empty($this->apiSecret)) {
            throw new Exception("Cloudinary credentials are missing from your .env file.");
        }
    }

    /**
     * Uploads a CV file (PDF/DOC/DOCX) to Cloudinary.
     * @return string The secure URL of the uploaded file.
     */
    public function uploadCV($filePath, $applicantId = null) {
        $timestamp = time();
        $folder = "applicant_cvs/" . ($applicantId ?? 'unassigned');
        
        // 1. Generate Signature (Required by Cloudinary for secure uploads)
        $params = ['folder' => $folder, 'timestamp' => $timestamp];
        ksort($params);
        $signatureString = http_build_query($params) . $this->apiSecret;
        $signature = sha1($signatureString);

        // 2. Prepare POST request (Note: '/raw/upload' for CVs)
        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/raw/upload";
        $postData = [
            'file' => new CURLFile($filePath),
            'api_key' => $this->apiKey,
            'timestamp' => $timestamp,
            'folder' => $folder,
            'signature' => $signature
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return $result['secure_url'];
        } else {
            throw new Exception("Cloudinary CV upload failed: " . $response);
        }
    }

    /**
     * Uploads an applicant video to Cloudinary and returns its URL + SHA-256 hash.
     * @return array ['success' => bool, 'url' => string, 'hash' => string]
     */
    public function uploadVideo($filePath, $applicantId = null) {
        $timestamp = time();
        $folder = "applicant_videos/" . ($applicantId ?? 'unassigned');
        
        // 1. Generate a SHA-256 hash BEFORE uploading (for risk scoring)
        // This is required for your rubric's "reused video" check.
        $videoHash = hash_file('sha256', $filePath);

        // 2. Generate Signature
        $params = ['folder' => $folder, 'timestamp' => $timestamp];
        ksort($params);
        $signatureString = http_build_query($params) . $this->apiSecret;
        $signature = sha1($signatureString);

        // 3. Prepare POST request (Note: '/video/upload' for videos)
        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/video/upload";
        $postData = [
            'file' => new CURLFile($filePath),
            'api_key' => $this->apiKey,
            'timestamp' => $timestamp,
            'folder' => $folder,
            'signature' => $signature
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return [
                'success' => true,
                'url' => $result['secure_url'],
                'hash' => $videoHash
            ];
        } else {
            throw new Exception("Cloudinary Video upload failed: " . $response);
        }
    }
}