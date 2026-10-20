<?php

namespace Ace;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mail
{
    private static ?string $lastError = null;

    /**
     * Get the last error message encountered during mail operations.
     */
    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Configure a PHPMailer instance with robust environment settings.
     * Fully compatible with port 587 (STARTTLS), port 465 (SMTPS), and custom relays.
     */
    private static function configureMailer(PHPMailer $mail): void
    {
        $mail->isSMTP();
        $mail->Timeout = (int) env('MAIL_TIMEOUT', 15);
        $mail->Host    = trim((string) env('MAIL_HOST', 'mail.eventlyy.com'));

        $mail->SMTPAuth = !empty(env('MAIL_USERNAME'));
        if ($mail->SMTPAuth) {
            $mail->Username = trim((string) env('MAIL_USERNAME'));
            $mail->Password = (string) env('MAIL_PASSWORD', '');
        }

        $port = (int) env('MAIL_PORT', 587);
        $mail->Port = $port;

        $encryption = strtolower(trim((string) env('MAIL_ENCRYPTION', '')));
        if ($encryption === 'ssl' || $encryption === 'smtps' || ($encryption === '' && $port === 465)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls' || $encryption === 'starttls' || ($encryption === '' && in_array($port, [587, 25, 2525]))) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'none' || $encryption === 'null' || $encryption === 'false') {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        // SSL options for cPanel, self-signed certificates & shared hosting compatibility
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // Resolving from address
        $fromAddress = trim((string) env('MAIL_FROM_ADDRESS', ''));
        if (empty($fromAddress)) {
            $fromAddress = site_setting('support_email', env('MAIL_USERNAME', 'support@eventlyy.com'));
        }
        $fromName = trim((string) env('MAIL_FROM_NAME', ''));
        if (empty($fromName)) {
            $fromName = site_setting('site_title', 'Eventlyy');
        }

        $mail->setFrom($fromAddress, $fromName);
        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->isHTML(true);
    }

    /**
     * Send an email using a view template for the body.
     *
     * @param string|array $to Single email address or array of addresses
     * @param string $subject The email subject
     * @param string $view The view template path for the HTML body
     * @param array $params Variables to pass to the view
     * @return bool True if sent, false otherwise
     */
    public static function send(string|array $to, string $subject, string $view, array $params = []): bool
    {
        self::$lastError = null;
        $mail = new PHPMailer(true);

        try {
            self::configureMailer($mail);

            if (is_array($to)) {
                foreach ($to as $address) {
                    $mail->addAddress(trim($address));
                }
            } else {
                $mail->addAddress(trim($to));
            }

            // Render view content for the body
            $body = Application::$app->view->render($view, $params);

            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            return $mail->send();
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            Logger::error("Message could not be sent. Error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Send batch emails using a persistent SMTP connection (Keep-Alive).
     * Greatly improves throughput and reliability in production for broadcasts.
     *
     * @param array $recipients Array of ['email' => string, 'name' => string]
     * @param string $subject Email subject
     * @param string $view View template path
     * @param callable $paramsCallback function(array $recipient): array
     * @return array ['total' => int, 'sent' => int, 'failed' => int, 'errors' => array]
     */
    public static function sendBatch(array $recipients, string $subject, string $view, callable $paramsCallback): array
    {
        self::$lastError = null;
        $total = count($recipients);
        $sentCount = 0;
        $failedCount = 0;
        $errors = [];

        if ($total === 0) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
        }

        $mail = new PHPMailer(true);

        try {
            self::configureMailer($mail);
            $mail->SMTPKeepAlive = true; // Keep connection alive for batch
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            Logger::error("Failed to initialize SMTP batch session: {$e->getMessage()}");
            return [
                'total' => $total,
                'sent' => 0,
                'failed' => $total,
                'errors' => [$e->getMessage()],
            ];
        }

        foreach ($recipients as $recipient) {
            $email = trim($recipient['email'] ?? '');
            $name  = trim($recipient['name'] ?? '');

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failedCount++;
                $errors[$email ?: 'unknown'] = 'Invalid email address';
                continue;
            }

            try {
                $mail->clearAddresses();
                $mail->clearCustomHeaders();
                $mail->clearAttachments();

                $mail->addAddress($email, $name);

                $params = $paramsCallback($recipient);
                $body = Application::$app->view->render($view, $params);

                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->AltBody = strip_tags($body);

                if ($mail->send()) {
                    $sentCount++;
                } else {
                    $failedCount++;
                    $errMsg = $mail->ErrorInfo ?: 'Unknown send failure';
                    $errors[$email] = $errMsg;
                    self::$lastError = $errMsg;
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[$email] = $e->getMessage();
                self::$lastError = $e->getMessage();
                Logger::error("Batch send failure for {$email}: {$e->getMessage()}");

                // If connection was lost, attempt to reconnect for remaining recipients
                if (!$mail->getSMTPInstance()->connected()) {
                    try {
                        $mail->smtpConnect();
                    } catch (\Throwable $reconnectErr) {
                        Logger::error("SMTP reconnection failed: {$reconnectErr->getMessage()}");
                    }
                }
            }
        }

        try {
            $mail->smtpClose();
        } catch (\Throwable $e) {
            // Ignore close exceptions
        }

        return [
            'total' => $total,
            'sent' => $sentCount,
            'failed' => $failedCount,
            'errors' => $errors,
        ];
    }
}

