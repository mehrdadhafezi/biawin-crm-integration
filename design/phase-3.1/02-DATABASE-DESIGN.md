# Phase 3.1 — سند ۲: طراحی دیتابیس

**Baseline:** v0.8.64.1 · **وضعیت:** ✅ Approved — بازبینی ۵ (FINAL BUSINESS ARCHITECTURE LOCK) · پیشوند: `{wp_prefix}bcrm_` · همه تغییرات **additive**

اصول:
- ستون جدید روی جداول موجود: `NULL` یا `DEFAULT` امن؛ هیچ ردیف موجود بازنویسی معنایی نمی‌شود مگر Backfillهای صریح §6.
- `DATETIME` برای رویداد، `DATE` برای تاریخ برنامه‌ریزی (میلادی در DB، شمسی در UI).
- مبالغ `DECIMAL(18,2)`.
- هر جدول جدید هم در `Activator::activate()` (نصب تازه) و هم در Migration فاز خود (`dbDelta` + strict check).
- Optimistic lock: `version INT UNSIGNED NOT NULL DEFAULT 0` روی موجودیت‌های چندنفره.
- **مفاهیم جدا، ستون‌های جدا ([۰۱ §0.1](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)):** وضعیت پروفایل (`customers.profile_completeness`: کامل/ناقص؛ `pending` فقط مقدار فنی پیش از ثبت مبلغ اعلامی)؛ رفع ناقصی (`deficiency_cases`/`case_actions`)؛ مسیر مشتری (`customers.journey_state`: `complaint`=ناراضی، `no_request`=بدون خدمات، `service_request`=خدمات مازاد)؛ نتیجه نارضایتی (`complaints.resolution_outcome`)؛ وضعیت پرونده نارضایتی (`complaints.state`). هیچ ستون جدیدی هیچ‌یک از مفاهیم Core را تکرار نمی‌کند.
- **Protected Core ([۰۱ §0](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)):** هیچ جدول فاز ۳ موجودیت مشتری یا فاکتور دوم نمی‌سازد؛ همه با `customer_id` → `customers.id` و `invoice_id` → `invoices.id` ارجاع می‌دهند. فاز ۳ هیچ ستون/ردیف `customers` (شناسه، پروفایل، مسیر، اعتبار)، `invoices` (مالکیت، مبلغ)، `invoice_phones`، `phones`، `assignments`، `customer_tags`، `interactions` (به‌جز ستون additive `complaint_id`)، `deficiency_cases`، `case_actions`، `financial_cases`، `service_catalog`، `service_cases` (به‌جز ستون پیوند additive §5)، `refund_registers` یا `refunds` را بازنویسی نمی‌کند.

### 0. Reuse ساختارهای Core (بدون موجودیت جایگزین)

| نیاز Journey | ساختار موجود (reuse) | فاز ۳ چه اضافه می‌کند |
|---|---|---|
| پروفایل مشتری و شناسه ثابت | `customers.id` | هیچ؛ فقط FK `customer_id` در جداول نارضایتی |
| فاکتورها و مالکیت | `invoices.customer_id`، `invoices.amount_paid`، `customers.total_paid` | هیچ؛ فقط FK `invoice_id` در Allocation عودت |
| مبلغ اعلامی و تماس | `interactions.declared_paid`، `customers.declared_paid` | ستون additive `interactions.complaint_id` |
| وضعیت پروفایل | `customers.profile_completeness`، `customers.discrepancy_amount` | هیچ |
| رفع ناقصی و تأیید مالی اختیاری | `deficiency_cases` (`current_department` = `deficiency`/`finance`، `status`، `completed_at`)، `case_actions` | هیچ |
| سابقه «تکمیل‌شده پس از رفع ناقصی» | `customer_tags` + `customers.completion_source` + `deficiency_cases` (بند بعد) | هیچ |
| تخصیص توسط سرپرست | `assignments` (`agent_user_id`، `status` ∈ `assigned`/`returned`، `assigned_by`، `assigned_at`، `completed_at`) + Guard «یک تخصیص فعال برای هر مشتری» | هیچ (CJA-2 فقط از وضعیت موجود `returned` استفاده می‌کند) |
| مسیر مشتری | `customers.journey_state` (+ تاریخچه `activities.journey_changed`) | هیچ؛ **هیچ جدول مسیر مشتری جدید** |
| بایگانی بدون خدمات | `journey_state='no_request'` + نتیجه تماس | هیچ (CJA-5 = نمای/فیلتر روی داده موجود) |
| خدمات مازاد | `service_catalog` (منبع حقیقت)، `service_cases`؛ مشتریان موجود این مسیر: `journey_state='service_request'` + `service_cases` | هیچ؛ مرز بیرونی بدون جدول (۰۱ §0.5) — نگاشت اشتراک/خدمت/کارت Biawin و تطبیق مشتریان موجود در قرارداد یکپارچگی (AMB-10) |
| تهاتر | همان `service_catalog` / `service_cases` | فقط ستون additive `service_cases.complaint_action_id` (§5)؛ **هیچ موتور تهاتر** |

