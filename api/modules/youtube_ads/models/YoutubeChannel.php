<?php

namespace Modules\YouTubeAds\Models;

class YoutubeChannel {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function saveChannelInfo($channelId, $tenantId, $channelName, $thumbnailUrl) {
        $sql = "
            INSERT INTO youtube_channels (id, tenant_id, channel_name, thumbnail_url) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                tenant_id = VALUES(tenant_id), 
                channel_name = VALUES(channel_name), 
                thumbnail_url = VALUES(thumbnail_url)
        ";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$channelId, $tenantId, $channelName, $thumbnailUrl]);
    }

    public function getChannelByTenantId($tenantId) {
        $stmt = $this->db->prepare("SELECT * FROM youtube_channels WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        return $stmt->fetch();
    }
}
