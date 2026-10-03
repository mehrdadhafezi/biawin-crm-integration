# PHASE-3.1-ARCHITECTURE-FINAL — Complaint Domain

**Project:** Biawin CRM · **Baseline:** v0.8.64.1 · **Status:** 🔒 FINAL BUSINESS ARCHITECTURE LOCK — بازبینی ۵ (قرارداد کسب‌وکاری قفل‌شده پیش از هر پیاده‌سازی فاز ۳، 2026-10-02)
**قرارداد کسب‌وکاری:** PHASE-3-COMPLAINT-DOMAIN-CONTRACT.md · **پیاده‌سازی:** شروع نشده (قاعده ۱۴)

| # | سند تفصیلی | موضوع |
|---|---|---|
| 01 | [Audit و Mapping](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md) | **§0 Customer Journey نهایی**: مفاهیم جدا، Journey و قواعد J1–J18، مسیر مشتری در برابر نتیجه نارضایتی، رفع ناقصی → کامل → تخصیص مجدد، مرز خدمات مازاد، مرز نارضایتی، CURRENT/TARGET/GAP/PHASE، CJA-1…7، تفسیرهای ممنوع |
| 02 | [دیتابیس](02-DATABASE-DESIGN.md) | Reuse ساختارهای Core، نمایش `COMPLETED_AFTER_DEFICIENCY`، جداول نارضایتی، Migrationها، Refund Ledger Rule |
| 03 | [State Machine](03-STATE-MACHINE.md) | مرزهای Core (ارجاعی)، ماشین‌های پایین‌دست مسیر ناراضی |
| 04 | [API Contract](04-API-CONTRACT.md) | Endpointهای موجود Core (ارجاع)، Domain Services نارضایتی، مرز یکپارچگی خدمات مازاد |
| 05 | [Permission Matrix](05-PERMISSION-MATRIX.md) | مجوزهای Core Journey، ماتریس نارضایتی، Visibility، خزانه، Cutover |
| — | [README](README.md) | فهرست و اصطلاحات |

در تعارض بین این خلاصه و اسناد تفصیلی، اسناد تفصیلی معتبرند؛ در تعارض هر سندی با **§1**، §1 معتبر است.

---

## 1. AUTHORITATIVE CUSTOMER JOURNEY — FINAL

**نسخه مرجع قفل‌شده:**
```text
Customer enters CRM → Initial contact → Declared amount → Compare declared amount with total invoice amount
   ├─ Mismatch       → INCOMPLETE → Deficiency Workflow → Profile becomes COMPLETE → COMPLETED_AFTER_DEFICIENCY
   │                   → Supervisor Allocation Queue → Assignment to Contact Agent → NEW CONTACT → Customer Route Selection
   └─ Difference = 0 → COMPLETE   → Customer Route Selection
Customer Route Selection:  Dissatisfied → After-Sales → Complaint → Outcome
                           No Service → Archive
                           Additional Services → API → Biawin CRM → Continue Operations
```

CRM چرخه **رضایت‌سازی** است. هر مشتری **یک** پروفایل مشتری با شناسه مشتری (`customer_id`) ثابت، فاکتورهای متصل (`invoice`) و مجموع مبلغ فاکتورها دارد؛ هیچ موجودیت مشتری دوم.

```text
ورود مشتری
↓
تماس اولیه
↓
ثبت مبلغ اعلامی
↓
مقایسه با مجموع فاکتورها
↓
┌───────────────────────────────┐
│                               │
مغایرت دارد                 مغایرت = صفر
│                               │
ناقص                            کامل
│                               │
رفع ناقصی                       مسیر مشتری باز
│                               │
├─ حل توسط رفع ناقصی             │
│                               │
└─ در صورت نیاز تأیید مالی       │
│                               │
↓                               │
مغایرت برطرف شد                  │
↓                               │
کامل                            │
↓                               │
تگ: COMPLETED_AFTER_DEFICIENCY   │
↓                               │
صف تخصیص سرپرست                  │
↓                               │
تخصیص به کارشناس                │
↓                               │
تماس جدید                        │
↓                               │
مسیر مشتری باز                   │
│                               │
└───────────────┬───────────────┘
                ↓
          انتخاب مسیر مشتری
                │
       ┌────────┼─────────┐
       ↓        ↓         ↓
     ناراضی   بدون خدمات  خدمات مازاد
       ↓        ↓         ↓
خدمات پس از    بایگانی   انتخاب خدمات
فروش        بدون خدمات     ↓
       ↓    (پایان چرخه  API/Web Service
پرونده نارضایتی   فعال)      ↓
       ↓                  سامانه Biawin
نتیجه نارضایتی
   ┌───┴────┐
   ↓        ↓
  عودت     تهاتر   (و سایر نتایج کاتالوگ)
```

