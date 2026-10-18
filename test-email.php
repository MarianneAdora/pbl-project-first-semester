<?php
require_once 'src/Services/EmailService.php';

$emailService = new EmailService();
// Sender AND receiver: ebsmi.screening@gmail.com
$emailService->sendTestEmail('ebsmi.screening@gmail.com'); 