<?php

// #####################################################################
// # SECURITY WARNING: DO NOT COMMIT SECRETS TO VERSION CONTROL
// #####################################################################
// #
// # The credentials below (DB_PASSWORD, ENCRYPTION_KEY, GOOGLE_CLIENT_SECRET, 
// # FACEBOOK_APP_SECRET) are sensitive. Storing them directly in the code 
// # is a major security risk and is not recommended for production.
// #
// # RECOMMENDED PRACTICE: Use environment variables.
// # 1. Create a file named '.env' in the root directory.
// # 2. Add your secrets to this file, e.g., DB_PASSWORD="your_password".
// # 3. Make sure '.env' is listed in your .gitignore file.
// # 4. Use a library (like PHP dotenv) to load these variables into your app.
// #    e.g., define('DB_PASSWORD', $_ENV['DB_PASSWORD']);
// #
// #####################################################################


// Feature Flags
define('FEATURE_ENHANCED_EXPENSE_WORKFLOW', true);

// Base URL
// --- REKEBISHO HAPA: Imebadilishwa kuwa 'https' ili ifanane na GOOGLE_REDIRECT_URI ---
define('BASE_URL', 'https://app.chatme.co.tz/');
// -------------------------------------------------------------------------

// Timezone
date_default_timezone_set('Africa/Nairobi');

// --- DATABASE CONSTANTS ADDED ---
// Hizi zinahitajika na AuthController.php (line 26)
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'app_chatmedb');
define('DB_PASSWORD', 'chatme2025@');
define('DB_NAME', 'app_chatmedb');
// --------------------------------

// --- ENCRYPTION KEY ADDED ---
// Inahitajika na EncryptionService.php (line 11)
// LAZIMA iwe na urefu wa bytes 32 (herufi 32)
define('ENCRYPTION_KEY', 'ThisIsASecretKeyForEncryption123');
// --------------------------------

// --- GOOGLE OAUTH CONSTANTS ---
// Inahitajika na AuthController.php
// Taarifa zako halisi zimejazwa hapa chini
define('GOOGLE_CLIENT_ID', '896289196300-2moq9edqbm1m3efrk8cdj9d1prf4ldmu.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');

// Hakikisha hii URL inafanana KABISA (herufi kwa herufi) na ile uliyoweka kwenye Google Cloud Console
// Kwenye "Authorized redirect URIs"
define('GOOGLE_REDIRECT_URI', 'https://app.chatme.co.tz/api/modules/youtube_ads/controllers/AuthController.php');
// --------------------------------

// --- FACEBOOK WHATSAPP OAUTH CONSTANTS ---
// Inahitajika na FacebookOauthController.php
// Muhimu: Badilisha 'YOUR_FACEBOOK_APP_ID' na 'YOUR_FACEBOOK_APP_SECRET' na credentials zako halisi.
define('FACEBOOK_APP_ID', '1258543722970850');
define('FACEBOOK_APP_SECRET', 'd9319c0e290c1939fc6bee01e037bc3a');
define('FACEBOOK_CONFIG_ID', '1977097963067331');
// -----------------------------------------

// --- DEFAULT SMTP FALLBACK SETTINGS ---
// These are used if a tenant has not configured their own SMTP server.
define('DEFAULT_SMTP_HOST', 'localhost');
define('DEFAULT_SMTP_PORT', 25); // Using SSL port
define('DEFAULT_SMTP_USERNAME', '');
define('DEFAULT_SMTP_PASSWORD', '');
define('DEFAULT_SMTP_SECURE', ''); // PHPMailer::ENCRYPTION_SMTPS = 'ssl'
define('DEFAULT_FROM_EMAIL', 'noreply@chatme.co.tz');
define('DEFAULT_FROM_NAME', 'ChatMe Platform');
// ------------------------------------

?>