<?php

// api/modules/youtube_ads/jobs/process_ads.php
// Hii ni Cron Job ya kusimamia mchakato wa ku-upload video za matangazo (ads)

// Weka error reporting kwa ajili ya debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ingiza faili muhimu
// Tumia __DIR__ kuhakikisha njia (paths) ni sahihi
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../mailer_config.php';
require_once __DIR__ . '/../models/Ad.php';
require_once __DIR__ . '/../models/Advertiser.php';
require_once __DIR__ . '/../models/YoutubeToken.php';
require_once __DIR__ . '/../services/EncryptionService.php';

use Modules\YouTubeAds\Models\Ad;
use Modules\YouTubeAds\Models\Advertiser;
use Modules\YouTubeAds\Models\YoutubeToken;
use Modules\YouTubeAds\Services\EncryptionService;


// Weka lock file kuzuia script isijikimbize mara mbili kwa wakati mmoja
$lockFile = __DIR__ . '/process_ads.lock';
$lockHandle = fopen($lockFile, 'w');

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Process is already running. Exiting.\n";
    exit;
}

// Andaa models
$adModel = new Ad($pdo);
$advertiserModel = new Advertiser($pdo);
$youtubeTokenModel = new YoutubeToken($pdo, new EncryptionService());

// 1. Pata tangazo moja tu la kushughulikia
// Tunatumia 'LIMIT 1' kuhakikisha tunachukua moja kwa wakati
$stmt = $pdo->prepare("SELECT * FROM ads WHERE status = 'Processing' AND payment_status = 'Paid' ORDER BY created_at ASC LIMIT 1");
$stmt->execute();
$ad = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$ad) {
    echo "No ads to process. Exiting.\n";
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    unlink($lockFile);
    exit;
}

echo "Processing Ad ID: {$ad['id']}...\n";

// Weka variables muhimu
$adId = $ad['id'];
$tenantId = $ad['tenant_id'];
$videoFilePath = __DIR__ . '/../../../../uploads/ads/' . $ad['file_path']; // Njia kamili ya faili

// --- Mchakato wa YouTube Upload ---
try {
    // 2. Pata Google Client iliyothibitishwa (authenticated)
    $tokenData = $youtubeTokenModel->getTokens($tenantId);
    if (!$tokenData) {
        throw new Exception("YouTube token not found for tenant ID: $tenantId");
    }

    $client = new Google_Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setAccessToken($tokenData);

    // Refresh token kama muda wake umekwisha
    if ($client->isAccessTokenExpired()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        $newAccessToken = $client->getAccessToken();
        
        // Pata refresh token ya zamani kama mpya haipo
        $oldRefreshToken = $client->getRefreshToken();
        $newRefreshToken = $newAccessToken['refresh_token'] ?? $oldRefreshToken;

        $expires_at = (new \DateTime())->add(new \DateInterval('PT' . $newAccessToken['expires_in'] . 'S'));
        
        $youtubeTokenModel->saveTokens(
            $tenantId,
            $newAccessToken['access_token'],
            $newRefreshToken,
            $expires_at
        );
        echo "Token refreshed successfully for tenant ID: $tenantId\n";
    }
    
    // 3. Anzisha YouTube Service
    $youtube = new Google_Service_YouTube($client);

    // 4. Andaa video metadata
    $video = new Google_Service_YouTube_Video();
    $videoSnippet = new Google_Service_YouTube_VideoSnippet();
    $videoSnippet->setTitle($ad['title']);
    $videoSnippet->setDescription("This is an advertisement campaign: " . $ad['title']);
    // (Unaweza kuongeza tags, categoryId, etc. hapa)
    $video->setSnippet($videoSnippet);

    $videoStatus = new Google_Service_YouTube_VideoStatus();
    $videoStatus->setPrivacyStatus('unlisted'); // Weka 'unlisted' au 'private'
    $video->setStatus($videoStatus);

    // 5. Anzisha Resumable Upload
    $chunkSizeBytes = 1 * 1024 * 1024; // 1MB per chunk
    $client->setDefer(true); // Muhimu sana kwa resumable upload

    $insertRequest = $youtube->videos->insert("status,snippet", $video);

    $media = new Google_Http_MediaFileUpload(
        $client,
        $insertRequest,
        'video/*',
        null,
        true,
        $chunkSizeBytes
    );
    $media->setFileSize(filesize($videoFilePath));

    // Upload faili
    $status = false;
    $handle = fopen($videoFilePath, "rb");
    while (!$status && !feof($handle)) {
        $chunk = fread($handle, $chunkSizeBytes);
        $status = $media->nextChunk($chunk);
    }
    fclose($handle);

    $client->setDefer(false); // Rudisha client kwenye hali ya kawaida

    if ($status === false) {
        throw new Exception("YouTube upload failed for unknown reasons.");
    }

    $youtubeVideoId = $status['id'];
    echo "Upload successful! YouTube Video ID: $youtubeVideoId\n";

    // 6. Update status kwenye database kuwa 'Active'
    $stmt_update = $pdo->prepare("UPDATE ads SET status = 'Active', youtube_video_id = ?, status_message = NULL WHERE id = ?");
    $stmt_update->execute([$youtubeVideoId, $adId]);

    // --- Tuma Email ya "Active" ---
    $advertiser = $advertiserModel->findById($ad['advertiser_id']);
    if ($advertiser) {
        $mail = getMailerInstance($pdo); // Tumia $pdo kutoka db.php
        $mail->addAddress($advertiser['email'], $advertiser['name']);
        $mail->isHTML(true);
        $mail->Subject = 'Your Campaign is Now Active: ' . $ad['title'];
        
        $startDateFormatted = (new \DateTime())->format('F j, Y'); // Siku ya leo
        $endDateFormatted = (new \DateTime($ad['end_date']))->format('F j, Y');

        $mail->Body = "
            <p>Hello {$advertiser['name']},</p>
            <p>Great news! Your campaign '<strong>{$ad['title']}</strong>' has completed processing and is now <strong>Active</strong> on YouTube.</p>
            <p>It has officially started today, <strong>{$startDateFormatted}</strong>, and will run until <strong>{$endDateFormatted}</strong>.</p>
            <p>You can view your ad here: <a href='https://www.youtube.com/watch?v={$youtubeVideoId}'>https://www.youtube.com/watch?v={$youtubeVideoId}</a></p>
            <p>You will now start receiving daily performance reports to this email address.</p>
            <p>Thank you for your business!</p>
            <p>Best regards,<br>The Support Team</p>
        ";
        $mail->send();
        echo "Activation email sent to {$advertiser['email']}\n";
    }

} catch (Exception $e) {
    // Ikiwa kuna kosa, hifadhi ujumbe na weka status 'Failed'
    $errorMessage = $e->getMessage();
    echo "An error occurred: $errorMessage\n";

    $stmt_fail = $pdo->prepare("UPDATE ads SET status = 'Failed', status_message = ? WHERE id = ?");
    $stmt_fail->execute([$errorMessage, $adId]);
} finally {
    // Ondoa lock file
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    unlink($lockFile);
    echo "Process finished for Ad ID: $adId.\n";
}

?>