<?php
// A simple, reusable email sending function.
// In a real-world application, you would use a robust library like PHPMailer or Symfony Mailer.

require_once 'config.php';

function sendEmail($to, $subject, $body) {
    // Check if custom SMTP settings are configured and active
    // For this example, we'll assume they are, but a real implementation
    // would have logic to fall back to a default mailer.

    // This is a simplified representation. A real implementation would use a proper SMTP library.
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: <' . SMTP_FROM_EMAIL . '>' . "\r\n";

    // The mail() function is notoriously unreliable on shared hosting.
    // A library using SMTP authentication is strongly recommended.
    if (mail($to, $subject, $body, $headers)) {
        error_log("Successfully sent email to {$to} with subject '{$subject}'");
        return true;
    } else {
        error_log("Failed to send email to {$to}");
        return false;
    }
}
