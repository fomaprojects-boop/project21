<?php

namespace Modules\YouTubeAds\Models;

use Modules\YouTubeAds\Services\EncryptionService;

class YoutubeToken {
    private $db;
    private $encryptionService;

    public function __construct($db, EncryptionService $encryptionService) {
        $this->db = $db;
        $this->encryptionService = $encryptionService;
    }

    /**
     * Hifadhi au update tokens za user/tenant.
     */
    public function saveTokens($tenantId, $accessToken, $refreshToken, $expiresAt) {
        $encryptedAccessToken = $this->encryptionService->encrypt($accessToken);
        $expiresAtFormatted = $expiresAt->format('Y-m-d H:i:s');

        // Angalia kwanza kama user/tenant tayari anayo row
        $existing = $this->getTokens($tenantId, true); // true = pata hata kama zimekuwa encrypted

        if ($existing) {
            // --- USER ANAYO ROW TAYARI (HII NI TOKEN REFRESH) ---
            
            if ($refreshToken) {
                // Hii inatokea kama user ame-reconnect (ame-force consent)
                $encryptedRefreshToken = $this->encryptionService->encrypt($refreshToken);
                $sql = "UPDATE youtube_tokens SET access_token = ?, refresh_token = ?, token_expires_at = ? WHERE tenant_id = ?";
                $stmt = $this->db->prepare($sql);
                return $stmt->execute([$encryptedAccessToken, $encryptedRefreshToken, $expiresAtFormatted, $tenantId]);
            } else {
                // Hii ni automatic refresh (common case)
                // Tunahifadhi access token mpya, lakini refresh token ya zamani inabaki
                $sql = "UPDATE youtube_tokens SET access_token = ?, token_expires_at = ? WHERE tenant_id = ?";
                $stmt = $this->db->prepare($sql);
                return $stmt->execute([$encryptedAccessToken, $expiresAtFormatted, $tenantId]);
            }
        } else {
            // --- USER MPYA KABISA (HII NI FIRST CONNECT) ---
            $encryptedRefreshToken = $refreshToken ? $this->encryptionService->encrypt($refreshToken) : null;
            
            $sql = "INSERT INTO youtube_tokens (tenant_id, access_token, refresh_token, token_expires_at) 
                    VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$tenantId, $encryptedAccessToken, $encryptedRefreshToken, $expiresAtFormatted]);
        }
    }

    /**
     * Inapata tokens za user/tenant kutoka database
     * $raw = true inarudisha data bila ku-decrypt (kwa ajili ya saveTokens check)
     */
    public function getTokens($tenantId, $raw = false) {
        // Tunachukua ile ya mwisho, kwa uhakika
        $stmt = $this->db->prepare("SELECT access_token, refresh_token, token_expires_at FROM youtube_tokens WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$tenantId]);
        $tokens = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($tokens) {
            if ($raw) {
                return $tokens; // Inarudisha data kama ilivyo (encrypted)
            }
            
            // MUHIMU: Decrypt tokens kabla ya kuzirudisha
            $tokens['access_token'] = $this->encryptionService->decrypt($tokens['access_token']);
            $tokens['refresh_token'] = $tokens['refresh_token'] ? $this->encryptionService->decrypt($tokens['refresh_token']) : null;
            
            // Check kama decrypt ilishindikana (k.m. key imebadilika)
            if ($tokens['access_token'] === false) {
                error_log("EncryptionService: Failed to decrypt token for tenant " . $tenantId . ". Check ENCRYPTION_KEY.");
                return null;
            }
            
            return $tokens;
        }
        return null;
    }
}
