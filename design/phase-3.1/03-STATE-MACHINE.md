# Phase 3.1 — سند ۳: State Machine

**Baseline:** v0.8.64.1 · **وضعیت:** ✅ Approved — بازبینی ۵ (FINAL BUSINESS ARCHITECTURE LOCK) · Decision Log: [۰۱ §1](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)

پنج ماشین **مستقل** (قاعده ۵ و ۶ قرارداد — ادغام نمی‌شوند):

| ماشین | ستون | ۰۲ |
|---|---|---|
| Complaint | `complaints.state` | §2.1 |
| Follow-up | `complaint_follow_ups.status` | §2.6 |
| Inquiry (Portal / Manual) | `complaint_inquiries.status` | §2.2 |
| Action | `complaint_actions.status` | §4.1 |
| Refund Plan / Refund Item | `refund_plans.status` / `refund_items.status` | §4.2–4.3 |

همه ماشین‌ها **پایین‌دست** Customer Journey نهایی هستند ([۰۱ §0](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)) و فقط از مسیر مشتری «ناراضی» آغاز می‌شوند. **وضعیت پروفایل** (کامل/ناقص) و **مسیر مشتری** (ناراضی/خدمات مازاد/بدون خدمات) مالک Core هستند و هیچ‌کدام در این سند ماشین یا وضعیت ندارند؛ «ورودی‌های جدید» نارضایتی با «تخصیص توسط سرپرست» مشتری (Core) یکی نیست. تهاتر ماشین جداگانه ندارد؛ رکورد آن `service_cases` موجود است.

Outcome **وضعیت نیست**؛ انتخاب Outcome رویدادی است که بر اساس Policy (۰۲ §3) Follow-up یا Actionها را می‌سازد.

---

## 0. مرزها — مدل‌های Core فقط برای ارجاع (مالک: CRM Core، نه فاز ۳)

این بخش مدل‌های **موجود** Core را برای روشن شدن مرز توصیف می‌کند؛ فاز ۳ هیچ‌یک از آن‌ها را پیاده، تغییر یا مالکیت نمی‌کند. فاصله کد فعلی با این مدل‌ها در [۰۱ §0.7](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md) (G1–G11) و Track CJA آمده است.

**وضعیت پروفایل (`profile_completeness`) — فقط دو وضعیت کسب‌وکاری:**
```text
(پیش از ثبت مبلغ اعلامی: مقدار فنی pending — وضعیت کسب‌وکاری نیست)
ثبت مبلغ اعلامی → مغایرت = 0 → کامل
                → مغایرت ≠ 0 → ناقص → (رفع ناقصی) → کامل
```

**رفع ناقصی (`deficiency`) — گردش‌کار جدا:**
```text
ناقص → بررسی توسط کارشناس رفع ناقصی ─┬─ حل مستقیم ─────────────────────────┐
                                      └─ (اختیاری) تأیید مالی ⇄ رفع ناقصی ──┤
                                                                         ↓
                              مغایرت برطرف → کامل → تگ COMPLETED_AFTER_DEFICIENCY → صف تخصیص سرپرست
```

**مسیر مشتری (`journey_state`) — تصمیم Core، نه ماشین وضعیت فاز ۳:**
```text
پروفایل کامل (+ اگر از رفع ناقصی آمده: تخصیص سرپرست + تماس جدید)
   → کارشناس تماس دقیقاً یکی را انتخاب می‌کند: ناراضی (complaint) | بدون خدمات (no_request) | خدمات مازاد (service_request)
ناراضی       → شروع پرونده نارضایتی (T1، §2)
بدون خدمات  → بایگانی بدون خدمات (پایان چرخه فعال؛ هیچ Transition بعدی)
خدمات مازاد → انتخاب خدمات → مرز API / Web Service → سامانه Biawin   (تنها مسیر یکپارچگی؛ هیچ ماشین فاز ۳ وارد آن نمی‌شود)
```

**قواعد مرزی:**
- پرونده نارضایتی **فقط** از مسیر «ناراضی» آغاز می‌شود (T1).
- نتیجه نارضایتی (`resolution_outcome`: عودت، تهاتر، …) هیچ‌وقت مسیر مشتری نیست؛ **هیچ Transition در این سند تهاتر را مسیر مشتری می‌کند**.
- **هیچ Transition از هیچ ماشین این سند به مسیریابی مشتری برنمی‌گردد** و هیچ‌کدام `journey_state`، `profile_completeness`، رفع ناقصی یا تخصیص تماس (`assignments`) را تغییر نمی‌دهد.

