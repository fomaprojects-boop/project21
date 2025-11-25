ALTER TABLE messages MODIFY COLUMN sender_type ENUM('user', 'contact', 'agent', 'system') NOT NULL;
