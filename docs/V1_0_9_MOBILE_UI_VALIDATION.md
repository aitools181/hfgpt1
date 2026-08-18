# v1.0.9 Mobile UI Validation

## Goal

Make the existing full Happy Family Portal behave like a purpose-built mobile application without removing desktop functionality or changing role/business rules.

## Implemented mobile shell

- Sticky top app bar on screens below the desktop breakpoint.
- Role-aware four-item primary bottom navigation plus a `More` action.
- `More` opens a safe-area-aware bottom sheet containing every permitted navigation item, current-role identity and logout.
- Current route remains visually highlighted in both the bottom navigation and full menu.
- Desktop sidebar behavior from v1.0.3 remains unchanged: independent sidebar scroll, active-route highlight and preserved scroll position.

## Responsive data behavior

Every one of the 23 portal tables is wrapped in a contained data viewport and carries the `hf-mobile-table` class. `AppLayout` reads each table's visible header labels and adds `data-label` values to body cells. Below 640px CSS changes rows into stacked record cards using those labels.

This avoids the previous phone behavior where a 760-1120px desktop table forced repeated sideways scrolling. Tablet/desktop layouts continue to use standard table semantics.

## Form and interaction behavior

- Inputs/selects use at least 46px controls and 16px font size on phones to avoid iOS zoom.
- Fixed two-column form fragments collapse to one column below 640px.
- Action groups can wrap/grow for thumb-sized use.
- The Home Visit completion report becomes a bottom sheet on phones.
- Cards, long text and tables stay inside the viewport.
- Safe-area insets protect the app bar and bottom navigation on notched/home-indicator devices.
- Reduced-motion preference disables non-essential motion.

## Login and home-screen presentation

- Login is a full-height mobile entry screen with application icon, large credential controls and mobile-safe spacing.
- `viewport-fit=cover`, theme metadata, `manifest.webmanifest`, SVG icon and 192/512 PNG icons are included.
- Supported mobile browsers can present the portal as a standalone home-screen web app. This does not add offline data synchronization; authenticated portal behavior remains online/server-driven.

## Static validation performed

- PHP syntax: PASS.
- Existing source integrity route/permission/Inertia checks: PASS.
- Docker Compose/YAML, package/composer/manifest JSON: PASS.
- CSS parse (`tinycss2`): PASS.
- TypeScript compiler syntax diagnostics: no TS1xxx parser/syntax errors. Full type/build execution still requires project `node_modules`.
- Mobile table coverage: 23/23 portal tables marked mobile-responsive and wrapped.
- Mobile shell markers, safe-area metadata and PWA icon files: PASS.
- v1.0.8 runtime supervisor/health/bootstrap harnesses reached and passed the web/health/bootstrap stages before the offline run timed out in the pre-existing background-supervisor stage; no runtime files were modified by this UI release.

## Manual UAT widths

Tester should verify at minimum 320px, 360px, 390px, 430px, 768px and desktop >=1024px. The testing checklist should include menu open/close, active tab, More menu, every major table page, create/edit forms, My Target/Home Visit, reports and login.
