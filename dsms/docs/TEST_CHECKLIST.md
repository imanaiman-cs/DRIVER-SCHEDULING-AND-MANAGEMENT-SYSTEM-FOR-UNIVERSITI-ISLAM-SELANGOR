# DSMS manual test checklist (per build step)

Seed accounts (all use password `Password123`; the first login forces a password change only for accounts created in the app):
`staff1`, `staff2`, `hod1`, `hod2`, `admin1`, `headadmin1`, `driver1`..`driver16`.

## Step 1 - Foundation
- Open `http://localhost/dsms/` -> redirected to the login page.
- Log in as `admin1` -> open *System check*: PHP version, PDO, database, writable folders should all be OK.

## Step 2 - Login and dashboards
- Wrong password 5 times for `staff1` -> account locked message; admin can unlock it in Users.
- Each role sees its own dashboard cards and menu (staff has no admin menu).
- Log out (button) -> back button does not show private pages. After 15 minutes idle you are logged out.

## Step 3 - Accounts, drivers, vehicles, settings (admin only)
- As `staff1`, open `?page=users/index` -> 403 page.
- As `admin1`: *Users* -> Add user. A temporary password is shown ONCE. Log in with it -> forced to change password.
- Duplicate staff ID / duplicate e-mail / HOD-less approver are rejected with a clear message.
- *Drivers* -> Manage: tick licence classes, switch group to Management, add leave over a day that already has an assigned trip -> warning lists the trip.
- *Vehicles* -> Add a Bus: licence shows PSV. Add a maintenance period over a booked trip -> warning lists the requests.
- *Settings*: set weights 0.7 / 0.7 -> rejected ("must add up to 1"); 0.7 / 0.3 -> saved and written to the audit log.

## Step 4 - Vehicle requests
- Run `php tests/run.php` -> all tests pass (R1-R6 rules, availability, status map).
- As `staff1`: *New request* -> choose a departure TOMORROW -> refused with the 2-day message that names form BPTK/BPI/2025/PIN.5.
- Choose a date 5+ days ahead -> vehicle cards: free vehicles have a *Select* button; booked / maintenance / too-small vehicles are greyed with the reason.
- Pick a vehicle -> fill the details -> *Review and submit* shows a confirm dialog -> Submit -> request appears as *Pending Approval*; the HOD gets a notification.
- Try to book the same vehicle for the same day with another account -> refused (the vehicle is held per day).
- Upload a PDF -> downloadable from the request page; a `.php` file renamed to `.pdf` is rejected.
- Cancel the request without a reason -> refused; with a reason -> *Cancelled*, vehicle free again.
- As `staff2`, open `?page=requests/show&id=<staff1 request>` -> 403.

## Step 5 - Approvals, comments, notifications, jobs
- As `hod1`: *Approvals* lists the pending requests. Approve one -> status *Approved*; the requester and the admins get a bell notification.
- Reject one without a reason -> refused; with a reason -> requester sees the reason on the request page.
- As `staff2` try `?page=approvals/approve` by POST on someone else's request -> 403.
- Add a comment on a request: it appears with your role. There is no edit/delete button, and `?page=comments/edit` is a 404.
- Open the bell: a notification marks itself read and jumps to the request. Check `storage/logs/mail.log` for the e-mail copy.
- As `admin1`: *Reminders* -> *Send reminder* on a pending request; pressing it again within 4 hours is refused.
- Run `php cron/run_jobs.php`: requests pending more than 48 hours become *Lapsed* (vehicle released); confirmed trips past their return time become *Completed*.

## Step 6 - Driver recommendation and assignment
- Run `php tests/run.php Recommender` -> the scoring formula and the hard filters pass.
- As `admin1`: *Drivers > Assign driver* -> open an Approved request. Each leg (departure / return) has its own ranked table:
  score, tasks this month, after-hours, welfare duty and clash warnings. "Why are N drivers not listed?" explains the excluded ones (management, wrong licence, on leave).
- Choose a management driver / a driver without the licence by tampering with the form -> refused by the server.
- Choose a driver who already has a job in that time slot -> a warning with "Assign anyway".
- Assign -> status *Driver Assigned*. As `admin1`, *Approve assignment* is NOT available (403); as `headadmin1` it is -> *Confirmed*, and the drivers get a notification.
- *Self-drive* on an Approved request -> *Confirmed* with no driver.
- *Change driver* -> reason required; the change appears in the request's "Driver changes" and the log cannot be edited.
- Settings: change the weights to 0.9 / 0.1 and open the assign page again: the ranking changes.

## Step 7 - Driver tasks, internal tasks, welfare roster
- Open the site on a phone (or a 390 px wide browser window) as `driver1`: *My tasks* shows today's legs with a tap-to-call number, tabs Today / Next 7 days / This month / Last 14 days, and the month totals.
- A driver cannot open another driver's tasks (the driver is taken from the login, never from the URL). `staff1` opening `?page=tasks/index` gets 403.
- As `admin1`: *Operations > Internal tasks* -> New task. A clash with another job shows a warning ("Save anyway"); a driver on leave is refused; management drivers are not offered.
- *Operations > Welfare duty* -> *Fill empty days automatically*: every day gets one driver, nobody twice in a row, nobody on leave. Replace a day: a reason is required and shown.
- Add leave to a driver who is on welfare duty -> the warning tells you to replace them in the roster.
