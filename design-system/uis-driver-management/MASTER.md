# Design System — UIS Driver Management (MASTER)

Global source of truth for how every page looks and behaves. Page files in `pages/` override this file for that page only.
Follows the Master + Overrides pattern of [ui-ux-pro-max](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill).

- **Product:** internal web app of Universiti Islam Selangor. Staff request vehicles, Heads of Department approve,
  the Vehicle Section assigns drivers, the Head of Admin confirms, drivers see their trips.
- **Style:** "Corporate" (direction A, chosen 22 Nov 2026 from three mockups): a full white sidebar with labelled sections, a white top bar with a breadcrumb, white cards with a 1 px border and 8 px corners, 6 px buttons, underline tabs. UIS green + gold. Light is the default; dark (`uis`) keeps the same shapes.
- **Stack:** plain PHP 8 pages, Tailwind CSS 4 + DaisyUI 5 (`assets/css/src/app.css` → `assets/css/app.css`), Font Awesome 6 icons,
  Chart.js 4. Rebuild CSS after changing classes: `npm run build:css`.

> The ui-ux-pro-max generator (`"internal admin dashboard fleet scheduling university" --design-system --density 7`) suggested a dark
> "tech" palette with Fira Code / Fira Sans and glassmorphism. It was **not adopted**: the UIS brand is green and gold, and the
> Ember look was chosen from a reference. Its UX rules and pre-delivery checklist *were* adopted (see the checklist below).

## Tokens ("Corporate", 22 Nov 2026; replaces "Emerald Pill")

| Token | Light | Dark | Use |
|---|---|---|---|
| `--color-primary` | `#0b5d3b` (white text) | `#10b981` (dark text) | UIS green: primary buttons, active sidebar item text, active tab underline |
| `--uis-active-bg` | `#e8f2ed` | emerald 14% | background of the active sidebar item |
| `--color-secondary` | `#b8860b` (dark text) | `#d4a017` | UIS gold: counts, recommended driver, avatar |
| `--color-base-100` | `#ffffff` | `#121b17` | cards, sidebar, top bar, fields |
| `--color-base-200` | `#f4f6f5` | `#0b120f` | the page, tiles, table header |
| `--color-base-300` | `#dde3e0` | `#24302a` | card borders and hairlines |
| `--uis-line` | `#cfd8d3` | base-300 | button and field borders |
| `--color-base-content` | `#17211c` | `#e7eeea` | text; muted text never below 66% |
| radius | cards 8 px, buttons/fields 6 px, badges/chips 4 px, dialogs 10 px | | |
| font | IBM Plex Sans 400-700 | | IBM Plex Sans Condensed 600 for number plates |

## Layout

- **Top bar:** white, full width, 1 px bottom border. Left: sidebar toggle, breadcrumb (Home / section / page) and the date. Right: BM | EN, light/dark, notifications, then the account (avatar, name, role).
- **Sidebar:** `includes/sidebar.php`, white with a right border. On ≥1024 px it is open by default at 15.5 rem with uppercase section names; `#railToggle` collapses it to a 68 px icon rail with tooltips. The choice is kept in `localStorage` (`uis-rail` = `closed`) and applied before paint (`html.rail-closed`). A labelled panel on phones (DaisyUI drawer).
  The active item has a pale green background and green text. Help and Log out sit apart at the bottom.
- **Panel:** everything else lives in `.ember-panel`, under the top bar, on the grey page.
- **Page title:** one `<h1>` per page, large and light. Card titles are `<h2>`. Never skip a level.
- Max content width 1400 px. No horizontal page scroll at 390 px; wide tables and the vehicle board scroll inside their card.

## Components (use these, don't invent new ones)