**نمایش «تکمیل‌شده پس از رفع ناقصی» (`COMPLETED_AFTER_DEFICIENCY`) با معماری موجود تگ/تاریخچه:**
- ردیف `customer_tags`: `customer_id`، `tag='COMPLETED_AFTER_DEFICIENCY'`، `case_id` (پرونده رفع ناقصی)، `created_by`، `created_at` — همان تگ موجود که امروز `Deficiency_Workflow::tag_customer()` در مسیرهای تکمیل ثبت می‌کند (کنار `DEFICIENCY_RESOLVED` و `FINANCE_RECONCILED`). **تگ تکراری یا مفهوم جدید ساخته نمی‌شود.**
- `customer_tags` کلید یکتای (`customer_id`, `tag`) دارد: برای مشتری‌ای که بیش از یک بار رفع ناقصی را طی کند، تگ یک ردیف می‌ماند و تاریخچه هر دور در `deficiency_cases` (`completed_at`) و `case_actions` حفظ می‌شود.
- `customers.completion_source` (`DEFICIENCY_WORKFLOW` / `FINANCE_WORKFLOW` در برابر `NORMAL`) نشانه تکمیلی موجود است.
- فاصله فعلی (تگ در همه مسیرهای تکمیل ثبت نمی‌شود): [۰۱ §0.7 G4](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md) → CJA-3 (بدون تغییر schema).

---

## 1. روابط

```text
customers 1─N complaints 1─N complaint_inquiries ─N:1 service_units
                 ├─1:N complaint_follow_ups
                 ├─1:N complaint_actions 1─N complaint_inquiries (action_id, برای requires_inquiry)
                 ├─1:N complaint_state_log
                 ├─1:N refund_plans 1─N refund_items 1─N refund_allocations N:1 invoices
                 └─N:1 service_units (assigned_unit_id)
catalog_items(complaint_subject) 1─N complaints.subject_id
catalog_items(complaint_outcome) 1─1 complaint_outcome_policies ; complaints.resolution_outcome = item_key
interactions.complaint_id ─N:1 complaints   (فقط تماس‌های جدید)
attachments(object_type, object_id) → complaint | complaint_inquiry | refund_item
service_cases.complaint_action_id ─N:1 complaint_actions   (تهاتر/خدمات: پیوند به فرایند موجود؛ §5)
activities(object_type='complaint'…)      (Timeline)
```

---

## 2. جداول موجود — توسعه، و جداول جدید سطح پرونده

### 2.1 `complaints`

| ستون جدید | نوع | توضیح |
|---|---|---|
| `state` | `VARCHAR(40) NULL` | State Machine (۰۳ §2). NULL فقط برای ردیف Backfill‌نشده |
| `state_changed_at` | `DATETIME NULL` | |
| `decision_round` | `INT UNSIGNED NOT NULL DEFAULT 1` | با هر بازگشت از Follow-up، Reopen یا تغییر تصمیم +۱ |
| `assigned_unit_id` | `BIGINT UNSIGNED NULL` | → `service_units.id` |
| `assigned_supervisor_id` | `BIGINT UNSIGNED NULL` | از سرپرستان همان واحد |
| `assigned_agent_id` | `BIGINT UNSIGNED NULL` | عضو `agent` همان واحد |
| `assigned_at` | `DATETIME NULL` | |
| `created_by` | `BIGINT UNSIGNED NULL` | تاریخچه NULL (O-8) |
| `updated_at` | `DATETIME NULL` | |
| `outcome_selected_at` / `outcome_selected_by` | `DATETIME NULL` / `BIGINT UNSIGNED NULL` | Outcome در `resolution_outcome` (key) |
| `resolved_at` / `resolved_by` | | `resolved_by` = بازیگر رویدادی که RESOLVED خودکار را رقم زد |
| `closed_by` | `BIGINT UNSIGNED NULL` | `closed_at` موجود فقط هنگام CLOSED |
| `reopen_count` | `INT UNSIGNED NOT NULL DEFAULT 0` | |
| `version` | `INT UNSIGNED NOT NULL DEFAULT 0` | |

