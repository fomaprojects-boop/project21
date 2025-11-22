<?php

namespace Modules\YouTubeAds\Models;

class Advertiser {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function create($tenantId, $name, $email, $phone, $tin, $vrn, $address, $verificationCode, $codeExpiresAt) {
        $stmt = $this->db->prepare("
            INSERT INTO advertisers (tenant_id, name, email, contact_phone, tin, vrn, address, verification_code, code_expires_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$tenantId, $name, $email, $phone, $tin, $vrn, $address, $verificationCode, $codeExpiresAt]);
    }

    public function findById($id) {
        $stmt = $this->db->prepare("SELECT * FROM advertisers WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function findByTenant($tenantId, $page = 1, $limit = 5) {
        $offset = ($page - 1) * $limit;
        
        $stmt = $this->db->prepare("SELECT * FROM advertisers WHERE tenant_id = ? LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $tenantId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $advertisers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $totalStmt = $this->db->prepare("SELECT COUNT(*) as total FROM advertisers WHERE tenant_id = ?");
        $totalStmt->execute([$tenantId]);
        $total = $totalStmt->fetchColumn();

        return [
            'advertisers' => $advertisers,
            'total' => (int)$total,
            'page' => (int)$page,
            'limit' => (int)$limit
        ];
    }

    public function verifyEmail($email, $code, $tenantId) {
        $stmt = $this->db->prepare("
            UPDATE advertisers 
            SET email_verified = 1
            WHERE email = ? AND verification_code = ? AND tenant_id = ? AND code_expires_at > NOW()
        ");
        $stmt->execute([$email, $code, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    public function getVerifiedByTenant($tenantId) {
        $stmt = $this->db->prepare("SELECT id, name FROM advertisers WHERE tenant_id = ? AND email_verified = 1");
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