---

## 1. اصول اجرایی

1. **یک نقطه ورود:** Transitionها فقط از `Biawin_CRM_Complaint_State_Machine` و سرویس‌های هر ماشین؛ هیچ `UPDATE` مستقیم ستون وضعیت در کد دیگر (Static guard).
2. **اتمی:** تراکنش + `SELECT … FOR UPDATE` روی ریشه + بررسی `version` + Guard + نوشتن + `version+1` + `complaint_state_log` + رویداد `activities`.
3. **Guard شکست‌خورده** → هیچ تغییر؛ خطای کددار (۰۴ §2.5).
4. **Transitionهای سیستمی (Projection):** هر رویداد Inquiry/Action در **همان تراکنش** وضعیت Complaint را بازمحاسبه می‌کند (§2.3، §2.4).
5. **وضعیت‌های سیستمی دستی قابل تنظیم نیستند:** `WAITING_INQUIRIES`، `WAITING_INQUIRY_RESPONSES`، `INQUIRIES_COMPLETED`، `ACTION_PENDING`، `FINANCE_PENDING`، `REFUND_APPROVAL_PENDING`، `TREASURY_PENDING` و **`RESOLVED` (O-1)** هیچ Endpoint/دکمه مستقیم ندارند.
6. **Timeline تاریخچه است نه منبع وضعیت** (قرارداد §12).
7. Hook پس از COMMIT: `do_action('bcrm_complaint_state_changed', $id, $from, $to, $context)` (بدون منطق کسب‌وکار در Listener).

---

## 2. Complaint

### 2.1 وضعیت‌ها

| State | معنا | نوع |
|---|---|---|
| `NEW` | ثبت شده؛ کارشناس ندارد | کاربری |
| `ASSIGNED` | کارشناس واحد تعیین شده | کاربری |
| `INVESTIGATING` | در حال بررسی | کاربری |
| `WAITING_INQUIRIES` | ≥۱ استعلام `DRAFT`، هیچ استعلام در جریان | سیستمی |
| `WAITING_INQUIRY_RESPONSES` | ≥۱ استعلام پرتالی `REQUESTED`/`IN_PROGRESS` | سیستمی |
| `INQUIRIES_COMPLETED` | همه استعلام‌های round پایان‌یافته | سیستمی |
| `DECISION_PENDING` | منتظر انتخاب Outcome | کاربری |
| `FOLLOW_UP_PENDING` | Outcome «نیاز به پیگیری مجدد»؛ پیگیری زمان‌بندی‌شده | کاربری (از Outcome) |
| `ACTION_PENDING` | اکشن فعال mandatory از انواع عمومی (`INQUIRY`، `REFUND_PLAN`، `SERVICE_OFFSET`، `SERVICE_FOLLOWTHROUGH`) | سیستمی |
| `FINANCE_PENDING` | اکشن فعال `FINANCE_VERIFICATION` | سیستمی |
| `REFUND_APPROVAL_PENDING` | اکشن فعال `REFUND_APPROVAL` | سیستمی |
| `TREASURY_PENDING` | اکشن فعال `TREASURY_PAYMENT` | سیستمی |
| `RESOLVED` | همه Actionهای mandatory و استعلام‌های لازم Outcome پایان‌یافته | **فقط سیستمی (O-1)** |
| `CLOSED` | مختومه | کاربری (O-2) |
| `REOPENED` | بازگشایی؛ منتظر شروع بررسی مجدد | کاربری |

### 2.2 Transitionها

