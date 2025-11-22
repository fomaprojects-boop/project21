-- Seeder for YouTube Ads Module

-- Create a demo tenant (user)
INSERT INTO users (full_name, email, password, role, status) VALUES ('Demo Tenant', 'tenant@example.com', '$2y$10$K.p././.', 'Admin', 'active');

-- Get the ID of the demo tenant
SET @tenant_id = LAST_INSERT_ID();

-- Create a demo advertiser
INSERT INTO advertisers (tenant_id, name, email, contact_phone) VALUES (@tenant_id, 'Demo Advertiser', 'advertiser@example.com', '123-456-7890');
