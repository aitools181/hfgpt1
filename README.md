# SMVS Happy Family Portal

Production-oriented cumulative source package implementing the uploaded **SMVS Happy Family Project SRS Version 3.0** and **Full Portal Wireframe Version 2.0**.

Current release: **1.0.15 - Testing-Report Bug Fixes + v1.0.14 UI Refinement + v1.0.12 Runtime Hardening (Phases 0-7 cumulative)**

## What is included

### Core administration and scope

- authenticated role-based portal with login throttling
- Karyalay/Super Admin, BN Karyalay Admin, Zonal Admin, Center Admin, Computer Op., Karyakar, Nirdeshak, Nirikshak and Sanchalak
- Zone/Center scoped access and editable role-permission matrix
- Super Admin password reset plus delegatable `reset_user_passwords` permission with scope/hierarchy enforcement and session revocation
- Center, Area, Society and Category master data
- Activity/Audit Log with old/new values and reason where applicable

### Registration and imports

- SMVS Global Family/Member import using Family ID as primary reference and Member ID as secondary reference
- CSV, TSV and XLSX import; streaming/bounded readers plus a dedicated Redis `imports` queue so large imports do not block web requests
- Sampark Area/Society import
- Manual Sankalp Family registration and audited Family/Member correction editing
- Manual and Family-ID based Sankalp Karyakar nomination
- Pending/Approved/Rejected approval workflow
- Male/Female + Age capture with automatic read-only eight-category calculation

### Group, Family and target workflow

- Group creation with exactly 2 approved Sankalp Karyakars
- Couple/Husband+Wife, 2 Male or 2 Female validation
- Center-code Group names such as `GND-001`
- one Karyakar may belong to multiple active Groups
- exactly 10 active Sankalp Families per active Group
- 5-6 Fixed/Locked + 4-5 Remaining composition
- database and transactional duplicate active Family prevention
- authorized Family transfer with closure, replacement assignment, reason and audit
- Sampark Area/Society assignment
- target creation and progress calculation

### Mobile application experience

- v1.0.13 visual-system refresh with a wider polished desktop shell, refined sidebar/header hierarchy, modern cards/buttons/forms and consistent responsive spacing
- v1.0.14 shared-shell refinement with grouped desktop navigation, floating mobile bottom navigation, upgraded login/dashboard composition, stronger table/card/form consistency and 320px-safe responsive treatment
- v1.0.15 bug-fix pass covering Center Admin create visibility, readable user scopes, independent role baselines, duplicate Group prevention, Family/member/mobile rules, female-head synchronization, multi-Family assignment, Area/Society UX, Inventory Center notice and working Analysis filters with Group member/status filtering
- upgraded dashboard hero, KPI surfaces, leaderboards and compact two-column phone metrics while preserving every existing data source/action
- refreshed desktop split-panel login and full-screen mobile login presentation

- sticky mobile app bar with compact page title and user/menu access
- role-aware bottom navigation with active-state highlighting
- More menu as a mobile bottom sheet with the complete permitted navigation catalog
- app-style stacked record cards for all data tables on phones; desktop tables remain intact
- safe-area support for iPhone/Android browser chrome and home-screen standalone display
- touch-sized controls, single-column mobile form flow, mobile completion bottom sheet and overflow-safe content
- PWA manifest/theme metadata and application icons for supported home-screen presentation

### Field execution

- responsive/mobile My Target workflow
- Family checklist with Fixed/Remaining and Completed/Pending state
- click-to-call for available Karyakar and Head-of-Family numbers
- transactional Home Visit completion
- completion popup with Zone/Center/completed/pending/ratio analysis
- motivation badges at 3/6/9/12/15 completed Families
- 4-day inactivity Reminder and 7-day Alert history via Laravel scheduler

### Monitoring and reports

- organization, Zone, Center and permitted Karyakar dashboards
- BN Karyalay female-specific analysis lock
- Gender/Category filters
- Zone/Center leaderboards and target-vs-completed analysis
- all ten minimum SRS reports
- bounded report previews and lazy/streamed CSV exports isolated in a dedicated PHP-FPM pool
- detailed Activity/Audit Log filters

### Bal Pravruti

- separate module
- exactly 3 children + 1 Sanchalak per Bal Group
- Nirdeshak/Nirikshak supervision scope
- assigned-Sanchalak-only completion submission
- separate Dashboard and Analysis
- Bal completed count contribution to main overall completed analysis

### Wireframe support modules

- Announcements
- Family Time schedule and completion
- Karyalay Shared Content: Quote, Aagna, Sankalp, Vachan, Ashirwad, video, PDF, audio, image, external link and motivation
- content sharing using browser Web Share API / clipboard fallback
- Testimonials/Feedback moderation
- Guruji video/highlight entries and Motivation carousel
- Inventory/Stock Register with inward/outward ledger and no-negative-stock guard
- owner-scoped Sticky Notes
- Contact/Support request workflow
- Correction/Change Request workflow with scoped administrative review

## Technology

