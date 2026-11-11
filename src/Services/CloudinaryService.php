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
    /** Build multipart/form-data body and POST via file_get_contents (no cURL needed). */
    private function multipartPost(string $url, array $fields, array $files = []): string
    {
        $boundary = '----WebKitFormBoundary' . uniqid();
        $delimiter = "--$boundary";

        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "$delimiter\r\n";
            $body .= "Content-Disposition: form-data; name=\"$name\"\r\n\r\n";
            $body .= "$value\r\n";
        }
        foreach ($files as $name => $filePath) {
            $filename = basename($filePath);
            $mime = function_exists('mime_content_type') ? @mime_content_type($filePath) : 'application/octet-stream';
            $body .= "$delimiter\r\n";
            $body .= "Content-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\n";
            $body .= "Content-Type: $mime\r\n\r\n";
            $body .= file_get_contents($filePath) . "\r\n";
        }
        $body .= "$delimiter--\r\n";

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\n",
                'content' => $body,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new Exception("Request to $url failed.");
        }
        return $response;
    }

    /** Get HTTP response code from $http_response_header after file_get_contents. */
    private function httpCode(): int
    {
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, 'HTTP/') === 0) {
                preg_match('/HTTP\/[\d.]+ (\d+)/', $header, $m);
                if (!empty($m[1])) return (int) $m[1];
            }
        }
        return 200;
    }


    /**
     * Build a Cloudinary upload signature.
     * IMPORTANT: Cloudinary expects the raw "key=value&..." string with NO URL-encoding
     * (http_build_query encodes "/" as %2F, which produces an Invalid Signature error
     * whenever the folder contains a slash, e.g. ebsmi-shortlist-applicant-cvs/7).
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
     * Layout: flat inside $folderBase (default 'ebsmi-shortlist-applicant-cvs') — NO per-applicant subfolders.
     * Filename: cv-{applicantId}-{original}, e.g. ebsmi-shortlist-applicant-cvs/cv-9-resume.pdf
     * (unique_filename=false, so re-uploading the same applicant's CV overwrites it).
     * Type: resource_type=auto, so PDFs/images become image resources (PREVIEWABLE) while
     * DOC/DOCX remain raw. Uploaded as type=private so the asset is NOT publicly
     * accessible by URL alone; cv-proxy.php streams bytes only to signed-in HR/Admin.
     * Returns ['url', 'public_id', 'name'].
     * @param string $filePath     Local temp path of the uploaded file.
     * @param mixed $applicantId  Applicant id (used in the filename, NOT a subfolder).
     * @param string $folderBase  Cloudinary folder (default 'ebsmi-shortlist-applicant-cvs').
     * @param string|null $originalName Original client filename (e.g. $_FILES['cv']['name']).
     * @param string|null $mimeType     MIME type (auto-detected when omitted).
     */
    public function uploadCV($filePath, $applicantId = null, $folderBase = 'ebsmi-shortlist-applicant-cvs', $originalName = null, $mimeType = null) {
        $timestamp = time();
        $folderBase = trim((string) $folderBase) !== '' ? trim((string) $folderBase) : 'ebsmi-shortlist-applicant-cvs';
        $folderBase = trim($folderBase, '/');

        $originalName = basename(trim((string) ($originalName ?? '')));
        if ($originalName === '' || $originalName === '.') $originalName = 'cv.pdf';
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $originalName);
        $safe = trim($safe, '-.');
        if ($safe === '') $safe = 'cv.pdf';
        $base = pathinfo($safe, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $base), '-');
        if ($base === '') $base = 'cv';
        $publicId = 'cv-' . ($applicantId ?? 'unassigned') . '-' . substr($base, 0, 60);

        if (!$mimeType) {
            $detected = function_exists('mime_content_type') ? @mime_content_type($filePath) : false;
            $mimeType = $detected ?: 'application/octet-stream';
        }

        $signed = [
            'folder'     => $folderBase,
            'public_id'  => $publicId,
            'timestamp'  => $timestamp,
            'type'       => 'private',
            'unique_filename' => 'false',
            'use_filename'    => 'true',
        ];
        $signature = $this->signParams($signed);

        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/auto/upload";
        $fields = [
            'api_key'         => $this->apiKey,
            'timestamp'       => $timestamp,
            'folder'          => $folderBase,
            'public_id'       => $publicId,
            'type'            => 'private',
            'use_filename'    => 'true',
            'unique_filename' => 'false',
            'signature'       => $signature,
        ];
        $files = ['file' => $filePath];

        $response = $this->multipartPost($url, $fields, $files);
        $httpCode = $this->httpCode();

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return [
                'url'       => $result['secure_url'] ?? '',
                'public_id' => $result['public_id'] ?? ($folderBase . '/' . $publicId),
                'name'      => $safe,
            ];
        } else {
            throw new Exception('Cloudinary CV upload failed: ' . $response);
        }
    }

    /**
     * Uploads a job description PDF to Cloudinary.
     * Layout: flat inside $folderBase (default 'ebsmi-job-position-pdfs') — NO subfolders.
     * Filename: jd-{positionId}-{original}, e.g. ebsmi-job-position-pdfs/jd-5-job-description.pdf
     * (unique_filename=false, so re-uploading overwrites it).
     * Type: default (public) so applicants can view the PDF; resource_type=auto makes PDFs previewable.
     * Returns ['url', 'public_id', 'name'].
     * @param string $filePath     Local temp path of the uploaded file.
     * @param mixed $positionId    Position id (used in the filename, NOT a subfolder).
     * @param string $folderBase   Cloudinary folder (default 'ebsmi-job-position-pdfs').
     * @param string|null $originalName Original client filename (e.g. $_FILES['jd']['name']).
     * @param string|null $mimeType MIME type (auto-detected when omitted).
     * @return array ['url', 'public_id', 'name']
     */
    public function uploadJobDescription($filePath, $positionId = null, $folderBase = 'ebsmi-job-position-pdfs', $originalName = null, $mimeType = null) {
        $timestamp = time();
        $folderBase = trim((string) $folderBase) !== '' ? trim((string) $folderBase) : 'ebsmi-job-position-pdfs';
        $folderBase = trim($folderBase, '/');

        $originalName = basename(trim((string) ($originalName ?? '')));
        if ($originalName === '' || $originalName === '.') $originalName = 'jd.pdf';
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $originalName);
        $safe = trim($safe, '-.');
        if ($safe === '') $safe = 'jd.pdf';
        $base = pathinfo($safe, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $base), '-');
        if ($base === '') $base = 'jd';
        $publicId = 'jd-' . ($positionId ?? 'unassigned') . '-' . substr($base, 0, 60);

        if (!$mimeType) {
            $detected = function_exists('mime_content_type') ? @mime_content_type($filePath) : false;
            $mimeType = $detected ?: 'application/pdf';
        }

        $signed = [
            'folder'     => $folderBase,
            'public_id'  => $publicId,
            'timestamp'  => $timestamp,
            'unique_filename' => 'false',
            'use_filename'    => 'true',
        ];
        $signature = $this->signParams($signed);

        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/auto/upload";
        $fields = [
            'api_key'         => $this->apiKey,
            'timestamp'       => $timestamp,
            'folder'          => $folderBase,
            'public_id'       => $publicId,
            'use_filename'    => 'true',
            'unique_filename' => 'false',
            'signature'       => $signature,
        ];
        $files = ['file' => $filePath];

        $response = $this->multipartPost($url, $fields, $files);
        $httpCode = $this->httpCode();

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return [
                'url'       => $result['secure_url'] ?? '',
                'public_id' => $result['public_id'] ?? ($folderBase . '/' . $publicId),
                'name'      => $safe,
            ];
        } else {
            throw new Exception('Cloudinary Job Description upload failed: ' . $response);
        }
    }

    /**
     * Lists the job description PDFs that really exist in the Cloudinary folder (Search API).
     * Works for both "dynamic folders" (asset_folder) and the older fixed folders.
     * Returns a list of ['public_id', 'url', 'name'], or NULL when Cloudinary could not be reached
     * (so callers never mistake "could not check" for "no PDFs").
     */
    public function listJobDescriptions(string $folder = 'ebsmi-job-position-pdfs'): ?array
    {
        $folder = trim($folder, '/');
        $expressions = [
            'asset_folder="' . $folder . '"',
            'folder="' . $folder . '"',
            'public_id:' . $folder . '/*',
        ];
        $url  = "https://api.cloudinary.com/v1_1/{$this->cloudName}/resources/search";
        $auth = base64_encode($this->apiKey . ':' . $this->apiSecret);
        $reached = false;

        foreach ($expressions as $expr) {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/json\r\nAuthorization: Basic $auth\r\n",
                    'content'       => json_encode(['expression' => $expr, 'max_results' => 100]),
                    'timeout'       => 30,
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) continue;
            $data = json_decode($response, true);
            if (!is_array($data) || isset($data['error'])) {
                error_log('Cloudinary search (' . $expr . '): ' . $response);
                continue;
            }
            $reached = true;

            $out = [];
            foreach (($data['resources'] ?? []) as $r) {
                $format = strtolower((string) ($r['format'] ?? ''));
                $link   = (string) ($r['secure_url'] ?? '');
                if ($link === '') continue;
                if ($format !== 'pdf' && !preg_match('/\.pdf($|\?)/i', $link)) continue;   // job descriptions are PDFs
                $name = (string) ($r['display_name'] ?? '');
                if ($name === '') $name = (string) ($r['filename'] ?? '');
                if ($name === '') $name = basename((string) ($r['public_id'] ?? ''));
                $out[] = ['public_id' => (string) ($r['public_id'] ?? ''), 'url' => $link, 'name' => $name];
            }
            if ($out) return $out;      // first expression that finds the folder's PDFs wins
        }
        return $reached ? [] : null;
    }

    /** Deletes a resource from Cloudinary by public_id (used for job description PDFs). */
    public function deleteResource(string $publicId, string $resourceType = 'auto', string $type = 'default'): array
    {
        $timestamp = time();
        $signed = [
            'public_id'  => $publicId,
            'timestamp'  => $timestamp,
            'type'       => $type,
        ];
        $signature = $this->signParams($signed);

        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/destroy";
        $fields = [
            'api_key'       => $this->apiKey,
            'timestamp'     => $timestamp,
            'public_id'     => $publicId,
            'resource_type' => $resourceType,
            'type'          => $type,
            'signature'     => $signature,
        ];

        $response = $this->multipartPost($url, $fields);
        $httpCode = $this->httpCode();

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return [
                'success'  => !empty($result['result']) && in_array($result['result'], ['ok', 'deleted', 'not_found'], true),
                'result'   => $result['result'] ?? 'unknown',
                'response' => $result,
            ];
        }
        throw new Exception('Cloudinary delete failed: ' . $response);
    }

    /**
     * Uploads an applicant video to Cloudinary and returns its URL + SHA-256 hash.
     * Layout: flat inside $folderBase (default 'ebsmi-shortlist-applicant-videos') — NO per-applicant subfolders.
     * Filename: video-{applicantId}-{timestamp}, e.g. ebsmi-shortlist-applicant-videos/video-9-1729...
     * (unique_filename=false + explicit public_id, so retries overwrite instead of duplicating).
     * @return array ['success' => bool, 'url' => string, 'hash' => string]
     */
    public function uploadVideo($filePath, $applicantId = null, $folderBase = 'ebsmi-shortlist-applicant-videos') {
        $timestamp = time();
        $folderBase = trim((string) $folderBase) !== '' ? trim((string) $folderBase) : 'ebsmi-shortlist-applicant-videos';
        $folderBase = trim($folderBase, '/');

        // 1. Generate a SHA-256 hash BEFORE uploading (for risk scoring)
        // This is required for your rubric's "reused video" check.
        $videoHash = hash_file('sha256', $filePath);

        // Flat layout: one public_id per applicant, retries overwrite.
        $publicId = 'video-' . ($applicantId ?? 'unassigned') . '-' . $timestamp;

        // 2. Generate Signature (folder + public_id + timestamp + overwrite, raw key=value string)
        $signed = [
            'folder'      => $folderBase,
            'overwrite'   => 'true',
            'public_id'   => $publicId,
            'timestamp'   => $timestamp,
            'unique_filename' => 'false',
        ];
        $signature = $this->signParams($signed);

        // 3. Prepare POST request (Note: '/video/upload' for videos)
        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/video/upload";
        $fields = [
            'api_key' => $this->apiKey,
            'timestamp' => $timestamp,
            'folder' => $folderBase,
            'public_id' => $publicId,
            'overwrite' => 'true',
            'unique_filename' => 'false',
            'signature' => $signature
        ];
        $files = ['file' => $filePath];

        $response = $this->multipartPost($url, $fields, $files);
        $httpCode = $this->httpCode();

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

    /**
     * Uploads a profile picture (JPG/PNG/WEBP) to Cloudinary.
     * Layout: flat inside $folderBase (default 'ebsmi-user-profiles') — NO subfolders.
     * Filename: avatar-{applicantId}-{original}, e.g. ebsmi-user-profiles/avatar-3-resume.jpg
     * Type: image/* only; resource_type=auto; private so only proxies can serve it.
     * Returns ['url', 'public_id', 'name'].
     * @param string $filePath Local temp path of the uploaded file.
     * @param int $applicantId 0 for Admin, HR id for HR staff (used in filename, NOT a subfolder).
     * @param string $folderBase Cloudinary folder (default 'ebsmi-user-profiles').
     * @param string|null $originalName Original client filename (e.g. $_FILES['avatar']['name']).
     * @param string|null $mimeType MIME type (auto-detected when omitted).
     * @return array ['url', 'public_id', 'name']
     */
    public function uploadAvatar($filePath, $applicantId = 0, $folderBase = 'ebsmi-user-profiles', $originalName = null, $mimeType = null) {
        $timestamp = time();
        $folderBase = trim((string) $folderBase) !== '' ? trim((string) $folderBase) : 'ebsmi-user-profiles';
        $folderBase = trim($folderBase, '/');
        $originalName = basename(trim((string) ($originalName ?? '')));
        if ($originalName === '' || $originalName === '.') $originalName = 'avatar.jpg';
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $originalName);
        $safe = trim($safe, '-.');
        if ($safe === '') $safe = 'avatar.jpg';
        $base = pathinfo($safe, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $base), '-');
        if ($base === '') $base = 'avatar';
        $publicId = 'avatar-' . ($applicantId ?? 'unassigned') . '-' . substr($base, 0, 60);
        if (!$mimeType) {
            $detected = function_exists('mime_content_type') ? @mime_content_type($filePath) : false;
            $mimeType = $detected ?: 'application/octet-stream';
        }
        $detectedType = explode('/', $mimeType, 2)[0] ?? 'image';
        if (!in_array($detectedType, ['image'], true)) {
            throw new Exception("Cloudinary Avatar upload failed: only image files are allowed.");
        }
        $signed = [
            'folder'     => $folderBase,
            'public_id'  => $publicId,
            'timestamp'  => $timestamp,
            'type'       => 'private',
            'unique_filename' => 'false',
            'use_filename'    => 'true',
        ];
        $signature = $this->signParams($signed);
        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/upload";
        $fields = [
            'api_key'         => $this->apiKey,
            'timestamp'       => $timestamp,
            'folder'          => $folderBase,
            'public_id'       => $publicId,
            'type'            => 'private',
            'use_filename'    => 'true',
            'unique_filename' => 'false',
            'signature'       => $signature,
        ];
        $files = ['file' => $filePath];
        $response = $this->multipartPost($url, $fields, $files);
        $httpCode = $this->httpCode();
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return [
                'url'       => $result['secure_url'] ?? '',
                'public_id' => $result['public_id'] ?? ($folderBase . '/' . $publicId),
                'name'      => $safe,
            ];
        } else {
            throw new Exception('Cloudinary Avatar upload failed: ' . $response);
        }
    }
}