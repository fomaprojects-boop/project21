<?php

namespace Modules\YouTubeAds\Services;

class VideoMergeService {
    private $ffmpegPath;

    public function __construct() {
        $this->ffmpegPath = getenv('FFMPEG_PATH') ?: 'ffmpeg';
    }

    public function isFfmpegAvailable() {
        exec($this->ffmpegPath . ' -version', $output, $returnCode);
        return $returnCode === 0;
    }

    public function merge($adPath, $videoPath, $outputPath, $placement = 'intro') {
        if (!$this->isFfmpegAvailable()) {
            return false;
        }

        if ($placement === 'intro') {
            $command = "{$this->ffmpegPath} -i 'concat:{$adPath}|{$videoPath}' -c copy {$outputPath}";
        } else {
            $command = "{$this->ffmpegPath} -i 'concat:{$videoPath}|{$adPath}' -c copy {$outputPath}";
        }

        exec($command, $output, $returnCode);
        return $returnCode === 0;
    }
}
