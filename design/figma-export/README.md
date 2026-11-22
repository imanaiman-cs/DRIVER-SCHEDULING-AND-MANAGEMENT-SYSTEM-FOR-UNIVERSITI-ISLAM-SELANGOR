# Figma export (FigCraft)

Thirteen screens of the system exported as static HTML that the [FigCraft](https://github.com/DivikWu/figcraft)
`import_html` tool turns into **editable Figma layers** (frames with auto layout, text, SVG icons, images).

FigCraft's importer reads only inline styles, flex layout and solid colours, and it cannot log in to the app.
So each page was rendered in a browser (logged in, light theme, English) and rebuilt as simple HTML:
grids became flex rows with real widths, gaps were measured from the page, translucent colours were blended
to solid ones, hairline shadows became borders, Font Awesome icons became SVG, and the logo and charts
became embedded images.

| File | Screen | Width × height |
|---|---|---|
| 01-login.html | Sign in | 1440 × 900 |
| 02-admin-dashboard.html | Administrative Assistant dashboard | 1440 × 2433 |
| 03-admin-vehicle-requests.html | Vehicle Requests (list + panel) | 1440 × 1550 |
| 04-admin-assign-driver.html | Assign Driver | 1440 × 1105 |
| 05-admin-calendar.html | Schedule Calendar | 1440 × 1048 |
| 06-admin-drivers.html | Drivers | 1440 × 1082 |
| 07-admin-vehicles.html | Vehicles | 1440 × 1094 |
| 08-admin-reports.html | Reports | 1440 × 1033 |
| 09-staff-dashboard.html | Staff dashboard | 1440 × 996 |
| 10-staff-request-vehicle.html | Request a Vehicle | 1440 × 1141 |
| 11-hod-dashboard.html | Head of Department dashboard | 1440 × 1357 |
| 12-driver-dashboard.html | Driver dashboard | 1440 × 900 |
| 13-staff-dashboard-mobile.html | Staff dashboard on a phone | 390 × 1918 |

`index.json` lists the same with node counts and nesting depth.

## Import into Figma

1. FigCraft only reads files under folders you allow. Add `FIGCRAFT_HTML_IMPORT_ROOTS` to the `figcraft`
   entry in the project's `.mcp.json` (use your own path), then restart Claude Code:

   ```json
   "env": {
     "FIGCRAFT_CHANNEL": "design-1",
     "FIGCRAFT_HTML_IMPORT_ROOTS": "C:\\xampp\\htdocs\\DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR\\design\\figma-export"
   }
   ```

2. Start the relay (`npm run dev:relay` in the FigCraft folder), open Claude Code in this project, open a Figma
   design file and run the FigCraft plugin (Connect).

3. Ask Claude Code:

   > Use the figcraft import_html tool to import every file listed in design/figma-export/index.json, in order.
   > For each: filePath = absolute path of the file, width and height from index.json, maxDepth 20,
   > maxNodes 600, x = 0 for the first and then the previous x + previous width + 200, y = 0.
   > Name each frame after its "title". Report any warnings.

   The defaults (`maxDepth` 8, `maxNodes` 250) are too small for these screens; that is why the prompt raises them.

## Limits of the importer

- Text uses Figma's default font unless you change it: set Plus Jakarta Sans (body) and IBM Plex Sans Condensed
  (number plates) in Figma afterwards.
- Elements are not centred or right-aligned inside a frame, so a few items (the sign-in card, avatar initials)
  need a nudge.
- Gradients become their first colour; small overlay badges (counts on the rail, score badges) are left out.
- The screens are frames, not components; turn repeated pieces (cards, buttons, plates) into components in Figma.

## Regenerate after the design changes

```bash
npm i -D playwright @fortawesome/fontawesome-free@6.4.0
```
```bash
npx playwright install chromium
```
PowerShell (Windows):

```bash
$env:BASE="http://localhost/DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR"; node scripts/figma-export.js design/figma-export
```

macOS / Linux:

```bash
BASE=http://localhost/DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR node scripts/figma-export.js design/figma-export
```

The script logs in with the demo accounts listed in it; change them if your accounts differ.