| # | From | To | Trigger | بازیگر (۰۵ §2) | Guard | اثر جانبی |
|---|---|---|---|---|---|---|
| T1 | — | `NEW` | مسیر مشتری «ناراضی» (پنل مسیر یا فرم ثبت نارضایتی در پروفایل) | کاربر با `bcrm_work_cases` و دسترسی به مشتری | (۱) `set_customer_journey(customer,'complaint')` **Core** موفق (پس از CJA: فقط پروفایل کامل و برای مشتریان برگشتی از رفع ناقصی فقط پس از تماس جدید — [۰۱ §0.7](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md))؛ (۲) **R-1:** `profile_completeness='complete'` (وگرنه `PROFILE_NOT_COMPLETE` — ابتدا رفع ناقصی)؛ (۳) موضوع فعال (اگر کاتالوگ فعال)، شرح | `assigned_unit_id = intake`؛ `created_by`؛ `complaint_created`. دامنه نارضایتی خودش هیچ ستونی از `customers` نمی‌نویسد؛ `journey_state='complaint'` فقط حاصل تابع موجود مسیریابی است ([۰۱ §4.3](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)) |
| T2 | `NEW` | `ASSIGNED` | تخصیص | سرپرست واحد پرونده / `assign_dissatisfaction` سازمانی | واحد فعال؛ کارشناس ∈ A(unit) فعال؛ سرپرست ∈ S(unit) | `assigned_*`، `owner_user_id`؛ `complaint_assigned` |
| T3 | `ASSIGNED` | `INVESTIGATING` | «شروع بررسی» یا اولین کار کارشناس (ثبت تماس/استعلام) | کارشناس مسئول | — | `investigation_started` |
| T4 | `REOPENED` | `INVESTIGATING` | «شروع بررسی مجدد» | کارشناس مسئول یا سرپرست | کارشناس مسئول هنوز ∈ A(unit) (وگرنه ابتدا T13) | `decision_round += 1` |
| T5 | گروه بررسی | گروه بررسی | **سیستمی** — رویداد Inquiry | — | Projection §2.3 | |
| T6 | `INVESTIGATING`, `INQUIRIES_COMPLETED` | `DECISION_PENDING` | «آماده تصمیم» | کارشناس مسئول یا سرپرست | هیچ استعلام round جاری در `DRAFT`/`REQUESTED`/`IN_PROGRESS` | |
| T7 | `DECISION_PENDING` | `INVESTIGATING` | «بازگشت به بررسی» | کارشناس مسئول یا سرپرست | دلیل | |
| T8 | `DECISION_PENDING` | `FOLLOW_UP_PENDING` / گروه اقدام / `RESOLVED` | **انتخاب Outcome** | کارشناس مسئول یا سرپرست | Outcome فعال؛ همه Flagهای Policy در نسخه نصب‌شده پشتیبانی می‌شوند (§6)؛ اگر `requires_follow_up`: `follow_up_at` آینده، مسئول، دلیل | `resolution_outcome`، `outcome_selected_*`؛ سپس §2.5 (Follow-up) یا تولید Actionها (§4.2) و Projection §2.4؛ `outcome_selected` |
| T9 | گروه اقدام | گروه اقدام / `RESOLVED` | **سیستمی** — رویداد Action | — | Projection §2.4 | در RESOLVED: `resolved_at/by`؛ `complaint_resolved` |
| T10 | گروه اقدام | `DECISION_PENDING` | «تغییر تصمیم» | سرپرست واحد / مدیر خدمات پس از فروش | هیچ Action `COMPLETED` در round جاری؛ هیچ Item `APPROVED`/`RESCHEDULED`/`SENT_TO_TREASURY`/`PAID`؛ هیچ `service_cases` پیوندشده به اکشن `SERVICE_OFFSET` (تصمیم خدمت ثبت‌شده در فرایند موجود برگشت داده نمی‌شود) | Actionهای باز → `CANCELLED`؛ Planهای DRAFT/SUBMITTED → `CANCELLED`؛ Outcome به Timeline و خالی؛ `decision_round += 1` |
| T11 | `RESOLVED` | `CLOSED` | «بستن پرونده» (صریح؛ بدون Auto-close) | **مدیر خدمات پس از فروش یا سرپرست نارضایتی** (O-2) | — | `closed_at/by`؛ `complaint_closed` |
| T12 | `CLOSED` | `REOPENED` | «بازگشایی» | مدیر خدمات پس از فروش یا سرپرست نارضایتی | دلیل | `reopen_count += 1`؛ `complaint_reopened` |
| T13 | هر وضعیت به‌جز `CLOSED` | همان (یا `NEW → ASSIGNED`) | تخصیص مجدد واحد/کارشناس | سرپرست واحد / `assign_dissatisfaction` سازمانی | Guard T2 | `complaint_reassigned` |
| T14 | `FOLLOW_UP_PENDING` | `INVESTIGATING` | «شروع پیگیری» | مسئول پیگیری یا سرپرست | — (شروع پیش از `follow_up_at` مجاز و ثبت می‌شود) | Follow-up → `STARTED`؛ Outcome به Timeline و خالی؛ `decision_round += 1`؛ `follow_up_started` |
| T15 | `FOLLOW_UP_PENDING` | همان | «زمان‌بندی مجدد پیگیری» | مسئول پیگیری یا سرپرست | `follow_up_at` آینده، دلیل | ردیف قبلی `CANCELLED`، ردیف `SCHEDULED` جدید؛ `follow_up_rescheduled` |

