# Aligning the DSMS code with the final SRS — plan

Structure stays as it is: plain PHP 8 + MySQLi, folders `admin/ staff/ supervisor/ driver/ ajax/ includes/ config/ cron/`.
No framework, no new top-level layers. Prepared statements for every query; CSRF token on every POST (added automatically).

## Decisions and assumptions (where the SRS leaves room)

| Topic | Decision |
|---|---|
| Role keys | Stay `staff`, `driver`, `supervisor`, `admin`, `head_admin`. Labels (function `roleLabel()`): Staff, Driver, **Head of Department**, **Administrative Assistant**, **Head of Admin**. |
| Status keys | Stay `pending approved assigned processed rejected cancelled lapsed completed`. Labels (`requestStatusLabel()`): Pending, Approved, **Driver Assigned**, **Confirmed** (= `processed`), Rejected, Cancelled, Lapsed, Completed. |
| Departure / return | `trip_date`+`start_time` = departure, `return_date`+`end_time` = return. Generated columns `depart_at`, `return_at`. |
| One vehicle per request | The requested vehicle is `vehicle_requests.vehicle_id`. |
| Two tasks | Outbound task (`schedules.leg_no = 1`) starts at departure, return task (`leg_no = 2`) starts at the return time. Each lasts `TASK_DURATION_HOURS` (setting, default 2 h), never longer than the gap to the return, never past midnight. This is what drivers are scheduled for; the vehicle stays locked for the whole departure→return period. |
| Vehicle lock | Table `vehicle_locks (request_id, vehicle_id, start_dt, end_dt)`. Overlap rule everywhere: `start < other_end AND end > other_start`. Atomic: transaction + `SELECT … FOR UPDATE` on the vehicle row (`lockVehiclePeriod()`). A separate return vehicle gets a lock for the return window only. |
| Welfare duty vs. recommender | SRS item "remove drivers with overlapping welfare duty" and item "flag drivers on welfare duty" contradict (duty is 24 h, so every trip that day overlaps). Implemented as: **flag, do not remove**; welfare days count as tasks in the workload. |
| "Outside working hours" | A request task / internal task that starts before `WORK_START` (08:00), ends after `WORK_END` (17:30), or falls on Sat/Sun. Welfare days are not counted as outside hours. |
| Maintenance | `vehicles.status = 'maintenance'` + a row in `vehicle_maintenance` (workshop, expected return = `end_date`, `closed_at` NULL while open). A vehicle under maintenance is not offered for periods starting on or before the expected return date. |
| In Use | Not stored. `vehicleDisplayStatus()` shows it when a Confirmed task covers the current time. |
| Schedules without a request | Not creatable from the UI any more (only past demo data / imports). Trips come from requests only. |
| Migration | `migrations/2026_10_align_srs.sql` is applied automatically once (and can be run by hand). Written for MariaDB 10.4+ (XAMPP). Assumes the previous release's schema. |

## Rule → files

| SRS | Files |
|---|---|
| A accounts | `admin/users.php` (removed), `change_password.php` (hidden), `login.php`, `tools/import_staff.php`, `tools/staff_sample.csv`, `config/database.php`, `database.sql`, `admin/add_driver.php` |
| B request | `staff/request_vehicle.php`, `staff/my_requests.php`, `ajax/get_free_vehicles.php`, `ajax/get_document.php`, `includes/workflow.php`, `includes/upload.php`, `cron/run_jobs.php` |
| C approval | `supervisor/approvals.php`, `ajax/request_action.php`, `ajax/cancel_request.php`, `ajax/add_comment.php`, `ajax/get_comments.php`, `admin/reminders.php` |
| D assignment | `admin/vehicle_requests.php`, `admin/assign_driver.php`, `admin/approve_assignments.php`, `admin/replace_driver.php`, `ajax/get_available_drivers.php`, `includes/recommender.php`, `includes/workflow.php` |
| E schedule | `admin/internal_tasks.php`, `admin/welfare.php`, `admin/calendar.php`, `driver/schedules.php` |
| F vehicles | `admin/vehicles.php`, `add_vehicle.php`, `edit_vehicle.php`, `vehicle_maintenance.php`, `ajax/update_vehicle_status.php`, `ajax/deactivate_vehicle.php`, `ajax/deactivate_driver.php` |
| G notifications | `includes/workflow.php` (`notifyUsers`), `includes/mailer.php`, `notifications.php` |
| H reports | `admin/reports.php`, `includes/report_export.php` |
| I removals | see the table in the final report |
| J NFR | every page; `database.sql` indexes; `.htaccess` |