- PHP 8.4 / Laravel 13
- React 19 / Inertia 3 / TypeScript
- Tailwind CSS 4
- PostgreSQL 17
- Redis 8
- Nginx
- Docker Compose
- GitHub Actions CI

## Repository layout

```text
app/                  Laravel controllers, models, middleware, services
database/             migrations, seeders and factories
resources/js/         React/Inertia UI
routes/                web + scheduler/command routes
tests/                 unit/feature/production-hardening tests
docker/                Nginx, PHP config and entrypoint
scripts/               release check, backup and restore
docs/                  architecture, RTM, decisions, acceptance and deployment docs
examples/imports/      sample import templates
Dockerfile
docker-compose.yml
.env.example
```

## Local Docker start

1. Copy `.env.example` to `.env`.
2. Set `APP_KEY`, `APP_URL`, `DB_PASSWORD`, `SUPER_ADMIN_EMAIL` and a unique `SUPER_ADMIN_PASSWORD` of at least 16 characters.
3. Generate a Laravel key if needed:

```bash
printf 'base64:%s\n' "$(openssl rand -base64 32)"
```

4. Put that key in `.env` and start:

```bash
docker compose up -d --build
```

5. Route traffic to the `web` service port 80. The production `web` container runs both Nginx and PHP-FPM locally, removing cross-container FastCGI DNS as a failure mode. Check readiness at `/health/ready`.

## GitHub + Coolify deployment

The intended production workflow is:

```text
Extract final ZIP
  -> push source to a private GitHub repository
  -> wait for GitHub Actions CI
  -> create Docker Compose application in Coolify
  -> configure production environment variables
  -> attach domain to web:80
  -> deploy
  -> verify /health/ready
  -> execute final acceptance smoke tests
```

Use `docs/DEPLOYMENT_COOLIFY.md` for exact environment, volume, backup and upgrade instructions.

## Pilot/UAT data

`PILOT_DATA=true` enables a deterministic staging seeder with sample Zone/Center, users, Families, Karyakars, a valid 2-Karyakar/10-Family Group, visits, Bal data and support records. Keep `PILOT_DATA=false` in production.

## Verification

With dependencies installed, run:

```bash
scripts/release_check.sh
```

This executes PHP syntax validation, manifest/YAML checks, Laravel tests, TypeScript checking, Vite production build and Laravel route/config cache smoke checks.

GitHub CI also builds both Docker targets after tests pass.

## Backup / restore

```bash
scripts/backup.sh
CONFIRM_RESTORE=YES scripts/restore.sh backups/<timestamp>
```

Restore is destructive. Rehearse it in staging first. See `docs/BACKUP_RESTORE.md`.

## Important source decisions

The SRS contains several internal ambiguities (for example BN Karyalay permissions, Sanchalak context, Couple evidence, Group target quantity vs 10-Family capacity and Bal denominator). They are not silently rewritten; the implementation interpretation is documented in `docs/DECISIONS_PENDING.md`.

## Documentation index

Start with:

- `docs/FINAL_HANDOFF.md`
- `docs/V1_0_8_FAILURE_PREVENTION_AUDIT.md`
- `docs/V1_0_8_VALIDATION.md`
- `docs/FINAL_ACCEPTANCE_MATRIX.md`
- `docs/REQUIREMENT_TRACEABILITY.md`
- `docs/DEPLOYMENT_COOLIFY.md`
- `docs/OPERATIONS_RUNBOOK.md`
- `docs/BACKUP_RESTORE.md`
- `docs/BUSINESS_RULES.md`
- `docs/ROLE_PERMISSION_MATRIX.md`
- `docs/ARCHITECTURE.md`
- `docs/DECISIONS_PENDING.md`
- `docs/ROADMAP.md`

## Build-environment note

The offline environment used to assemble and audit this release did not provide a Composer binary, Docker CLI or external dependency-network access. The source was re-audited with PHP syntax checks, route/permission/static checks, TypeScript/TSX parser/transpile checks using the locally available TypeScript compiler with `--noCheck`, manifest/config parsing, pure-PHP domain checks and release-integrity verification. Real dependency installation, Laravel/PostgreSQL runtime tests, the actual Vite production build and Docker image/runtime validation remain configured in GitHub CI and must be green before production sign-off. See `docs/FULL_CODE_AUDIT.md`.

### Runtime health and self-healing

- `/up` - dependency-light Laravel liveness.
- `/health/live` - operator-facing application liveness.
- `/health/ready` - PostgreSQL, file cache, Redis read/write, schema, writable storage and free-disk readiness.
- Docker/Coolify health checks supervised PIDs plus direct Nginx, dedicated FPM ping and isolated Laravel liveness.
- Nginx/PHP-FPM single failures or live-but-unresponsive states are recovered inside the web container first. Repeated unrecoverable failure escalates to Docker `restart: always`.
- Queue worker and scheduler use persistent in-container supervisors so child recycling/transient crashes do not stop their Compose services.
- Web sessions/cache are file based, so a temporary Redis queue outage does not intentionally take down normal portal browsing.

For private GitHub repositories, grant Coolify repository access through a GitHub App or deploy key before redeploying.

