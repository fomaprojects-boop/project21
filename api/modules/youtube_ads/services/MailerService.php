<?php

namespace Modules\YouTubeAds\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../../../vendor/autoload.php';

class MailerService {
    private $mail;

    public function __construct() {
        $this->mail = new PHPMailer(true);
        $this->configureSmtp();
    }

    private function configureSmtp() {
        try {
            // Server settings
            $this->mail->isSMTP();
            $this->mail->Host       = getenv('SMTP_HOST');
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = getenv('SMTP_USER');
            $this->mail->Password   = getenv('SMTP_PASS');
            $this->mail->SMTPSecure = getenv('SMTP_SECURE') ?: PHPMailer::ENCRYPTION_STARTTLS;
            $this->mail->Port       = getenv('SMTP_PORT') ?: 587;

            // Sender
            $fromEmail = getenv('SMTP_FROM_EMAIL') ?: 'noreply@yourdomain.com';
            $fromName = getenv('SMTP_FROM_NAME') ?: 'ChatMe Reporter';
            $this->mail->setFrom($fromEmail, $fromName);

        } catch (Exception $e) {
            error_log("MailerService configuration failed: {$this->mail->ErrorInfo}");
        }
    }

    public function sendReportEmail($recipientEmail, $adTitle, $pdfPath) {
        try {
            // Recipients
            $this->mail->addAddress($recipientEmail);

            // Content
            $this->mail->isHTML(true);
            $this->mail->Subject = 'Your Ad Performance Report: ' . $adTitle;
            $this->mail->Body    = 'Hello, <br><br>Please find the latest performance report for your ad campaign, "' . $adTitle . '", attached to this email.<br><br>Thank you,<br>The ChatMe Team';
            $this->mail->AltBody = 'Hello, Please find the latest performance report for your ad campaign, "' . $adTitle . '", attached to this email.';

            // Attachments
            $fullPdfPath = __DIR__ . '/../../../../' . $pdfPath;
            if (file_exists($fullPdfPath)) {
                $this->mail->addAttachment($fullPdfPath);
            } else {
                error_log("MailerService: PDF attachment not found at " . $fullPdfPath);
                return false;
            }
            
            $this->mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$this->mail->ErrorInfo}");
            return false;
        }
    }
}
