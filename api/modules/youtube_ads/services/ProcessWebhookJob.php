<?php

namespace Modules\YouTubeAds\Jobs;

class ProcessWebhookJob {
    private $payload;

    public function __construct($payload) {
        $this->payload = $payload;
    }

    public function handle() {
        // Parse the XML payload from PubSubHubbub
        $xml = simplexml_load_string($this->payload, "SimpleXMLElement", LIBXML_NOCDATA);
        if ($xml === false) {
            file_put_contents(__DIR__ . '/../../../../webhook_log.txt', "Failed to parse XML\n", FILE_APPEND);
            return;
        }

        $entry = $xml->entry;
        if (!$entry) {
            return; // Not a video update notification
        }
        
        $videoId = (string) $entry->{'yt:videoId'};

        // For now, we'll just log the video ID to a file.
        // In a real application, you would trigger an analytics refresh for this video.
        $logMessage = "Webhook received for video ID: {$videoId} at " . date('Y-m-d H:i:s') . "\n";
        file_put_contents(__DIR__ . '/../../../../webhook_log.txt', $logMessage, FILE_APPEND);
    }
}
