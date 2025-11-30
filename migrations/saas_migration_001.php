<?php
// migrations/saas_migration_001.php

// Prevent multiple executions
$lockFile = __DIR__ . '/saas_migration_001.lock';
if (file_exists($lockFile)) {
    return;
}

try {
    // Ensure PDO connection is available
    if (!isset($pdo)) {
        require_once __DIR__ . '/../api/db.php';
    }

    $pdo->beginTransaction();

    // 1. Create SaaS Tables
    // ---------------------------------------------------------

    // Tenants
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tenants` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `business_name` varchar(255) NOT NULL,
      `support_pin` varchar(10) NOT NULL UNIQUE,
      `remote_access_enabled` tinyint(1) DEFAULT 0,
      `subscription_status` enum('active','suspended') DEFAULT 'active',
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Super Admins
    $pdo->exec("CREATE TABLE IF NOT EXISTS `super_admins` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `username` varchar(50) NOT NULL UNIQUE,
      `password_hash` varchar(255) NOT NULL,
      `email` varchar(100) NOT NULL UNIQUE,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Roles
    $pdo->exec("CREATE TABLE IF NOT EXISTS `roles` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `tenant_id` int(11) NOT NULL,
      `name` varchar(50) NOT NULL,
      `description` varchar(255) NULL,
      PRIMARY KEY (`id`),
      KEY `idx_roles_tenant` (`tenant_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Permissions
    $pdo->exec("CREATE TABLE IF NOT EXISTS `permissions` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `slug` varchar(50) NOT NULL UNIQUE,
      `name` varchar(100) NOT NULL,
      `category` varchar(50) NOT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Role Permissions
    $pdo->exec("CREATE TABLE IF NOT EXISTS `role_permissions` (
      `role_id` int(11) NOT NULL,
      `permission_id` int(11) NOT NULL,
      PRIMARY KEY (`role_id`, `permission_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


    // 2. Seed Initial Data
    // ---------------------------------------------------------

    // Default Tenant (ID 1)
    // We assume the current business name is in 'settings' id 1, or fallback to 'Default Company'
    $stmt = $pdo->query("SELECT business_name FROM settings WHERE id = 1 LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    $businessName = $settings['business_name'] ?? 'My Company';

    // Generate a random support PIN
    $supportPin = strtoupper(substr(md5(uniqid()), 0, 6));

    $stmt = $pdo->prepare("INSERT IGNORE INTO tenants (id, business_name, support_pin) VALUES (1, ?, ?)");
    $stmt->execute([$businessName, $supportPin]);

    // Seed Permissions
    $permissions = [
        // Dashboard
        ['view_dashboard', 'View Dashboard', 'Dashboard'],

        // Financials
        ['view_financials', 'View Financial Reports', 'Financials'],
        ['manage_invoices', 'Create/Edit/Delete Invoices', 'Financials'],
        ['view_invoices', 'View Invoices', 'Financials'],

        // Expenses
        ['view_own_expenses', 'View Own Expenses', 'Expenses'],
        ['view_all_expenses', 'View All Expenses', 'Expenses'],
        ['approve_expenses', 'Approve Expenses', 'Expenses'],

        // WhatsApp
        ['manage_whatsapp_config', 'Manage Connection', 'WhatsApp'],
        ['manage_whatsapp_templates', 'Manage Templates', 'WhatsApp'],
        ['view_whatsapp_inbox', 'View Inbox', 'WhatsApp'],

        // Team
        ['manage_users', 'Manage Users', 'Team'],
        ['manage_roles', 'Manage Roles & Permissions', 'Team'],

        // System
        ['manage_settings', 'Manage System Settings', 'System'],

        // Print & Design
        ['access_print_design', 'Access Print & Design', 'Print & Design'],
        ['manage_job_orders', 'Manage Job Orders', 'Print & Design'],

        // Workflows
        ['manage_workflows', 'Manage Workflows', 'Workflows']
    ];

    $insertPerm = $pdo->prepare("INSERT IGNORE INTO permissions (slug, name, category) VALUES (?, ?, ?)");
    foreach ($permissions as $perm) {
        $insertPerm->execute($perm);
    }

    // Create Default Roles for Tenant 1
    $roles = [
        'Admin' => 'Full access to all features',
        'Accountant' => 'Access to financials and expenses',
        'Staff' => 'Basic access to chat and tasks'
    ];

    foreach ($roles as $roleName => $desc) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO roles (tenant_id, name, description) VALUES (1, ?, ?)");
        $stmt->execute([$roleName, $desc]);
    }

    // Map Permissions to Roles (Helper function logic inline)
    // 1. Admin: All Permissions
    $adminRoleId = $pdo->query("SELECT id FROM roles WHERE name = 'Admin' AND tenant_id = 1")->fetchColumn();
    if ($adminRoleId) {
        $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT $adminRoleId, id FROM permissions");
    }

    // 2. Accountant: Financials, Expenses, Dashboard
    $accountantRoleId = $pdo->query("SELECT id FROM roles WHERE name = 'Accountant' AND tenant_id = 1")->fetchColumn();
    if ($accountantRoleId) {
        $acctPerms = ['view_dashboard', 'view_financials', 'manage_invoices', 'view_invoices', 'view_all_expenses', 'approve_expenses'];
        $placeholders = implode(',', array_fill(0, count($acctPerms), '?'));
        $stmt = $pdo->prepare("SELECT id FROM permissions WHERE slug IN ($placeholders)");
        $stmt->execute($acctPerms);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $pid) {
            $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ($accountantRoleId, $pid)");
        }
    }

    // 3. Staff: View Inbox, Own Expenses, Print Access
    $staffRoleId = $pdo->query("SELECT id FROM roles WHERE name = 'Staff' AND tenant_id = 1")->fetchColumn();
    if ($staffRoleId) {
        $staffPerms = ['view_dashboard', 'view_whatsapp_inbox', 'view_own_expenses', 'access_print_design'];
        $placeholders = implode(',', array_fill(0, count($staffPerms), '?'));
        $stmt = $pdo->prepare("SELECT id FROM permissions WHERE slug IN ($placeholders)");
        $stmt->execute($staffPerms);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $pid) {
            $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ($staffRoleId, $pid)");
        }
    }


    // 3. Alter Existing Tables (Add tenant_id)
    // ---------------------------------------------------------
    $tablesToUpdate = [
        'settings', 'users', 'contacts', 'conversations', 'messages',
        'invoices', 'direct_expenses', 'payout_requests', 'assets',
        'investments', 'tax_payments', 'payroll_batches', 'message_templates',
        'workflows', 'job_orders' // Added job_orders
    ];

    foreach ($tablesToUpdate as $table) {
        // Check if table exists first (to be safe)
        $checkTable = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($checkTable->rowCount() > 0) {
            // Check if column exists
            $checkCol = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'tenant_id'");
            if ($checkCol->rowCount() == 0) {
                // Add column
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `tenant_id` INT(11) NOT NULL DEFAULT 1");
                $pdo->exec("CREATE INDEX `idx_{$table}_tenant` ON `$table` (`tenant_id`)");
            }
        }
    }

    // Add role_id to users
    $checkRole = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'role_id'");
    if ($checkRole->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `role_id` INT(11) DEFAULT NULL");
        $pdo->exec("CREATE INDEX `idx_users_role` ON `users` (`role_id`)");
    }

    // CRITICAL FIX: Update Settings Table Schema BEFORE Migrating Data
    // Add columns that are moving from `users` table to `settings` table
    $settingColumnsToAdd = [
        'flw_public_key' => 'VARCHAR(255) NULL',
        'flw_secret_key' => 'VARCHAR(255) NULL',
        'flw_encryption_key' => 'VARCHAR(255) NULL',
        'flw_display_name' => 'VARCHAR(255) NULL',
        'flw_test_mode' => 'TINYINT(1) DEFAULT 0',
        'flw_active' => 'TINYINT(1) DEFAULT 0',
        'flw_webhook_secret_hash' => 'VARCHAR(255) NULL',
        'whatsapp_token' => 'TEXT NULL',
        'whatsapp_phone_id' => 'VARCHAR(255) NULL',
        'whatsapp_business_account_id' => 'VARCHAR(255) NULL',
        'tin_number' => 'VARCHAR(50) NULL',
        'vrn_number' => 'VARCHAR(50) NULL',
        'corporate_tax_rate' => 'DECIMAL(5,2) NULL',
        'vfd_enabled' => 'TINYINT(1) DEFAULT 0',
        'vfd_frequency' => 'VARCHAR(50) DEFAULT "monthly"',
        'vfd_is_verified' => 'TINYINT(1) DEFAULT 0',
        'exchange_rate' => 'DECIMAL(10,2) DEFAULT 1.00'
    ];

    foreach ($settingColumnsToAdd as $colName => $colDef) {
        // Check if column exists in settings
        $checkSetCol = $pdo->query("SHOW COLUMNS FROM `settings` LIKE '$colName'");
        if ($checkSetCol->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `settings` ADD COLUMN `$colName` $colDef");
        }
    }


    // 4. Data Migration (Settings & Roles)
    // ---------------------------------------------------------

    // Migrate API Keys from users table to settings
    // Find the main admin user (usually ID 1 or first created)
    $adminUserStmt = $pdo->query("SELECT * FROM users ORDER BY id ASC LIMIT 1");
    $adminUser = $adminUserStmt->fetch(PDO::FETCH_ASSOC);

    if ($adminUser) {
        // Map of users column -> settings column
        // Note: Some might already exist in settings, so we update
        $migrationMap = [
            'flw_public_key' => 'flw_public_key',
            'flw_secret_key' => 'flw_secret_key',
            'flw_encryption_key' => 'flw_encryption_key',
            'flw_display_name' => 'flw_display_name',
            'flw_test_mode' => 'flw_test_mode',
            'flw_active' => 'flw_active',
            'flw_webhook_secret_hash' => 'flw_webhook_secret_hash',
            'whatsapp_access_token' => 'whatsapp_token', // Note name change
            'whatsapp_phone_number_id' => 'whatsapp_phone_id', // Note name change
            'whatsapp_business_account_id' => 'whatsapp_business_account_id',
            'tin_number' => 'tin_number',
            'vrn_number' => 'vrn_number',
            'corporate_tax_rate' => 'corporate_tax_rate',
            'vfd_enabled' => 'vfd_enabled',
            'vfd_frequency' => 'vfd_frequency',
            'vfd_is_verified' => 'vfd_is_verified',
            'exchange_rate' => 'exchange_rate'
        ];

        $updateParts = [];
        $params = [];

        foreach ($migrationMap as $userCol => $settingCol) {
            // Check if column exists in users table
            $chk = $pdo->query("SHOW COLUMNS FROM users LIKE '$userCol'");
            if ($chk->rowCount() > 0) {
                // If value is not empty, prepare update
                if (!empty($adminUser[$userCol])) {
                    $updateParts[] = "$settingCol = ?";
                    $params[] = $adminUser[$userCol];
                }
            }
        }

        if (!empty($updateParts)) {
            $sql = "UPDATE settings SET " . implode(', ', $updateParts) . " WHERE id = 1 AND tenant_id = 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
    }

    // Migrate User Roles (String -> ID)
    // We already created roles for Tenant 1. Now link users.
    $roleMapStmt = $pdo->query("SELECT id, name FROM roles WHERE tenant_id = 1");
    $roleMap = $roleMapStmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['Admin' => 1, 'Staff' => 3]

    foreach ($roleMap as $roleName => $roleId) {
        // Case-insensitive match for old string roles
        $pdo->exec("UPDATE users SET role_id = $roleId WHERE role = '$roleName' AND tenant_id = 1");
    }

    // Fallback for any user without a role_id -> Assign 'Staff'
    if (isset($roleMap['Staff'])) {
        $staffId = $roleMap['Staff'];
        $pdo->exec("UPDATE users SET role_id = $staffId WHERE role_id IS NULL AND tenant_id = 1");
    }


    // 5. Cleanup (Destructive)
    // ---------------------------------------------------------
    // Drop the migrated columns from users table
    $columnsToDrop = [
        'flw_public_key', 'flw_secret_key', 'flw_encryption_key',
        'flw_display_name', 'flw_test_mode', 'flw_active',
        'flw_webhook_secret_hash', 'tin_number', 'vrn_number',
        'corporate_tax_rate', 'vfd_enabled', 'vfd_frequency',
        'vfd_is_verified', 'exchange_rate'
        // Note: keeping whatsapp columns for now as they might be used in session logic elsewhere temporarily
    ];

    foreach ($columnsToDrop as $col) {
        $chk = $pdo->query("SHOW COLUMNS FROM users LIKE '$col'");
        if ($chk->rowCount() > 0) {
            $pdo->exec("ALTER TABLE users DROP COLUMN `$col`");
        }
    }

    $pdo->commit();
    file_put_contents($lockFile, date('Y-m-d H:i:s') . " - Migration Successful");

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    file_put_contents(__DIR__ . '/saas_migration_001_error.log', date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>
