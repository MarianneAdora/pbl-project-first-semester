<?php
class Applicant {
    private $applicant_id;
    private $reference_code;
    private $secure_token;
    private $encoded_by_user_id;
    private $name;
    private $email;
    private $phone_number;
    private $cv_file_url;
    private $device_fingerprint;
    private $ip_address;
    private $status;
    private $result;
    private $deleted_at;
    private $created_at;

    public function __construct(array $data = []) {
        $this->applicant_id = $data['applicant_id'] ?? null;
        $this->reference_code = $data['reference_code'] ?? null;
        $this->secure_token = $data['secure_token'] ?? null;
        $this->encoded_by_user_id = $data['encoded_by_user_id'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->email = $data['email'] ?? null;
        $this->phone_number = $data['phone_number'] ?? null;
        $this->cv_file_url = $data['cv_file_url'] ?? null;
        $this->device_fingerprint = $data['device_fingerprint'] ?? null;
        $this->ip_address = $data['ip_address'] ?? null;
        $this->status = $data['status'] ?? 'PENDING';
        $this->result = $data['result'] ?? null;
        $this->deleted_at = $data['deleted_at'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
    }

    // Add getters and setters here...
    public function getApplicantId() { return $this->applicant_id; }
    public function getReferenceCode() { return $this->reference_code; }
    public function getSecureToken() { return $this->secure_token; }
    public function getEmail() { return $this->email; }
    public function getStatus() { return $this->status; }
    public function getResult() { return $this->result; }
    public function setStatus($status) { $this->status = $status; }
    public function setResult($result) { $this->result = $result; }

    public function toArray() {
        return [
            'applicant_id' => $this->applicant_id,
            'reference_code' => $this->reference_code,
            'name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'status' => $this->status,
            'result' => $this->result
        ];
    }
}