**ممنوع:** هر Transition دیگر؛ به‌ویژه هر Transition دستی به `RESOLVED`، `RESOLVED → INVESTIGATING`، `CLOSED → INVESTIGATING` بدون `REOPENED`، و Auto-close.

### 2.3 Projection گروه بررسی (T5)
فقط وقتی `state ∈ {INVESTIGATING, WAITING_INQUIRIES, WAITING_INQUIRY_RESPONSES, INQUIRIES_COMPLETED}`؛ فقط استعلام‌های round جاری **بدون** `action_id`:
```text
if count(status ∈ {REQUESTED, IN_PROGRESS}) > 0   → WAITING_INQUIRY_RESPONSES
elif count(status = DRAFT) > 0                     → WAITING_INQUIRIES
elif count(status ∈ {ANSWERED, MANUAL_RECORDED, CLOSED}) > 0 → INQUIRIES_COMPLETED
else                                               → INVESTIGATING
```

### 2.4 Projection گروه اقدام و RESOLVED خودکار (T8/T9 — O-1)
Action **فعال** = mandatory، round جاری، وضعیت ∈ {`PENDING`, `IN_PROGRESS`, `BLOCKED`}، و `depends_on_action_id` خالی یا `COMPLETED`.
```text
if any active FINANCE_VERIFICATION   → FINANCE_PENDING
elif any active REFUND_APPROVAL      → REFUND_APPROVAL_PENDING
elif any active TREASURY_PAYMENT     → TREASURY_PENDING
elif any non-terminal mandatory action (هر نوع، فعال یا منتظر وابستگی) → ACTION_PENDING
else                                 → RESOLVED  (resolved_by = بازیگر آخرین رویداد)
```
- «استعلام‌های لازم Outcome» = اکشن `INQUIRY` (Flag `requires_inquiry`)؛ تا تکمیل آن RESOLVED ممکن نیست.
- `BLOCKED` وضعیت Complaint نیست (Badge «مسدود»).
- `CANCELLED` پایان‌یافته است؛ لغو اکشن mandatory فقط سرپرست/مدیر با دلیل.
- Outcome بدون Flag (مثل `satisfied`) → مستقیم `RESOLVED` (قرارداد §2 «مشتری راضی»).
- پرداخت یک Refund Item هرگز Complaint را RESOLVED نمی‌کند؛ فقط تکمیل `TREASURY_PAYMENT` (همه Itemهای غیر REJECTED پرداخت‌شده — قاعده ۷).

### 2.5 Follow-up (O-5)
پرونده حل‌نشده می‌ماند → مشتری پیگیری دیگری لازم دارد → پیگیری زمان‌بندی‌شده → **همان پرونده** ادامه می‌یابد (همان `id`، round بعدی). هیچ Complaint جدید، هیچ تغییر `journey_state` و هیچ مسیر جدید مشتری.

- T8 با Outcome دارای `requires_follow_up=1` → ایجاد ردیف `complaint_follow_ups` (`SCHEDULED`؛ `follow_up_at`، `responsible_user_id` ∈ A(unit) ∪ S(unit)، `reason`) + رویداد `follow_up_scheduled` → `FOLLOW_UP_PENDING`. هیچ Action ساخته نمی‌شود.
- T14 → `INVESTIGATING` (round جدید؛ استعلام‌ها و تصمیم جدید).
- سررسید گذشته (`follow_up_at < now` و `SCHEDULED`) وضعیت نیست؛ در تب و گزارش «عقب‌افتاده» محاسبه می‌شود. بدون Cron/انتقال خودکار.
- تنها خروج از `FOLLOW_UP_PENDING` T14 است (پرونده در این وضعیت RESOLVED یا CLOSED نمی‌شود). T15 ردیف قبلی را `CANCELLED` (`cancel_reason='rescheduled'`) و ردیف `SCHEDULED` جدید می‌سازد تا تاریخچه سررسیدها قابل گزارش بماند.