Reuse بدون تغییر: `subject`, `subject_id`, `description`, `priority`, `related_unit`, `needs_manager_call`, `due_at`, `resolution` (= Resolution Data), `resolution_outcome`, `status` (Compatibility), `owner_user_id` (Compatibility).

Compatibility:
- `status = 'closed'` ⟺ `state ∈ {RESOLVED, CLOSED}`؛ در غیر این صورت `'open'` — فقط State Machine می‌نویسد.
- `owner_user_id = assigned_agent_id` در هر Assignment.
- `customers.journey_state='complaint'` سابقه تصمیم مسیریابی Core است و فقط توسط `set_customer_journey()` موجود نوشته می‌شود (۰۱ §4.3)؛ هیچ ستون Complaint آن را تکرار یا جایگزین نمی‌کند.

ایندکس: `state_unit (state, assigned_unit_id)` · `agent_state (assigned_agent_id, state)` · `customer_state (customer_id, state)` · `state_changed (state, state_changed_at)`.

### 2.2 `complaint_inquiries`

| ستون جدید | نوع | توضیح |
|---|---|---|
| `action_id` | `BIGINT UNSIGNED NULL` | اگر استعلام برای اکشن `INQUIRY` (Outcome با `requires_inquiry`) ساخته شده |
| `decision_round` | `INT UNSIGNED NOT NULL DEFAULT 1` | |
| `assigned_responder_id` | `BIGINT UNSIGNED NULL` | Claim‌کننده |
| `claimed_at` | `DATETIME NULL` | |
| `closed_at` / `closed_by` | | |
| `close_reason` | `VARCHAR(40) NULL` | `acknowledged` · `withdrawn` · `discarded_draft` · `unit_unavailable` |
| `version` | `INT UNSIGNED NOT NULL DEFAULT 0` | |

Reuse: `request_note` = Question (هر دو Mode)، `result_text` = Answer، `responded_by/at` = Recorded/Answered By/At، `contacted_at`، `notes`، `unit_name_snapshot`، `mode`. `status` طبق ۰۳ §3؛ Backfill D7 (§6.3). شواهد: `attachments.object_type='complaint_inquiry'`.
ایندکس جدید: `responder_status (assigned_responder_id, status)` · `complaint_round (complaint_id, decision_round)` · `action (action_id)`.

### 2.3 `complaint_state_log` (جدید، append-only)

| ستون | نوع |
|---|---|
| `id` | PK |
| `complaint_id` | `BIGINT UNSIGNED NOT NULL` |
| `from_state` / `to_state` | `VARCHAR(40) NULL` / `VARCHAR(40) NOT NULL` |
| `trigger` | `VARCHAR(40) NOT NULL` — `user` · `system:inquiry` · `system:action` · `system:outcome` · `migration` |
| `actor_user_id` | `BIGINT UNSIGNED NULL` |
| `reason` | `VARCHAR(255) NULL` |
| `created_at` | `DATETIME NOT NULL` |

ایندکس: `complaint_time`, `to_state_time`. هر Transition: یک ردیف اینجا (KPI زمان‌در‌وضعیت) + یک رویداد `activities` (Timeline).

### 2.4 `interactions`
`complaint_id BIGINT UNSIGNED NULL` + ایندکس — فقط تماس‌های جدید ثبت‌شده از Workspace. D15: هیچ Backfill.

### 2.5 `attachments` — بدون تغییر schema
`object_type` جدید: `complaint` · `complaint_inquiry` · `refund_item`.

### 2.6 `complaint_follow_ups` (جدید — O-5)

