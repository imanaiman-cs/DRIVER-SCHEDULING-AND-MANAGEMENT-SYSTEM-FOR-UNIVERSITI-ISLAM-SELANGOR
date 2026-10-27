# Code vs SRS v1.1 — what is still missing

Checked on 27 October 2026 against `docs/SRS.md`; re-checked against the code on 27 October 2026 (nothing was done yet, three items corrected, two items added). Do the items in this order; tick them off as they are finished.
Each item names the use case to read first.

## 1. Missing features

- [x] **Automatic approval reminder — UC012.** (done 27 Oct: `runRequestJobs()` step 1b, `sendApprovalReminder($conn, $id, true)`; one reminder per request, HoD only, recorded in `reminders` with no sender; the manual page is kept) Code: reminders are manual only (`admin/reminders.php`, `sendApprovalReminder()`).
  SRS: the hourly job sends one reminder email to the HoD when a request is still Pending one day after submission.
  Add it to `runRequestJobs()`, send it only once per request (record it in `reminders`), and keep the manual page only if Iman wants it.
  _Re-checked: still open. `runRequestJobs()` only lapses, cancels and completes; `includes/workflow.php` says "There is NO automatic reminder". The manual page and `sendApprovalReminder()` stay (nothing is deleted)._
- [ ] **Request without driver — UC010 A3.** Add a "With driver / Without driver" choice to `staff/request_vehicle.php`
  (new column, e.g. `vehicle_requests.need_driver`). Without driver: same approval flow and vehicle lock, but no driver
  assignment step and no driver tasks.
- [ ] **Record paper form request — UC010 A4.** Admin page "Record Paper Form": enter the details of form BPTK/BPI/2025/PIN.5,
  upload the scanned form, save with status Approved and lock the vehicle. No UC011/UC012 emails. Allowed even when the trip
  is less than 1 day away.
  _Re-checked: still open (no `record paper form` page or code anywhere)._
- [ ] **Driver profile — UC002 and UC003.** Driver page "My Profile" (view, UC002) and update of their own phone number and licence details (classes, expiry; UC003).
  Licence expiry in the past → error (exception 3.1). Admin keeps `admin/edit_driver.php`.
  _Corrected: SRS also asks to notify the Assistant when the licence classes change (UC003 3.3) and no licence class selected → error. `driver/` has only dashboard, schedules and task_sheet, so My Profile does not exist yet._
- [ ] **Driver updates vehicle after a task — UC007 A1.** New table `vehicle_usage_log` (vehicle, driver, mileage, fuel level,
  condition issue, recorded_at). The driver can record it only for vehicles used in their own tasks. Update `vehicles.mileage`.
- [ ] **Schedule change request — UC029 and UC030.** New table `schedule_change_requests` (driver, type = unavailable dates or
  withdraw from a task, dates / task, reason, status). Driver submits (no duplicate pending request for the same date or task).
  Head of Admin approves or rejects with a comment (UC016). Approved "unavailable" dates must be excluded by the recommender
  and must block welfare duty on those days (UC025 exception 3.1). Approved "withdraw" frees the driver from the task so the
  Assistant can replace them (UC020).
- [ ] **Vendor bus — UC017 A2.** When no UIS driver with a PSV licence is available, the Assistant records a vendor bus
  (vendor name, bus registration number, vendor driver name) instead of a UIS driver.

## 2. Behaviour that differs from the SRS

- [ ] **Date joined and recommender score — UC001, UC003 A1 and UC018.** Code ranks by tasks this month (`W_TASKS` 0.6) and tasks
  **outside working hours** (`W_AFTER_HOURS` 0.4, which also counts before-start/after-end hours); experience is excluded.
  SRS: weighted score of tasks this month (highest weight), **weekend tasks only** (Saturday/Sunday) and **experience calculated
  from the date the driver joined the Vehicle Section**. Add the three weights to Settings.
  _Corrected: the gap list said `drivers.experience_years` already exists and can be used. It exists (decimal, typed by hand), but the SRS
  wants experience **calculated from a date joined** that the Assistant enters in Add Driver (UC001 step 3) and edits (UC003 A1). There is no
  `date_joined` column yet, so a migration is needed. Questions for Iman before this item: (1) the weight of the experience factor
  (En. Khairul is still confirming), (2) what to do with the old `experience_years` column (keep unused or drop; nothing is dropped without asking)._
- [ ] **Reports — UC031.** Code has 4 reports (`reportTypes()`: tasks, **outside working hours**, welfare, vehicle usage). SRS has 5: tasks per driver,
  **weekend tasks per driver**, welfare duty days, **driver replacements**, vehicle usage. So: replace/rename "outside working hours" by
  "weekend tasks" and add "driver replacements". Export as PDF/Excel and print (UC032) for all 5. Exception 3.1: message when there is no data.
- [ ] **Delete internal task — UC024.** Code never deletes an internal task (cancel with a reason). Ask Iman: either keep it as
  a soft delete with a reason and call it "Delete" in the interface, or change the SRS wording.
  _Re-checked: still open (code only cancels, with a reason). **Waiting for Iman's decision.**_
- [ ] **WhatsApp notifications.** The code can also notify drivers on WhatsApp (optional, off by default). The SRS only lists
  in-system and email. Keep it disabled, or ask Iman to add it to the SRS.
  _Re-checked: still open. Note: `notifyUsers()` calls `sendWhatsApp()` for every driver even when WhatsApp is switched off, and then writes a `skipped` row in `whatsapp_log`. **Waiting for Iman's decision** (keep disabled, remove, or add to the SRS)._

## 3. Already matching (do not redo)

Vehicle lock per period, 1-day / 2-day rules, Lapsed after 2 days, approval by HoD of the same PTJ, comments on
cancel/approve/reject, two tasks per trip with separate drivers, Head of Admin approval of assignments, driver replacement with
reason, internal tasks, welfare duty set/update with reason, schedule calendar, driver task view (Confirmed only),
login lockout, 15-minute idle logout, upload limits, prepared statements and CSRF.
