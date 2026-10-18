<?php
require_once 'src/Helpers/functions.php';
require_once 'src/Services/EmailService.php';

$emailService = new EmailService();

// Use Clark Kent's details from your database
$toEmail = 'ebsmi.screening@gmail.com'; // sender + receiver inbox
$firstName = 'Clark';
$secureToken = 'test-token-abc123'; // From your applicant table

$result = $emailService->sendInviteEmail($toEmail, $firstName, $secureToken);

if ($result) {
    echo "SUCCESS! Invite email sent.";
} else {
    echo "FAILED to send invite.";
}