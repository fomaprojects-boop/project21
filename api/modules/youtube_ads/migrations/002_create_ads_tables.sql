-- Migration for YouTube Ads Module

-- Create advertisers table
CREATE TABLE advertisers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(191),
  email VARCHAR(191),
  contact_phone VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create ads table
CREATE TABLE ads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  advertiser_id INT NOT NULL,
  title VARCHAR(255),
  file_path VARCHAR(512),
  placement ENUM('intro','outro') DEFAULT 'intro',
  duration_seconds INT,
  start_date DATE,
  end_date DATE,
  price DECIMAL(12,2),
  payment_status ENUM('pending','paid','unpaid','refunded') DEFAULT 'pending',
  status ENUM('draft','approved','active','inactive','expired') DEFAULT 'draft',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (advertiser_id) REFERENCES advertisers(id)
);

-- Create ad_video_map table
CREATE TABLE ad_video_map (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ad_id INT NOT NULL,
  user_id INT NOT NULL,
  video_id VARCHAR(191) NOT NULL, -- YouTube video id
  approved TINYINT(1) DEFAULT 0,
  inserted TINYINT(1) DEFAULT 0,
  inserted_at TIMESTAMP NULL,
  FOREIGN KEY (ad_id) REFERENCES ads(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create youtube_tokens table
CREATE TABLE youtube_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  google_user_id VARCHAR(191),
  access_token TEXT,
  refresh_token TEXT,
  token_expires_at DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create ad_reports table
CREATE TABLE ad_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ad_id INT NOT NULL,
  user_id INT NOT NULL,
  advertiser_id INT NOT NULL,
  report_date DATE,
  metrics JSON,
  pdf_path VARCHAR(512),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ad_id) REFERENCES ads(id)
);
