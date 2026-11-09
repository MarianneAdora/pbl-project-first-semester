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
            throw new RuntimeException("Email template '{$file}' could not be loaded.");
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
    private const LOGO_URL = 'https://res.cloudinary.com/y0s0brvb/image/upload/v1792922174/expertise.png';

    private function brevoApiKey(): string
    {
        $envPath = __DIR__ . '/../../.env';
        $env = is_file($envPath) ? @parse_ini_file($envPath, false, INI_SCANNER_RAW) : false;
        $apiKey = is_array($env) ? trim((string) ($env['BREVO_API_KEY'] ?? '')) : '';
        if ($apiKey === '' || $apiKey === 'your_brevo_api_key_here') {
            error_log('Email delivery unavailable: BREVO_API_KEY is missing or unreadable.');
            return '';
        }
        return $apiKey;
    }

    private function brevoSend(array $data): bool
    {
        $apiKey = $this->brevoApiKey();
        if ($apiKey === '') return false;

        $url = "https://api.brevo.com/v3/smtp/email";
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n"
                          . "api-key: $apiKey\r\n"
                          . "accept: application/json\r\n",
                'content' => json_encode($data),
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            error_log('Brevo email request failed: ' . ($error['message'] ?? 'network request failed.'));
            return false;
        }
        $httpCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, 'HTTP/') === 0) {
                preg_match('/HTTP\/[\d.]+ (\d+)/', $header, $m);
                if (!empty($m[1])) $httpCode = (int) $m[1];
            }
        }
        if ($httpCode === 201) return true;
        error_log('Brevo email request was rejected with HTTP status ' . $httpCode . '.');
        return false;
    }
    public function sendTestEmail($toEmail) {
        $data = [
            "sender" => ["name" => "Expertise BPO", "email" => "ebsmi.screening@gmail.com"],
            "to" => [["email" => $toEmail, "name" => "Test Applicant"]],
            "subject" => "Testing Brevo Integration",
            "htmlContent" => "<h1>It works!</h1><p>Your PHP code successfully connected to Brevo.</p>"
        ];
        $success = $this->brevoSend($data);
        if ($success) {
            echo "SUCCESS! Check your inbox.";
        } else {
            echo "FAILED. Check Brevo API key and network.";
        }
        return $success;
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

        return $this->brevoSend($data);
    }

    public function sendInviteEmail($toEmail, $toName, $secureToken) {
        // $toName may be the full name — the template wants the first name only.
        $firstName = trim(explode(' ', trim((string) $toName))[0] ?? (string) $toName);
        if ($firstName === '') $firstName = (string) $toName;
        $appUrl = defined('APP_URL') ? (string) APP_URL : (string) getenv('APP_URL');
        if ($appUrl === '') {
            $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/hr/data.php');
            $root   = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/');
            $appUrl = ($https ? 'https://' : 'http://') . $host . $root;
        }
        $link = rtrim($appUrl, '/') . '/applicant/opening.php?token=' . rawurlencode((string) $secureToken);

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

        return $this->brevoSend($data);
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

        return $this->brevoSend($data);
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

        return $this->brevoSend($data);
    }

    public function sendHrCredentialsEmail($toEmail, $toName, $password) {
        $firstName = trim(explode(' ', trim((string) $toName))[0] ?? (string) $toName);
        if ($firstName === '') $firstName = (string) $toName;
        $htmlContent = $this->loadTemplate('hr-credentials-email.html');
        $htmlContent = str_replace('{{FIRST_NAME}}', htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{EMAIL}}',      htmlspecialchars((string) $toEmail,   ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{PASSWORD}}',   htmlspecialchars((string) $password,  ENT_QUOTES, 'UTF-8'), $htmlContent);
        $htmlContent = str_replace('{{YEAR}}', date('Y'), $htmlContent);

        $data = [
            "sender" => ["name" => "Expertise BPO", "email" => "ebsmi.screening@gmail.com"],
            "to" => [["email" => $toEmail, "name" => (string) $toName]],
            "subject" => "Your Expertise HR account credentials",
            "htmlContent" => $htmlContent
        ];

        return $this->brevoSend($data);
    }

    public function sendEmail(string $toEmail, string $subject, string $html, string $toName = ''): bool
    {
        $data = [
            'sender'      => ['name' => 'Expertise BPO', 'email' => 'ebsmi.screening@gmail.com'],
            'to'          => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
            'subject'     => $subject,
            'htmlContent' => $html,
        ];
        return $this->brevoSend($data);
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

        return $this->brevoSend($data);
    }
}

