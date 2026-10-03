# Biawin CRM Integration

This repository is the clean development workspace for the Biawin CRM Integration project.

## Systems

### CRM رضایت‌سازی
The CRM where customer profiles are completed, declared amounts are recorded,
customer routes are selected, dissatisfaction is processed, deficiency is resolved,
refund/offset workflows are handled, and additional services are selected.

### CRM فروش بیاوین + عملیات و اجرا
The Biawin sales CRM and operations/execution system.

The API / Web Service connection between these systems is only one part of the
overall CRM architecture.

## Current baseline

Current development baseline:
Biawin CRM Integration v0.8.66.1

Phase 2 has been implemented and corrected.
Phase 3 has not started.

## Repository structure

- `plugin/` — current CRM plugin source
- `design/` — business and architecture documents
- `integration/` — Biawin integration documents
- `reports/` — phase and QA reports
- `review/` — pre-Git review material

## Important

Do not modify business architecture or production behaviour without reviewing
the approved design documents.

Do not introduce a second customer entity, second assignment engine,
second deficiency workflow, or parallel service/complaint engine without
explicit approval.

The Biawin integration must remain limited to the approved
«خدمات مازاد» boundary unless the business contract explicitly changes.
