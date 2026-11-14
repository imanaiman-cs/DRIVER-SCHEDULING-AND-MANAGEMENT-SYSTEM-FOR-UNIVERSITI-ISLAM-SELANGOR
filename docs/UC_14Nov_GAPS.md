# Changes for the Use Case Descriptions of 14 November 2026

Source: `docs/DSMS_Use_Case_Description_14Nov.pdf` (UC001–UC028). Decisions of Iman: keep Dashboard and Settings; remove everything else that
is not in the use cases; keep cancelling by the Assistant / Head of Admin (a reason is required).

## Done
- UC021: a reason is required for every cancellation (also for the requester).
- UC004: internal task types are **Workshop, Refuelling, Test Drive** (migration `2026_11_internal_task_types`); only a completed task cannot be deleted.
- UC003 A3: a vendor bus needs only the vendor name and the bus plate number.
- UC009: a driver whose licence has expired is not recommended.
- UC020: the reminder says the time left before the request lapses.
- UC001: *Create driver account* only (no generic Add account); update = department, role, licence class, licence expiry, start date (+ e-mail to the user);
  deactivate only (a driver is deactivated with the driver record, no activation any more); search by staff ID / name and filter by role.
- UC002: Add Vehicle = plate, type, brand, model, fuel type, capacity, licence class, mileage, fuel level (no year, notes, photo); Update Vehicle = fuel level,
  mileage, status Active / Under Maintenance only; Under Maintenance and Deactivate are refused while the vehicle has upcoming bookings (listed);
  no workshop / return date, no maintenance page, no activation any more.
- UC011 / UC012: three reports (Driver Workload, Driver Replacement, Vehicle Usage with trips and total mileage); PDF and Excel (no Print button).
- Removed because they are not in the use cases: manual Approval Reminders page, Help page, printable task sheets, WhatsApp code and `whatsapp_log`
  (migration `2026_11_remove_whatsapp`), the staff import tool, Add account / reset password by the Assistant.

- UC002.3 3.2 / UC002.4 3.1: *Assign another vehicle to each booking* (`admin/reassign_vehicle.php`, `reassignBookingVehicle()`): requests (same type, seats, driver licence, free in the period), internal tasks and welfare duty days; the people involved are told.
- UC008: Replace Driver also for approved internal tasks and welfare duty days (`admin/replace_task_driver.php`, `replaceNonRequestDriver()`, migration `2026_11_replace_internal_welfare`); the task returns to Driver Assigned and the Head of Admin approves; the Driver Replacement report lists them.

- Line-by-line check against the PDF (gaps found and closed): UC022 the driver can cancel a Pending schedule change request (migration `2026_11_schedule_change_cancel`);
  UC002.2 vehicle details show the current mileage and fuel level; UC004.2 filter by task type; UC001.2 a driver account shows licence class, expiry and start date;
  UC026 the approval page shows each driver's task count this month; UC021 A1 the Head of Department cancels from Department Request;
  UC008 A1 deactivating a driver links to Replace driver for internal tasks and welfare days; "No record is found" messages (accounts, vehicles, internal tasks, welfare week).

## Open / for Iman
- The drivers list (`admin/drivers.php`, `view_driver.php`, `edit_driver.php`) is a view of the same accounts as UC001; it was kept.
