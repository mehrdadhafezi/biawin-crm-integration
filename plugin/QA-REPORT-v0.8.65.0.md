# QA REPORT — v0.8.65.0 — Master Phase 1: organization / units / roles / permissions

Date: 2026-10-02. Baseline: 0.8.64.1. Scope: organization only.

Not changed:
- customer journey;
- complaint workflow (the only edit is the shared supervisor-role helper, which keeps the rule identical);
- refunds;
- treasury operations;
- Biawin / external API;
- customer profile UI.

## Changes
- **Roles:** 4 final roles, created once through the ledger:
  - `bcrm_dissatisfaction_supervisor`
  - `bcrm_after_sales_agent`
  - `bcrm_it_supervisor`
  - `bcrm_it_agent`
- **Capabilities:**
  - New: `view_it_inquiries`, `respond_it_inquiries`.
  - These go to administrator and portal admin; the after-sales manager gets `view_it_inquiries` only.
  - Caps marker 5 → 6.
- **Legacy roles:** `bcrm_complaint_supervisor` and `bcrm_complaint_agent` keep their capabilities. Their label becomes «(قدیمی)» only if it is still the default.
- **Supervisor checks:** the four `in_array('bcrm_complaint_supervisor', …)` checks now go through `Biawin_CRM_Auth::is_dissatisfaction_supervisor()`. It accepts the final role and the legacy role, nothing else.
- **`bcrm_supervisor`:** unchanged, on existing and fresh installs. The reduction is "Deferred to Phase 3 permission migration" (pre-approval correction).
- **IT boundary:** the IT caps are inquiry-specific only. The after-sales manager has `view_it_inquiries` (view IT inquiry results of after-sales cases) and no IT unit, member, user, assignment or admin capability.
- **Units:**
  - `it_engineering` «مهندسین IT»: top-level, type `it`, inquiry-enabled, portal response, inserted once.
  - The fresh-install seed of `treasury` is now top-level; an existing treasury unit is never moved.
  - Unit type label `it` added.
- **Migration `0.8.65.0`:** no schema change, no user, no membership, and no business data touched.

## Tests

### Static
| Suite | Result |
|---|---|
| organization-v08650 (new) | 63/63 |
| after-sales-config-v08640 | 30/30 (caps-marker pin relaxed to `>= 5`) |
| after-sales-foundation-v08630 | 41/0 |
| inquiry-hardening-v08641 | 20/20 |
| final-hardening-v08632 | 16/16 |
| receipt-upload harness | 609/0 |
| refund-regression | 141/0 |
| v08599, v08598, v08594, v08593 (version pins bumped), v08571, b1 | 0 failures |
| v08597 3, v08592 2, v08595 3, b21 2, b22 4, reporting-foundation 6, b23 3, v08591 2 | unchanged pre-existing baseline (25) |
| PHP 8.1 lint | 36 plugin files + 3 test files, 0 errors |

### Real WordPress (`tests/update-simulation-v08650/run-p1org.sh`): 133/133

The baseline was a production-like 0.8.64.1 site with these admin changes in place:
- users and a deleted user;
- a role deleted by an admin (`bcrm_loan_agent`);
- a capability removed by an admin (complaint supervisor `assign_dissatisfaction`);
- a custom legacy-role label;
- treasury under after-sales;
- memberships;
- complaints and inquiries;
- refunds and deficiency rows.

| Step | Result |
|---|---|
| Update 0.8.64.1 → 0.8.65.0 (`verify`) | 37/37. Users and 16 business tables are identical, as are memberships, the service catalog, seed ledgers and admin config, and every existing unit byte-for-byte. Exactly one unit was added (IT). No role or capability was removed. Additions are exactly the IT caps on administrator / portal admin / manager. The deleted role is not recreated and the removed cap is not re-granted. `bcrm_supervisor` is unchanged. Exactly 4 roles were created, with exact caps. The legacy default label is marked; the custom label is kept. |
| Forced migration re-run from 0.8.64.1 | 24/24 identical (no duplicate unit, role or member) |
| Forced migration re-run from 0.8.62.28 | 24/24 identical |
| Functional (real admin handlers) | 32/32. See below. |
| Fresh install 0.8.65.0 | 16/16: final roles; legacy roles present; general supervisor without dissatisfaction caps; 7 units; treasury + IT top-level; no users or memberships created |
| debug.log | 0 plugin lines |

The functional tests covered:
- users created per role in «کاربران پرتال»;
- IT unit: supervisor + 2 agents; the inquiry provider resolves to the IT agents;
- after-sales: manager heads `after_sales`, the dissatisfaction supervisor + 2 agents are in `dissatisfaction`;
- treasury: supervisor + agent;
- least-privilege `user_can` checks;
- complaint management rule identical for the legacy and final supervisor;
- the agent rule is unchanged;
- IT users have no complaint access;
- a deleted final role is not recreated, and a removed cap is not re-granted.

### Previous phase suite against the new build (`run-p21.sh`, 0.8.64.0 → 0.8.65.0)
- 63 pass.
- The only failures are its 4 strict "nothing changed" snapshot checks (roles, units, units_new, ledgers). A snapshot diff shows the difference is exactly the intended additions: unit `it_engineering`, the 4 roles, the IT caps and the «(قدیمی)» labels.
- No existing unit, role or capability changed.

## Notes
- `can_manage_complaint($id, $user_id)` evaluates `bcrm_manage_cases` on the current user, not on `$user_id`. This is pre-existing behaviour. Real requests always pass the current user, so there is no production impact; tests must act as the user under test. Left for Phase 3 (complaint workflow).
