<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/record_paper_form.php  -  UC010 A4 Record Paper Form (Administrative Assistant / Head of Admin)
// Same form as staff/request_vehicle.php in "paper form" mode: the request is saved as Approved and the vehicle is locked.
// ============================================================
require_once '../config/database.php';
requireAdmin();
$PAPER_FORM_MODE = true;
require __DIR__ . '/../staff/request_vehicle.php';
