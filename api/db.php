<?php
$host = 'localhost';
$dbname = 'app_chatmedb';
$user = 'app_chatmedb';
$password = 'chatme2025@';

try {
    // Anzisha muunganisho kwa kutumia PDO (njia ya kisasa na salama)
    // Updated to utf8mb4 to support Emojis in WhatsApp messages
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password);

    // Weka PDO itoe errors kama zikitokea
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Weka timezone iwe East Africa Time (+3:00)
    $pdo->exec("SET time_zone = '+03:00'");

    // --- Schema Auto-Fix: Ensure whatsapp_status column exists ---
    $schema_lock = __DIR__ . '/schema_whatsapp_status.lock';
    if (!file_exists($schema_lock)) {
        try {
            // Add column if not exists
            $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_status ENUM('Pending', 'Connected', 'Disconnected') DEFAULT 'Pending'");
            // Mark as done on success
            file_put_contents($schema_lock, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            // Check for duplicate column error (1060) or similar
            // If it exists, we can also mark as done.
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock, date('Y-m-d H:i:s'));
            } else {
                // Log other errors but continue (don't crash app, though feature might fail)
                // Do NOT create lock file so it retries next time
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Ensure provider_message_id exists for Status Ticks ---
    $schema_lock_ticks = __DIR__ . '/schema_provider_msg_id.lock';
    if (!file_exists($schema_lock_ticks)) {
        try {
            // Add column if not exists
            // Using VARCHAR(255) for Meta WAMID and INDEX for fast lookups during webhook updates
            $pdo->exec("ALTER TABLE messages ADD COLUMN provider_message_id VARCHAR(255) DEFAULT NULL, ADD INDEX (provider_message_id)");
            file_put_contents($schema_lock_ticks, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1060) { // Duplicate column
                file_put_contents($schema_lock_ticks, date('Y-m-d H:i:s'));
            } else {
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - MsgID Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }
    // -----------------------------------------------------------

    // --- Schema Auto-Fix: Ensure user_id column exists in templates ---
    $schema_lock_template_user = __DIR__ . '/schema_template_userid.lock';
    if (!file_exists($schema_lock_template_user)) {
        try {
            // Add user_id column and an index for performance
            $pdo->exec("ALTER TABLE message_templates ADD COLUMN user_id INT NULL, ADD INDEX (user_id)");
            // Mark as done on success
            file_put_contents($schema_lock_template_user, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            // If column already exists (error 1060), mark as done
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_template_user, date('Y-m-d H:i:s'));
            } else {
                // Log other errors but allow the app to continue
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Template UserID Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Ensure closed_by and closed_at columns exist in conversations ---
    $schema_lock_closed_cols = __DIR__ . '/schema_conversation_closed_cols.lock';
    if (!file_exists($schema_lock_closed_cols)) {
        try {
            // Add columns if not exist
            $pdo->exec("ALTER TABLE conversations ADD COLUMN closed_by INT NULL, ADD COLUMN closed_at DATETIME NULL");
            // Mark as done on success
            file_put_contents($schema_lock_closed_cols, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            // If column already exists (error 1060), mark as done
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_closed_cols, date('Y-m-d H:i:s'));
            } else {
                // Log other errors but allow the app to continue
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Conversation Closed Columns Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Ensure is_active column exists in workflows ---
    $schema_lock_workflow_active = __DIR__ . '/schema_workflow_active.lock';
    if (!file_exists($schema_lock_workflow_active)) {
        try {
            // Add column if not exists (Default 0 = Draft)
            $pdo->exec("ALTER TABLE workflows ADD COLUMN is_active TINYINT(1) DEFAULT 0");
            file_put_contents($schema_lock_workflow_active, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_workflow_active, date('Y-m-d H:i:s'));
            } else {
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Workflow Active Column Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

} catch (PDOException $e) {
    // Ikishindikana, toa ujumbe wa kosa katika format ya JSON
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    // Sitisha utekelezaji
    die();
}
?>
