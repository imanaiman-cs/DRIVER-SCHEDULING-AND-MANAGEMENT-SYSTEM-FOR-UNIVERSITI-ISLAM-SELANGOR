-- SRS 14 Nov UC022 notes: the driver can cancel a schedule change request that is still Pending. Safe to run more than once.
ALTER TABLE schedule_change_requests MODIFY COLUMN status enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending';
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_schedule_change_cancel');