- **وضعیت پروفایل** (`profile_completeness`) فقط دو وضعیت کسب‌وکاری دارد: کامل · ناقص (`pending` فقط مقدار فنی پیش از ثبت مبلغ اعلامی).
- **رفع ناقصی** (`deficiency`) ≠ **تأیید مالی** (`finance verification`): تأیید مالی مسیر اختیاری داخل رفع ناقصی است.
- پس از رفع ناقصی: تگ **تکمیل‌شده پس از رفع ناقصی** (`COMPLETED_AFTER_DEFICIENCY`، تگ موجود) → صف تخصیص توسط سرپرست (موتور موجود `assignment`) → کارشناس تماس → **تماس جدید اجباری** → مسیر مشتری. مسیر نه برای کارشناس رفع ناقصی باز می‌شود نه خودکار برای کارشناس تماس قبلی.
- **مسیر مشتری** (`journey_state`): دقیقاً ناراضی · بدون خدمات · خدمات مازاد؛ فقط پس از کامل شدن؛ انتخاب توسط کارشناس تماس.
- **بدون خدمات** → ثبت نتیجه → **بایگانی بدون خدمات** (`no-service archive`) → پایان چرخه فعال؛ حذف نیست؛ قابل جست‌وجو؛ بدون مرحله بعد.
- **خدمات مازاد** → انتخاب یک یا چند خدمت توسط کارشناس تماس از کاتالوگ موجود → API / Web Service → سامانه Biawin → ادامه عملیات در Biawin (قرارداد جدا). **تنها** مسیر یکپارچگی Biawin.
- **نارضایتی** فقط از مسیر ناراضی؛ هرگز مسیر مشتری را تغییر نمی‌دهد و Gate رفع ناقصی را دور نمی‌زند.

قواعد کامل J1–J18 و تفسیرهای ممنوع: [۰۱ §0.2، §0.9](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md).

### 1.1 مفاهیم جدا

| مفهوم | مقادیر | ستون | مالک |
|---|---|---|---|
| وضعیت پروفایل (`profile_completeness`) | کامل · ناقص | `customers.profile_completeness` | CRM Core |
| رفع ناقصی (`deficiency`) | گردش‌کار (تأیید مالی اختیاری) | `deficiency_cases`، `case_actions` | CRM Core |
| مسیر مشتری (`journey_state`) | ناراضی · بدون خدمات · خدمات مازاد | `customers.journey_state` (`complaint` · `no_request` · `service_request`) | CRM Core |
| نتیجه نارضایتی (`resolution_outcome`) | عودت · تهاتر · … | `complaints.resolution_outcome` | دامنه نارضایتی |
| وضعیت پرونده نارضایتی | چرخه مستقل | `complaints.state` | دامنه نارضایتی |
| یکپارچگی بیرونی خدمات مازاد | مرز API / Web Service به Biawin | — (قرارداد جدا) | مرز Core |

نابرابری‌های قفل‌شده: مسیر مشتری ≠ نتیجه نارضایتی · خدمات مازاد ≠ نارضایتی · خدمات مازاد ≠ تهاتر · تأیید مالی ≠ تکمیل رفع ناقصی · وضعیت پروفایل ≠ وضعیت پرونده نارضایتی.

## 2. مسیر مشتری در برابر نتیجه نارضایتی

| | خدمات مازاد (`additional services`) | تهاتر (`offset`) |
|---|---|---|
| نوع | مسیر مشتری | نتیجه نارضایتی |
| زمان | پس از کامل شدن پروفایل | داخل خدمات پس از فروش |
| انتخاب خدمت | کارشناس تماس | «واحد تهاتر» |
| زیرساخت | کاتالوگ خدمات موجود | ممکن است همان کاتالوگ/زیرساخت (و خدمات رضایت‌سازی) |
| یکپارچگی API / Biawin | **تنها** مسیر یکپارچگی | **خارج** از یکپارچگی؛ چرخه داخلی خدمات؛ استفاده آتی از Biawin فقط با تصمیم جدا |
| اثر بر مسیر مشتری | `service_request` | هیچ |