| ستون | نوع | توضیح |
|---|---|---|
| `id` | PK | |
| `complaint_id` | `BIGINT UNSIGNED NOT NULL` | |
| `decision_round` | `INT UNSIGNED NOT NULL` | |
| `follow_up_at` | `DATETIME NOT NULL` | تاریخ/ساعت پیگیری (قابل گزارش) |
| `responsible_user_id` | `BIGINT UNSIGNED NOT NULL` | مسئول پیگیری |
| `reason` | `VARCHAR(255) NOT NULL` | |
| `status` | `VARCHAR(20) NOT NULL` | `SCHEDULED` · `STARTED` · `CANCELLED` |
| `started_at` / `started_by` | | زمان بازگشت به INVESTIGATING |
| `cancelled_at` / `cancelled_by` / `cancel_reason` | | زمان‌بندی مجدد (`rescheduled`) |
| `created_by` / `created_at` / `updated_at` | | |

ایندکس: `due (status, follow_up_at)` (گزارش «سررسید/عقب‌افتاده») · `responsible (responsible_user_id, status)` · `complaint (complaint_id)`.
Invariant: حداکثر یک ردیف `SCHEDULED` برای هر پرونده. پیگیری متعلق به **همان پرونده** است: هیچ Complaint جدید و هیچ تغییر `journey_state` با پیگیری ایجاد نمی‌شود.

---

## 3. Outcome Policy (D5 + O-10)

### 3.1 `complaint_outcome_policies` (جدید، ۱:۱ با `catalog_items` از نوع `complaint_outcome`)

| ستون | نوع | معنا |
|---|---|---|
| `outcome_item_id` | `BIGINT UNSIGNED NOT NULL` UNIQUE | → `catalog_items.id` |
| `requires_refund` | `TINYINT(1) NOT NULL DEFAULT 0` | اکشن‌های `REFUND_PLAN` → `REFUND_APPROVAL` → `TREASURY_PAYMENT` |
| `requires_finance` | `TINYINT(1) NOT NULL DEFAULT 0` | اکشن `FINANCE_VERIFICATION` |
| `requires_service` | `TINYINT(1) NOT NULL DEFAULT 0` | «انجام خدمات»: اکشن `SERVICE_FOLLOWTHROUGH` — پیگیری تحقق `service_cases` **موجود** مشتری؛ بدون انتخاب/ساخت خدمت (AMB-3) |
| `requires_follow_up` | `TINYINT(1) NOT NULL DEFAULT 0` | → `FOLLOW_UP_PENDING` (بدون Action؛ Resolve نمی‌کند) |
| `requires_inquiry` | `TINYINT(1) NOT NULL DEFAULT 0` | اکشن `INQUIRY` (استعلام پس از تصمیم) |
| `requires_offset` | `TINYINT(1) NOT NULL DEFAULT 0` | «تهاتر» = استفاده از **زیرساخت موجود خدمات** به‌جای عودت نقدی: اکشن `SERVICE_OFFSET` → «واحد تهاتر» → `create_service_cases()` مشترک، **بدون** تغییر مسیر مشتری. با مسیر «خدمات مازاد» یکی نیست ([۰۱ §0.3](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)) |
| `required_inquiry_unit_id` | `BIGINT UNSIGNED NULL` | اختیاری: استعلام لازم باید به این واحد باشد |
| `created_at` / `updated_at` / `updated_by` | | |

Validation پیکربندی (Admin، 3.2):
- `requires_follow_up = 1` ⇒ همه Flagهای دیگر ۰ (پیگیری نتیجه نهایی نیست).
- `requires_refund` و `requires_offset` هم‌زمان مجاز نیستند (تهاتر = به‌جای عودت نقدی).
- `required_inquiry_unit_id` فقط وقتی `requires_inquiry = 1`؛ واحد باید `can_receive_inquiry = 1` باشد.
- Outcome بدون ردیف Policy = همه Flagها ۰.

### 3.2 Seed (insert-only، Ledger `biawin_crm_outcome_policy_seed_ledger`)

| Outcome (key / label فعلی) | refund | finance | service | follow_up | inquiry | offset |
|---|---|---|---|---|---|---|
| `satisfied` / مشتری راضی شد (موجود) | 0 | 0 | 0 | 0 | 0 | 0 |
| `service_request` / تبدیل به درخواست خدمت (کلید **Outcome** موجود — نه مسیر مشتری «خدمات مازاد») = **تهاتر** (D5b) | 0 | 0 | 0 | 0 | 0 | 1 |
| `refund` / درخواست عودت وجه (موجود) | 1 | 1 | 0 | 0 | 0 | 0 |
| `follow_up` / نیاز به پیگیری مجدد (**جدید** — O-5) | 0 | 0 | 0 | 1 | 0 | 0 |

