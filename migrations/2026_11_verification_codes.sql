-- SRS 10 Nov (UC013 Register Account, UC014 Send Verification Email, UC015 Reset Password, UC016 Manage Profile):
-- verification codes sent to the UIS e-mail, the address of a user, and the UIS e-mail domain. Safe to run more than once.
CREATE TABLE IF NOT EXISTS verification_codes (
  code_id int(11) NOT NULL AUTO_INCREMENT,
  email varchar(100) NOT NULL,
  staff_id varchar(50) NOT NULL,
  purpose enum('register','reset') NOT NULL,
  code_hash varchar(255) NOT NULL COMMENT 'hash of the 6-digit code',
  expiry_time datetime NOT NULL,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  verified_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (code_id),
  KEY idx_vc_lookup (email, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE users ADD COLUMN IF NOT EXISTS address text DEFAULT NULL COMMENT 'own address (UC016)' AFTER phone;
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES ('UIS_EMAIL_DOMAIN', 'uis.edu.my', 'Only e-mail addresses of this domain can register (UC013)');
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_verification_codes');
