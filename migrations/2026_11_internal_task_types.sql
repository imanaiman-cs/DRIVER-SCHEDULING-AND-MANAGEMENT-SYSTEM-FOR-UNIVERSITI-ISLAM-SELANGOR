-- SRS 14 Nov UC004: the internal task types are Workshop, Refuelling and Test Drive. Old send / collect rows become Workshop;
-- the old values stay in the list so that no old row is lost. Safe to run more than once.
ALTER TABLE internal_tasks MODIFY COLUMN task_type enum('workshop','workshop_send','workshop_collect','refuel','test_drive','road_tax','document_delivery','other') NOT NULL;
UPDATE internal_tasks SET task_type = 'workshop' WHERE task_type IN ('workshop_send', 'workshop_collect');
INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_internal_task_types');
