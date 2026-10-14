<?php
require_once 'src/Helpers/functions.php';
require_once 'src/Services/OtpService.php';

$pdo = getDbConnection();

// 1. Automatically find the first applicant in the database
$stmt = $pdo->query("SELECT applicant_id, name, email FROM applicant ORDER BY applicant_id ASC LIMIT 1");
$applicant = $stmt->fetch();

if (!$applicant) {
    die("Error: No applicants found in the database. Please run the INSERT SQL command first.");
}

$applicantId = $applicant['applicant_id']; 
$email = $applicant['email']; 
$name = $applicant['name'];

echo "Found Applicant ID: {$applicantId} ({$name}) <br>";
echo "Sending OTP to: {$email} <br><br>";

$otpService = new OtpService($pdo);
$result = $otpService->generateAndSendOtp($applicantId, $email, $name);

if ($result) {
    echo "SUCCESS! OTP generated, saved to DB, and emailed to {$email}.";
} else {
    echo "FAILED to send the OTP email.";
}