<?php
class Otp {
    private $otp_id;
    private $applicant_id;
    private $otp_code_hash;
    private $attempt_count;
    private $request_count;
    private $expires_at;
    private $created_at;

    public function __construct(array $data = []) {
        $this->otp_id = $data['otp_id'] ?? null;
        $this->applicant_id = $data['applicant_id'] ?? null;
        $this->otp_code_hash = $data['otp_code_hash'] ?? null;
        $this->attempt_count = $data['attempt_count'] ?? 0;
        $this->request_count = $data['request_count'] ?? 0;
        $this->expires_at = $data['expires_at'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
    }

    public function getOtpId() { return $this->otp_id; }
    public function getApplicantId() { return $this->applicant_id; }
    public function getOtpCodeHash() { return $this->otp_code_hash; }
    public function getAttemptCount() { return $this->attempt_count; }
    public function getRequestCount() { return $this->request_count; }
    public function getExpiresAt() { return $this->expires_at; }
    
    public function incrementAttempt() { $this->attempt_count++; }
    public function incrementRequest() { $this->request_count++; }
}