# Class diagram → database and code

The class diagram is in `docs/class_diagram.png` (Iman, 16 Nov 2026). This page shows where each class, attribute and
method is in the system. The database follows the diagram since the migrations `2026_11_class_diagram_tasks`,
`2026_11_class_diagram_schedule_change`, `2026_11_class_diagram_departments` and `2026_11_class_diagram_attributes`.

Column names are in snake_case (`approved_by` = `approvedBy`). "Extra" means a column the system needs that the diagram does not show.

## Classes → tables

| Class | Table | Notes |
|---|---|---|
| User | `users` | One table for every role (`role`: staff, driver, supervisor, admin, head_admin). |
| AdministrativeAssistant, HeadOfAdmin, HeadOfDepartment | `users` (`role` = admin / head_admin / supervisor) | Subclasses without their own attributes, so they have no table. |
| Driver | `users` (`role` = driver) + `drivers` | `users.driver_id` → `drivers` (1 : 1) holds licenceClass, licenceExpiry, startDate. |
| Department | `departments` | `head_user_id` = "headed by" (one Head of Department). |
| Request | `vehicle_requests` | |
| Task | `tasks` | One table for request tasks, internal tasks and welfare duty days (`task_source`). |
| WelfareDuty | `welfare_duties` | One row per week; its 1..7 days are tasks with `duty_id`. |
| ScheduleChange | `schedule_changes` | |
| DriverReplacement | `driver_replacements` | |
| TaskReport | `task_reports` | |
| Vehicle | `vehicles` | |
| Notification | `notifications` | |

## Attributes

| Class.attribute | Column | Notes |
|---|---|---|
| User.userID, staffID, name, email, phoneNo, address, password, role | `user_id`, `username`, `full_name`, `email`, `phone`, `address`, `password`, `role` | |
| User.departmentID | `users.department_id` → `departments` | |
| User.status | `users.is_active` (1 Active / 0 Inactive) | Users are deactivated, never deleted (UC001.4). |
| Driver.licenceClass, licenceExpiry, startDate | `drivers.license_class`, `license_expiry`, `date_joined` | |
| Department.departmentID, departmentName | `department_id`, `department_name` | |
| Department "headed by" HoD | `departments.head_user_id` | |
| **(added)** Department.parentDepartmentID | `departments.parent_department_id` | Iman's decision (16 Nov): a Head of Department's request goes to the head of the parent department (UC019 "the head of that Head of Department"). **Please add it to the diagram.** |
| Request.requestID, requesterID | `request_id`, `staff_id` | |
| Request.vehicleType, quantity, driverOption, purposeType, purpose | `vehicle_type`, `quantity`, `need_driver`, `purpose_type`, `purpose` | |
| Request.destination, address, waitingLocation | `destination`, `destination_address`, `waiting_place` / `waiting_address` | |
| Request.departDateTime, returnDateTime | `depart_at`, `return_at` (from `trip_date` + `start_time`, `return_date` + `end_time`) | |
| Request.noOfPassengers, distanceKm, charge, isUrgent, status, dateSubmitted | `passenger_count`, `distance_km`, `charge`, `is_urgent`, `status`, `created_at` | |
| Request.supportingDoc | table `request_documents` | Files are stored apart (PDF/JPG/PNG, 5 MB). |
| Request.approvedBy, rejectReason, rejectedBy, rejectedDate | `approved_by`, `reject_reason`, `rejected_by`, `rejected_at` | Extra: `supervisor_id` = the approver chosen when the request was made. |
| Task.taskID, requestID, dutyID | `task_id`, `request_id`, `duty_id` | |
| Task.taskSource | `task_source` (request / internal / welfare) | |
| Task.taskType | `task_type` (trip, workshop, refuel, ..., welfare) | |
| Task.tripType | `trip_type` (outbound / return) | |
| Task.driverID, vehicleID, startDateTime, endDateTime, description | `driver_id`, `vehicle_id`, `start_dt`, `end_dt`, `description` | A welfare day runs 24 hours from midnight. |
| Task.assignedBy, approvedBy, status, rejectReason, rejectedBy, rejectedDate | `assigned_by`, `approved_by`, `status`, `reject_reason`, `rejected_by`, `rejected_at` | Status: waiting → assigned → approved → completed, or cancelled. Extra: `assigned_at`, `approved_at`, `location`, `cancel_reason`, `cancelled_at`, vendor bus columns (UC017 A2). |
| WelfareDuty.dutyID, weekStartDate, status | `duty_id`, `week_start_date`, `status` | The status follows its days (`syncWelfareDutyStatus()`). |
| ScheduleChange.changeID, driverID, taskID, startDate, endDate, reason, status | `change_id`, `driver_id`, `task_id`, `start_date`, `end_date`, `reason`, `status` | Extra: `change_type` (unavailable / withdraw, UC022 A1). |
| ScheduleChange.approvedBy, rejectReason, rejectedBy, rejectedDate | `approved_by`, `reject_reason`, `rejected_by`, `rejected_at` | UC027: approving has no comment. |
| DriverReplacement.replacementID, taskID, driverID, previousDriverID, reason, replacedBy, dateTime, status | `replacement_id`, `task_id`, `driver_id` (the new driver), `previous_driver_id`, `reason`, `replaced_by`, `replaced_at`, `status` | Status: pending → approved / rejected by the Head of Admin. |
| TaskReport.* | `task_reports`: `report_id`, `task_id`, `actual_depart`, `actual_return`, `end_mileage`, `fuel_level`, `vehicle_condition`, `problem_type`, `problem_desc`, `photo`, `can_be_used` | Extra: `start_mileage` (for the mileage in the Vehicle Usage report). |
| Vehicle.* | `vehicles`: `vehicle_id`, `plate_number`, `vehicle_type`, `brand`, `model`, `fuel_type`, `capacity`, `license_required`, `mileage`, `fuel_level`, `status` | |
| Notification.notificationID, userID, message, dateSent, isRead, emailSent | `notification_id`, `user_id`, `message`, `created_at`, `is_read`, `email_sent` | Extra: `link`. |