Outcome جدید `follow_up` در `catalog_items` با Ledger فاز ۲ (`biawin_crm_catalog_seed_ledger`) و `is_system=1` درج می‌شود؛ Label Outcomeهای موجود بازنویسی نمی‌شود (Admin می‌تواند Label `service_request` را «تهاتر» کند). **هیچ Outcome جداگانه `offset` ساخته نمی‌شود**: کلید Outcome موجود `service_request` (در کاتالوگ نتایج نارضایتی، نه مسیر مشتری) همان «تهاتر» است (D5b). «انجام خدمات»، «عدم احراز نارضایتی»، «ارجاع» از Admin ساخته می‌شوند.

---

## 4. Action، Refund و Treasury

### 4.1 `complaint_actions`

| ستون | نوع | توضیح |
|---|---|---|
| `id` | PK | |
| `complaint_id` | `BIGINT UNSIGNED NOT NULL` | |
| `action_type` | `VARCHAR(40) NOT NULL` | `INQUIRY` · `FINANCE_VERIFICATION` · `REFUND_PLAN` · `REFUND_APPROVAL` · `TREASURY_PAYMENT` · `SERVICE_OFFSET` · `SERVICE_FOLLOWTHROUGH` |
| `outcome_key` | `VARCHAR(80) NOT NULL` | Snapshot |
| `decision_round` | `INT UNSIGNED NOT NULL` | |
| `status` | `VARCHAR(20) NOT NULL` | PENDING · IN_PROGRESS · COMPLETED · CANCELLED · BLOCKED |
| `is_mandatory` | `TINYINT(1) NOT NULL DEFAULT 1` | |
| `sequence` | `INT NOT NULL DEFAULT 0` | |
| `depends_on_action_id` | `BIGINT UNSIGNED NULL` | |
| `assigned_unit_id` / `assigned_user_id` | `BIGINT UNSIGNED NULL` | |
| `result_json` | `LONGTEXT NULL` | مثلاً مبلغ تأییدشده مالی، شناسه `service_cases` انتخاب‌شده |
| `blocked_reason` / `cancel_reason` | `VARCHAR(255) NULL` | |
| `created_by`, `created_at`, `started_at`, `completed_at`, `completed_by`, `cancelled_at`, `cancelled_by`, `updated_at`, `version` | | |

ایندکس: `complaint_round` · `type_status` · `unit_status`.

### 4.2 `refund_plans`
`id` · `complaint_id` · `customer_id` · `action_id` · `revision INT` · `status` (DRAFT · SUBMITTED · DECIDED · COMPLETED · REJECTED · CANCELLED) · `total_amount DECIMAL(18,2)` (Cache) · `note` · `created_by/at` · `submitted_by/at` · `decided_by/at` · `updated_at` · `version`.

### 4.3 `refund_items` (Refund Item قرارداد)

| ستون | نوع | توضیح |
|---|---|---|
| `id` | PK | |
| `plan_id`, `complaint_id`, `customer_id` | `BIGINT UNSIGNED NOT NULL` | |
| `sequence` | `INT NOT NULL` | |
| `amount` | `DECIMAL(18,2) NOT NULL` | > 0 |
| `proposed_date` | `DATE NOT NULL` | کارشناس |
| `approved_date` | `DATE NULL` | مدیر (برابر یا جایگزین) |
| `final_payment_date` | `DATE NULL` | تاریخ قطعی ارسال‌شده به خزانه |
| `status` | `VARCHAR(30) NOT NULL` | PROPOSED · APPROVED · REJECTED · RESCHEDULED · SENT_TO_TREASURY · PAID |
| `decision_note` | `VARCHAR(255) NULL` | |
| `approved_by/at`, `sent_to_treasury_by/at` | | |
| `paid_at` / `paid_by` | | زمان واقعی پرداخت |
| `payment_reference` | `VARCHAR(120) NULL` | UNIQUE (`plan_id`, `payment_reference`) |
| `capacity_override_id` | `BIGINT UNSIGNED NULL` | |
| `created_at`, `updated_at`, `version` | | |

