-- SRS 14 Nov: WhatsApp is not part of the system (task details in My Task replace the WhatsApp messages). The unused log table is removed.
DROP TABLE IF EXISTS whatsapp_log;
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_remove_whatsapp');
