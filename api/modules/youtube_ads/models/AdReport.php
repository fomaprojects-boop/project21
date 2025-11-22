<?php
// api/modules/youtube_ads/models/AdReport.php

namespace Modules\YouTubeAds\Models;

class AdReport {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function createReport($adId, $tenantId, $advertiserId, $reportDate, $analyticsData, $pdfPath) {
        $analyticsJson = json_encode($analyticsData);
        $stmt = $this->db->prepare("
            INSERT INTO ad_reports (ad_id, tenant_id, advertiser_id, report_date, analytics_data, pdf_path)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$adId, $tenantId, $advertiserId, $reportDate, $analyticsJson, $pdfPath]);
    }

    public function getByTenant($tenantId, $page = 1, $limit = 3) {
        $offset = ($page - 1) * $limit;

        // Get total count
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM ad_reports WHERE tenant_id = ?");
        $totalStmt->execute([$tenantId]);
        $total = $totalStmt->fetchColumn();

        // Get paginated data
        $stmt = $this->db->prepare("
            SELECT 
                ar.id,
                ar.report_date,
                ar.pdf_path,
                ar.created_at AS generated_at,
                a.title AS ad_title,
                adv.name AS advertiser_name
            FROM ad_reports ar
            JOIN ads a ON ar.ad_id = a.id
            JOIN advertisers adv ON ar.advertiser_id = adv.id
            WHERE ar.tenant_id = ?
            ORDER BY ar.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        // Use bindValue for LIMIT/OFFSET to ensure they are treated as integers
        $stmt->bindValue(1, $tenantId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ['data' => $data, 'total' => (int)$total];
    }
}