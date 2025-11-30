<?php
// api/helpers/PermissionHelper.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if a user has a specific permission.
 *
 * @param int $userId The ID of the user.
 * @param string $permissionSlug The slug of the permission to check (e.g., 'view_invoices').
 * @return boolean True if allowed, False otherwise.
 */
function hasPermission($userId, $permissionSlug) {
    global $pdo;

    // 1. Super Admin Bypass (Optional, if we want Super Admins to log in as users)
    // For now, we focus on Tenant RBAC.

    // 2. Fetch User's Role ID
    // We try to get it from session first to save a DB call, if available.
    // However, for robustness, we query the DB or use a cached session variable.
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $userId && isset($_SESSION['role_id'])) {
        $roleId = $_SESSION['role_id'];
    } else {
        $stmt = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $roleId = $stmt->fetchColumn();
    }

    if (!$roleId) {
        return false; // No role assigned
    }

    // 3. Check Permission
    // Query: Does this Role have this Permission?
    $sql = "SELECT COUNT(*) FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ? AND p.slug = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$roleId, $permissionSlug]);

    return $stmt->fetchColumn() > 0;
}

/**
 * Get the current Tenant ID from the session.
 *
 * @return int The Tenant ID.
 */
function getCurrentTenantId() {
    if (isset($_SESSION['tenant_id'])) {
        return (int)$_SESSION['tenant_id'];
    }
    // Fallback for migration or errors: return 1 (Default Tenant)
    return 1;
}

/**
 * Fetch settings for a specific tenant.
 *
 * @param int $tenantId
 * @return array
 */
function getTenantSettings($tenantId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM settings WHERE tenant_id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
?>
