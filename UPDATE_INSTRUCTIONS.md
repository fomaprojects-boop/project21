# Update Instructions & Manual Fixes

## 1. Database Error: Column 'whatsapp_status' not found

You are seeing the error `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'whatsapp_status' in 'SELECT'` because the database schema is missing the new column required for the WhatsApp integration.

The system attempts to fix this automatically in `api/db.php`, but if your database user does not have `ALTER TABLE` permissions, it will fail silently.

### **Manual Fix**
Please run the following SQL command on your database (`app_chatmedb`):

```sql
ALTER TABLE users ADD COLUMN whatsapp_status ENUM('Pending', 'Connected', 'Disconnected') DEFAULT 'Pending';
```

A file named `manual_fix.sql` containing this command has also been created in the root directory.

## 2. Webhook Location Correction

We have corrected the webhook logic to ensure it runs from the root directory, as you indicated that is the correct external endpoint.

-   **Correct File:** `./webhook.php` (Root Directory)
    -   This file now contains the improved WhatsApp logic (robust payload parsing, logging, contact creation) AND the legacy Flutterwave logic.
-   **Wrapper File:** `api/webhook.php`
    -   This file now simply includes the root webhook to ensure consistency if accessed internally.

### **Verification**
After running the SQL command above, the "Complete Registration" button in Settings should appear if the status is Pending, or hide if Connected.
