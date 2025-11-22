<?php

namespace Modules\YouTubeAds\Services;

class AdInsertionService {
    
    public function insertAd($originalVideoId, $adPath, $placement, $youtubeService) {
        if (getenv('USE_FFMPEG') === 'true') {
            return $this->insertAdWithFFmpeg($originalVideoId, $adPath, $placement);
        } else {
            return $this->insertAdWithEditorApi($originalVideoId, $adPath, $placement, $youtubeService);
        }
    }

    private function insertAdWithEditorApi($originalVideoId, $adPath, $placement, $youtubeService) {
        // This is a placeholder for the YouTube Editor API implementation.
        // The Editor API is more complex and may require additional permissions.
        // For now, we will simulate a successful response.
        return 'editor_api_' . uniqid();
    }

    private function insertAdWithFFmpeg($originalVideoId, $adPath, $placement) {
        $downloadService = new VideoDownloadService();
        $originalVideoPath = '/tmp/' . $originalVideoId . '.mp4';
        
        if (!$downloadService->downloadVideo($originalVideoId, $originalVideoPath)) {
            error_log("AdInsertionService: Failed to download original video.");
            return null;
        }

        if (!file_exists($adPath)) {
            error_log("AdInsertionService: Missing ad file.");
            unlink($originalVideoPath);
            return null;
        }

        $mergedVideoPath = '/tmp/' . uniqid() . '.mp4';
        $ffmpegPath = getenv('FFMPEG_PATH') ?: 'ffmpeg';

        $fileListName = '/tmp/ffmpeg_list_' . uniqid() . '.txt';
        
        if ($placement === 'intro') {
            $fileListContent = "file '" . realpath($adPath) . "'\n";
            $fileListContent .= "file '" . realpath($originalVideoPath) . "'\n";
        } else {
            $fileListContent = "file '" . realpath($originalVideoPath) . "'\n";
            $fileListContent .= "file '" . realpath($adPath) . "'\n";
        }

        file_put_contents($fileListName, $fileListContent);

        $command = "{$ffmpegPath} -f concat -safe 0 -i {$fileListName} -c copy " . escapeshellarg($mergedVideoPath);

        $output = null;
        $return_var = null;
        exec($command, $output, $return_var);

        unlink($fileListName);
        unlink($originalVideoPath);
        
        if ($return_var !== 0) {
            error_log("AdInsertionService: FFmpeg command failed. Return code: $return_var");
            return null;
        }

        return $mergedVideoPath;
    }
}
