# v1.0.14 Figma-Reference UI Refinement

Date: 2026-08-19

## Scope

This release is a visual-only refinement on top of v1.0.13. Existing Laravel/Inertia routes, permissions, business rules, database behavior, field workflows, Bal Pravruti logic, monitoring logic, runtime hardening and deployment behavior remain unchanged.

The supplied Figma Make reference was treated as a UI/UX direction only. The connected Figma design-context API requires editor permission for Make files, so exact node-level extraction was not available with view-only access. The implementation therefore keeps the portal's established purple SMVS visual identity while applying the requested polished desktop/mobile app treatment to the real codebase.

## UI changes

- Rebuilt the desktop application shell with a light navigation rail, grouped menu sections, compact icon treatment, active-state emphasis and a persistent user/profile footer.
- Reworked the desktop top bar with active-page icon, clearer page hierarchy and role/user context.
- Reworked mobile navigation into a floating app-style bottom navigation surface with safe-area handling and improved active states.
- Reworked the mobile More menu into grouped navigation sections with a cleaner bottom-sheet presentation.
- Consolidated duplicated legacy UI CSS into one design-token-driven visual system.
- Refined global cards, inputs, buttons, badges, alerts, table rows, focus states, shadows and border radii.
- Kept mobile tables as stacked record cards while improving spacing and narrow-screen behavior down to 320px.
- Upgraded the dashboard hero with a completion ring, more legible progress hierarchy, icon-led KPI cards and cleaner quick-action/leaderboard sections.
- Upgraded the login page for both desktop and mobile with a stronger visual hierarchy, campaign identity and responsive split/mobile layouts.
- Preserved mobile form controls at 16px to avoid iOS zoom and retained touch-friendly control heights.

## Validation

- Static integrity check: PASS
- Inertia pages: 32
- Named routes: 88
- Seeded permissions: 43
- Used route/navigation permissions: 39
- PHP syntax: PASS
- TypeScript parser reached dependency-resolution only; project dependencies are not bundled in the release archive, so full Vite/TypeScript build validation requires `npm install` in a network-enabled environment.

## Functional boundary

No controller, model, route, migration, policy, permission, seeder, service, runtime or database behavior was intentionally changed in v1.0.14.
