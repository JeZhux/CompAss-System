# Design System: CompAss Console

> Source of truth for all CompAss frontend screens (admin, teacher, student).
> Extracted from `frontend-prototype/compass-admin-prototype.html`,
> `compass-teacher-prototype.html`, `compass-student-prototype.html`.
> Prototype DNA wins over generic taste rules wherever they conflict —
> deviations are documented in §8.

**Design Read:** console product UI (role-based dashboard + data tables) for school
admins, teachers, and students, with a restrained editorial-minimalist language,
leaning toward the prototype system (Fraunces + IBM Plex Sans, flat bordered cards).

**Dials:** `DESIGN_VARIANCE: 4 / MOTION_INTENSITY: 3 / VISUAL_DENSITY: 6`
(legible console, not marketing page; motion is feedback-only).

## 1. Visual Theme & Atmosphere

A quiet, paper-like school console. Flat surfaces, hairline borders, zero
decoration. The atmosphere is a well-kept staff room ledger: warm off-white paper,
ink text, one deep-green institutional accent, four muted semantic hues.
Density is daily-app balanced: stat cards on top, one data table per page,
filter bar above the table, pager below. No hero sections, no marketing blocks,
no overlapping layers. Motion is restrained and functional — hover states,
modal entry, skeleton shimmer on tables. Everything else is static.

## 2. Color Palette & Roles

Light theme is default. Dark theme mirrors the prototype `data-theme="dark"`
block. Honor `prefers-color-scheme` unless `data-theme="light"` is pinned.

