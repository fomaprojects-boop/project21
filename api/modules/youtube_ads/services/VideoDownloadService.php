<?php

namespace Modules\YouTubeAds\Services;

require_once __DIR__ . '/../../../vendor/autoload.php';

use YouTube\YouTubeDownloader;

class VideoDownloadService {
    public function downloadVideo($videoId, $downloadPath) {
        $yt = new YouTubeDownloader();
        try {
            $links = $yt->getDownloadLinks("https://www.youtube.com/watch?v=$videoId");
            $videoUrl = $links[0]['url'];
            
            $fileContents = file_get_contents($videoUrl);
            if ($fileContents === false) {
                return false;
            }
            
            file_put_contents($downloadPath, $fileContents);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