## 3. رفع ناقصی → کامل → تخصیص مجدد

```text
ناقص → رفع ناقصی → بررسی → (اختیاری) تأیید مالی ⇄ رفع ناقصی → مغایرت برطرف → کامل (همان customer_id)
     → تگ COMPLETED_AFTER_DEFICIENCY → تخصیص فعال قبلی 'returned' → صف سرپرست (استخر «بدون تخصیص فعال» + فیلتر تگ)
     → تخصیص به کارشناس تماس (همان موتور assignments) → تماس جدید → مسیر مشتری
```
نمایش سابقه: `customer_tags` (`COMPLETED_AFTER_DEFICIENCY`، `case_id`) + `customers.completion_source` + `deficiency_cases`/`case_actions` ([۰۲ §0](02-DATABASE-DESIGN.md)). هیچ موتور تخصیص یا مشتری دوم.

## 4. Additional Services Integration Boundary

```text
CRM → خدمات مازاد → انتخاب خدمات → کاتالوگ خدمات CRM (منبع حقیقت) → API / Web Service → سامانه بیرونی Biawin
```
- **تنها** مسیر یکپارچگی Biawin «خدمات مازاد» است؛ API زیر ناراضی، نارضایتی، خدمات پس از فروش، عودت یا تهاتر قرار نمی‌گیرد.
- **مدل خدماتی Biawin:** مشتری ← اشتراک (Subscription) ← چند خدمت که در UI «کارت» هستند (۱۰، ۱۲، ۱۵ یا بیشتر) ← صف عملیات، سرپرست و گردش‌کار اختصاصی هر خدمت/کارت.
- **دامنه مشتریان:** مشتریان جدید **و** مشتریان موجودی که امروز مسیر خدمات مازاد دارند؛ الزامات: ایجاد اولیه، همگام‌سازی، تطبیق، Idempotency، جلوگیری از تکرار مشتری/اشتراک/خدمت در Biawin.
- هیچ Endpoint، فیلد، جدول بیرونی یا پیاده‌سازی در فاز ۳.۱؛ قرارداد پس از بررسی سند Web Service سامانه Biawin ([۰۴ §7](04-API-CONTRACT.md)). هیچ کاتالوگ خدمات دوم.

## 5. Complaint Domain Boundary

```text
CRM CORE:  پروفایل → کامل/ناقص → (ناقص: رفع ناقصی → کامل → صف سرپرست → تماس جدید) → مسیر مشتری
                                             ↓
                                     ┌──────────────┐
                                     │    ناراضی    │
                                     └──────┬───────┘
                                            ↓
                                   خدمات پس از فروش
                                            ↓
PHASE 3:                              پرونده نارضایتی
           استعلام · تصمیم · اقدام‌ها · عودت · تهاتر · پیگیری مجدد · خزانه
```
دامنه نارضایتی مالک نیست: پروفایل مشتری، مالکیت فاکتور، کامل/ناقص، رفع ناقصی، تخصیص سرپرست، مسیر مشتری. هیچ Transition از نارضایتی به مسیریابی مشتری.

## 6. کد فعلی در برابر قرارداد — خلاصه (جزئیات: [۰۱ §0.7](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md))

