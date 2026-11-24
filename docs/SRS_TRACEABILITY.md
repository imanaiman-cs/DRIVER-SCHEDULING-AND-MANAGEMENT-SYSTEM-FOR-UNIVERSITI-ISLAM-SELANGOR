# SRS traceability — use case → code

The system follows the final SRS (UC001–UC028, class diagram in Appendix A). Every use case below is covered by
`tests/e2e_flow.py` (end-to-end over HTTP) and the rules by `tests/RulesTest.php` / `tests/RecommenderTest.php`.

| Use case | Page(s) | Logic |
|---|---|---|
| UC001 Manage Staff and Driver Account | `admin/users.php` (list, search, role filter, Pending Role, deactivate/activate) | `includes/accounts.php` |
| UC001A Create Driver Account | `admin/add_driver.php` | `createDriverAccount()` — default password e-mailed |
| UC001B View | `admin/users.php`, `admin/view_user.php` | `loadUser()` |
| UC001C Update (+A1 Pending Role) | `admin/edit_user.php`, `admin/users.php?tab=pending` | `updateAccount()`, `decidePendingRole()` — one HoD per department |
| UC001D Deactivate | `admin/view_user.php` | `setAccountActive()`, `deactivateBlockers()` |
| UC002A–D Manage Vehicle | `admin/vehicles.php`, `add_vehicle.php`, `view_vehicle.php`, `edit_vehicle.php` | `includes/fleet.php`, `vehicleUpcomingBookings()`, `changeBookingVehicle()` |
| UC003 Assign Request Task | `admin/assign_driver.php` | `assignRequest()`, `requestTaskWindows()` |
| UC004A–D Manage Internal Task | `admin/internal_tasks.php` | `saveInternalTask()`, `deleteInternalTask()` |
| UC005 Assign Internal Task | `admin/internal_tasks.php?assign=` | `assignInternalTask()` |
| UC006A–D Manage Welfare Duty | `admin/welfare.php` | `createWelfareWeek()`, `updateWelfareDay()`, `deleteWelfareDay()` |
| UC007 Assign Welfare Duty Task | `admin/welfare.php` | `assignWelfareWeek()` |
| UC008 Replace Driver | `admin/replace_driver.php`, `admin/schedule.php?view=replace` | `requestReplacement()`, `tasksNeedingReplacement()` |
| UC009 Recommend Driver | inside UC003/005/007/008 | `includes/recommender.php` — `recommendDrivers()`, `driverUnavailableReason()` |
| UC010 View Driver Schedule | `admin/schedule.php` (week, month, filters), `admin/task.php` | |
| UC011 View Report / UC012 Export | `admin/reports.php` | `includes/report_export.php` |
| UC013 Register Account | `register.php` | `registerStartProblem()`, `registerAccount()` |
| UC014 Send Verification Email | `register.php`, `forgot_password.php` | `issueVerificationCode()`, `checkVerificationCode()` |
| UC015 Reset Password | `forgot_password.php` | `accountByStaffAndEmail()`, `setPassword()` |
| UC016A–C Manage Profile | `profile.php` | `updateProfile()`, `changePassword()` |
| UC017 View Request (+A1, A2, A3) | `staff/my_requests.php`, `supervisor/approvals.php`, `admin/vehicle_requests.php`, `staff/request.php`, `staff/approval_letter.php` | `requestVisibleTo()`, `requestDetailsHtml()` |
| UC018 Submit Vehicle Request (Staff, HoD, Driver, Administrative Assistant, Head of Admin) | `staff/request_vehicle.php` | `unitsLeftByType()`, `submitRequest()` |
| UC019 Send Approval Email | on submit + `cron/run_jobs.php` | `sendApprovalEmail()` |
| UC020 Send Approval Reminder Email | `cron/run_jobs.php` | `runRequestJobs()` |
| UC021 Cancel Vehicle Request | `staff/request.php` | `cancelRequest()` |
| UC022 Request Schedule Change | `driver/schedule_change.php`, `driver/task.php` (A1) | `submitScheduleChange()` |
| UC023 View Assigned Task | `driver/tasks.php`, `driver/task.php` | |
| UC024A–C Task Report | `driver/task.php` | `saveTaskReport()` |
| UC025 Approve/Reject Vehicle Request | `staff/request.php`, `admin/approvals.php` (A2) | `hodDecide()`, `hoaDecideRequest()` |
| UC026 Approve/Reject Driver Assignment | `admin/approvals.php`, `staff/request.php` | `hoaApproveAssignment()`, `hoaRejectAssignment()`, `hoaDecideTask()`, `hoaDecideWelfare()`, `hoaDecideReplacement()` |
| UC027 Approve/Reject Schedule Change | `admin/schedule_changes.php` | `hoaDecideScheduleChange()` |
| UC028 Add Comment | the reason dialog on every Reject (`data-reason`, `assets/js/ui.js`) | stored with user, date and time (`request_history`, `reject_reason` columns) |

## Class diagram → tables

Department `departments` (reports to = `parent_id`) · User `users` (+ role) · Driver `drivers` · Vehicle `vehicles` ·
Request `requests` + `request_vehicles` (reserves) · Task `tasks` · WelfareDuty `welfare_weeks` · TaskReport `task_reports` ·
ScheduleChange `schedule_changes` · DriverReplacement `driver_replacements` · Notification `notifications`.