### 2.6 Tabها (View — بدون Status جدید)

| Tab | Filter |
|---|---|
| همه | Visibility (۰۵ §3) |
| ورودی‌های جدید | `NEW`, `ASSIGNED`, `REOPENED` (Badge «بازگشایی») |
| در حال بررسی | `INVESTIGATING` |
| منتظر استعلام | `WAITING_INQUIRIES` |
| منتظر پاسخ واحدها | `WAITING_INQUIRY_RESPONSES` **یا** (`ACTION_PENDING` با استعلام پرتالی باز متصل به اکشن `INQUIRY`) |
| در انتظار تصمیم | `INQUIRIES_COMPLETED`, `DECISION_PENDING` |
| پیگیری مجدد | `FOLLOW_UP_PENDING` (مرتب بر `follow_up_at`؛ Badge «عقب‌افتاده») |
| در انتظار اقدام | `ACTION_PENDING` |
| در انتظار تأیید مالی | `FINANCE_PENDING`, `REFUND_APPROVAL_PENDING` |
| در انتظار پرداخت | `TREASURY_PENDING` |
| تکمیل‌شده | `RESOLVED` |
| مختومه | `CLOSED` |

### 2.7 داده تاریخی (C1)
`open → NEW`، `closed → CLOSED` (`trigger='migration'`). هیچ RESOLVED، Action، Follow-up یا Plan تاریخی. `NEW`های تاریخی بدون واحد → تب «ورودی‌های جدید» برای سرپرستان Intake و مدیر (۰۵ §3 V4).

### 2.8 Adapter `resolve_complaint()` (C2)
در Engine `v3`:
1. `state ∈ {INVESTIGATING, INQUIRIES_COMPLETED}` → T6 ضمنی (با Guard).
2. `state ≠ DECISION_PENDING` → `INVALID_TRANSITION`.
3. T8 با `outcome` و `note` (`resolution`). Outcome با `requires_follow_up` از این مسیر پذیرفته نمی‌شود (نیازمند تاریخ/مسئول) → `GUARD_FAILED`.
4. نه `refunds` می‌سازد، نه Journey را تغییر می‌دهد، نه `CLOSED` می‌کند.
در Engine `legacy`: رفتار دقیق 0.8.64.1.

---

## 3. Inquiry

### 3.1 Portal

| # | From | To | بازیگر | Guard |
|---|---|---|---|---|
| I1 | — | `DRAFT` | کارشناس مسئول / سرپرست | پرونده در گروه بررسی، یا اکشن `INQUIRY` فعال (آنگاه `action_id` ثبت) |
| I2 | `DRAFT` | `REQUESTED` | همان | `unit_accepts_inquiries(unit)` و `requires_portal_response=1`؛ سؤال؛ ≥۱ پاسخ‌دهنده از `get_inquiry_responders()`؛ اگر برای اکشن با `required_inquiry_unit_id` → همان واحد |
| I3 | `REQUESTED` | `IN_PROGRESS` | پاسخ‌دهنده (D8) | واحد هنوز واجد شرایط |
| I4 | `IN_PROGRESS` | `REQUESTED` | Claim‌کننده یا سرپرست واحد مقصد | — |
| I5 | `REQUESTED`, `IN_PROGRESS` | `ANSWERED` | پاسخ‌دهنده (اگر IN_PROGRESS: Claim‌کننده یا سرپرست واحد مقصد) | پاسخ غیرخالی |
| I6 | `ANSWERED` | `CLOSED` | کارشناس مسئول | `close_reason='acknowledged'` |
| I7 | `REQUESTED`, `IN_PROGRESS` | `CLOSED` | کارشناس مسئول / سرپرست | دلیل؛ `withdrawn` |
| I8 | `DRAFT` | `CLOSED` | کارشناس مسئول | `discarded_draft` (حذف فیزیکی ندارد) |
| I9 | `REQUESTED`, `IN_PROGRESS` | `CLOSED` | سرپرست / مدیر | `unit_unavailable` (واحد غیرفعال یا استعلام خاموش؛ هشدار، نه خودکار) |

