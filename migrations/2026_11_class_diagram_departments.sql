-- Class diagram (Iman, 16 Nov 2026), class Department: departmentID, departmentName; a User belongs to 1 Department; a Department is
-- headed by a Head of Department. Iman's decision (16 Nov): a Department also has a parent department (parentDepartmentID), so that
-- the request of a Head of Department goes to the head of the parent department (UC019 "the head of that Head of Department").
--   users.department (text)      ->  users.department_id -> departments
--   users.supervisor_id          ->  departments.head_user_id + departments.parent_department_id
-- vehicle_requests.supervisor_id stays: it is the approver chosen when the request was made.
-- Run it ONCE (recorded in schema_migrations).

CREATE TABLE IF NOT EXISTS `departments` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(100) NOT NULL,
  `head_user_id` int(11) DEFAULT NULL COMMENT 'the Head of Department',
  `parent_department_id` int(11) DEFAULT NULL COMMENT 'a request of the Head of Department goes to the head of this department',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `uq_department_name` (`department_name`),
  UNIQUE KEY `uq_department_head` (`head_user_id`),
  KEY `idx_department_parent` (`parent_department_id`),
  CONSTRAINT `fk_department_head` FOREIGN KEY (`head_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_department_parent` FOREIGN KEY (`parent_department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Class Department (PTJ)';

INSERT IGNORE INTO departments (department_name)
SELECT DISTINCT TRIM(department) FROM users WHERE department IS NOT NULL AND TRIM(department) <> '' ORDER BY TRIM(department);

ALTER TABLE users ADD COLUMN IF NOT EXISTS `department_id` int(11) DEFAULT NULL AFTER `driver_id`;
UPDATE users u JOIN departments d ON d.department_name = TRIM(u.department) SET u.department_id = d.department_id WHERE u.department_id IS NULL;

-- the head: the (first) active Head of Department of the department
UPDATE departments d
  JOIN (SELECT department_id, MIN(user_id) AS uid FROM users WHERE role = 'supervisor' AND is_active = 1 AND department_id IS NOT NULL GROUP BY department_id) h
    ON h.department_id = d.department_id
   SET d.head_user_id = h.uid
 WHERE d.head_user_id IS NULL;
-- the parent: the department of the person who approved the head's own requests
UPDATE departments d
  JOIN users h ON h.user_id = d.head_user_id
  JOIN users s ON s.user_id = h.supervisor_id
   SET d.parent_department_id = s.department_id
 WHERE d.parent_department_id IS NULL AND s.department_id IS NOT NULL AND s.department_id <> d.department_id;

ALTER TABLE users DROP COLUMN IF EXISTS department, DROP COLUMN IF EXISTS supervisor_id;
ALTER TABLE users ADD KEY IF NOT EXISTS idx_users_department (department_id);
ALTER TABLE users ADD FOREIGN KEY IF NOT EXISTS fk_users_department (department_id) REFERENCES departments (department_id) ON DELETE SET NULL;

INSERT IGNORE INTO schema_migrations (name) VALUES ('2026_11_class_diagram_departments');