ایندکس: `treasury_queue (status, final_payment_date)` · `approved_date (approved_date, status)` · `complaint`.

### 4.4 `refund_allocations` (Invoice-level)
`id` · `item_id` · `invoice_id` (→ `invoices.id`) · `invoice_code_snapshot VARCHAR(80) NOT NULL` (کلید تطبیق با Excel ledger) · `customer_id` · `amount DECIMAL(18,2) > 0` · `created_at`.
UNIQUE `item_invoice (item_id, invoice_id)` · ایندکس `invoice (invoice_id)`.
Invariant (در تراکنش): `Σ allocations = item.amount`؛ فاکتور متعلق به همان مشتری؛ `amount ≤ remaining_refundable(invoice)` (§7.3). پرداخت روی Item؛ Allocation تعلق پرداخت به فاکتور را مشخص می‌کند.

### 4.5 Treasury (فاز ۳.۶)
- `treasury_capacity_rules`: `id` · `rule_type` (`DEFAULT`·`WEEKDAY`) · `weekday TINYINT NULL` (۰=شنبه) · `daily_amount` · `effective_from DATE` · `effective_to DATE NULL` · `created_by/at` · `updated_at`. ظرفیت روز: Exception ← WEEKDAY ← DEFAULT.
- `treasury_calendar_exceptions`: `id` · `date DATE` UNIQUE · `capacity_amount NULL` (NULL = تعطیل) · `reason` · `created_by/at`.
- `treasury_capacity_overrides`: `id` · `item_id` · `date` · `requested_amount` · `capacity_at_decision` · `scheduled_at_decision` · `approved_by` · `reason NOT NULL` · `created_at`.
- Scheduled روز = Σ `refund_items.amount` با `status ∈ {APPROVED, RESCHEDULED, SENT_TO_TREASURY}` و `COALESCE(final_payment_date, approved_date) = day`.

---

## 5. تهاتر و خدمات — بدون جدول یا Ledger جدید (D11 / O-10)

تهاتر یک **نتیجه نارضایتی** است (نه مسیر «خدمات مازاد») که از **زیرساخت موجود خدمات** استفاده می‌کند؛ رکورد آن همان `service_cases` موجود است ([۰۱ §4.2](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)):

| تغییر | نوع | توضیح |
|---|---|---|
| `service_cases.complaint_action_id` | `BIGINT UNSIGNED NULL` + ایندکس | **تنها** تغییر additive روی جدول خدمات: پیوند پرونده خدمت (ساخته‌شده توسط تابع مشترک `create_service_cases()` — منطق ساخت پرونده خدمت `select_services()` بدون نوشتن مسیر — برای اکشن `SERVICE_OFFSET`، یا پرونده موجود پیوندشده به اکشن `SERVICE_FOLLOWTHROUGH`) به اکشن نارضایتی. ردیف‌های موجود NULL می‌مانند. |

- **هیچ** جدول `complaint_offsets`، کاتالوگ خدمت، محاسبه اعتبار یا سازوکار انتخاب خدمت جدید.
- دامنه نارضایتی `customers.service_credit_*` را نمی‌خواند/نمی‌نویسد؛ محاسبه اعتبار Core (`sync_customer()`) بدون تغییر می‌ماند (اثر بر پرونده‌های تهاتر: AMB-5).
- **هیچ** نوشتن `journey_state` در تهاتر: مسیر مشتری «ناراضی» می‌ماند. `select_services()` (مسیر «خدمات مازاد») رفتار امروزش را عیناً حفظ می‌کند (`create_service_cases()` + `journey_state='service_request'`).
- کاتالوگ خدمات (`service_catalog`) تنها منبع حقیقت خدمات است، هم برای «خدمات مازاد»، هم برای «تهاتر»، هم برای مرز یکپارچگی بیرونی آینده ([۰۱ §0.5](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)).
- پرونده‌های خدمت تهاتر در چرخه **داخلی** خدمات می‌مانند؛ تهاتر خارج از یکپارچگی خدمات مازاد است و هرگونه استفاده آتی از Biawin فقط با تصمیم تأییدشده جدا (AMB-9). هیچ جدول جدیدی برای آن.
- تهاتر در هیچ KPI عودت نقدی نیست (`Cash Refund ≠ Offset`)؛ گزارش تهاتر از `service_cases` با `complaint_action_id` غیرخالی.

---

