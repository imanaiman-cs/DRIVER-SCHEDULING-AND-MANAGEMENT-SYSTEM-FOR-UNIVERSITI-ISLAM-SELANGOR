# SRS / SDD traceability — use case → class → file

The system follows the final SRS (UC001–UC028) and the SDD (three layers, SDD_CLASS_101–314, data dictionary of
`dsms_db`). Every use case below is covered by `tests/e2e_flow.py` (end-to-end over HTTP) and the rules by
`tests/RulesTest.php` / `tests/RecommenderTest.php`.

## Layers

| SDD layer | Folder | Rule |
|---|---|---|
| View Layer (SDD_CLASS_101–111) | the pages + `includes/ui.php` | calls Domain classes only; no SQL (checked by `tests/e2e_flow.py`) |
| Domain Layer (SDD_CLASS_201–211) | `app/domain/` | business rules, workflow, notifications |
| Data Access Layer (SDD_CLASS_301–314) | `app/data/` | one class per table, PDO prepared statements |

## Classes

| SDD class | Implemented in |
|---|---|
| 101 AccountUI | `login.php`, `register.php`, `forgot_password.php`, `profile.php` |
| 102 UserManagementUI | `admin/users.php`, `view_user.php`, `edit_user.php`, `add_driver.php` |
| 103 VehicleUI | `admin/vehicles.php`, `add_vehicle.php`, `view_vehicle.php`, `edit_vehicle.php` |
| 104 RequestUI | `staff/request_vehicle.php`, `my_requests.php`, `request.php`, `approval_letter.php`, `admin/vehicle_requests.php` |
| 105 ApprovalUI | `supervisor/approvals.php`, `admin/approvals.php`, `admin/schedule_changes.php` |
| 106 AssignmentUI | `admin/assign_driver.php`, `admin/replace_driver.php` |
| 107 InternalTaskUI | `admin/internal_tasks.php`, `admin/welfare.php` |
| 108 DriverTaskUI | `driver/tasks.php`, `driver/task.php`, `driver/schedule_change.php` |
| 109 ScheduleUI | `admin/schedule.php`, `admin/task.php` |
| 110 ReportUI | `admin/reports.php` |
| 111 NotificationUI | `notifications.php`, the bell in `includes/sidebar.php` |
| 201 AccountController | `app/domain/AccountController.php` |
| 202 VehicleController | `app/domain/VehicleController.php` |
| 203 RequestController | `app/domain/RequestController.php` |
| 204 ApprovalController | `app/domain/ApprovalController.php` |
| 205 AssignmentController | `app/domain/AssignmentController.php` |
| 206 RecommendationService | `app/domain/RecommendationService.php` |
| 207 TaskController | `app/domain/TaskController.php` |
| 208 DriverTaskController | `app/domain/DriverTaskController.php` |
| 209 ReportController | `app/domain/ReportController.php` (+ `includes/report_export.php` for PDF / Excel) |
| 210 NotificationService | `app/domain/NotificationService.php` (+ `includes/mailer.php`, `includes/whatsapp.php`) |
| 211 ScheduledJob | `app/domain/ScheduledJob.php`, run by `cron/run_jobs.php` and on page loads |
| 301 Database | `app/data/Database.php` |
| 302–314 | `User`, `Driver` (+ `driver_licence`), `Department`, `Vehicle`, `Request`, `VehicleReservation`, `RequestStatusHistory`, `Task`, `WelfareDuty`, `TaskReport`, `ScheduleChange`, `DriverReplacement`, `Notification` in `app/data/` |

## Use cases

