--
-- Jedwali la Kuhifadhi Taarifa za Chaneli za YouTube
--

CREATE TABLE `youtube_channels` (
  `id` varchar(255) NOT NULL COMMENT 'YouTube Channel ID',
  `user_id` int(11) NOT NULL COMMENT 'FK to users table (tenant/creator)',
  `channel_name` varchar(255) NOT NULL,
  `thumbnail_url` varchar(255) DEFAULT NULL,
  `connected_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `youtube_channels_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Jedwali la Kuhifadhi Ripoti za YouTube
--

CREATE TABLE `youtube_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'FK to users table (tenant/creator)',
  `advertiser_id` int(11) NOT NULL COMMENT 'FK to advertisers table',
  `report_name` varchar(255) NOT NULL,
  `report_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`report_data`)),
  `generated_at` timestamp NULL DEFAULT current_timestamp(),
  `emailed_at` datetime DEFAULT NULL,
  `pdf_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `advertiser_id` (`advertiser_id`),
  CONSTRAINT `youtube_reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `youtube_reports_ibfk_2` FOREIGN KEY (`advertiser_id`) REFERENCES `advertisers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Jedwali la Kuhusisha Ripoti na Video
--
CREATE TABLE `youtube_report_videos` (
  `report_id` int(11) NOT NULL,
  `video_id` varchar(255) NOT NULL,
  PRIMARY KEY (`report_id`,`video_id`),
  CONSTRAINT `youtube_report_videos_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `youtube_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