## 6. Migrationها و Backfillها (اجرا فقط در فاز مربوطه)

همه: idempotent، retry-safe، strict check (throw → `db_version` جلو نمی‌رود، خطا لاگ، قفل آزاد، تلاش مجدد در درخواست بعد)، بدون DELETE/DROP، بدون تغییر User/Role/Service/Unit/داده مشتری.

### 6.1 `0.8.65.0` — فاز ۳.۲
1. `CREATE TABLE complaint_outcome_policies` + strict check.
2. Seed Outcome `follow_up` در `catalog_items` (insert-only، Ledger کاتالوگ، اگر Key یا Label مشابه وجود دارد درج نمی‌شود). Outcome `offset` ساخته نمی‌شود (D5b).
3. Seed Policy چهار Outcome جدول §3.2 (insert-only، فقط اگر ردیف Policy وجود ندارد).
4. Seed موضوعات D6 (insert-only، Ledger کاتالوگ):

| key | label | sort |
|---|---|---|
| `service_delay` | تأخیر در ارائه خدمات | 10 |
| `financial_issue` | مشکل مالی | 20 |
| `contract_issue` | مشکل قرارداد | 30 |
| `service_not_received` | عدم دریافت خدمت | 40 |
| `amount_mismatch` | مغایرت مبلغ | 50 |
| `loan_issue` | مشکل وام | 60 |
| `product_issue` | مشکل محصول | 70 |
| `other` | سایر | 80 |

   Key موجود (فعال یا غیرفعال) → هیچ کاری؛ ثبت در Ledger و حذف‌شده → ساخته نمی‌شود؛ Label تکراری با Key دیگر → درج نمی‌شود.
5. Capabilityهای جدید (`respond_unit_inquiries`, `override_treasury_capacity`) — Ledger additive، `CAPS_MARKER_VERSION` 5 → 6.
6. Optionها (فقط اگر وجود ندارند): `biawin_crm_complaint_intake_unit_id` = id واحد `dissatisfaction` (در صورت وجود)، `biawin_crm_complaint_engine = 'legacy'`، `biawin_crm_offset_unit_id` = خالی (واحد تهاتر توسط Admin تعیین می‌شود؛ هیچ واحدی ساخته نمی‌شود).

### 6.2 `0.8.66.0` — فاز ۳.۳
1. ستون‌ها/ایندکس‌های §2.1، §2.4؛ جداول `complaint_state_log`، `complaint_follow_ups`، `complaint_actions`.
2. Backfill `state` فقط `WHERE state IS NULL`: `open → NEW`، `closed → CLOSED`؛ مقدار دیگر → NULL + گزارش. برای هر ردیف یک `complaint_state_log` با `trigger='migration'`.
3. `assigned_*` تاریخی NULL؛ `owner_user_id`، `closed_at`، `resolution`، `resolution_outcome` دست نمی‌خورند. Preflight: شمارش پرونده‌های باز با `owner_user_id NOT NULL` (گزارش، بدون تفسیر).
4. هیچ RESOLVED، Action، Follow-up یا Plan تاریخی.

### 6.3 `0.8.67.0` — فاز ۳.۴
1. ستون‌های §2.2.
2. D7: `UPDATE … SET status='REQUESTED' WHERE status='pending'`؛ `… 'MANUAL_RECORDED' WHERE status='recorded'`؛ ناشناخته‌ها گزارش. ثابت‌های کد در همان Release.

### 6.4 `0.8.68.0` — فاز ۳.۵
`refund_plans`، `refund_items`، `refund_allocations`؛ `service_cases.complaint_action_id BIGINT UNSIGNED NULL` + ایندکس (§5). بدون Backfill (`refunds` قدیمی به Plan تبدیل نمی‌شود؛ `service_cases` موجود پیوند نمی‌گیرند).

### 6.5 `0.8.69.0` — فاز ۳.۶
`treasury_capacity_rules`، `treasury_calendar_exceptions`، `treasury_capacity_overrides`.

---

## 7. Refund Ledger Rule و عدم Double Counting

### 7.1 سه منبع جدا

