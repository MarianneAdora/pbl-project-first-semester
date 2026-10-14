<?php
class EmailService {
    public function sendTestEmail($toEmail) {
        // 1. Manually load the .env file just for this test
        $env = parse_ini_file(__DIR__ . '/../../.env');
        $apiKey = $env['BREVO_API_KEY'] ?? '';

        if (empty($apiKey)) {
            die("Error: BREVO_API_KEY is missing from your .env file.");
        }

        // 2. Prepare the email data
        $data = [
            "sender" => ["name" => "Expertise BPO", "email" => "ebsmi.screening@gmail.com"],
            "to" => [["email" => $toEmail, "name" => "Test Applicant"]],
            "subject" => "Testing Brevo Integration",
            "htmlContent" => "<h1>It works!</h1><p>Your PHP code successfully connected to Brevo.</p>"
        ];

        // 3. Send to Brevo
        $ch = curl_init("https://api.brevo.com/v3/smtp/email");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "accept: application/json",
            "api-key: " . $apiKey,
            "content-type: application/json"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 201) {
            echo "SUCCESS! Check your inbox.";
        } else {
            echo "FAILED. Brevo said: " . $response;
        }
    }

    public function sendOtpEmail($toEmail, $toName, $otpCode) {
        $env = parse_ini_file(__DIR__ . '/../../.env');
        $apiKey = $env['BREVO_API_KEY'] ?? '';

        $data = [
            "sender" => ["name" => "Expertise BPO", "email" => "ebsmi.screening@gmail.com"],
            "to" => [["email" => $toEmail, "name" => $toName]],
            "subject" => "Your Screening Verification Code",
            "htmlContent" => "<h2>Hello {$toName},</h2>
                            <p>Your 6-digit verification code is:</p>
                            <h1 style='font-size: 32px; letter-spacing: 5px;'>{$otpCode}</h1>
                            <p>This code will expire in 5 minutes.</p>"
        ];

        $ch = curl_init("https://api.brevo.com/v3/smtp/email");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "accept: application/json",
            "api-key: " . $apiKey,
            "content-type: application/json"
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 201;
    }

    public function sendInviteEmail($toEmail, $firstName, $secureToken) {
        $env = parse_ini_file(__DIR__ . '/../../.env');
        $apiKey = $env['BREVO_API_KEY'] ?? '';

        // 1. Build the personal link using the secure token
        // NOTE: Change 'localhost' to your real domain when you deploy
        $link = "http://localhost/pbl-project-first-semester/applicant/opening.php?token=" . $secureToken;

        // 2. Load the HTML template
        $htmlContent = file_get_contents(__DIR__ . '/../../shortlist-email.html');

        // 3. Replace the placeholders
        $htmlContent = str_replace('{{FIRST_NAME}}', $firstName, $htmlContent);
        $htmlContent = str_replace('{{LINK}}', $link, $htmlContent);
        $htmlContent = str_replace('{{YEAR}}', date('Y'), $htmlContent);

        // 4. CRITICAL FIX: Replace the relative image path with an absolute URL
        // Email clients cannot read local files. You must host this image publicly.
        // If you don't have it hosted yet, you can use a placeholder URL for testing.
        $htmlContent = str_replace('../assets/images/expertise.png', 'https://your-domain.com/assets/images/expertise.png', $htmlContent);

        // 5. Prepare Brevo payload
        $data = [
            "sender" => ["name" => "Expertise BPO", "email" => "ebsmi.screening@gmail.com"], // USE YOUR VERIFIED SENDER
            "to" => [["email" => $toEmail, "name" => $firstName]],
            "subject" => "You've been shortlisted | Expertise",
            "htmlContent" => $htmlContent
        ];

        $ch = curl_init("https://api.brevo.com/v3/smtp/email");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "accept: application/json",
            "api-key: " . $apiKey,
            "content-type: application/json"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 201) {
            return true;
        } else {
            echo "<strong>Brevo API Error:</strong> " . $response . "<br>";
            return false;
        }
    }
}