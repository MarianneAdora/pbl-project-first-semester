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
     * Build a Cloudinary upload signature.
     * IMPORTANT: Cloudinary expects the raw "key=value&..." string with NO URL-encoding
     * (http_build_query encodes "/" as %2F, which produces an Invalid Signature error
     * whenever the folder contains a slash, e.g. ebsmi_cvs/7).
     */
    private function signParams(array $params): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        return sha1(implode('&', $parts) . $this->apiSecret);
    }

    /**
     * Uploads a CV file (PDF/DOC/DOCX) to Cloudinary.
     * Layout: flat inside $folderBase (default 'ebsmi_cvs') — NO per-applicant subfolders.
     * Filename: cv-{applicantId}-{original}, e.g. ebsmi_cvs/cv-9-resume.pdf
     * (unique_filename=false, so re-uploading the same applicant's CV overwrites it).
     * Type: resource_type=image, so PDFs are PREVIEWABLE in the dashboard AND can be
     * fetched server-side later for Gemini text extraction (raw/ uploads download instead).
     * Returns ['url', 'public_id', 'name'].
     * @param string $filePath     Local temp path of the uploaded file.
     * @param mixed $applicantId  Applicant id (used in the filename, NOT a subfolder).
     * @param string $folderBase  Cloudinary folder (default 'ebsmi_cvs').
     * @param string|null $originalName Original client filename (e.g. $_FILES['cv']['name']).
     * @param string|null $mimeType     MIME type (auto-detected when omitted).
     */
    public function uploadCV($filePath, $applicantId = null, $folderBase = 'ebsmi_cvs', $originalName = null, $mimeType = null) {
        $timestamp = time();
        $folderBase = trim((string) $folderBase) !== '' ? trim((string) $folderBase) : 'ebsmi_cvs';
        $folderBase = trim($folderBase, '/');

        // Keep the real filename: strip any path, keep only safe chars, fall back to cv.pdf.
        $originalName = basename(trim((string) ($originalName ?? '')));
        if ($originalName === '' || $originalName === '.') $originalName = 'cv.pdf';
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $originalName);
        $safe = trim($safe, '-.');
        if ($safe === '') $safe = 'cv.pdf';
        // Cloudinary public_id must not contain the extension when use_filename... instead we
        // pass public_id explicitly: cv-{id}-{basename-without-ext}. Extension comes from the file.
        $base = pathinfo($safe, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $base), '-');
        if ($base === '') $base = 'cv';
        $publicId = 'cv-' . ($applicantId ?? 'unassigned') . '-' . substr($base, 0, 60);

        if (!$mimeType) {
            $detected = function_exists('mime_content_type') ? @mime_content_type($filePath) : false;
            $mimeType = $detected ?: 'application/octet-stream';
        }

        // 1. Generate Signature (must cover every signed param exactly as posted,
        //    minus file / api_key / signature).
        $signed = [
            'folder' => $folderBase,
            'public_id' => $publicId,
            'timestamp' => $timestamp,
            'unique_filename' => 'false',
            'use_filename' => 'true',
        ];
        // PDFs go up as resource_type=image so they render inline (previewable);
        // resource_type is part of the URL path, NOT a signed param.
        $isPdf = strtolower(pathinfo($safe, PATHINFO_EXTENSION)) === 'pdf'
            || stripos((string) $mimeType, 'pdf') !== false;
        $signature = $this->signParams($signed);

        // 2. Prepare POST request (image/upload for PDFs = previewable; raw/upload otherwise)
        $resource = $isPdf ? 'image' : 'raw';
        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/{$resource}/upload";
        $postData = [
            'file' => new CURLFile($filePath, $mimeType, $safe),
            'api_key' => $this->apiKey,
            'timestamp' => $timestamp,
            'folder' => $folderBase,
            'public_id' => $publicId,
            'use_filename' => 'true',
            'unique_filename' => 'false',
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
                'url' => $result['secure_url'] ?? '',
                'public_id' => $result['public_id'] ?? ($folderBase . '/' . $publicId),
                'name' => $safe,
            ];
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
        $signature = $this->signParams(['folder' => $folder, 'timestamp' => $timestamp]);

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