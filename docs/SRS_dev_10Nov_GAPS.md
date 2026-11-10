# Code vs "DSMS Requirements for Development" (SRS 10 Nov 2026) — gap list

Source: `docs/SRS_dev_10Nov.md` (copy of the file Iman gave; `docs/SRS.md` v1.1 of 22 Oct is NOT changed).
Use case numbers below are the NEW numbers (UC001–UC028). Old code comments still use the v1.1 numbers.
`[x]` = done, `[~]` = partly, `[ ]` = missing, `[?]` = waits for Iman's decision (see the questions at the end).

## A. Already done (new UC number in brackets)
- [x] Driver recommendation with weights tasks 0.5 / weekend 0.3 / experience 0.2, licence filter, clash and approved schedule change removal (UC009)
- [x] Schedule change request by driver + Head of Admin decision, unavailable dates (UC022, UC027)
- [x] Replace driver with reason, recorded, Head of Admin approves again (UC008, UC026)
- [x] Reports: driver workload (tasks, weekend, welfare), driver replacement, vehicle usage, PDF/Excel/print (UC011, UC012)
- [x] Internal task update + delete (soft delete, driver told) (UC004)
- [x] Reminder to HoD after 1 day, Lapsed after 2 days, hourly job (UC020, UC018 notes)
- [x] Cancel with reason, related users notified (UC021)
- [x] Reject needs a reason (UC025/26/27/28)
- [x] My Profile for the driver, own phone/licence update (UC016 partly)
- [x] Self-registration without verification code, AA user management (UC001/UC013 partly, see C)
- [x] Pay and Claim: HoD then HoA, no vehicle reserved (UC018 A1, UC025 A2/A3) — uncommitted work finished in this change
- [x] Session timeout 15 min, login lock 15 min after 5 failed attempts, hashed passwords, prepared statements, CSRF, audit log never edited

## B. Business rules that CHANGED compared with v1.1 (code does the old thing)
- [x] **Reservation by vehicle TYPE and quantity** — done, including choosing the 2nd, 3rd ... vehicle with its own driver (UC003 A1, `t_multi.py`; migration `2026_11_vehicle_reservations`).  Original text:, not a specific vehicle (UC018 step 3, UC003 note). Reserved from departure to return **+ 1 hour**. The AA picks the specific vehicle at assignment (UC003 step 3). Code: the requester picks one vehicle and a per-vehicle lock is taken.
- [x] **Urgent flag instead of refusal** (done: `vehicle_requests.is_urgent`, migration `2026_11_urgent`, paper form page removed; Iman agreed): less than 2 days before departure is accepted and flagged Urgent; **no paper form** any more (UC018 A5, note). Code: <1 day refused, <2 days warning; Record Paper Form page exists.
- [x] Urgent requests listed first for HoD, AA, HoA (UC003, UC025, UC026)
- [x] Statuses (labels only, keys unchanged): Pending, **Approved by Head of Department**, Driver Assigned, **Approved**, Rejected, Cancelled, Lapsed, Completed. Code: Approved, Confirmed. Rename labels (keys can stay).
- [~] **Purpose type** Official / Unofficial, supporting letter **required** — done (migration `2026_11_purpose_type`, `t_purpose.py`). Personal Rental still open.
- [x] Vehicle types **car, MPV, van, bus, lorry** (MPV done); quantity of vehicles per request; more than one vehicle (UC018, UC003 A1, UC002 notes). Code: Car, Van, Lorry, Bus.
- [x] **Self Drive lorry** (done: `canSelfDriveLorry()`, `t_nodriver.py`): only a driver with a GDL licence; HoD then HoA (UC018 A2). Code: "Without driver" for any vehicle goes AA then HoA.
- [x] **Personal Rental** (done: migration `2026_11_personal_rental`, `t_rental.py`) (lorry, no letter, round-trip km, RM3/km, AA confirms distance then HoA, no HoD; print rental approval) (UC018 A4, UC003 A5, UC017 A4, UC019 A1)
- [x] **Print Claim Approval** letter (done together with the rental letter: `staff/print_approval.php`) for approved Pay and Claim (UC017 A3)
- [ ] A HoD's own request goes to that HoD's head (exists: approver = head) — recheck with the new "one HoD per department" rule
- [x] **One Head of Department per department** (done) (UC001 A2.1, UC013 4.2). Code allows several.
- [x] Welfare duty (the driver's welfare task report is still open): **weekly** schedule, vehicle (car/van only) chosen per day and reserved, then driver assigned per day, **HoA approves** (UC006, UC007). Code: monthly rotation, no vehicle, no HoA approval.
- [x] Internal task: status **Waiting for Driver → Driver Assigned → Approved**, vehicle reserved, driver assigned in a separate page with recommendation, **HoA approves** (UC004, UC005). Task types Workshop, Refuelling, Test Drive.
- [x] Driver task **report** (done: `task_reports`, `driver/task_report.php`, `t_report.py`; the welfare duty report waits for the weekly welfare redesign) (UC024): actual depart/return, end mileage, fuel level (Full, 3/4, 1/2, 1/4, Empty), condition, problem (type, description, photo, still usable), vehicle status Active/Under Maintenance, never deleted, notifies AA. Task becomes Completed. Replaces the smaller "Update Vehicle" page.
- [ ] Vehicle: types incl. MPV, **required licence class** and **current fuel level** fields, status Active / Under Maintenance, edit only fuel/mileage/status, deactivating or maintenance with upcoming bookings lists them (UC002)
- [ ] Driver registration by AA with **start date of service**, default password **emailed** to the driver (UC001 step 3). Code: password typed by AA.
- [ ] Deactivate a driver with upcoming tasks lists the tasks and asks to replace first (exists for drivers); a HoD with pending requests (done)
- [x] **Register Account with verification code by e-mail** (done: `t_users.py`, `t_reset.py`) (staff ID + UIS e-mail, only UIS e-mail accepted, code with expiry, resend), role Staff or HoD; **Reset Password** with code (UC013, UC014, UC015). Code: direct registration, no code, no reset.
- [x] Profile for Staff, Driver and AA (done: `profile.php`, `t_profile.py`) with address, change password (UC016). Code: driver profile only; change_password page hidden.
- [ ] My Request list with filters by status/date, Department Request (HoD), All Request (AA/HoA); details with status history (UC017)
- [ ] Notifications in-system and e-mail for every status change; approval e-mail recorded with date/time and retried (UC019 1.1)
- [ ] Data model names differ (Department table, VehicleReservation, Task, WelfareDuty, TaskReport, StatusHistory, VerificationCode). Decide whether to migrate (see question 3).
- [ ] WhatsApp is not in this SRS (still switched off)

## C. Needs Iman's decision first
See the questions asked in the chat. Nothing in section B is started until they are answered.