### 3.2 Manual

| # | From | To | بازیگر | Guard |
|---|---|---|---|---|
| M1 | — | `MANUAL_RECORDED` | کارشناس مسئول / سرپرست | `unit_accepts_inquiries(unit)`؛ واحد، سؤال، پاسخ، زمان تماس؛ پیوست اختیاری؛ اگر برای اکشن با `required_inquiry_unit_id` → همان واحد |
| M2 | `MANUAL_RECORDED` | `CLOSED` | کارشناس مسئول | — |

پاسخ‌دهندگان همیشه لحظه‌ای از `get_inquiry_responders()`؛ Mode پس از ساخت تغییرناپذیر. Backfill D7: `pending → REQUESTED`، `recorded → MANUAL_RECORDED`.

---

## 4. Action

### 4.1 Transitionها

| # | From | To | بازیگر | Guard |
|---|---|---|---|---|
| A1 | — | `PENDING` | سیستم (T8) | — |
| A2 | `PENDING` | `IN_PROGRESS` | مسئول اکشن (۰۵ §2) | وابستگی `COMPLETED` |
| A3 | `PENDING`, `IN_PROGRESS` | `BLOCKED` | مسئول اکشن / سرپرست | دلیل |
| A4 | `BLOCKED` | `IN_PROGRESS` | مسئول اکشن / سرپرست | — |
| A5 | `IN_PROGRESS` | `COMPLETED` | مسئول اکشن یا **سیستم** | معیار §4.3 |
| A6 | `PENDING`, `IN_PROGRESS`, `BLOCKED` | `CANCELLED` | سرپرست / مدیر، یا سیستم در T10 | دلیل |

### 4.2 تولید از Policy (T8) و ترتیب

| Flag | Action | مسئول | وابستگی |
|---|---|---|---|
| `requires_inquiry` | `INQUIRY` | کارشناس مسئول | — |
| `requires_finance` | `FINANCE_VERIFICATION` | واحد `unit_type='finance'` | `INQUIRY` (اگر هست) |
| `requires_refund` | `REFUND_PLAN` → `REFUND_APPROVAL` → `TREASURY_PAYMENT` | کارشناس → مدیر (با عضویت خزانه، O-12) → واحد خزانه | اولی به `FINANCE_VERIFICATION` یا `INQUIRY` (هر کدام آخر است) |
| `requires_offset` | `SERVICE_OFFSET` (تحویل به فرایند موجود خدمات/تهاتر) | «واحد تهاتر» (`biawin_crm_offset_unit_id`) | آخرین مورد از `INQUIRY`/`FINANCE_VERIFICATION` |
| `requires_service` | `SERVICE_FOLLOWTHROUGH` (پیگیری `service_cases` موجود) | کارشناس مسئول | آخرین مورد از `INQUIRY`/`FINANCE_VERIFICATION` |
| `requires_follow_up` | — (§2.5) | — | انحصاری |

### 4.3 معیار تکمیل

| Type | COMPLETED |
|---|---|
| `INQUIRY` | **سیستمی:** ≥۱ استعلام متصل در `ANSWERED`/`MANUAL_RECORDED`/`CLOSED(acknowledged)`، هیچ استعلام متصل باز، و اگر `required_inquiry_unit_id` تنظیم است ≥۱ استعلام تکمیل‌شده به همان واحد |
| `FINANCE_VERIFICATION` | پاسخ‌دهنده واحد مالی نتیجه + مبلغ تأییدشده را ثبت کند (`result_json`) |
| `REFUND_PLAN` | **سیستمی:** Plan `SUBMITTED` |
| `REFUND_APPROVAL` | **سیستمی:** Plan `DECIDED` (≥۱ Item تأییدشده). اگر Plan `REJECTED` → این اکشن باز می‌ماند و `REFUND_PLAN` به `IN_PROGRESS` برمی‌گردد (Revision جدید) |
| `TREASURY_PAYMENT` | **سیستمی:** همه Itemهای غیر `REJECTED` Plan جاری `PAID` |
| `SERVICE_OFFSET` | **سیستمی:** عضو «واحد تهاتر» با `create_service_cases()` مشترک ≥۱ خدمت فعال از `service_catalog` را برای مشتری انتخاب کرده و `service_cases` ساخته‌شده `complaint_action_id` این اکشن را دارند (§4.4) |
| `SERVICE_FOLLOWTHROUGH` | **سیستمی:** ≥۱ `service_cases` موجود مشتری پیوند شده و همه پیوندشده‌ها در وضعیت پایانی موجود (`completed`/`rejected`/`cancelled`) هستند (O-4) |