| # | منبع | ماهیت | پس از فاز ۳ |
|---|---|---|---|
| 1 | `refund_registers` (`source_type='excel'` / `'used'`) | **Legacy / Excel snapshot ledger خارجی**؛ برای هر `(customer_id, invoice_code)` فقط آخرین ردیف (`MAX(id)`) معتبر است (جایگزینی، نه جمع) | بدون تغییر: «عودتی‌ها»، تگ `REFUND_CONFIRMED`، `confirmed_total()`، `balance_for()`. **CRM هیچ‌وقت در آن نمی‌نویسد.** |
| 2 | `refunds` | پرونده عودت legacy در سطح Complaint | فقط در Engine `legacy` ساخته می‌شود؛ پس از Cutover ردیف جدید ندارد؛ ردیف‌های باز در کارتابل «عودت وجه» تا بسته شدن قابل مدیریت |
| 3 | `refund_items` + `refund_allocations` | Pipeline عملیاتی CRM | منبع KPIهای برنامه‌ریزی/تأیید/خزانه/پرداخت CRM |

### 7.2 قواعد
0. Refund روی `customers`/`invoices` موجود عمل می‌کند و هرگز مالکیت فاکتور (`invoices.customer_id`)، `amount_paid`، `exclude_from_total`، `customers.total_paid`، `declared_paid`، `profile_completeness`، تجمیع پروفایل یا رکوردهای رفع ناقصی را تغییر نمی‌دهد. `R(i)` فقط محاسبه خواندنی است.
1. هیچ Query/KPI این سه منبع را با هم **SUM** نمی‌کند (Static guard + تست Reporting در 3.7).
2. گزارش‌ها هر منبع را در ستون/کارت جدا نشان می‌دهند؛ `refunds` با برچسب «legacy».
3. Offset در هیچ KPI عودت نقدی نیست.
4. پرداخت CRM تگ `REFUND_CONFIRMED` را اعمال نمی‌کند (تگ متعلق به Excel ledger است).

### 7.3 محاسبه در سطح فاکتور (Reconciliation و Validation)

برای فاکتور `i` با `invoice_code = k` و مشتری `c`:

| مقدار | تعریف |
|---|---|
| **Invoice amount** `A(i)` | `0` اگر `invoices.exclude_from_total = 1`، وگرنه `invoices.amount_paid` |
| **Latest Excel refund snapshot** `X(i)` | `refund_amount` ردیف `MAX(id)` در `refund_registers` با `customer_id=c`، `invoice_code=k`، `source_type='excel'`؛ اگر نیست `0` |
| **CRM paid refund** `P(i)` | `Σ refund_allocations.amount` با `invoice_id = i` و `refund_items.status = 'PAID'` |
| **Open CRM refund commitments** `O(i)` | `Σ refund_allocations.amount` با `invoice_id = i` و `refund_items.status ∈ {PROPOSED, APPROVED, RESCHEDULED, SENT_TO_TREASURY}` |
| **Remaining refundable** `R(i)` | `max(0, A(i) − max(X(i), P(i)) − O(i))` |

- `max(X, P)` به‌جای `X + P`: اگر Excel ledger پرداخت‌های CRM را هم منعکس کند، دوبار کسر نمی‌شود؛ اگر منعکس نکند، کسر حداقلی است. **هرگز `X + P`.**
- `REJECTED` در `O` نیست؛ `PAID` فقط در `P`.
- ردیف‌های `source_type='used'` (سطح مشتری، بدون فاکتور) در `R(i)` نیستند؛ فقط هشدار سطح مشتری.
- Allocation با `amount > R(i)` → خطای `ALLOCATION_EXCEEDS_REFUNDABLE`.

وضعیت Reconciliation هر فاکتور (گزارش 3.7):

| وضعیت | شرط |
|---|---|
| `NONE` | `X = 0` و `P = 0` |
| `LEDGER_ONLY` | `X > 0` و `P = 0` |
| `CRM_ONLY` | `X = 0` و `P > 0` |
| `MATCHED` | `X > 0`، `P > 0`، `X = P` |
| `MISMATCH` | `X > 0`، `P > 0`، `X ≠ P` |

مثال: `A=10M`، Excel آخرین ردیف `X=3M`، CRM پرداخت‌شده `P=5M`، تعهد باز `O=1M` → `R = 10 − max(3,5) − 1 = 4M`، وضعیت `MISMATCH` (برای بررسی واحد مالی). مجموع «عودت‌شده» در گزارش هرگز `8M` نیست: دو ستون جدا `3M` (Excel) و `5M` (CRM).