## Methods → code

| Method | Where |
|---|---|
| User.login(), logout() | `login.php`, `logout.php` |
| User.updateProfile() | `profile.php` (UC016) |
| User.resetPassword() | `forgot_password.php`, `includes/verification.php` (UC015) |
| User.viewRequestStatus() | `staff/my_requests.php` (UC017) |
| Department.addDepartment(), updateDepartment() | **Not built**: no use case describes it. The departments are loaded with `database.sql` / the migration. Question for Iman: add a use case, or remove the two methods from the diagram? |
| Request.submitRequest() | `staff/request_vehicle.php` (UC018) |
| Request.cancelRequest() | `ajax/cancel_request.php` → `changeRequestStatus()` (UC021) |
| Request.calculateCharge() | `rentalCharge()` in `includes/workflow.php` |
| Request.updateStatus() | `changeRequestStatus()` (every status change) |
| HeadOfDepartment.approveRequest(), rejectRequest() | `ajax/request_action.php` → `changeRequestStatus()` (UC025) |
| AdministrativeAssistant.assignTask() | `assignRequestDrivers()`, `assignInternalTask()`, `assignWelfareWeek()` |
| AdministrativeAssistant.replaceDriver() | `replaceTaskDriver()`, `replaceNonRequestDriver()` (UC008) |
| AdministrativeAssistant.createWelfareDuty() | `createWelfareWeek()` (UC006) |
| AdministrativeAssistant.manageVehicle() | `admin/vehicles.php`, `admin/add_vehicle.php`, `admin/edit_vehicle.php` (UC002) |
| AdministrativeAssistant.generateReport() | `admin/reports.php`, `includes/report_export.php` (UC011, UC012) |
| HeadOfAdmin.approveTask(), rejectTask() | `approveAssignment()` / `rejectAssignment()`, `approveInternalTask()` / `rejectInternalTask()`, `approveWelfareWeek()` / `rejectWelfareWeek()` (UC026) |
| HeadOfAdmin.approveScheduleChange(), rejectScheduleChange() | `decideScheduleChange()` (UC027) |
| HeadOfAdmin.manageUser() | `admin/users.php`, `admin/edit_user.php` (UC001) |
| Driver.viewSchedule() | `driver/schedules.php` (UC023) |
| Driver.submitTaskReport() | `saveTaskReport()`, `driver/task_report.php` (UC024) |
| Driver.requestScheduleChange() | `createScheduleChangeRequest()`, `driver/schedule_change.php` (UC022) |
| Task.createTask() | `admin/internal_tasks.php`; request tasks in `assignRequestDrivers()`; welfare days in `createWelfareWeek()` |
| Task.assignDriver() | `assignRequestDrivers()`, `assignInternalTask()`, `assignWelfareWeek()` |
| Task.assignVehicle() | `chooseRequestVehicle()`, `reassignBookingVehicle()` |
| Task.updateStatus() | the approve / reject / cancel functions above, `runRequestJobs()` (completed) |
| WelfareDuty.createDuty(), generateTasks() | `createWelfareWeek()` (the week and its days) |
| ScheduleChange.submitChange(), cancelChange(), updateStatus() | `createScheduleChangeRequest()`, `cancelScheduleChangeRequest()`, `decideScheduleChange()` |
| DriverReplacement.recordReplacement() | inside `replaceTaskDriver()` / `replaceNonRequestDriver()`; status: `settleDriverReplacements()` |
| Vehicle.updateStatus(), updateMileage(), checkAvailability() | `admin/edit_vehicle.php`, `saveTaskReport()`, `vehicleUnavailableReason()` / `availableVehicles()` |
| TaskReport.submitReport(), viewReport() | `saveTaskReport()`, `taskReportOf()` |
| Notification.sendNotification(), sendEmail(), markAsRead() | `notifyUsers()` (in-system + e-mail + WhatsApp for drivers), `sendMail()`, `notifications.php` |

## Tables that support the classes (not in the diagram)

`vehicle_locks` and `vehicle_reservations` (the vehicle is reserved per time period, UC018), `vehicle_maintenance` (workshop and return date, UC002),
`vehicle_usage_log`, `audit_log` (who did what and why; never edited), `reminders` (UC020), `request_comments` (UC028),
`request_documents` (Request.supportingDoc), `verification_codes` (UC014), `email_log`, `whatsapp_log`, `settings`, `schema_migrations`.