### 4.4 تهاتر روی زیرساخت موجود خدمات — بدون تغییر مسیر مشتری (O-3 / O-10)
```text
SERVICE_OFFSET (PENDING) ── صف «واحد تهاتر» (همان مشتری، همان پرونده نارضایتی به‌عنوان زمینه)
   → A2: عضو واحد تهاتر شروع می‌کند
   → Workflow_Service::create_service_cases(customer, service_ids, note)   ← تابع مشترک استخراج‌شده از select_services()
        (service_catalog فعال، Guard تکرار، credit_amount، service_cases با status=unassigned)
        **بدون** نوشتن journey_state — مسیر مشتری «ناراضی» می‌ماند
   → service_cases جدید: complaint_action_id = اکشن     (ثبت تصمیم خدمت)
   → A5 سیستمی: COMPLETED
```
- دامنه نارضایتی هیچ کاتالوگ، اعتبار، رکورد تهاتر یا Journey Rule اختصاصی ندارد و هیچ‌وقت مسیر مشتری را تغییر نمی‌دهد.
- «تهاتر» (نتیجه نارضایتی) ≠ «خدمات مازاد» (مسیر مشتری): فقط زیرساخت ساخت پرونده خدمت مشترک است؛ `select_services()` مسیر «خدمات مازاد» رفتار امروزش (شامل `journey_state='service_request'`) را عیناً حفظ می‌کند.
- `SERVICE_FOLLOWTHROUGH` هیچ خدمتی نمی‌سازد؛ فقط `service_cases` موجود مشتری را پیوند می‌دهد و پایان آن‌ها را از کارتابل خدمات موجود دنبال می‌کند.
- ساخت پرونده، انتخاب Outcome، Follow-up، Resolve و Close هیچ ستونی از `customers` را تغییر نمی‌دهند.

---

## 5. Refund

### 5.1 Plan

| From | To | Trigger |
|---|---|---|
| — | `DRAFT` | کارشناس (اکشن `REFUND_PLAN` فعال) |
| `DRAFT` | `SUBMITTED` | کارشناس — Guard: ≥۱ Item؛ Allocation کامل؛ `R(i)` (۰۲ §7.3) |
| `SUBMITTED` | `DECIDED` | سیستم: همه Itemها تصمیم دارند و ≥۱ تأیید |
| `SUBMITTED` | `REJECTED` | سیستم: همه Itemها رد |
| `DECIDED` | `COMPLETED` | سیستم: همه Itemهای تأییدشده `PAID` |
| `DRAFT`, `SUBMITTED` | `CANCELLED` | T10 یا سرپرست |

### 5.2 Item

| # | From | To | بازیگر (O-12: Capability **و** عضویت واحد خزانه) | Guard |
|---|---|---|---|---|
| R1 | — | `PROPOSED` | کارشناس | Plan `DRAFT`؛ `amount>0`؛ `proposed_date ≥ امروز` |
| R2 | `PROPOSED` | `APPROVED` | `approve_refund_schedule` + عضو خزانه | `approved_date = proposed_date`؛ ظرفیت §5.3 |
| R3 | `PROPOSED` | `RESCHEDULED` | همان | تاریخ جایگزین؛ ظرفیت روی تاریخ جدید |
| R4 | `PROPOSED` | `REJECTED` | همان | دلیل |
| R5 | `APPROVED`, `RESCHEDULED` | `SENT_TO_TREASURY` | همان | `final_payment_date = approved_date` |
| R6 | `SENT_TO_TREASURY` | `PAID` | `process_refund_payment` + عضو خزانه | `payment_reference` یکتا در Plan؛ `paid_at`؛ رسید اختیاری |
| R7 | `SENT_TO_TREASURY` | `RESCHEDULED` | `approve_refund_schedule` + عضو خزانه | تاریخ جدید + ظرفیت؛ سپس R5 |

