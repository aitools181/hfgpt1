# Phase 6 Handoff - Wireframe Support Modules

Phase 6 completes the support/engagement screens shown in the uploaded Full Portal Wireframe while preserving the SRS role and Center scope model.

## Delivered

- Announcements with global/Center scope, audience, publish/expiry and draft/published/archive state.
- Family Time schedule plus idempotent user completion history.
- Shared Content for Quote, Aagna, Sankalp, Vachan, Ashirwad, video, PDF, audio, image, external link and motivation entries.
- Web Share API / clipboard sharing action for published content.
- Testimonials/Feedback submission plus administrative publish/reject review.
- Motivation carousel and video/highlight content presentation.
- Inventory/Stock Register with Center scope, current stock, minimum stock and transaction history.
- Transactional inward/outward stock handling with row locking; outward stock cannot become negative.
- Owner-only Sticky Notes.
- Contact/Support requests with open/in-progress/resolved/closed lifecycle.
- New support-module changes integrated with Activity/Audit logging where administrative changes occur.

## Access model

View/submit permissions are available to relevant portal roles; management permissions remain role-driven. Global records are visible to permitted users while Center-scoped records are restricted through the same organizational-scope service used by the core portal.

## Test coverage

`tests/Feature/Phase6SupportModulesTest.php` covers announcement scope, Family Time completion idempotence, shared-content management authorization, inventory stock guards/audit, Sticky Note owner isolation, Support request handling and testimonial review.
