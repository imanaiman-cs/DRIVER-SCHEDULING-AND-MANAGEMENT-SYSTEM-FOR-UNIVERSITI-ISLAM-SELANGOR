-- Class diagram (Iman, 16 Nov 2026): the last attributes that were missing.
--   Notification.emailSent                          -> notifications.email_sent
--   Request.approvedBy, rejectReason, rejectedBy, rejectedDate -> vehicle_requests.approved_by, reject_reason, rejected_by, rejected_at
-- Safe to run more than once.

ALTER TABLE notifications ADD COLUMN IF NOT EXISTS `email_sent` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = the e-mail of this notification was sent' AFTER `is_read`;

ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS `approved_by` int(11) DEFAULT NULL COMMENT 'the Head of Department who approved' AFTER `reviewed_at`;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS `reject_reason` varchar(500) DEFAULT NULL AFTER `approved_by`;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS `rejected_by` int(11) DEFAULT NULL AFTER `reject_reason`;
ALTER TABLE vehicle_requests ADD COLUMN IF NOT EXISTS `rejected_at` datetime DEFAULT NULL AFTER `rejected_by`;

-- existing requests: from the history (audit_log) where it is known, else from the approver of the request
UPDATE vehicle_requests vr
   SET vr.approved_by = COALESCE((SELECT a.user_id FROM audit_log a WHERE a.request_id = vr.request_id AND a.action = 'approve' ORDER BY a.log_id LIMIT 1), vr.supervisor_id)
 WHERE vr.approved_by IS NULL AND vr.reviewed_at IS NOT NULL AND vr.status NOT IN ('pending', 'rejected', 'lapsed');
UPDATE vehicle_requests vr
   SET vr.rejected_by = COALESCE((SELECT a.user_id FROM audit_log a WHERE a.request_id = vr.request_id AND a.action = 'reject' ORDER BY a.log_id DESC LIMIT 1), vr.supervisor_id),
       vr.rejected_at = COALESCE(vr.reviewed_at, vr.updated_at),
       vr.reject_reason = vr.status_reason
 WHERE vr.status = 'rejected' AND vr.rejected_at IS NULL;

INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_class_diagram_attributes');
