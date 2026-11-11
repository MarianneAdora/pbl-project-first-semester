<?php
require_once __DIR__ . '/../src/Helpers/functions.php';
require_once __DIR__ . '/../src/Services/EmailService.php';

$emailService = new EmailService();

// Sender is ebsmi.screening@gmail.com (see EmailService.php), receiver is the same inbox
$toEmail   = 'ebsmi.screening@gmail.com';
$firstName = 'Clark';

// Sample interview details (same shape as hr/applicant-details-data.php uses)
$date     = 'Monday, October 20, 2026';
$time     = '10:00 AM';
$type     = 'Face-to-face';
$location = 'Expertise Office, BGC, Taguig';
$link     = 'http://localhost/pbl-project-first-semester/applicant/application-status.php';

$type_param = strtolower(trim($_GET['type'] ?? ''));

function send_and_report($label, $ok) {
    if ($ok) {
        echo "SUCCESS! {$label} email sent to {$GLOBALS['toEmail']}.<br>";
    } else {
        echo "FAILED to send {$label} email.<br>";
    }
}

if ($type_param === '') {
    // No type chosen: show clickable test links
    echo "<h2>Test status emails</h2>";
    echo "<p>Sending to: <strong>" . htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8') . "</strong> (change \$toEmail in this file to use your inbox)</p>";
    echo "<ul>";
    echo "<li><a href='?type=approved'>Send APPROVED email</a> (schedule-approved-email.html)</li>";
    echo "<li><a href='?type=rescheduled'>Send RESCHEDULED email</a> (schedule-rescheduled-email.html)</li>";
    echo "<li><a href='?type=rejected'>Send REJECTED email</a> (screening-rejected-email.html)</li>";
    echo "<li><a href='?type=all'>Send ALL THREE</a></li>";
    echo "</ul>";
    exit;
}

if ($type_param === 'approved' || $type_param === 'all') {
    $result = $emailService->sendScheduleApprovedEmail($toEmail, $firstName, $date, $time, $type, $location, $link);
    send_and_report('APPROVED (interview confirmed)', $result);
}

if ($type_param === 'rescheduled' || $type_param === 'all') {
    $result = $emailService->sendScheduleRescheduledEmail($toEmail, $firstName, 'Tuesday, October 21, 2026', '2:00 PM', $type, $location, $link);
    send_and_report('RESCHEDULED (new schedule)', $result);
}

if ($type_param === 'rejected' || $type_param === 'all') {
    $result = $emailService->sendScreeningRejectedEmail($toEmail, $firstName);
    send_and_report('REJECTED (screening not passed)', $result);
}

if (!in_array($type_param, ['approved', 'rescheduled', 'rejected', 'all'], true)) {
    echo "Unknown type '{$type_param}'. Use ?type=approved, ?type=rescheduled, ?type=rejected, or ?type=all.";
}
