# Page override — Dashboards

Applies to `admin/dashboard.php`, `staff/dashboard.php`, `supervisor/dashboard.php`, `driver/dashboard.php`. Everything not listed here follows `../MASTER.md`.

- **Heading:** "Good afternoon, <first name>" as the `<h1>` (`data-dashboard` on its container — the HTTP tests look for it), actions as pills on the right.
- **Admin grid (12 columns, 20 px gaps):**
  - left 8 columns: hero `.e-card` (this month's requests as a 60 px light figure, trend chip, driver avatars, dark "Assign drivers" pill, three `.e-tile`s), then "Needs your action" (7/12) + the green `.e-accent` "In progress" card (5/12);
  - right 4 columns: "Today" (week strip `.e-day`, today's trips, welfare duty) and "Alerts";
  - full width: "Vehicles today" board (`dashBoard()`);
  - then "Requests per month" (8) + the dark "Fleet now" card (4), then three charts (4 + 4 + 4).
- **One accent card** (`.e-accent`) and **one dark card** (`.e-dark`) per dashboard, no more.
- Figures use weight 300 at 48–60 px; labels stay ≥ 12 px at 65% or more.
- Empty states are a sentence ("Nothing is waiting. Well done."), never an empty chart.
