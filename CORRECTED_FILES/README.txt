CORRECTED FILES:
1. api/get_conversations.php - Fixed SQL to handle NULL contact names.
2. index.php - Fixed JavaScript to handle NULL contact names safely.
3. webhook.php - Added Auto-Assignment logic to assign new conversations to the tenant owner.

UPDATE NOTES:
1. api/get_conversations.php:
   - Replaced 'is_read' with 'status' in unread count query because 'is_read' column is missing in the database.
   - Retained 'COALESCE' fix for contact names.
2. index.php:
   - Retained safety check for contact name.
3. webhook.php:
   - Retained auto-assignment logic.


4. api/get_messages.php:
   - Updated to accept both 'conversation_id' and 'id' parameters to resolve the 'Conversation ID haipo' error.
   - Wrapped the message list in a 'messages' key and added 'success: true' for better frontend handling.
