# Code vs SRS v1.1 — what is still missing

Checked on 27 October 2026 against `docs/SRS.md`. Do the items in this order; tick them off as they are finished.
Each item names the use case to read first.

## 1. Missing features

- [ ] **Automatic approval reminder — UC012.** Code: reminders are manual only (`admin/reminders.php`, `sendApprovalReminder()`).
  SRS: the hourly job sends one reminder email to the HoD when a request is still Pending one day after submission.
  Add it to `runRequestJobs()`, send it only once per request (record it in `reminders`), and keep the manual page only if Iman wants it.
- [ ] **Request without driver — UC010 A3.** Add a "With driver / Without driver" choice to `staff/request_vehicle.php`
  (new column, e.g. `vehicle_requests.need_driver`). Without driver: same approval flow and vehicle lock, but no driver
  assignment step and no driver tasks.
- [ ] **Record paper form request — UC010 A4.** Admin page "Record Paper Form": enter the details of form BPTK/BPI/2025/PIN.5,
  upload the scanned form, save with status Approved and lock the vehicle. No UC011/UC012 emails. Allowed even when the trip
  is less than 1 day away.
- [ ] **Driver updates own profile — UC003.** Driver page to update their own phone number and licence details (classes, expiry).
  Licence expiry in the past → error (exception 3.1). Admin keeps `admin/edit_driver.php`.
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

- [ ] **Recommender score — UC018.** Code ranks by tasks this month (0.6) and tasks outside working hours (0.4); experience is
  excluded. SRS: weighted score of tasks this month, **weekend tasks** and **experience** (`drivers.experience_years` already
  exists). Add the weights to Settings. Iman is still confirming the experience factor with En. Khairul, so make its weight a setting.
- [ ] **Reports — UC031.** Code has 4 reports (tasks, outside working hours, welfare, vehicle usage). SRS has 5: tasks per driver,
  **weekend tasks per driver**, welfare duty days, **driver replacements**, vehicle usage. Export as PDF/Excel and print (UC032) for all 5.
- [ ] **Delete internal task — UC024.** Code never deletes an internal task (cancel with a reason). Ask Iman: either keep it as
  a soft delete with a reason and call it "Delete" in the interface, or change the SRS wording.
- [ ] **WhatsApp notifications.** The code can also notify drivers on WhatsApp (optional, off by default). The SRS only lists
  in-system and email. Keep it disabled, or ask Iman to add it to the SRS.

## 3. Already matching (do not redo)

Vehicle lock per period, 1-day / 2-day rules, Lapsed after 2 days, approval by HoD of the same PTJ, comments on
cancel/approve/reject, two tasks per trip with separate drivers, Head of Admin approval of assignments, driver replacement with
reason, internal tasks, welfare duty set/update with reason, schedule calendar, driver task view (Confirmed only),
login lockout, 15-minute idle logout, upload limits, prepared statements and CSRF.
