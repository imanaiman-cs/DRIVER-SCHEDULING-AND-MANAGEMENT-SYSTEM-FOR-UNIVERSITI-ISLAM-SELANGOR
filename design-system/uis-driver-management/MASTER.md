# Design System — UIS Driver Management (MASTER)

Global source of truth for how every page looks and behaves. Page files in `pages/` override this file for that page only.
Follows the Master + Overrides pattern of [ui-ux-pro-max](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill).

- **Product:** internal web app of Universiti Islam Selangor. Staff request vehicles, Heads of Department approve,
  the Vehicle Section assigns drivers, the Head of Admin confirms, drivers see their trips.
- **Style:** "Forest": the Ember layout (floating icon rail, one big rounded panel, borderless cards, pills) in a dark theme, emerald + UIS gold. `color-scheme: dark`; charts use light text on dark.
- **Stack:** plain PHP 8 pages, Tailwind CSS 4 + DaisyUI 5 (`assets/css/src/app.css` → `assets/css/app.css`), Font Awesome 6 icons,
  Chart.js 4. Rebuild CSS after changing classes: `npm run build:css`.

> The ui-ux-pro-max generator (`"internal admin dashboard fleet scheduling university" --design-system --density 7`) suggested a dark
> "tech" palette with Fira Code / Fira Sans and glassmorphism. It was **not adopted**: the UIS brand is green and gold, and the
> Ember look was chosen from a reference. Its UX rules and pre-delivery checklist *were* adopted (see the checklist below).

## Tokens

| Token | Value | Use |
|---|---|---|
| `--color-primary` | `#10b981` (content `#04140d`) | emerald: primary actions, active nav, key figures, today |
| gradient | emerald glow (`.e-accent`) | the one accent card per dashboard, active rail item |
| `--color-secondary` | `#d4a017` | UIS gold: counts on the rail, welfare duty, the `.e-dark` card |
| `--ember-dark` | `#0a1611` | rail and panel |
| page background | `#050c09` + emerald radial glow | behind the panel |
| `--color-base-100` | `#0f1c16` | cards |
| `--color-base-200` | `#13241c` | tiles, fields, hover |
| `--color-base-300` | `#1f3529` | hairlines, field borders, tooltips |
| `--color-base-content` | `#e6efe9` | text |
| muted text | `text-base-content/65` or more | never below 65% |
| `--color-success` / `--color-error` / `--color-info` / `--color-warning` | `#22c55e` / `#f87171` / `#38bdf8` / `#f59e0b` | status |
| radius | cards 22–24 px, fields 14 px, buttons / tabs / badges full pill | |
| `--ember-shadow` | `0 0 0 1px rgb(255 255 255/.06), 0 20px 40px -28px rgb(0 0 0/.9)` | the only card shadow |
| font | Outfit 300–700 (body, headings, figures with `tabular-nums`) | IBM Plex Sans Condensed 600 only for number plates |
| type scale | 12 · 14 · 16 · 18 · 24 · 32–38 (page title) · 48–60 (hero figure, weight 300) | |
| spacing | Tailwind 4 px scale; card padding 24 px; gaps 20 px between cards | |

## Layout

- **Rail:** `includes/sidebar.php`. On ≥1024 px it collapses to icons with tooltips (`data-tip`) or opens to 15 rem with labels; the `#railToggle` button in the header switches it, the choice is kept in `localStorage` (`uis-rail`) and applied before paint (`html.rail-open`). A labelled panel on phones (DaisyUI drawer).
  The active item is the green gradient circle with a soft glow. Help and Log out sit apart at the bottom.
- **Panel:** everything else lives in `.ember-panel` (rounded 28 px). Header: date, the page's icon + title, clock, notifications, avatar.
- **Page title:** one `<h1>` per page, large and light. Card titles are `<h2>`. Never skip a level.
- Max content width 1400 px. No horizontal page scroll at 390 px; wide tables and the vehicle board scroll inside their card.

## Components (use these, don't invent new ones)

| Need | Use |
|---|---|
| Card | `.card` (pages) or `.e-card` (dashboards). No borders, `--ember-shadow` |
| Card header | `.e-head` with an `<h2>` and an optional round `.e-icon-btn` (needs `aria-label`) |
| The one highlighted card | `.e-accent` (green gradient) — at most one per page |
| Contrast card | `.e-dark` |
| Small figure | `.e-tile` (label + value) inside a card |
| Trend | `.e-chip` / `.e-chip-down`, always "…vs last month" |
| Primary action | `.btn.btn-primary` (green) or `.btn.btn-neutral` (dark pill) — one per screen |
| Secondary action | `.btn` (white pill) |
| Filters / sub-pages | `<nav aria-label="…" class="tabs tabs-border">` with `<a class="tab">`, `aria-current="page"` on the active one |
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
