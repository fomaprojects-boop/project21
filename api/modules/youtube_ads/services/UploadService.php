<?php

namespace Modules\YouTubeAds\Services;

use Google_Client;
use Google_Service_YouTube;

class UploadService {
    private $client;
    private $youtube;

    public function __construct(Google_Client $client) {
        $this->client = $client;
        $this->youtube = new Google_Service_YouTube($this->client);
    }

    public function upload($filePath, $title, $description, $tags, $privacyStatus = 'unlisted') {
        if (!file_exists($filePath)) {
            throw new \Exception("Video file not found: $filePath");
        }

        $video = new \Google_Service_YouTube_Video();
        $videoSnippet = new \Google_Service_YouTube_VideoSnippet();
        $videoSnippet->setTitle($title);
        $videoSnippet->setDescription($description);
        $videoSnippet->setTags($tags);
        $video->setSnippet($videoSnippet);

        $videoStatus = new \Google_Service_YouTube_VideoStatus();
        $videoStatus->setPrivacyStatus($privacyStatus);
        $video->setStatus($videoStatus);

        $chunkSizeBytes = 1 * 1024 * 1024;
        $this->client->setDefer(true);

        $insertRequest = $this->youtube->videos->insert('status,snippet', $video);

        $media = new \Google_Http_MediaFileUpload(
            $this->client,
            $insertRequest,
            'video/*',
            null,
            true,
            $chunkSizeBytes
        );
        
        $fileSize = filesize($filePath);
        $media->setFileSize($fileSize);

        $status = false;
        $handle = fopen($filePath, "rb");
        if ($handle === false) {
             throw new \Exception("Failed to open file: $filePath");
        }

        try {
            while (!$status && !feof($handle)) {
                $chunk = fread($handle, $chunkSizeBytes);
                $status = $media->nextChunk($chunk);
            }
        } finally {
            fclose($handle);
        }

        $this->client->setDefer(false);
        
        if ($status && isset($status['id'])) {
            return $status['id'];
        }
        
        return false;
    }
}
