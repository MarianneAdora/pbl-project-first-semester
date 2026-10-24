<?php
class EmailService {
    private function templatePath(string $file): string
    {
        // All email HTML templates live in includes/emails/.
        $root = realpath(__DIR__ . '/../../');
        $candidates = [];
        if ($root) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'emails' . DIRECTORY_SEPARATOR . $file;
            $candidates[] = $root . DIRECTORY_SEPARATOR . $file; // legacy: project root
            $candidates[] = $root . DIRECTORY_SEPARATOR . 'hr' . DIRECTORY_SEPARATOR . $file; // legacy: hr/
        }
        $candidates[] = __DIR__ . '/../../includes/emails/' . $file;
        foreach ($candidates as $path) {
            if (is_file($path)) return $path;
        }
        return $candidates[0];
    }

    private function loadTemplate(string $file): string
    {
        $path = $this->templatePath($file);
        $html = is_file($path) ? file_get_contents($path) : false;
        if ($html === false || $html === '') {
            die("Error: email template '{$file}' not found in includes/emails/.");
        }
        // Swap relative logo paths to the Cloudinary URL so the logo displays in email clients.
        $html = str_replace(
            ['../../assets/images/expertise.png', '../assets/images/expertise.png',
             'assets/images/expertise.png', '../pic/expertise.png'],
            self::LOGO_URL,
            $html
        );
        return $html;
    }

    // Cloudinary logo URL used in sent emails (relative paths don't work in email clients).
    private const LOGO_URL = 'https://res.cloudinary.com/y0s0brvb/image/upload/v1792442818/expertise.png';

    private function brevoSend(array $data, bool $echoError = true): bool
    {
        $env = parse_ini_file(__DIR__ . '/../../.env');
        $apiKey = $env['BREVO_API_KEY'] ?? '';
        if (empty($apiKey)) {
            die("Error: BREVO_API_KEY is missing from your .env file.");
        }
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
        if ($httpCode === 201) return true;
        if ($echoError) echo "<strong>Brevo API Error:</strong> " . $response . "<br>";
        return false;
    }
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
        $firstName = trim(explode(' ', trim((string) $toName))[0] ?? (string) $toName);
        $htmlContent = $this->loadTemplate('otp-email.html');
        $htmlContent = str_replace('{{FIRST_NAME}}', htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{OTP_CODE}}', htmlspecialchars((string) $otpCode, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{YEAR}}', date('Y'), $htmlContent);

        $data = [
            "sender" => ["name" => "Expertise BPO", "email" => "ebsmi.screening@gmail.com"],
            "to" => [["email" => $toEmail, "name" => $toName]],
            "subject" => "Your Screening Verification Code",
            "htmlContent" => $htmlContent
        ];

        return $this->brevoSend($data, false);
    }

    public function sendInviteEmail($toEmail, $toName, $secureToken) {
        // $toName may be the full name — the template wants the first name only.
        $firstName = trim(explode(' ', trim((string) $toName))[0] ?? (string) $toName);
        if ($firstName === '') $firstName = (string) $toName;
        // 1. Build the personal link using the secure token
        // NOTE: Change 'localhost' to your real domain when you deploy
        $link = "http://localhost/pbl-project-first-semester/applicant/opening.php?token=" . $secureToken;

        // 2. Load the HTML template from project root
        $htmlContent = $this->loadTemplate('shortlist-email.html');

        // 3. Replace the placeholders
        $htmlContent = str_replace('{{FIRST_NAME}}', htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{LINK}}', htmlspecialchars($link, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{YEAR}}', date('Y'), $htmlContent);

        // 4. Prepare Brevo payload
        $data = [
            "sender" => ["name" => "Expertise BPO", "email" => "ebsmi.screening@gmail.com"], // USE YOUR VERIFIED SENDER
            "to" => [["email" => $toEmail, "name" => (string) $toName]],
            "subject" => "You've been shortlisted | Expertise",
            "htmlContent" => $htmlContent
        ];

        return $this->brevoSend($data, true);
    }

    public function sendScheduleApprovedEmail($toEmail, $firstName, $date, $time, $type, $location, $link) {
        $htmlContent = $this->loadTemplate('schedule-approved-email.html');
        $htmlContent = str_replace('{{FIRST_NAME}}', htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{DATE}}', htmlspecialchars($date, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{TIME}}', htmlspecialchars($time, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{INTERVIEW_TYPE}}', htmlspecialchars($type, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{LOCATION}}', htmlspecialchars($location, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{LINK}}', htmlspecialchars($link, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{YEAR}}', date('Y'), $htmlContent);

        $data = [
            "sender" => ["name" => "Expertise BPO", "email" => "ebsmi.screening@gmail.com"],
            "to" => [["email" => $toEmail, "name" => $firstName]],
            "subject" => "Your interview is confirmed | Expertise",
            "htmlContent" => $htmlContent
        ];

        return $this->brevoSend($data, true);
    }

    public function sendScheduleRescheduledEmail($toEmail, $firstName, $date, $time, $type, $location, $link) {
        $htmlContent = $this->loadTemplate('schedule-rescheduled-email.html');
        $htmlContent = str_replace('{{FIRST_NAME}}', htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{DATE}}', htmlspecialchars($date, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{TIME}}', htmlspecialchars($time, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{INTERVIEW_TYPE}}', htmlspecialchars($type, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{LOCATION}}', htmlspecialchars($location, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{LINK}}', htmlspecialchars($link, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{YEAR}}', date('Y'), $htmlContent);

        $data = [
            "sender" => ["name" => "Expertise BPO", "email" => "ebsmi.screening@gmail.com"],
            "to" => [["email" => $toEmail, "name" => $firstName]],
            "subject" => "New interview schedule | Expertise",
            "htmlContent" => $htmlContent
        ];

        return $this->brevoSend($data, true);
    }

    public function sendScreeningRejectedEmail($toEmail, $firstName) {
        $htmlContent = $this->loadTemplate('screening-rejected-email.html');
        $htmlContent = str_replace('{{FIRST_NAME}}', htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{YEAR}}', date('Y'), $htmlContent);

        $data = [
            "sender" => ["name" => "Expertise BPO", "email" => "ebsmi.screening@gmail.com"],
            "to" => [["email" => $toEmail, "name" => $firstName]],
            "subject" => "Your application update | Expertise",
            "htmlContent" => $htmlContent
        ];

        return $this->brevoSend($data, true);
    }
}