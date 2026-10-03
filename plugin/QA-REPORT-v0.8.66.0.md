# QA REPORT — v0.8.66.0 — Master Phase 2: core customer journey alignment

Date: 2026-10-02. Baseline: 0.8.65.0. No schema change, no migration.
Design and conflict table: `design/phase-2/PHASE-2-JOURNEY-REPORT.md` (outside the plugin).

## Static
| Suite | Result |
|---|---|
| journey-v08660 (new) | 42/42 |
| organization-v08650 | 69/69 |
| after-sales-config-v08640 / foundation-v08630 / inquiry-hardening-v08641 / final-hardening-v08632 | 30/30 · 41/0 · 20/20 · 16/16 |
| receipt-upload harness | 609/0 (SQLite shim gains the production `assignments` table) |
| refund-regression | 141/0 |
| v08599, v08598, v08594, v08593 (version pins bumped), v08571, b1 | 0 failures |
| v08597 3, v08592 2, v08595 3, b21 2, b22 4, reporting-foundation 6, b23 3, v08591 2 | unchanged pre-existing baseline (25) |
| PHP 8.1 lint | 67 files, 0 errors |
| `node --check` | 3 files, 0 errors |

## Real WordPress (`tests/update-simulation-v08660/run-p2journey.sh`, 0.8.65.0 → 0.8.66.0): 135/135

The baseline included production-like legacy data:
- L1: خدمات مازاد with service cases;
- L2: خدمات مازاد without service cases;
- L3: reached the route through the legacy complaint outcome;
- L4: INCOMPLETE profile with an old route;
- L5: historical COMPLETED_AFTER_DEFICIENCY;
- L6: legacy `journey_state=refund`;
- L7: complete only thanks to the tolerance.

| Step | Result |
|---|---|
| Update: 20 business tables, activity count, ledgers, tolerance, roles | 22/22 identical |
| Forced migration re-run | 22/22 identical |
| A complete → open, three routes, actor rule | ✔ |
| B incomplete: locked UI; AJAX ×3, services, complaint and REST all refused; nothing written | ✔ |
| C direct deficiency completion | ✔ See below. |
| C2 same agent reassigned | the old interaction is not reused (new row) |
| D deficiency → finance → completion | same hand-off; finance optional |
| E new contact | route locked before it, unlocked after it; supervisor still cannot select |
| F بدون خدمات | archive and list filter; no complaint, service case, refund or boundary; assignment untouched |
| G ناراضی | bare click writes nothing; the complaint form hands off to after-sales; the general agent has no resolution form; the after-sales agent cannot select routes |
| H خدمات مازاد | a failed selection leaves no route (R-07); two cases; boundary NOT_SENT; hook fired once; **0 HTTP requests** |
| I reconciliation | L1–L3 identified and counted; 0 duplicates; no Biawin data; legacy not pushed. D-01 dry-run counts L7; nothing transitioned. |
| J legacy | L5 not retro-locked; L4 keeps its value but is locked; L6 labelled; outcome semantics unchanged |
| K known pre-existing defect D-09 reproduced (INFO, unchanged) | ✔ |
| Phase 1 roles/units on the same site | 24/24 |
| debug.log | 0 plugin lines |

Detail of test C (direct deficiency completion):
- same customer_id and invoices;
- tag applied;
- the previous assignment is `returned`, with the same activity and audit;
- no automatic route;
- «منتظر تخصیص»: agent, supervisor and deficiency agent cannot select;
- the supervisor card lists the customer and assigns through the existing handler;
- completion is durable across recalculation.

Previous phase suite against this build (`run-p21.sh`, 0.8.64.0 → 0.8.66.0): 63 pass. The only failures are its 4 strict snapshot checks (roles/units/ledgers), which reflect the intended Phase 1 additions.

## UX verification
I rendered the real `?bcrm_customer=` HTML for four states and checked the structure:

| State | Result |
|---|---|
| Incomplete | route, services and complaint form locked; «رفع ناقصی» message; steps ناقص / مغایرت / در جریان |
| Awaiting allocation | badges «تکمیل‌شده پس از رفع ناقصی» + «منتظر تخصیص»; everything locked |
| New contact required | badge «نیازمند تماس جدید»; locked |
| Open | exactly ناراضی / بدون خدمات / خدمات مازاد; services and complaint form available |

## Known pre-existing defects (reported, not changed: business decision needed)
- **D-09:** after «تکمیل با مبلغ اصلاح‌شده», `recalculate_customer()` re-reads the declared amount from the last interaction. The profile becomes incomplete again and a new deficiency case is created.
- **D-10:** «تأیید مالی با فاکتور» counts the invoice twice, so the automatic completion does not fire. Deficiency completion still works afterwards.
