<?php

namespace Modules\YouTubeAds\Models;

class AdVideoMap {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function create($adId, $tenantId, $videoId) {
        $stmt = $this->db->prepare("
            INSERT INTO ad_video_map (ad_id, tenant_id, video_id) 
            VALUES (?, ?, ?)
        ");
        return $stmt->execute([$adId, $tenantId, $videoId]);
    }

    public function findByAd($adId) {
        $stmt = $this->db->prepare("SELECT * FROM ad_video_map WHERE ad_id = ?");
        $stmt->execute([$adId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function updateStatus($id, $inserted, $newVideoId = null) {
        $stmt = $this->db->prepare("UPDATE ad_video_map SET inserted = ?, inserted_at = NOW(), new_video_id = ? WHERE id = ?");
        return $stmt->execute([$inserted, $newVideoId, $id]);
    }

    public function getVideosByAdId($adId, $page = 1, $limit = 5) {
        $offset = ($page - 1) * $limit;

        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM ad_video_map WHERE ad_id = ?");
        $totalStmt->execute([$adId]);
        $total = $totalStmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT * FROM ad_video_map WHERE ad_id = ? ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $adId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ['data' => $data, 'total' => (int)$total];
    }
}