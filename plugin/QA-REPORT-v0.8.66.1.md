# QA REPORT — v0.8.66.1 — Phase 2 correction pass

Date: 2026-10-03. Baseline: 0.8.66.0. No schema change, no migration.
Details: `design/phase-2/PHASE-2-CORRECTION-REPORT.md` (outside the plugin).

## Static
| Suite | Result |
|---|---|
| journey-correction-v08661 (new) | 22/22 |
| journey-v08660 | 42/42 (3 assertions updated to the corrected rules) |
| organization-v08650 | 69/69 |
| after-sales-config / foundation / inquiry-hardening / final-hardening | 30/30 · 41/0 · 20/20 · 16/16 |
| receipt-upload harness | 609/0 |
| refund-regression | 141/0 |
| v08599, v08598, v08594, v08593 (version pins bumped), v08571, b1 | 0 failures |
| v08597 3, v08592 2, v08595 3, b21 2, b22 4, reporting-foundation 6, b23 3, v08591 2 | unchanged pre-existing baseline (25) |
| PHP 8.1 lint | 69 files, 0 errors |
| `node --check` | 0 errors |

## Real WordPress (`tests/update-simulation-v08661/run-p2correction.sh`): 193/193
| Step | Result |
|---|---|
| Update 0.8.65.0 → 0.8.66.1 + forced re-run | 22/22 + 22/22 identical |
| Phase 2 functional A–K | 68/68. D: finance with invoice now auto-completes, counted once. K: D-09 durable. |
| Correction functional | 30/30. See below. |
| D-01 on the existing install | 9/9: rule NOT switched on upgrade; tolerance customer L7 still complete. Simulated approved switch: nothing changes until recalculation; then L7 → incomplete + one deficiency case; invoices, interactions and route kept. 100 Toman mismatch → incomplete; equal → complete; preview uses the same rule. |
| Phase 1 roles/units | 24/24 |
| Fresh install | correction 2/2 (rule = zero; 500 Toman mismatch → incomplete) + organization 16/16 |
| debug.log | 0 plugin lines |

The 30 correction checks:
- **D-07:** manager refused (AJAX route and services), `bcrm_manage_cases` untouched.
- **D-08:** open case locks the route even when the calculation shows complete, until genuine completion → supervisor pool → reassignment.
- **New contact:** «عدم پاسخ» and «درخواست تماس مجدد» do not unlock; «تماس موفق» does.
- **D-09:** corrected amount stable across recalculations; interactions and invoices untouched; a newer customer statement supersedes it.
- **D-10:**
  - normal invoice;
  - with invoice (auto-complete, total once, reconciled = invoice total, FINANCE_RECONCILED);
  - repeated processing refused, with no second invoice;
  - with receipt;
  - multiple invoices;
  - partial confirmation → back to deficiency → completion durable.
- **Archive:** isolation as it stands today (no pool entry; workload limitation reported).
- **No HTTP.**

Previous phase suite against this build (`run-p21.sh`): 63 pass. The only failures are its 4 strict snapshot checks, which reflect the Phase 1 additions.

## Reported, not changed (decision required)
- **D-01-T:** switching existing installs to the zero rule (lazy transition) needs approval with production numbers.
- **D-05:** archive representation, to remove archived customers from active workload. Changes the reporting/assignment engine.
- **D-11:** finance approval without an invoice is not an invoice, so the profile reverts on recalculation (finance architecture).