## Shared API (already written — use it, do not re-invent)

`includes/workflow.php`
* Settings: `getSetting($conn,$key)`, `getSettingInt`, `saveSetting`.
* Dates: `isValidDate('Y-m-d')`, `isValidTime('H:i[:s]')`, `timeWithSeconds()`.
* History: `auditLog($conn,$action,$request_id,$reason,$user_id=null,$role=null)`, `requestHistory($conn,$id)`.
* People: `adminUserIds`, `assistantUserIds`, `headAdminUserIds`, `userIdOfDriver`, `driverUserIdsOfSchedules`.
* **Notifications: `notifyUsers($conn,$user_ids,$message,$link)`** — ALWAYS saves the in-system notification and sends the e-mail; returns `['sent','failed','skipped','errors']`. If e-mail fails the notification is kept and the failure is in `email_log`.
* Request times: `requestDepartDT($req)`, `requestReturnDT($req)`, `requestTaskWindows($conn,$req)`, `hoursUntilDeparture($req)`.
* Availability: `availableVehicles($conn,$startDT,$endDT,$passengers,$exceptRequest=0)`, `vehicleUnavailableReason(...)`, `vehicleUnavailableText()`, `lockVehiclePeriod()` (inside a transaction), `releaseVehicleLocks()`.
* Status engine: `changeRequestStatus($conn,$id,$to,['reason'=>…,'system'=>bool])`, `cancelRulesFor($req)`, `REQUEST_ACTIVE_STATUSES`.
* Assignment: `assignRequestDrivers($conn,$id,$outDriver,$retDriver=null,$retVehicle=null)`, `approveAssignment($conn,$id)`, `rejectAssignment($conn,$id,$reason)`, `replaceTaskDriver($conn,$schedule_id,$new_driver,$reason)`.
* Comments: `addRequestComment($conn,$req,$text)`, `requestComments($conn,$id)`. Visibility: `requestForCurrentUser($conn,$id)`, `hodOwnsRequest()`.
* Reminder (manual): `sendApprovalReminder($conn,$id)`.
* Jobs: `runRequestJobs($conn)` (lapse, cancel unhandled, complete).
* Driver visibility: `SCHEDULE_CONFIRMED_ONLY_SQL` (table alias `s` = schedules) — a driver sees a task only when its request is Confirmed/Completed.

`includes/recommender.php`
* `recommendDrivers($conn,['start'=>…,'end'=>…,'licence'=>'D|GDL|PSV','except_schedule'=>id])` → `ranked` (tasks, outside, welfare flag, score, rank), `excluded` (reason).
* `driverClashReason($conn,$driver_id,$start,$end,$exceptSchedule=0,$exceptInternal=0)`, `driverMonthCounts()`, `isOutsideWorkingHours()`.
* Licence helpers: `LICENCE_CLASSES`, `VEHICLE_TYPES`, `licenceForVehicleType()`, `driverHoldsLicence()`, `normaliseLicenceList()`.

`config/database.php`: `requireLogin/Admin/HeadAdmin/Supervisor/Driver/Requester`, `isAdmin()` (= Administrative Assistant **or** Head of Admin), `isHeadAdmin()`, `roleLabel()`, `requestStatusLabel()`, `requestStatusBadgeClass()`, `vehicleStatusLabel()`, `vehicleDisplayStatus()`, `setFlash()/showFlash()`, `csrfToken()`.

Page skeleton: `includes/admin_page.php` → `adminPageStart('Title'); … adminPageEnd();` (works for every role; set `$current_page = 'file.php'` before requiring config). Model pages: `admin/internal_tasks.php`, `admin/welfare.php`.

## Testing

HTTP harness (scratchpad) `uist2/lib.py`, unit tests `php tests/run.php`. To test in parallel with a private database and server:

```
mysql -uroot -e "CREATE DATABASE mydb"; UIST_DB=mydb python3 -c "import lib; lib.reseed()"     # run inside uist2/
DSMS_DB=mydb php -S 127.0.0.1:8201 -t <project folder> &                                       # UIST_BASE=http://127.0.0.1:8201
```