| Use case | Page(s) | Domain method(s) |
|---|---|---|
| UC001 Manage Staff and Driver Account | `admin/users.php` | `AccountController::listAccounts`, `confirmRole`, `deactivateAccount`, `activateAccount` |
| UC001A Create Driver Account | `admin/add_driver.php` | `AccountController::createDriverAccount` |
| UC001B–D View / Update / Deactivate | `admin/view_user.php`, `edit_user.php` | `getProfile`, `updateAccount`, `deactivateBlockers` |
| UC002 Manage Vehicle | `admin/vehicles.php` and the vehicle pages | `VehicleController::addVehicle`, `updateVehicle`, `deactivateVehicle`, `moveBooking` |
| UC003 Assign Request Task | `admin/assign_driver.php` | `AssignmentController::assignRequestTask`, `taskWindows`, `VehicleController::freeForReservation` |
| UC004 Manage Internal Task | `admin/internal_tasks.php` | `TaskController::addInternalTask`, `updateInternalTask`, `deleteInternalTask` |
| UC005 Assign Internal Task | `admin/internal_tasks.php?assign=` | `AssignmentController::assignInternalTask` |
| UC006 Manage Welfare Duty | `admin/welfare.php` | `TaskController::createWelfareWeek`, `updateWelfareDay`, `deleteWelfareDay`, `deleteWelfareWeek` |
| UC007 Assign Welfare Duty Task | `admin/welfare.php` | `AssignmentController::assignWelfareDuty` |
| UC008 Replace Driver | `admin/replace_driver.php`, `admin/schedule.php?view=replace` | `AssignmentController::replaceDriver`, `listWaiting('Replacement')` |
| UC009 Recommend Driver | inside UC003/005/007/008 | `RecommendationService::recommendDrivers`, `calculateScore`, `unavailableReason` |
| UC010 View Driver Schedule | `admin/schedule.php`, `admin/task.php` | `ReportController::getSchedule` |
| UC011 View Report / UC012 Export | `admin/reports.php` | `ReportController::generateReport`, `exportReport` |
| UC013 Register Account | `register.php` | `AccountController::register` |
| UC014 Send Verification Email | `register.php`, `forgot_password.php` | `AccountController::requestCode`, `verifyCode` |
| UC015 Reset Password | `forgot_password.php` | `AccountController::resetPassword` |
| UC016 Manage Profile | `profile.php` | `AccountController::updateProfile`, `changePassword` |
| UC017 View Request | `staff/my_requests.php`, `staff/request.php`, `supervisor/approvals.php`, `admin/vehicle_requests.php`, `staff/approval_letter.php` | `RequestController::listRequests`, `getRequestDetails`, `isVisibleTo`, `getApprovalLetter` |
| UC018 Submit Vehicle Request (every role) | `staff/request_vehicle.php` | `RequestController::checkAvailability`, `submitRequest` |
| UC019 Send Approval Email | on submit | `NotificationService::notify`, `retryFailedEmails` |
| UC020 Send Approval Reminder Email | `cron/run_jobs.php` | `ScheduledJob::sendReminders` |
| UC021 Cancel Vehicle Request | `staff/request.php` | `RequestController::cancelRequest` |
| UC022 Request Schedule Change | `driver/schedule_change.php`, `driver/task.php` | `DriverTaskController::requestScheduleChange`, `cancelScheduleChange` |
| UC023 View Assigned Task | `driver/tasks.php`, `driver/task.php` | `DriverTaskController::listAssignedTasks`, `getTaskDetails` |
| UC024 Task Report | `driver/task.php` | `DriverTaskController::submitReport`, `updateReport` (same day only) |
| UC025 Approve/Reject Vehicle Request | `staff/request.php`, `admin/approvals.php` | `ApprovalController::decideRequest` |
| UC026 Approve/Reject Driver Assignment | `admin/approvals.php`, `staff/request.php` | `ApprovalController::decideAssignment`, `decideReplacement` |
| UC027 Approve/Reject Schedule Change | `admin/schedule_changes.php` | `ApprovalController::decideScheduleChange` |
| UC028 Add Comment | the reason dialog on every Reject (`assets/js/ui.js`) | stored in `request_status_history.remark` and the `reject_reason` columns |

## Data dictionary → tables (`dsms_db`, 14 tables)

`department` · `user` · `driver` · `driver_licence` · `vehicle` · `request` · `vehicle_reservation` · `welfare_duty` ·
`task` · `task_report` · `schedule_change` · `driver_replacement` · `notification` · `request_status_history` (insert-only).