`PAID`، `REJECTED` پایانی؛ بدون برگشت پرداخت در این فاز.

### 5.3 ظرفیت (R2/R3/R7)
```text
if scheduled(day) + item.amount > capacity(day):
    OVER_CAPACITY — مگر override_treasury_capacity + عضو خزانه + دلیل
    → treasury_capacity_overrides + هشدار Over-capacity
```

---

## 6. Feature Gate و Engine Switch (استقرار مرحله‌ای)

| Flag Policy | از نسخه پشتیبانی |
|---|---|
| (بدون Flag)، `requires_follow_up` | 0.8.66.0 (3.3) |
| `requires_inquiry` | 0.8.67.0 (3.4) |
| `requires_offset` (به‌علاوه تعیین «واحد تهاتر» فعال با عضو)، `requires_service` | 0.8.68.0 (3.5) |
| `requires_finance`، `requires_refund` | 0.8.69.0 (3.6) |

- T8 Outcomeی را که Flag پشتیبانی‌نشده دارد رد می‌کند (`OUTCOME_NOT_SUPPORTED`).
- Option `biawin_crm_complaint_engine`: `legacy` (پیش‌فرض) تا Cutover؛ در `legacy` همه رفتارهای 0.8.64.1 (از جمله ساخت `refunds` برای Outcome `refund`) حفظ می‌شود.
- Cutover به `v3` یک‌طرفه و فقط وقتی: هر Outcome فعال پشتیبانی می‌شود + چک‌لیست ۰۵ §7.

---

## 7. تهاتر — بدون ماشین جداگانه

تهاتر ماشین وضعیت مستقلی در دامنه نارضایتی ندارد: چرخه آن چرخه موجود `service_cases` (`unassigned` → … → `completed`/`rejected`/`cancelled`) در کارتابل **داخلی** خدمات است (خارج از یکپارچگی خدمات مازاد؛ استفاده آتی از Biawin فقط با تصمیم جدا، AMB-9) و دامنه نارضایتی فقط از طریق اکشن `SERVICE_OFFSET` و پیوند `complaint_action_id` آن را می‌بیند (D11). تهاتر مسیر مشتری نیست و `journey_state` را تغییر نمی‌دهد.

---

## 8. Invariantها

1. `status` = Projection `state`.
2. هیچ پرونده `RESOLVED` با اکشن mandatory غیرپایانی در round جاری؛ هیچ پرونده در گروه اقدام بدون اکشن غیرپایانی.
3. `RESOLVED` فقط با `trigger='system:action'` یا `'system:outcome'` در `complaint_state_log`.
4. `FOLLOW_UP_PENDING` ⟺ دقیقاً یک Follow-up `SCHEDULED`.
5. `RESOLVED`/`CLOSED` ⇒ `resolution_outcome` غیرخالی، به‌جز `CLOSED` تاریخی (C1).
6. Inquiry `ANSWERED` ⇒ `result_text`، `responded_by/at` غیرخالی.
7. Item `PAID` ⇒ `payment_reference`، `paid_at` و `Σ allocations = amount`.
8. هر Transition پرونده ⇒ یک ردیف `complaint_state_log` و یک رویداد Timeline.
9. دامنه نارضایتی هیچ ستونی از `customers`، `invoices`، `deficiency_cases`، `financial_cases` یا `service_catalog` را نمی‌نویسد؛ `journey_state` (مسیر مشتری) فقط توسط Core تغییر می‌کند: `set_customer_journey()` و — فقط در مسیر «خدمات مازاد» — `select_services()`. هیچ رویداد نارضایتی (Outcome، تهاتر، عودت، پیگیری، Resolve، Close، Reopen) آن را تغییر نمی‌دهد.
10. هیچ Complaint برای پروفایل غیر `complete` ساخته نمی‌شود (R-1)؛ هیچ Complaint خارج از مسیر مشتری «ناراضی».
11. Follow-up هرگز Complaint جدید نمی‌سازد.
12. `customers.id` پرونده در کل چرخه ثابت است؛ هیچ رکورد مشتری یا فاکتوری ساخته نمی‌شود.
