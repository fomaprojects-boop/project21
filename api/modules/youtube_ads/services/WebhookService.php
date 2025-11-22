<?php

namespace Modules\YouTubeAds\Services;

require_once __DIR__ . '/../jobs/ProcessWebhookJob.php';
use Modules\YouTubeAds\Jobs\ProcessWebhookJob;

class WebhookService {
    
    public function verifySignature($payload, $signature, $secret) {
        // In a real application, you'd implement a more secure verification.
        // For PubSubHubbub, the signature is a SHA1 hash of the payload.
        if (empty($secret)) {
            return true; // No secret configured, assume valid
        }
        $expectedSignature = 'sha1=' . hash_hmac('sha1', $payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }

    public function processNotification($payload) {
        // Dispatch a job to process the notification asynchronously.
        // This prevents blocking the webhook response.
        $job = new ProcessWebhookJob($payload);
        
        // In a real application, you might use a proper queueing system.
        // For this example, we'll execute it synchronously for simplicity.
        $job->handle();
    }
}
