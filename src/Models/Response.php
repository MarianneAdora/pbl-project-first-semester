<?php
class Response {
    private $response_id;
    private $applicant_id;
    private $answers_json;
    private $video_file_url;
    private $video_file_hash;
    private $ip_address;
    private $device_fingerprint;
    private $data_protection_agreed_at;
    private $started_at;
    private $submitted_at;

    public function __construct(array $data = []) {
        $this->response_id = $data['response_id'] ?? null;
        $this->applicant_id = $data['applicant_id'] ?? null;
        $this->answers_json = isset($data['answers_json']) ? json_decode($data['answers_json'], true) : [];
        $this->video_file_url = $data['video_file_url'] ?? null;
        $this->video_file_hash = $data['video_file_hash'] ?? null;
        $this->ip_address = $data['ip_address'] ?? null;
        $this->device_fingerprint = $data['device_fingerprint'] ?? null;
        $this->data_protection_agreed_at = $data['data_protection_agreed_at'] ?? null;
        $this->started_at = $data['started_at'] ?? null;
        $this->submitted_at = $data['submitted_at'] ?? null;
    }

    public function getResponseId() { return $this->response_id; }
    public function getApplicantId() { return $this->applicant_id; }
    public function getAnswersJson() { return $this->answers_json; }
    public function getVideoFileUrl() { return $this->video_file_url; }
    public function getVideoFileHash() { return $this->video_file_hash; }
    public function getIpAddress() { return $this->ip_address; }
    public function getDeviceFingerprint() { return $this->device_fingerprint; }
    public function getSubmittedAt() { return $this->submitted_at; }
}