| # | CURRENT (0.8.64.1) | TARGET | PHASE |
|---|---|---|---|
| G1 | مسیر مشتری برای ناقص پذیرفته می‌شود | مسدود | CJA-1 |
| G2 | ردیف legacy `financial_cases` برای هر ناقص خودکار ساخته می‌شود — طبقه‌بندی: **رکورد گردش‌کار legacy با اثرات جانبی فعال** (دسترسی مالی، الحاق مدارک، نمایش پروفایل، کارتابل قدیمی، KPI)؛ نه پرونده واقعی تأیید مالی؛ مانع کامل شدن نیست | تأیید مالی فقط به ارسال رفع ناقصی؛ هیچ مرحله اجباری | بدون تغییر؛ تصمیم صریح بعدی (AMB-8) → CJA-1 |
| G3 | پس از رفع ناقصی تخصیص قبلی می‌ماند | بازگشت به صف سرپرست | CJA-2 |
| G4 | تگ در همه مسیرهای تکمیل ثبت نمی‌شود | تگ در همه مسیرها | CJA-3 |
| G5 | تماس جدید لازم نیست؛ هر کاربر مجاز مسیر را انتخاب می‌کند | تماس جدید + کارشناس تخصیص‌یافته | CJA-4 |
| G6 | بایگانی بدون خدمات وجود ندارد | بایگانی پایانی قابل جست‌وجو | CJA-5 |
| G7 | خدمات مازاد فقط کارتابل داخلی واحدهای خدمات؛ بدون اشتراک/کارت/یکپارچگی؛ مشتریان موجود این مسیر فقط در CRM | API به Biawin (اشتراک ← خدمات/کارت‌ها) برای مشتریان جدید و موجود، Idempotent و بدون تکرار | CJA-6 + قرارداد یکپارچگی |
| G8 | Outcome legacy `service_request` مسیر را `service_request` می‌کند | تهاتر = نتیجه، بدون تغییر مسیر | CJA-7 + 3.3/3.5 |
| G9 | `resolve_complaint()`/`create_refund()` legacy مسیر را تغییر می‌دهند (`journey_state='refund'`) | نارضایتی مسیر را تغییر نمی‌دهد | 3.3 (Cutover) |
| G10 | برچسب‌های پنل قدیمی | ناراضی · بدون خدمات · خدمات مازاد | CJA-1 |
| G11 | پرونده نارضایتی برای ناقص ساخته می‌شود | فقط کامل + مسیر ناراضی (R-1) | 3.3 |

**CJA-1…7** (Track جدای Core، تأیید جدا، بدون تغییر schema، پیش از Cutover): ۱ مسدود کردن مسیر برای ناقص · ۲ بازگشت به تخصیص سرپرست · ۳ تگ/تاریخچه `COMPLETED_AFTER_DEFICIENCY` · ۴ تماس جدید اجباری · ۵ بدون خدمات = بایگانی پایانی · ۶ خدمات مازاد = مرز یکپارچگی · ۷ تهاتر = نتیجه نارضایتی نه مسیر.

---

## 7. پرونده نارضایتی (فاز ۳)

```text
NEW → ASSIGNED → INVESTIGATING ⇄ {WAITING_INQUIRIES, WAITING_INQUIRY_RESPONSES, INQUIRIES_COMPLETED}   (سیستمی)
INVESTIGATING | INQUIRIES_COMPLETED → DECISION_PENDING → (انتخاب نتیجه)
    ├─ requires_follow_up → FOLLOW_UP_PENDING → INVESTIGATING   (همان پرونده)
    ├─ بدون Flag          → RESOLVED                             (سیستمی)
    └─ اقدام‌ها           → {ACTION_PENDING, FINANCE_PENDING, REFUND_APPROVAL_PENDING, TREASURY_PENDING} → RESOLVED (سیستمی)
RESOLVED → CLOSED (صریح: مدیر خدمات پس از فروش یا سرپرست نارضایتی)     CLOSED → REOPENED → INVESTIGATING
```
ورود: فقط مسیر ناراضی + پروفایل کامل (R-1). RESOLVED هرگز دستی؛ CLOSED هرگز خودکار. استعلام (`inquiry`)، اقدام (`action`)، عودت (`refund`)، پیگیری مجدد: [۰۳](03-STATE-MACHINE.md).

Outcome Policy: `requires_inquiry` · `requires_finance` (تأیید مبلغ عودت، نه رفع ناقصی) · `requires_refund` · `requires_offset` (تهاتر روی زیرساخت موجود خدمات، بدون تغییر مسیر) · `requires_service` · `requires_follow_up`. Seed: `satisfied` · `service_request` (= تهاتر) · `refund` · `follow_up`.

## 8. عودت — Refund Ledger Rule
عودت لایه نتیجه نارضایتی است، نه مسیر مشتری. روی `customers`/`invoices` موجود، بدون تغییر مالکیت فاکتور، مجموع، مبلغ اعلامی، کامل/ناقص یا رفع ناقصی. سه منبع جدا، **هرگز SUM**: `refund_registers` (Excel snapshot؛ CRM نمی‌نویسد)، `refunds` (legacy)، Refund Items. برای هر فاکتور **`R = max(0, A − max(X, P) − O)`** ([۰۲ §7.3](02-DATABASE-DESIGN.md)).

