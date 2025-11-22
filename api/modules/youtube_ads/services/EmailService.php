<?php
namespace Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    private function getTenantSettings($tenantId) {
        $stmt = $this->pdo->prepare("SELECT * FROM settings WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $settings = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $settings ? $settings : [];
    }

    public function sendEmailWithTenantSettings($tenantId, $to, $subject, $body, $attachmentPath = null) {
        $mail = new PHPMailer(true);
        $settings = $this->getTenantSettings($tenantId);

        try {
            // Server settings
            if (!empty($settings['smtp_host']) && $settings['smtp_choice'] === 'custom') {
                $mail->isSMTP();
                $mail->Host       = $settings['smtp_host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $settings['smtp_username'];
                $mail->Password   = $settings['smtp_password']; // Assuming it's decrypted
                $mail->SMTPSecure = $settings['smtp_secure'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = $settings['smtp_port'];
            } else {
                // Use default mail() function if no custom SMTP
                $mail->isMail();
            }

            // From address
            $fromEmail = !empty($settings['smtp_from_email']) ? $settings['smtp_from_email'] : 'noreply@chatme.com';
            $fromName = !empty($settings['business_name']) ? $settings['business_name'] : 'ChatMe Reports';
            $mail->setFrom($fromEmail, $fromName);

            //Recipients
            $mail->addAddress($to);

            //Attachments
            if ($attachmentPath && file_exists($attachmentPath)) {
                $mail->addAttachment($attachmentPath);
            }

            //Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error for tenant $tenantId: {$mail->ErrorInfo}");
            return false;
        }
    }
}