- **Paper** (#F5F6F2) — App background wash. Dark: #15191A.
- **Paper Raised** (#FFFFFF) — Card, input, modal, and table-row-hover fill. Dark: #1D2321.
- **Ink** (#1C2521) — Primary text and headings. Dark: #ECEEE8.
- **Ink Soft** (#5B655F) — Secondary text, page subtitles, nav labels. Dark: #A6AFA8.
- **Ink Faint** (#686F6A) — Tertiary text, table headers, metadata. Dark: #8E9790.
- **Hairline** (#D8DAD2) — 1px structural borders (cards, sidebar, topbar, rows). Dark: #333A36.
- **Hairline Strong** (#C2C6BC) — Input borders, table header underline, buttons. Dark: #454D48.
- **Institutional Green** (#2F6B4F) — Single brand accent: primary buttons, active
  nav, links, focus rings, AI-ready dot. Soft wash #E4EEE8. Dark: #6BB78F on #1E2E26.
- **Amber** (#7A4E20 / soft #F5E9DC) — Warnings, pending states, draft pills.
- **Blue** (#33547A / soft #E6EBF1) — Informational pills, neutral highlights.
- **Red** (#A6413A / soft #F3E3E1) — Errors, destructive actions, AI-degraded state.
- **Purple** (#6B4F8F / soft #EBE5F1) — Fourth semantic tone (avatar fill,
  specialty pills). Never a second brand accent.

Rules: max one brand accent (green). Semantic hues appear only as pill washes,
status dots, or inline messages — never as large fills. Saturation stays muted.
Never pure black (#000000) or pure white-on-white without a hairline.
Button label on green fill is white in light mode, near-black (#0F1512) in dark
mode for contrast.

## 3. Typography Rules

- **Display:** Fraunces (serif), weight 400–600, tight tracking, `text-wrap: balance`.
  Page titles ~24px, card titles ~16–17px, brand wordmark ~20px. Serif is
  intentional brand DNA here, not decoration.
- **Body/UI:** IBM Plex Sans, 400/500/600. Base 14.5px, line-height ~1.55.
  Table body 13.5px, table headers 11.5px uppercase at 0.3px letter-spacing,
  metadata 11–12.5px in Ink Faint.
- **Mono:** system mono stack (`ui-monospace, SF Mono, Menlo`) for codes,
  temporary passwords, and route hints only. Tabular numerals for table numbers.
- **Scale discipline:** headlines max two lines; page subtitle max one line
  (13.5px Ink Soft). No gradient text. No centered display type inside the console.
- **Banned:** Inter/Roboto/Open Sans as replacements; generic Georgia/Times body
  text; all-caps paragraphs; placeholder-as-label inputs (label sits above input,
  error text below input).

## 4. Component Stylings

- **Shell:** one solid viewport-fixed left sidebar 220px with right hairline
  (identical on every screen; no collapse rail, no persisted width state);
  topbar 52px with bottom hairline, sticky (title left, AI pill + avatar
  right, zero theme toggles); content column max 1120px (narrow pages 980px), padding
  30px 36px 60px, offset by the fixed sidebar. The sidebar is wordmark-only
  (no tagline, no theme control); desktop renders no footer. The sidebar nav flexes and
  paints at 36px rows (44px touch via hit-slop) so the 11-item admin nav
  always fits; safety `overflow-y:auto` only triggers
  on very short viewports/zoom. Scrollbar stays hidden on all engines
  (`scrollbar-width: none`, `::-webkit-scrollbar { display: none }`); scroll
  affordance is a keyboard-focusable `<nav>` (`tabindex="0"` + existing
  `aria-label`, landmark preserved) plus a subtle bottom fade cue
  (`mask-image` linear fade, no colors), never a visible scrollbar. Below 960px the sidebar hides and opens as a
  fixed drawer via the topbar hamburger (non-focusable scrim-click / Escape closes, route
  change closes, roomier 44px rows, focusin pull-back keeps focus in drawer); content collapses to single column with
  16px gutters. Drawer-only footer holds a single Close button (44px,
  "Close navigation menu"); desktop has no footer.
- **Buttons:** flat bordered rectangles, radius 5px, 8px 14px, 13px/500.
  Primary is green fill; quiet is borderless; danger is red outline turning to
  red-wash hover. Active press is `translateY(1px)`. Small variant 5px 10px/12px.
  No glows, no gradients, no full-pill CTAs.
- **Cards:** flat `1px solid Hairline` on Paper Raised, radius 5px, padding
  16px 18px. No shadow. Cards exist to group (stat tiles, table holders, auth forms).
- **Pills:** fully rounded (99px), 11.5px/600, wash background + saturated text
  per tone (green/amber/blue/red/purple/neutral-outline). Used for statuses only.
- **Data table (`.dtable`):** full width, collapsed, header row uppercase faint
  with strong-hairline underline, 11px cell padding, hairline row dividers,
  row hover in Paper Raised. Primary cell 500 weight with faint sub-line.
  Empty state is a composed single row, never a blank card.
- **Pager:** centered row, 12.5px bordered number buttons radius 4px; active page
  is Ink fill on Paper text. Hidden when there is one page.
- **Filter bar:** wrapping flex row above tables; text input min 220px, selects
  alongside; same bordered 5px input language as modals.
- **AI pill:** small outline pill with 6px status dot (green ready / red degraded).
- **Avatar:** 26px wash circle with initials + 13px name; dropdown menu is a
  bordered 5px panel (170px min) with hover rows.
- **Modal:** 440px (wide 640px), 92vw max, 88vh max with scroll, 24px padding,
  bordered 5px panel on a 45%-ink scrim, portal to `document.body` and
  viewport-centered (grid `place-items:center` + `margin:auto`). Title 17px
  serif, 13px soft description, full-width bordered inputs, right-aligned
  actions, tone-washed message slot (err/ok/warn). Escape and scrim-click
  close. Constrained elevation `var(--shadow-modal)`; the sole elevated
  surfaces are modal + drawer, see §8.5.
- **Loaders:** skeletal shimmer matching table/card shape (shared `.skel` /
  `.skel-row` with `skelPulse` opacity-only keyframes). No circular spinners.
- **Shared feedback states (src/components/shared/Feedback.jsx + styles/app.css):**
  uniform `.empty` / `.forbidden` / `.error-state` dashed 5px boxes,
  `.err-line` / `.warn-line` / `.ok-line` / `.note-line` message lines,
  `.notice-actions` retry row, and `.visually-hidden` live text. Role screens
  reuse these so loading / empty / forbidden / error output reads the same
  everywhere, in both themes, with opacity-only motion.
- **Plain-word errors (src/components/shared/errors.js):** `friendlyError()`
  translates `ApiError` into one plain sentence with a next step; raw codes
  (`HTTP_*`), HTML dumps, and stack traces are never shown. Contact-admin
  guidance (`If this keeps happening, contact your school administrator.`) is
  appended for 403 / 404 / 409 / 410 / 5xx. 429 renders the wait-retry
  countdown (`ThrottleNotice`, button disabled until the wait ends, per-action
  budget shown, e.g. sign-in 5 attempts per 15 minutes). 410 renders re-upload
  guidance (expired link/file — re-upload to generate a fresh one). 409 renders
  move guidance (use Move instead of Place, or pick a different destination).
- **Inputs:** label above, helper optional, error below in 11.5px red. Visible
  green focus ring. Contrast-checked in both themes.

## 5. Layout Principles

Grid-first, single-purpose pages: page head (serif title + soft subtitle), stat
row, filter bar, one table card, pager. Sidebar nav uses one active state
(green wash + green text) in the single fixed 220px mode (same NavLink
`active` on every screen and role). Scale is deduped: canonical
section-head / filter-chips / stat numerals / row-list / classroom-grid /
tabbar / form-grid-2 live in `styles/app.css`; role sheets must not redefine
them. Topbar holds title left, AI pill + avatar right. A skip-link (first child
of `.app-shell`) jumps to `#main-content` and shows only on focus-visible.
Auth pages are centered single cards (400px) on Paper. 404 returns a card with a
way back. Every flow has a back path; no dead `#` buttons. Page gutters collapse
to single column below 960px with no horizontal scroll. Touch targets are
44px effective via hit-slop matching §4 (desktop nav paints 36px rows with
44px slop; drawer rows paint 44px). Content never stretches past 1120px. No flexbox percentage math — grid and
max-width containment only. Full-height shells use `min-h: 100dvh`, never `h-screen`.

## 6. Motion & Interaction

Static by default; motion only communicates feedback or state change.
200ms border/background hovers, 100ms press-down, 600ms `translateY(12px)` +
fade entry on major blocks with `cubic-bezier(0.16, 1, 0.3, 1)` and cascade
delays, modal fade/scale entry. Animate `transform` and `opacity` only. Honor
`prefers-reduced-motion` by collapsing all motion to instant (shared
`app.css` blanket `transition/animation: none`, skeleton `skelPulse` included).
No scroll-hijack,
no perpetual loops (except optional AI-working shimmer), no custom cursors,
no `window scroll` listeners (IntersectionObserver only).

## 7. Anti-Patterns (Banned)

No emojis. No Inter/Roboto/Open Sans swaps. No pure black. No neon/outer glows,
no gradients, no glassmorphism, no drop shadows (except portal modal/drawer
elevation §8.5). No pill-shaped cards or CTAs.
No centered hero inside the console. No three-equal-marketing-card rows. No AI
copy clichés (Elevate, Seamless, Unleash, Next-Gen, Game-changer, Delve,
Tapestry). No generic placeholder people/companies. No fake-precise stats. No
`h-screen` shells. No dead links or `window.alert` errors. No missing
label/error/empty/loading states. No overlapping elements (except centered
modal/drawer over scrim).

## 8. Deliberate Deviations From Generic Taste Defaults

1. **Fraunces serif is kept.** Generic dashboard rules ban serif; the prototype
   brand is Fraunces display + Plex Sans body, so serif stays for headings only.
2. **Flat bordered 5px cards are kept.** High-end rules prefer 2rem double-bezel
   cards with soft shadows; CompAss stays flat/hairline/5px to match the approved
   prototype ledger language.
3. **Single fixed 220px sidebar, no rail.** Generic rules prefer top nav; the
   prototype information architecture is sidebar-driven per role, so one
   viewport-fixed 220px sidebar stays on every desktop screen, switching to a
   fixed drawer only below 960px. No collapse state is persisted.
4. **Multi-hue semantic washes are kept.** Generic rules demand one accent; green
   remains the sole brand accent while amber/blue/red/purple survive strictly as
   status washes with documented roles.
5. **Constrained elevation is the only shadow.** Generic flat rules ban all
   shadows; CompAss keeps flat hairline cards/tables and allows muted
   elevation on two surfaces only — portal modal (`var(--shadow-modal)`) and
   mobile drawer (`var(--shadow-drawer)`), see `styles/tokens.css`. No
   gradients, glass, or glows.
6. **No collapsible rail.** The sidebar is a single fixed 220px column on
   desktop (drawer below 960px); the former 72px icon-rail and
   `compass:sidebar-collapsed` persistence were removed to keep one solid
   sidebar across all screens.
7. **Centered portal modal is kept.** Dialogs portal to `document.body` and
   center via grid `place-items:center` + `margin:auto`, layering scrim <
   overlay < drawer so a dialog stays interactive above the nav scrim. The
   mobile drawer auto-closes when a modal opens (AppLayout observer) so the
   two are never interactive together; the drawer-above-overlay rank is a
   fallback for the unmount tick only. Topmost Escape is owned via
   `hasOpenModal`/`isTopModal`, and the avatar menu closes on modal open.
8. **OS-driven theme + skip-link are kept.** No shell toggle UI: theme is
   OS-driven via `prefers-color-scheme` (tokens.css); a stored
   `compass:theme` pin is applied once by `InitTheme` (App.jsx reuses
   `readTheme`/`applyTheme` from Sidebar.jsx, `system` removes the pin).
   A skip-link first in `.app-shell` jumps to `#main-content` on focus-visible.

## 9. API Transport: Proxy vs Direct Origin (Sanctum)

- `vite.config.js` proxies `/api` and `/sanctum` to `http://127.0.0.1:8000`
  for `vite dev` on `:3000` so same-origin relative fetches work locally.
- `src/api/client.js` instead talks to a **direct origin** (`VITE_API_BASE`,
  e.g. `http://127.0.0.1:8000`) with `credentials: "include"` on every
  request. This direct-origin + credentials pairing is intentional for
  Sanctum stateful auth: the `XSRF-TOKEN` cookie and `X-XSRF-TOKEN` header
  only flow when cookies are included against the Laravel origin.
- First paint theme is OS-owned: `index.html` ships with **no**
  `data-theme` attribute so `prefers-color-scheme` (tokens.css) decides;
  `App.jsx InitTheme` only re-pins `data-theme` when the user has a stored
  `compass:theme` choice.
