ALTER TABLE users ADD COLUMN whatsapp_status ENUM('Pending', 'Connected', 'Disconnected') DEFAULT 'Pending';
