<?php

namespace Modules\YouTubeAds\Models;

class YoutubeChannel {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function saveChannelInfo($channelId, $tenantId, $channelName, $thumbnailUrl, $accessToken = null, $refreshToken = null, $userId = null) {
        // Multi-channel logic:
        // We check if this specific channel (by channelId) exists for this tenant.
        // If so, update. If not, insert.

        $sql = "
            INSERT INTO youtube_channels (channel_id, tenant_id, channel_name, thumbnail_url, access_token, refresh_token, added_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                channel_name = VALUES(channel_name), 
                thumbnail_url = VALUES(thumbnail_url),
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token)
        ";
        // Note: We need a UNIQUE constraint on (tenant_id, channel_id) for ON DUPLICATE KEY to work correctly per channel per tenant
        // or just on `channel_id` if a channel can only belong to one tenant globally (logical).
        // Let's assume global uniqueness of channel_id is fine.

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$channelId, $tenantId, $channelName, $thumbnailUrl, $accessToken, $refreshToken, $userId]);
    }

    public function getChannelByTenantId($tenantId) {
        // Backward compatibility: Get the first channel
        $stmt = $this->db->prepare("SELECT * FROM youtube_channels WHERE tenant_id = ? LIMIT 1");
        $stmt->execute([$tenantId]);
        return $stmt->fetch();
    }

    public function getAllChannelsByTenantId($tenantId) {
        $stmt = $this->db->prepare("SELECT * FROM youtube_channels WHERE tenant_id = ? ORDER BY created_at DESC");
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    public function getChannelById($id, $tenantId) {
        // Securely fetch by ID ensuring tenant ownership
        $stmt = $this->db->prepare("SELECT * FROM youtube_channels WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenantId]);
        return $stmt->fetch();
    }
}