| Need | Use |
|---|---|
| Card | `.card` (pages) or `.e-card` (dashboards). 1 px border via `--ember-shadow`, 8 px corners |
| Card header | `.e-head` with an `<h2>` and an optional round `.e-icon-btn` (needs `aria-label`) |
| The one highlighted card | `.e-accent` (solid UIS green) — at most one per page |
| Contrast card | `.e-dark` |
| Small figure | `.e-tile` (label + value) inside a card |
| Trend | `.e-chip` / `.e-chip-down`, always "…vs last month" |
| Primary action | `.btn.btn-primary` (green) or `.btn.btn-neutral` (dark pill) — one per screen |
| Secondary action | `.btn` (white pill) |
| Filters / sub-pages (underline tabs) | `<nav aria-label="…" class="tabs tabs-border">` with `<a class="tab">`, `aria-current="page"` on the active one |
| Status | `requestStatusBadge()` / `vehicleStatusBadgeClass()` — text plus colour, never colour alone |
| Vehicle registration | `plate()` / `vehicleHtml()` — the number-plate signature |
| Dialog | `UIS.confirm()`, `UIS.alert()`, `UIS.askReason()` or `data-confirm` (DaisyUI `<dialog class="modal">`) |
| Chart | `dashChart()` — gives the canvas `role="img"` + a summary and keeps a screen-reader table |

## Rules (from ui-ux-pro-max, by priority)

1. **Accessibility:** text ≥ 4.5:1 (large 3:1); visible focus; icon-only controls have `aria-label`; one `h1`, no skipped levels;
   inline errors tied to fields (`aria-invalid`, `aria-describedby` — done by `assets/js/ui.js`); scrollable regions are focusable.
2. **Touch:** 44 px targets on touch screens (`@media (pointer: coarse)`), 8 px between targets, `touch-action: manipulation`,
   buttons show "Please wait…" while a form submits.
3. **Performance:** fonts with `display=swap`; reserve space for async content (vehicle search shows a status line).
4. **Style:** one icon set (Font Awesome solid), one shadow, one accent card, no emoji icons.
5. **Layout:** mobile first; 16 px fields on phones (no iOS zoom); `min-h-dvh`.
6. **Type & colour:** semantic DaisyUI tokens, no raw colours in pages; tabular figures for numbers and times.
7. **Motion:** 150 ms colour/shadow transitions only; `prefers-reduced-motion` turns motion and chart animation off.
8. **Forms:** visible labels, required marker `*`, errors under the field, confirm destructive actions, reasons for reject/cancel.
9. **Navigation:** the rail is the same on every page; current page highlighted; destructive actions (Log out) set apart.
10. **Charts:** bar for comparisons, line for trends; no pie/donut above 5 slices; legend only with 2+ series.

## Pre-delivery checklist

- [ ] `npx axe` style check: 0 violations (WCAG 2.2 AA + best practice) at 1366 px and 390 px
- [ ] No horizontal scroll at 390 px; nothing hidden behind the header
- [ ] Every clickable element has a hover state (150 ms) and `cursor: pointer`; focus is visible with the keyboard
- [ ] Each screen has one primary action
- [ ] Empty, loading and error states have words, not blanks
- [ ] `php tests/run.php` and the HTTP suites in `tests/http` pass as before

## Light mode

A second DaisyUI theme, `uis-light`, sits next to the dark `uis` (Forest) theme. The sun/moon button (`[data-theme-toggle]`, in the
header and on the login page) switches `data-theme` on `<html>`; the choice is saved in `localStorage` (`uis-theme`) and applied
before paint. Light tokens: page `#e3ebe6`, panel `#f4f7f5`, cards white, text `#07110d`, primary `#047857` (white text), gold `#b8860b`.
The rail stays dark green (`#0b2a1d`) in both themes. Surfaces that differ use `--uis-page`, `--uis-panel`, `--uis-btn`, `--uis-line`,
`--uis-chip-fg`, `--uis-rail`; charts redraw on the `uis-theme` event with deeper tones in light mode. Both themes pass axe (0 violations).

## Language (BM / EN)

Pages are written in English. The BM | EN switch (header; account menu on phones; login page) sets the `uis_lang` cookie via `?lang=ms|en`.
In BM, `includes/i18n.php` translates the finished HTML (text nodes and readable attributes) from `lang/ms.php`, and `assets/js/i18n.js`
translates text that scripts add later with the same dictionary. **When you add a label, add its BM text to `lang/ms.php`**
(`exact` for fixed text, `patterns` for text with numbers or names). Data typed by users is never translated.