## 9. دسترسی
Core Journey با Capabilityهای موجود ([۰۵ §6.2](05-PERMISSION-MATRIX.md)): سرپرست تخصیص می‌دهد؛ کارشناسان رفع ناقصی پروفایل ناقص را رسیدگی می‌کنند؛ تأیید مالی فقط به ارسال رفع ناقصی؛ کارشناس تماس تخصیص‌یافته مسیر را پس از کامل شدن (و تماس جدید) انتخاب می‌کند؛ بایگانی بدون خدمات و انتخاب خدمات مازاد توسط کارشناس تماس؛ مرز بیرونی بدون مجوز در فاز ۳.۱. دامنه نارضایتی: Visibility واحدمحور، پاسخ استعلام = Membership + Capability، خزانه = Capability + عضویت، تهاتر = `bcrm_services` + عضویت «واحد تهاتر». Capabilityهای جدید فقط: `respond_unit_inquiries`، `override_treasury_capacity`.

## 10. Phase 3.2 — دامنه (Admin Configuration؛ شروع نشده)
Migration `0.8.65.0` (`complaint_outcome_policies`؛ Seed `follow_up`، Policy چهار Outcome، هشت موضوع؛ دو Capability؛ Optionهای Intake، Engine=`legacy`، واحد تهاتر) · `Outcome_Policy_Service` · `Unit_People` · Admin «تنظیمات نارضایتی» · تست‌ها شامل **عدم تغییر رفتار Core/Journey**. خارج از 3.2: CJA، پرونده نارضایتی، استعلام، عودت، تهاتر، خزانه، Engine Switch، یکپارچگی بیرونی.

## 11. Migrationهای آینده

| نسخه | فاز | تغییرات |
|---|---|---|
| — | CJA | هیچ (بدون تغییر schema) |
| `0.8.65.0` | 3.2 | `complaint_outcome_policies`؛ Seedها؛ Capabilityها؛ Optionها |
| `0.8.66.0` | 3.3 | `complaints` +16 ستون؛ `interactions.complaint_id`؛ `complaint_state_log`، `complaint_follow_ups`، `complaint_actions`؛ Backfill `state` |
| `0.8.67.0` | 3.4 | `complaint_inquiries` +۸ ستون؛ Backfill D7 |
| `0.8.68.0` | 3.5 | `refund_plans`، `refund_items`، `refund_allocations`؛ `service_cases.complaint_action_id` |
| `0.8.69.0` | 3.6 | جداول خزانه |

هیچ Migrationی `customers`، `invoices`، `assignments`، `customer_tags`، `interactions` موجود، `deficiency_cases`، `case_actions`، `financial_cases`، `service_catalog`، `service_cases` موجود، `complaints` موجود (به‌جز ستون‌های جدید و Backfill `state`)، `refund_registers`، `refunds` یا تاریخچه CRM را حذف یا بازنویسی نمی‌کند.

## 12. ابهام‌های باقی‌مانده

| کد | ابهام |
|---|---|
| AMB-3 | «انجام خدمات» در برابر «تهاتر» |
| AMB-4 | فیلتر «عودت» پرتال روی `journey_state='refund'` legacy |
| AMB-5 | شمارش پرونده‌های خدمت تهاتر در اعتبار خدمات مازاد |
| AMB-6 | بستن تخصیص فعال در «بدون خدمات» با کدام وضعیت موجود |
| AMB-8 | ردیف legacy `financial_cases` (رکورد گردش‌کار legacy با اثرات جانبی فعال): حفظ، یا جایگزینی اثرات پیش از حذف ساخت خودکار — بدون تغییر تا تصمیم |
| AMB-9 | استفاده آتی تهاتر از Biawin — امروز خیر؛ فقط با تصمیم جدا |
| AMB-10 | نگاشت اشتراک/خدمت/کارت Biawin به کاتالوگ CRM و تطبیق مشتریان موجود — قرارداد یکپارچگی |
| O-4 | معیار تکمیل `SERVICE_FOLLOWTHROUGH` |
| O-13 | عضویت مدیر خدمات پس از فروش در واحد خزانه |
