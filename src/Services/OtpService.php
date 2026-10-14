<?php
require_once __DIR__ . '/EmailService.php';

class OtpService {
    private $pdo;
    private $emailService;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->emailService = new EmailService();
    }

    public function generateAndSendOtp($applicantId, $email, $name) {
        // 1. Generate a random 6-digit code
        $otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // 2. Hash it for security
        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);
        
        // 3. Set expiration to 5 minutes from now
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        // 4. Save to database
        $stmt = $this->pdo->prepare("
            INSERT INTO otp (applicant_id, otp_code_hash, request_count, attempt_count, expires_at) 
            VALUES (?, ?, 1, 0, ?)
        ");
        $stmt->execute([$applicantId, $otpHash, $expiresAt]);

        // 5. Send the email (return true/false)
        return $this->emailService->sendOtpEmail($email, $name, $otpCode);
    }
}