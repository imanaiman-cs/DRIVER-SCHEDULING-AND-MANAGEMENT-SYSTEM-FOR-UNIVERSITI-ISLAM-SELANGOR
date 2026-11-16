-- SRS 10 Nov UC019: the date and time the approval e-mail was sent is recorded on the request; a request whose e-mail could not be sent
-- (approval_email_at stays empty) is tried again by the hourly job. Safe to run more than once.
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS approval_email_at datetime DEFAULT NULL COMMENT 'when the approval e-mail was sent to the approver' AFTER is_urgent;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_approval_email');
