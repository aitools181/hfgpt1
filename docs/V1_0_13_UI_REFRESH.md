# v1.0.13 Responsive UI / Mobile App Refresh

## Scope

This release is a presentation-only refinement on top of v1.0.12. Existing functionality, routes, permissions, controller behavior, database behavior, role scoping, reports, field workflows, Bal Pravruti workflows and deployment/runtime hardening remain unchanged.

## UI changes

- Shared desktop shell widened and polished with clearer navigation hierarchy and active states.
- Desktop header made sticky and visually lighter while preserving page titles and role-scoped indicator.
- Shared cards, buttons, inputs, badges and tables normalized with a consistent modern visual system.
- Mobile app bar, fixed bottom navigation and More bottom sheet refined for stronger native-app feel.
- Phone content density reduced and KPI/table-card layouts tuned for thumb-friendly use.
- Dashboard campaign hero, metric surfaces, KPI cards and leaderboards visually upgraded without changing any calculations or links.
- Login page improved for both desktop and phone while retaining the exact existing authentication behavior.

## Validation

`python3 scripts/static_integrity_check.py` passes:

- 32 Inertia pages
- 88 named routes
- 43 seeded permissions
- 39 used route/navigation permissions

The packaging environment did not complete `npm install`, so TypeScript/Vite compilation could not be independently re-run here. No dependency versions or backend behavior were changed in this UI release.
