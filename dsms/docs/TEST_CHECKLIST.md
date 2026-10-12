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
