<?php
// api/mailer_config.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php'; // Ensure default constants are loaded

function getMailerInstance($pdo, $user_id = null) {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    // Tumeondoa '$mail->SMTPAuth = true;' hapa. Itasetiwa kulingana na mazingira.

    $user_settings = null;
    if ($user_id) {
        // Hakikisha $pdo ipo
        if (!isset($pdo) || !$pdo) {
             throw new Exception("Database connection is not available (pdo is null) in mailer_config.");
        }
        // Step 1: Fetch tenant-specific SMTP settings from the 'users' table.
        $stmt_user = $pdo->prepare("SELECT smtp_choice, smtp_host, smtp_port, smtp_secure, smtp_username, smtp_password, smtp_from_email, smtp_from_name FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_settings = $stmt_user->fetch(PDO::FETCH_ASSOC);
    }

    // Step 2: Decide whether to use custom settings or the default fallback.
    $use_custom_smtp = (
        $user_settings &&
        isset($user_settings['smtp_choice']) && 
        $user_settings['smtp_choice'] === 'custom' && 
        !empty($user_settings['smtp_host']) && 
        !empty($user_settings['smtp_username']) && 
        !empty($user_settings['smtp_password'])
    );

    if ($use_custom_smtp) {
        // === ANATUMIA VIGEZO VYAKE (CUSTOM) ===
        $mail->SMTPAuth   = true; // Mteja lazima aweke vigezo
        $mail->Host       = $user_settings['smtp_host'];
        $mail->Username   = $user_settings['smtp_username'];
        $mail->Password   = $user_settings['smtp_password']; // In production, decrypt this
        $mail->Port       = (int)$user_settings['smtp_port'];
        $mail->SMTPSecure = $user_settings['smtp_secure'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        
        $from_email = !empty($user_settings['smtp_from_email']) ? $user_settings['smtp_from_email'] : DEFAULT_FROM_EMAIL;
        $from_name = !empty($user_settings['smtp_from_name']) ? $user_settings['smtp_from_name'] : DEFAULT_FROM_NAME;
        $mail->setFrom($from_email, $from_name);

    } else {
        // === ANATUMIA VIGEZO VYA 'DEFAULT' (KUTOKA config.php) ===
        
        $mail->Host     = DEFAULT_SMTP_HOST;
        $mail->Port     = DEFAULT_SMTP_PORT;
        $mail->Username = DEFAULT_SMTP_USERNAME;
        $mail->Password = DEFAULT_SMTP_PASSWORD;

        // --- HAPA NDIPO PENYE REKEBISHO KUU ---
        
        // Tunatumia 'localhost' (kama ulivyoweka kwenye config.php)?
        if (DEFAULT_SMTP_HOST === 'localhost' || DEFAULT_SMTP_HOST === '127.0.0.1') {
            
            // Kwa localhost, hatuhitaji authentication wala encryption
            $mail->SMTPAuth   = false;
            $mail->SMTPSecure = false; // (PHPMailer::ENCRYPTION_NONE)
            
            // Zima uthibitishaji wa cheti cha SSL kwa localhost (muhimu sana)
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
        } else {
            // Kama default SIO localhost (k.m. bado ungetumia mail.chatme.co.tz)
            $mail->SMTPAuth   = true; // Weka 'true'
            $mail->SMTPSecure = DEFAULT_SMTP_SECURE === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        }
        // --- MWISHO WA REKEBISHO ---

        $mail->setFrom(DEFAULT_FROM_EMAIL, DEFAULT_FROM_NAME);
    }

    return $mail;
}
?>