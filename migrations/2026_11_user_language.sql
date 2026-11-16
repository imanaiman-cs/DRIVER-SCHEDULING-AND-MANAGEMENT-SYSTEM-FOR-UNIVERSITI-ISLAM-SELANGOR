-- Interface language per user (English / Bahasa Melayu). Outside SRS v1.1.
-- Safe to run more than once.
ALTER TABLE users ADD COLUMN IF NOT EXISTS language varchar(5) NOT NULL DEFAULT 'en' COMMENT 'interface language: en or ms' AFTER locked_until;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_user_language');
