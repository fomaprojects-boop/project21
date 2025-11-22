<?php

namespace Modules\YouTubeAds\Models;

class Ad {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function create($tenantId, $advertiserId, $invoiceId, $title, $filePath, $placement, $campaignType, $duration, $startDate, $endDate, $price, $status, $paymentStatus) {
        $stmt = $this->db->prepare("
            INSERT INTO ads (tenant_id, advertiser_id, invoice_id, title, file_path, placement, campaign_type, duration_seconds, start_date, end_date, price, status, payment_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tenantId, $advertiserId, $invoiceId, $title, $filePath, $placement, $campaignType, $duration, $startDate, $endDate, $price, $status, $paymentStatus]);
        return $this->db->lastInsertId();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("SELECT * FROM ads WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    public function findAdsToInsert() {
        $query = "SELECT * FROM ads WHERE status = 'approved' AND payment_status = 'paid' AND start_date <= CURDATE() AND end_date >= CURDATE()";
        $stmt = $this->db->query($query);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function findAdsToRemove() {
        $query = "SELECT * FROM ads WHERE status = 'active' AND (payment_status != 'paid' OR end_date < CURDATE())";
        $stmt = $this->db->query($query);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function findActiveAds() {
        $query = "SELECT * FROM ads WHERE status = 'active'";
        $stmt = $this->db->query($query);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findQueuedForUpload() {
        // This method is now used by the scheduler to find paid ads ready for upload.
        $query = "SELECT * FROM ads WHERE status = 'Processing' AND payment_status = 'Paid'";
        $stmt = $this->db->query($query);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function updateStatus($id, $status) {
        $stmt = $this->db->prepare("UPDATE ads SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    public function updateStatusAfterPayment($invoiceId) {
        $this->db->beginTransaction();
        try {
            // Find the ad associated with the invoice
            $stmt = $this->db->prepare("SELECT id, status FROM ads WHERE invoice_id = ?");
            $stmt->execute([$invoiceId]);
            $ad = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($ad) {
                if ($ad['status'] == 'Pending Payment') {
                    // Manual ads become active immediately after payment
                    $updateStmt = $this->db->prepare("UPDATE ads SET status = 'active', payment_status = 'Paid' WHERE id = ?");
                    $updateStmt->execute([$ad['id']]);
                } elseif ($ad['status'] == 'Queued for Upload') {
                    // Dedicated ads move to 'Processing' state for the scheduler to pick them up
                    $updateStmt = $this->db->prepare("UPDATE ads SET status = 'Processing', payment_status = 'Paid' WHERE id = ?");
                    $updateStmt->execute([$ad['id']]);
                }
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            // Optionally log the error
            error_log("Failed to update ad status after payment for invoice ID {$invoiceId}: " . $e->getMessage());
        }
    }

    public function getAdVideoMaps($adId) {
        $stmt = $this->db->prepare("SELECT * FROM ad_video_map WHERE ad_id = ?");
        $stmt->execute([$adId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getPendingCampaignsByTenant($tenantId, $page = 1, $limit = 3) {
        $offset = ($page - 1) * $limit;

        $totalStmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM ads a
            WHERE a.tenant_id = ? AND a.status IN ('Queued for Upload', 'Pending Payment', 'Processing')
        ");
        $totalStmt->execute([$tenantId]);
        $total = $totalStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT a.id, a.title, a.status, adv.name as advertiser_name, i.status as invoice_status, i.pdf_url as invoice_pdf_url
            FROM ads a
            JOIN advertisers adv ON a.advertiser_id = adv.id
            LEFT JOIN invoices i ON a.invoice_id = i.id
            WHERE a.tenant_id = ? AND a.status IN ('Queued for Upload', 'Pending Payment', 'Processing')
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $tenantId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return ['data' => $data, 'total' => (int)$total];
    }

    public function getActiveCampaignsByTenant($tenantId, $page = 1, $limit = 3) {
        $offset = ($page - 1) * $limit;

        $totalStmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM ads a
            WHERE a.tenant_id = ? AND a.status = 'active'
        ");
        $totalStmt->execute([$tenantId]);
        $total = $totalStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT a.id, a.title, a.start_date, a.end_date, a.campaign_type, a.file_path, a.youtube_video_id, adv.name as advertiser_name, i.pdf_url as invoice_pdf_url
            FROM ads a
            JOIN advertisers adv ON a.advertiser_id = adv.id
            LEFT JOIN invoices i ON a.invoice_id = i.id
            WHERE a.tenant_id = ? AND a.status = 'active'
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $tenantId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ['data' => $data, 'total' => (int)$total];
    }

    public function getAllActiveCampaigns() {
        $stmt = $this->db->prepare("
            SELECT id, tenant_id, advertiser_id, youtube_video_id
            FROM ads
            WHERE status = 'active' AND youtube_video_id IS NOT NULL
        ");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getActiveCampaignsByType($campaignType) {
        $stmt = $this->db->prepare("
            SELECT *
            FROM ads
            WHERE status = 'active' AND campaign_type = ?
        ");
        $stmt->execute([$campaignType]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}