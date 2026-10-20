-- WhatsApp (OpenWA) attempts for drivers; safe to run more than once.
CREATE TABLE IF NOT EXISTS `whatsapp_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `to_number` varchar(20) DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('sent','failed','skipped') NOT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_wa_created` (`created_at`),
  KEY `idx_wa_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_whatsapp_log');
