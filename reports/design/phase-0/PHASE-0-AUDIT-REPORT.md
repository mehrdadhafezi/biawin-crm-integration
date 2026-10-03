# PHASE 0 — Audit + UX Baseline

**پروژه:** CRM رضایت‌سازی (افزونهٔ `biawin-crm-integration`) · **Baseline:** 0.8.64.1 (SHA `ddc3ff72…7ca8`) · **تاریخ:** ۲۰۲۶-۱۰-۰۲
**نوع کار:** فقط Audit. هیچ Production Code، Plugin Code، Migration، Database Change یا Deployment انجام نشده است.
**مرجع:** `BIAWIN_CRM_MASTER_PHASED_IMPLEMENTATION_PROMPTS.md` (PART B تا PART P) + قرارداد قفل‌شدهٔ Phase 3.1 (`design/phase-3.1/`، بازبینی ۵).

> **نام‌گذاری (PART B §1):** در این گزارش «CRM رضایت‌سازی» = همین افزونه. «CRM فروش بیاوین + عملیات و اجرا» = افزونهٔ Sales Network (`sales-network-v4`) که سیستم جداگانه است. هیچ‌کدام صرفاً «Biawin CRM» نامیده نمی‌شوند.

**منابع شواهد:** کد `extracted/` (0.8.64.1)، سورس Sales Network موجود در workspace (`tmp-salesnet` 2.0.116 و `sn-phase1` 2.0.123)، اجرای همهٔ تست‌های static و شبیه‌سازی به‌روزرسانی روی WordPress واقعی (بخش ۱۶).

---

## ۱. Current Architecture

| لایه | فایل / کلاس | نقش |
|---|---|---|
| Bootstrap | `biawin-crm-integration.php` | Version 0.8.64.1؛ بارگذاری کلاس‌ها، `maybe_upgrade()` |
| Schema / Upgrade | `includes/class-biawin-crm-activator.php` | ۳۲ جدول (`{prefix}bcrm_*`)، Migrationهای ترتیبی و idempotent (`MIGRATIONS`)، قفل `GET_LOCK`، Ledgerهای نقش/Capability/Seed |
| Core Journey | `class-biawin-crm-workflow-service.php` | `sync_customer()` (کامل/ناقص)، `set_customer_journey()`، `create_complaint()`، `resolve_complaint()`، `create_refund()`، `select_services()`، پرونده‌های خدمت |
| مشتری / فاکتور | `class-biawin-crm-customer-service.php`، `class-biawin-crm-import-service.php` | محاسبهٔ `total_paid`، مبلغ اعلامی، گرید؛ Import اکسل و تجمیع هویت |
| رفع ناقصی + تأیید مالی | `class-biawin-crm-deficiency-workflow.php` (۲۱۶۷ خط)، `class-biawin-crm-finance-review.php`، `class-biawin-crm-receipt-staging.php` | پرونده `deficiency_cases`، دپارتمان deficiency/finance، `case_actions`، تگ‌ها |
| پرتال (Frontend) | `class-biawin-crm-frontend.php` | Shortcode پرتال، لیست مشتریان، پروفایل `?bcrm_customer=`، AJAX/admin-post handlers |
| Admin | `admin/class-biawin-crm-admin.php` | منوهای wp-admin، تخصیص، کاتالوگ خدمات، واحدها، کارتابل‌ها، عودت، تنظیمات |
| پیکربندی خدمات پس از فروش | `class-biawin-crm-after-sales-config(.php/-admin.php)` | کاتالوگ موضوع/نتیجه نارضایتی (`catalog_items`)، پیکربندی استعلام واحدها، `complaint_inquiries` |
| عودت اکسلی | `class-biawin-crm-refund-register.php` | Ledger اکسل `refund_registers` (ادمین‌محور)، تگ‌های `REFUND_*` |
| REST داخلی | `class-biawin-crm-rest.php` | `biawin-crm/v1/*` فقط برای پرتال خود CRM (کاربر واردشده)؛ **هیچ API بیرونی** |
| گزارش‌ها | `class-biawin-crm-reporting-*.php` | Consumer adapter، جمعیت‌ها، داشبورد مدیریتی |
| ابزارهای داده | `historical-audit`، `controlled-reconstruction`، `post-run7-reconciliation`، `blacklist-*` | ابزارهای اصلاح داده‌های تاریخی؛ خارج از دامنهٔ این پروژه |

**الگوهای معماری موجود که باید Reuse شوند:**
- Migration ترتیبی، idempotent و retry-safe با `biawin_crm_db_version` و قفل اتمیک.
- Ledger نقش/Capability (`biawin_crm_caps_ledger`): نقش حذف‌شده بازسازی نمی‌شود و Cap برداشته‌شده دوباره داده نمی‌شود.
- Seed Ledger برای خدمات، واحدها و کاتالوگ: insert-once.
- موتور تخصیص واحد (`service_units` + `service_unit_members` با `member_role` = supervisor/agent).
- Timeline (`activities`) و Audit (`audit_log`).

## ۲. Current Business Flow (کد 0.8.64.1)

```text
Import / ثبت دستی فاکتور → customers (یک customer_id) + invoices + phones
   ↓
تخصیص سرپرست (assignments؛ حداکثر یک تخصیص فعال برای هر مشتری — UNIQUE generated column)
   ↓
تماس کارشناس (interactions) + ثبت مبلغ اعلامی (declared_paid)
   ↓
sync_customer():  |اعلامی − total_paid| ≤ tolerance (حداقل 500,000) ?  complete : incomplete
   ├─ incomplete → deficiency_cases (رفع ناقصی) + ردیف legacy financial_cases (FIN-…)
   │                 └─ (اختیاری) send_case_to_finance → دپارتمان مالی → برگشت/تکمیل
   │                 └─ تکمیل → completion_source + تگ COMPLETED_AFTER_DEFICIENCY (فقط در ۳ مسیر)
   │                 └─ ❗ تخصیص تماس قبلی باقی می‌ماند؛ بازگشت به صف سرپرست وجود ندارد
   └─ complete → بستن financial_cases باز
   ↓
set_customer_journey(): no_request | complaint | service_request   ❗ برای ناقص هم مجاز است
   ├─ complaint → create_complaint() → complaints(status=open) → resolve_complaint():
   │        satisfied → journey=no_request ❗ · service_request → journey=service_request ❗ · refund → refunds + journey=refund ❗
   ├─ no_request → فقط ذخیره سطح رضایت؛ بایگانی/لیست جدا وجود ندارد ❗
   └─ service_request → select_services() → service_cases برای کارتابل‌های داخلی واحدهای خدمت (بدون API بیرونی)
```

❗ = مغایرت با معماری محافظت‌شده (جزئیات: بخش ۱۳).

## ۳. Audit آیتم‌های PHASE 0 (۲۴ مورد)

| # | موضوع | وضعیت فعلی (شواهد کد) | انطباق با PART B–M |
|---|---|---|---|
| 1 | معماری پلاگین | بخش ۱؛ Migration و Ledger امن | ✅ قابل Reuse |
| 2 | پروفایل مشتری | `customers` تنها موجودیت؛ `internal_uuid`/`customer_code`/`source_customer_id` یکتا | ✅ Invariant 1/3 |
| 3 | تطبیق فاکتورها | `total_paid` = SUM(`amount_paid`) فاکتورهای متصل به‌جز `exclude_from_total=1` (`customer-service.php:33`) | ⚠️ «مجموع فاکتورها» با حذف فاکتورهای مستثنی‌شده — تأیید لازم (D-02) |
| 4 | مبلغ اعلامی | آخرین `interactions.declared_paid` غیرخالی → `customers.declared_paid` | ✅ |
| 5 | کامل/ناقص | `abs(declared − total) ≤ tolerance`؛ tolerance = `max(500000, option)` در ۶ نقطه + `ensure_reconciliation_tolerance()` آن را هرگز زیر 500,000 نمی‌گذارد | ❌ PART B: «مغایرت = صفر» — **تصمیم کسب‌وکار لازم (D-01)** |
| 6 | رفع ناقصی | `deficiency_cases` + `case_actions`؛ دپارتمان deficiency؛ حل مستقیم ممکن | ✅ |
| 7 | تأیید مالی | داخل رفع ناقصی **اختیاری** (`send_case_to_finance`)؛ اما `sync_customer()` برای هر ناقص ردیف legacy `financial_cases` می‌سازد و پیام‌های UI «باید در کارتابل تأیید مالی تکمیل شود» می‌گویند | ⚠️ منطق ✅؛ رکورد legacy + متن UI ❌ (G2، D-03) |
| 8 | Assignment Engine | `assignments` (assigned/returned)، Guard یک تخصیص فعال، صفحه «تخصیص پرونده» (`bcrm_assign_customers`، `bcrm_return_assignments`، اکسل) | ✅ تنها موتور؛ Reuse |
| 9 | تخصیص سرپرست پس از رفع ناقصی | وجود ندارد؛ تخصیص قبلی فعال می‌ماند | ❌ G3 |
| 10 | Tags | `customer_tags` UNIQUE(customer_id, tag)؛ `COMPLETED_AFTER_DEFICIENCY` فقط در ۳ مسیر تکمیل؛ در تکمیل خودکار Tolerance و تکمیل با مبلغ اعلامی جدید ثبت نمی‌شود | ⚠️ G4 |
| 11 | Customer Route | `journey_state` با ۳ کلید + مقدار legacy `refund`؛ برای ناقص مجاز؛ انتخاب‌کننده محدود نیست؛ برچسب‌ها «بدون درخواست/نارضایتی/درخواست خدمات» | ❌ G1، G5، G10 |
| 12 | Complaint | `complaints` با `status` = open/closed؛ ثبت حتی برای ناقص؛ `resolve_complaint()` مسیر مشتری را تغییر می‌دهد | ❌ G8، G9، G11 |
| 13 | Complaint Tabs/Statuses | کارتابل admin یک جدول ۲۰۰ ردیفی بدون Tab؛ فقط باز/بسته؛ تخصیص فقط `owner_user_id` (همه NULL)؛ بدون داشبورد/مانیتورینگ | ❌ (Phase 3) |
| 14 | Topic Catalog | `catalog_items` (`complaint_subject`)؛ Admin: افزودن/ویرایش/فعال‌غیرفعال/ترتیب؛ Dropdown در پروفایل؛ Free-text فقط تا تعریف اولین موضوع | ✅ (0.8.64.0) |
| 15 | Outcome Catalog | `catalog_items` (`complaint_outcome`)؛ ۳ کلید legacy دارای Handler (`satisfied`، `service_request`، `refund`)؛ Outcome جدید Admin بدون Handler در فرم نمایش داده نمی‌شود | ⚠️ کلید `service_request` با برچسب «تبدیل به درخواست خدمت» = تداخل تهاتر/خدمات مازاد (G8) |
| 16 | Inquiry | جدول `complaint_inquiries` + پیکربندی واحد (`can_receive_inquiry`، `requires_portal_response`، `inquiry_responders`) + توابع دامنه `request_portal_inquiry()`/`record_manual_inquiry()`؛ **هیچ UI یا Handler برای ثبت/پاسخ استعلام وجود ندارد**؛ Capهای استعلام مالی/وام/خدمات وجود دارند؛ **IT وجود ندارد** | ⚠️ زیرساخت بله، گردش‌کار خیر (Phase 4) |
| 17 | Refund | سه منبع: (الف) `refunds` legacy (پرونده‌ای، یک مبلغ، وضعیت new→paid، کارتابل admin)، (ب) `refund_registers` Ledger اکسل ادمین‌محور + تگ‌ها + صفحه «عودتی‌ها»، (ج) Summary «مانده/استفاده‌شده» پروفایل از (ب) | ❌ برنامه عودت سطح فاکتور/آیتم/تاریخ وجود ندارد (Phase 6) |
| 18 | Treasury | فقط نقش‌ها (`bcrm_treasury_supervisor/agent`)، Capها (`view_treasury`، `manage_treasury_capacity`، `process_refund_payment`، …) و واحد Seed `treasury`؛ هیچ جدول، ظرفیت، تقویم یا UI | ❌ (Phase 7) |
| 19 | Service Catalog | `service_catalog` (کد، نام، `credit_cost`، `unit_key`، فعال، ترتیب)؛ ۸ خدمت Seed با Ledger؛ Admin «کاتالوگ خدمات» | ✅ منبع حقیقت خدمات CRM |
| 20 | Service Selection | `select_services()` → `service_cases` (یکتا per خدمت فعال)، اعتبار خدمات (`calculate_credit`)، کارتابل واحد؛ پنل خدمات برای ناقص هم فعال؛ `ajax_select_services()` قبل از ساخت پرونده‌ها مسیر را ثبت می‌کند | ⚠️ G7 + ریسک R-07 |
| 21 | Biawin integration موجود | در CRM رضایت‌سازی: **هیچ** (نه `wp_remote_*`، نه webhook، نه outbox)؛ REST فقط داخلی. در Sales Network: فقط اتصال **خروجی** قدیمی `class-sn-biavin-api.php` و هیچ endpoint ورودی درخواست خدمات | ✅ مرز تمیز؛ قرارداد واقعی OPEN |
| 22 | UI `?bcrm_customer=` | بخش ۴ | ⚠️ |
| 23 | UX کارشناس عمومی | بخش ۴.۲ | ⚠️ |
| 24 | UX کارشناس نارضایتی | بخش ۴.۳ | ❌ |

## ۴. Current UI — پروفایل مشتری `?bcrm_customer=`

### ۴.۱ ساختار فعلی (`Frontend::render_profile()`، خط 1241)

| ترتیب | بخش | توضیح / مشکل |
|---|---|---|
| 1 | Topbar + Header | نام، Badge کامل/ناقص، Badge مسیر («بدون درخواست/نارضایتی/درخواست خدمات/عودت وجه»)، کد مشتری، CustomerID، گرید |
| 2 | Summary (۱۰ کارت) | مجموع فاکتورها، مبلغ اعلامی، مغایرت، **مانده/استفاده‌شده (از Ledger عودت اکسلی)**، تعداد فاکتور/محصول، اعتبار خدمات ×۳ — ترکیب مفاهیم مالی، عودت و اعتبار خدمات در یک ردیف |
| 3 | هشدار وضعیت پروفایل | ناقص: «باید در **کارتابل تأیید مالی** تکمیل شود» ❌ (باید: رفع ناقصی)؛ کامل: «می‌تواند وارد مسیر رضایت، نارضایتی یا خدمات شود» (برچسب‌های غیرنهایی) |
| 4 | عودت‌ها (شرطی) | فقط اگر تگ عودت دارد؛ **قبل از Tabها** و بالای کل محتوای کاری |
| 5 | Tabهای Anchor | نمای کلی، فاکتورها، فرم کارشناس، خدمات، نارضایتی‌ها، (عودت‌ها)، تاریخچه |
| 6 | ستون اصلی | فاکتورها → جست‌وجو/الحاق → **فرم تماس و مبلغ اعلامی** → **تعیین مسیر** → **پنل خدمات** → **نارضایتی و عودت** → Timeline |
| 7 | ستون کناری | شماره‌ها → وضعیت رفع ناقصی/مالی + وضعیت پروفایل + آخرین پرونده مالی legacy + فرم‌های مالی → خدمات Import‌شده |

**مشاهدات کلی:**
- همهٔ پنل‌ها برای **هر** کاربری که به پروفایل دسترسی دارد رندر می‌شوند. فقط فرم ثبت نتیجهٔ نارضایتی و فرم‌های مالی به Capability وابسته‌اند.
- پنل مسیر برای ناقص فعال است و متن آن صریحاً می‌گوید «مغایرت مالی مانع تعیین مسیر نیست». این متن با Invariant 7 مغایر است.
- پنل خدمات **همیشه** رندر می‌شود (`data-profile-complete="1"` ثابت)، حتی وقتی مسیر «خدمات مازاد» نیست. انتخاب خدمت، مسیر را خودکار به `service_request` تغییر می‌دهد. متن پنل می‌گوید «انتخاب خدمات حتی در صورت مغایرت مالی مجاز است» ❌.
- پنل نارضایتی **همیشه** با فرم «ثبت نارضایتی جدید» رندر می‌شود. ثبت آن مسیر را خودکار `complaint` می‌کند و دوراهی مسیر را دور می‌زند. زیرعنوان آن می‌گوید «نارضایتی می‌تواند به رضایت، درخواست خدمت یا عودت ختم شود» ❌ (Outcome ≠ Route).
- هیچ نشانی از «تکمیل‌شده پس از رفع ناقصی» در Header یا پنل مسیر نیست، با اینکه تگ در DB وجود دارد.
- برای «بدون خدمات» هیچ نمایش بایگانی وجود ندارد.

### ۴.۲ UX کارشناس عمومی تماس (`bcrm_agent`: `read`، `bcrm_work_cases`)

| جنبه | وضعیت فعلی |
|---|---|
| دسترسی | فقط مشتری با تخصیص فعال خودش (`is_assigned_to_current_user`) |
| کار اصلی | فرم تماس + مبلغ اعلامی (پیش‌نمایش زنده مغایرت) ✅ |
| مسیر | می‌تواند برای ناقص مسیر انتخاب کند ❌؛ پس از رفع ناقصی بدون تماس جدید ❌ |
| نارضایتی | می‌تواند مستقیم پرونده بسازد (موضوع از Dropdown ✅) — بدون انتخاب مسیر «ناراضی» به‌صورت گام جدا |
| خدمات | پنل همیشه قابل استفاده؛ اعتبار خدمات و انتخاب چندگانه ✅ |
| نویز | Summary عودت/اعتبار، پرونده‌های عودت، فرم‌های مالی (پنهان بدون Cap)، کارت خدمات Import‌شده |
| ترتیب | فرم کار اصلی پس از جدول فاکتورها؛ پنل مسیر پس از فرم تماس ✅؛ اما خدمات و نارضایتی هم‌زمان باز هستند و مسیر را خودکار تعیین می‌کنند |

### ۴.۳ UX کارشناس نارضایتی (`bcrm_complaint_agent`: `bcrm_complaints`، `view/process_dissatisfaction`)

| جنبه | وضعیت فعلی |
|---|---|
| ورود | کارتابل admin «نارضایتی‌ها»: جدول ساده، بدون Tab/فیلتر/وضعیت میانی/مهلت؛ لینک به پروفایل |
| دسترسی پروفایل | فقط اگر پرونده **باز** با `owner_user_id` NULL یا خودش؛ پس از بستن پرونده دسترسی قطع می‌شود |
| در پروفایل | همان صفحهٔ کارشناس تماس: فرم تماس/مبلغ اعلامی، پنل مسیر، پنل خدمات (قابل ثبت!) — هیچ نمای مخصوص پرونده نارضایتی |
| تصمیم | یک Select نتیجه (۳ گزینهٔ Handler‌دار) + یادداشت در کارت پرونده؛ ثبت = بستن فوری + تغییر مسیر مشتری ❌ |
| استعلام | هیچ UI ❌ |
| تاریخچه پرونده | فقط Timeline عمومی مشتری؛ تاریخچهٔ اختصاصی پرونده نیست |
| ریسک | کارشناس نارضایتی با `bcrm_work_cases` می‌تواند از همین صفحه مسیر یا خدمات را تغییر دهد ❌ |

### ۴.۴ UI هدف پیشنهادی (فقط پیشنهاد؛ اجرای آن در Phase 2/3)

**برای کارشناس عمومی تماس:**

```text
Header: نام · کامل/ناقص · مسیر (ناراضی/بدون خدمات/خدمات مازاد) · Badge «تکمیل‌شده پس از رفع ناقصی»
Stepper:  ① اطلاعات و فاکتورها → ② تماس و مبلغ اعلامی → ③ وضعیت پروفایل → ④ مسیر مشتری → ⑤ اقدام مسیر
   ③ ناقص: «در رفع ناقصی» + وضعیت پرونده (فقط‌خواندنی) — گام ④ قفل
   ③ کامل پس از رفع ناقصی: «نیازمند تماس جدید» تا ثبت تماس جدید توسط کارشناس تخصیص‌یافته
   ④ سه کارت: ناراضی · بدون خدمات · خدمات مازاد (فقط کامل)
   ⑤ ناراضی → فرم ثبت پرونده نارضایتی · بدون خدمات → ثبت نتیجه و بایگانی · خدمات مازاد → پنل انتخاب خدمات
Side: شماره‌ها · خلاصه مالی (فاکتور/اعلامی/مغایرت) · Timeline فشرده
پنهان/جمع‌شده: عودت‌ها، اعتبار جزئی، ابزارهای مالی (فقط با Cap)
```

**برای کارشناس نارضایتی:** نمای «پرونده نارضایتی» جدا با ترتیب زیر:

```text
Header پرونده: موضوع · وضعیت پرونده (state) · مهلت · مالک · مسیر مشتری (فقط‌خواندنی)
Tabs: خلاصه مشتری (فقط‌خواندنی: فاکتورها، مبلغ اعلامی، کامل/ناقص) · شرح و تماس‌ها · استعلام‌ها (پرتال/دستی، چندگانه) · تصمیم/نتیجه · عودت (برنامه سطح فاکتور) · تاریخچه پرونده
```

فرم‌های مسیر و خدمات در این نما وجود ندارند؛ تصمیم Outcome هرگز مسیر را تغییر نمی‌دهد.

## ۵. Current Database

| گروه | جداول |
|---|---|
| مشتری/هویت | `customers`، `phones`، `merge_events` |
| فاکتور | `invoices`، `invoice_phones`، `invoice_items`، `payments`، `services` (خدمات Import‌شده) |
| Import | `import_templates`، `imports`، `import_jobs`، `import_stage_invoices`، `import_stage_phones` |
| سازمان | `service_units` (+ `parent_unit_id`، `unit_type`، `can_receive_inquiry`، `requires_portal_response`، `display_order`، `inquiry_responders`)، `service_unit_members` |
| تخصیص/تماس | `assignments` (+ `active_customer_guard` UNIQUE)، `sales_manager_mapping`، `interactions` |
| رفع ناقصی/مالی | `deficiency_cases`، `case_actions`، `financial_cases` (legacy)، `customer_tags` |
| نارضایتی | `complaints` (+ `subject_id`، `resolution_outcome`، `owner_user_id`، `due_at`)، `catalog_items`، `complaint_inquiries` |
| خدمات | `service_catalog`، `service_cases` |
| عودت | `refunds` (legacy)، `refund_registers` (Ledger اکسل) |
| مشترک | `attachments`، `activities`، `audit_log` |

ستون‌های Journey روی `customers`: `profile_completeness` (complete/incomplete/pending)، `completion_source` (NORMAL/DEFICIENCY_WORKFLOW/FINANCE_WORKFLOW)، `journey_state`، `satisfaction_state`، و `service_credit_*`. **جداول وجود ندارند** برای: وضعیت/state پرونده نارضایتی، اقدام، پیگیری، برنامه عودت، خزانه، ظرفیت، و یکپارچگی (outbox/mapping).

## ۶. Permissions

**نقش‌های موجود (۱۸):**
- Portal / عمومی: `bcrm_portal_admin`، `bcrm_supervisor`، `bcrm_agent`
- رفع ناقصی: `bcrm_deficiency_supervisor/agent`
- تأیید مالی: `bcrm_financial_supervisor/agent`
- نارضایتی: `bcrm_complaint_supervisor/agent`
- خدمات: `bcrm_service_supervisor/agent`
- عودت: `bcrm_refund_supervisor/agent/register`
- 0.8.63.0: `bcrm_after_sales_manager`، `bcrm_loan_supervisor/agent`، `bcrm_treasury_supervisor/agent`

**Capabilityها:**
- Legacy: `bcrm_*`، deficiency/finance.
- خدمات پس از فروش (۲۲ مورد): view/manage after_sales، dissatisfaction، subjects/outcomes، inquiry units، finance/loan/service inquiries، treasury، `approve_refund_schedule`، `process_refund_payment`.

**Upgrade safety (موجود):** Ledger مانع بازسازی نقش حذف‌شده و Cap برداشته‌شده است. Marker = 5.

**فاصله‌ها:**
- **IT**: نه واحد، نه نقش (`سرپرست IT`/`کارشناس IT`) و نه Cap استعلام IT وجود دارد (PART E §9).
- `bcrm_supervisor` (سرپرست CRM) همهٔ `$crm_all` را دارد، از جمله دسترسی کامل نارضایتی. تفکیک «سرپرست نارضایتی‌ها» از سرپرست عمومی فقط با نقش جدا ممکن است.
- `bcrm_after_sales_manager` Cap `view_after_sales_reports` دارد، اما هیچ صفحهٔ گزارش/مانیتورینگ خدمات پس از فروش وجود ندارد.
- باگ‌های امنیتی قبلی (S1/S2، S5، S6، S7، L1–L12، R1–R7) طبق دستور شما **ON HOLD** هستند و در این Phase بررسی مجدد نشدند.

## ۷. Assignment

- **تخصیص تماس:** `assignments` + صفحهٔ «تخصیص پرونده». شامل دستی، توزیع بین همه، Rule مدیر فروش، اکسل CustomerID، cohort 1404، برگشت تخصیص (`returned`) و Guard یک تخصیص فعال برای هر مشتری.
- **تخصیص رفع ناقصی/مالی:** درون `deficiency_cases` (`deficiency_agent_id`، `finance_agent_id`) با `assign_cases`، `assign_cases_multi`، اکسل و `return_assignments`.
- **تخصیص خدمات:** `service_cases.assigned_user_id` توسط سرپرست واحد (`member_role='supervisor'`).
- **تخصیص نارضایتی:** فقط ستون `owner_user_id` (بدون UI تخصیص؛ عملاً NULL).
- **نتیجه:** موتور تخصیص تماس و موتور واحد کافی هستند. موتور جدید لازم نیست (Invariant 4). بازگشت پس از رفع ناقصی = همان `returned` + فیلتر تگ.

## ۸. Deficiency

- ایجاد خودکار در `sync_customer()` برای هر ناقص (`ensure_case_for_customer`)؛ تکمیل خودکار با Tolerance (`CASE_AUTO_COMPLETED_BY_TOLERANCE`).
- **مسیرهای تکمیل:** (۱) اصلاح مبلغ اعلامی، (۲) تصمیم نهایی «تکمیل»، (۳) تکمیل خودکار پس از تأیید مالی. تگ + `completion_source` فقط در این سه مسیر ثبت می‌شود (G4).
- ارسال به مالی اختیاری است و رفت‌وبرگشت پشتیبانی می‌شود.
- **فاصله‌ها:**
  - G3: تکمیل، تخصیص تماس را برنمی‌گرداند.
  - G4: تگ در همهٔ مسیرها ثبت نمی‌شود.
  - G5: تماس جدید الزامی نیست.
  - G2: ردیف legacy `financial_cases` اثرات جانبی فعال دارد: دسترسی کاربران مالی، الحاق مدارک، «آخرین پرونده مالی» در پروفایل، کارتابل `?legacy=1` و KPI.

## ۹. Complaint

- ثبت: `create_complaint()` → درج + `set_customer_journey('complaint')`. با شکست مسیر، پرونده حذف می‌شود. برای ناقص هم انجام می‌شود (G11).
- وضعیت: فقط `open`/`closed`. بدون state machine، Tab، مهلت فعال، تخصیص واحد‌محور، داشبورد یا گزارش.
- نتیجه: `resolve_complaint()` (تراکنشی) پرونده را می‌بندد و **مسیر مشتری را تغییر می‌دهد** (G8/G9). کلید `service_request` با برچسب «تبدیل به درخواست خدمت»، تهاتر و خدمات مازاد را یکی می‌کند.
- طراحی هدف این بخش در Phase 3.1 (بازبینی ۵) تأیید شده است: `state` جدا، Engine switch legacy→v3، Outcome policy flags. Phase 3 و 5 جدید باید آن را Reuse کنند (بخش ۱۵).

## ۱۰. Refund

| منبع | ماهیت | وضعیت |
|---|---|---|
| `refunds` | پرونده legacy با یک مبلغ (`requested/approved_amount`)، وضعیت new/review/approved/ready_payment/paid/…، کارتابل admin | ساخته می‌شود فقط از Outcome `refund`؛ `journey_state='refund'` می‌نویسد ❌ |
| `refund_registers` | Ledger اسنپ‌شاتی اکسل ادمین (`excel`/`used`/اعلامی)، تگ `REFUND_CONFIRMED`/`REFUND_REPORTED`، صفحهٔ «عودتی‌ها» با فیلتر و Export | ✅ باید دست‌نخورده بماند (آپلود فقط Admin) |
| Summary پروفایل | «مانده/استفاده‌شده» از Ledger | نمایشی |

برنامهٔ عودت در سطح فاکتور/آیتم/تاریخ، تأیید مدیر و ارسال به خزانه **وجود ندارد**. قانون Ledger تأییدشده: `R = max(0, A − max(X, P) − O)`. هیچ SUM بین منابع انجام نمی‌شود (Phase 3.1 C4).

## ۱۱. Treasury

موجودها: فقط نقش‌ها، Capها و واحد Seed `treasury` (`unit_type='treasury'`، قابل استعلام).

وجود ندارد: ظرفیت روزانه/هفتگی، استثنای تقویمی، هشدار، Override، پرداخت یا Reconciliation.

قاعدهٔ تأییدشده O-12: عملیات خزانه = Capability **و** عضویت واحد خزانه.

## ۱۲. Service و Biawin

**خدمات داخلی CRM رضایت‌سازی:** کاتالوگ (۸ Seed)، اعتبار خدمات (ضریب ۲٫۵، کف/سقف)، `service_cases` در کارتابل واحدها، جلوگیری از تکرار خدمت فعال برای یک مشتری.

**مرز Biawin:**
- CRM رضایت‌سازی هیچ تماس بیرونی ندارد.
- Sales Network (2.0.116/2.0.123 موجود در workspace) هیچ endpoint ورودی برای درخواست خدمات ندارد. فقط `class-sn-biavin-api.php` خروجی است (کیف پول/پروژه) و REST‌های `sn/v1/bale/*` و `sn-crm/v1/campaign/report` نامرتبط‌اند.
- سند `BIAWIN-INTEGRATION-REVISED.md` Revision 3 (`design/biawin-integration/`) هم‌تراز با Journey قفل‌شده است. endpoint، auth و schema واقعی **OPEN** است.
- مشتریان موجود با `journey_state='service_request'` و `service_cases` موجود، جمعیت legacy خدمات مازاد هستند که در Phase 9 باید Reconcile شوند.
- **نکته:** سورس محلی Sales Network (2.0.123) از نسخهٔ مبنای سند یکپارچگی (2.0.115) جلوتر است. استخراج Part O باید روی نسخهٔ تولیدی تأییدشده انجام شود (D-06).

## ۱۳. Gaps (مغایرت کد با معماری محافظت‌شده)

| # | Gap | Invariant | Phase مقصد |
|---|---|---|---|
| G1 | مسیر برای پروفایل ناقص مجاز (سرور + پنل + متن UI) | 7، 8 | 2 |
| G2 | ردیف legacy `financial_cases` برای هر ناقص + متن «باید در تأیید مالی تکمیل شود» | 6 | 2 (با D-03) |
| G3 | بازگشت به صف سرپرست پس از تکمیل رفع ناقصی وجود ندارد | 9 | 2 |
| G4 | تگ `COMPLETED_AFTER_DEFICIENCY` در همهٔ مسیرهای تکمیل ثبت نمی‌شود | 10 | 2 |
| G5 | تماس جدید پس از رفع ناقصی الزامی نیست؛ انتخاب‌کننده مسیر محدود نیست | 11 | 2 |
| G6 | بایگانی قابل جست‌وجوی «بدون خدمات» وجود ندارد | 13 | 2 |
| G7 | خدمات مازاد فقط داخلی؛ بدون مرز Outbox/Idempotency | 20، 23 | 8 → 9 |
| G8 | Outcome `service_request` مسیر را «خدمات مازاد» می‌کند (تهاتر = خدمات مازاد) | 17، 18، 19 | 5 (Engine v3) |
| G9 | Outcomeها `journey_state` را تغییر می‌دهند (`no_request`، `refund`) | 17 | 5 |
| G10 | برچسب مسیرها غیرنهایی | 12 | 2 |
| G11 | پرونده نارضایتی برای ناقص و بدون گام مسیر «ناراضی» | 14، 16 | 2/3 |
| G12 | پنل‌های خدمات و نارضایتی همیشه فعال‌اند و مسیر را خودکار تعیین می‌کنند | 12 | 2 |
| G13 | Complaint بدون Tab/State/Dashboard/Assignment/Report | — | 3 |
| G14 | Inquiry بدون UI/گردش‌کار؛ IT وجود ندارد | — | 1 (IT) + 4 |
| G15 | Refund بدون Plan سطح فاکتور و تأیید مدیر | 28 | 6 |
| G16 | Treasury بدون ظرفیت/Override/پرداخت | 26، 27 | 7 |
| G17 | واحد/نقش IT وجود ندارد | — | 1 |
| G18 | نمای مخصوص کارشناس نارضایتی وجود ندارد؛ او از پروفایل می‌تواند مسیر/خدمات را تغییر دهد | — | 2 (Guard) + 3 (UI) |
| G19 | تعریف «کامل»: Tolerance ≥ 500,000 در برابر «مغایرت = صفر» | تعریف PART B | **تصمیم D-01 قبل از Phase 2** |

## ۱۴. Risks

| # | ریسک | شدت | کنترل پیشنهادی |
|---|---|---|---|
| R-01 | تغییر تعریف «کامل» (D-01) جمعیت کامل/ناقص موجود را جابه‌جا می‌کند و پرونده‌های رفع ناقصی جدید می‌سازد | بالا | تصمیم صریح + گزارش Dry-run اثر قبل از اعمال؛ هیچ بازمحاسبهٔ انبوه بدون تأیید |
| R-02 | مشتریانی که امروز ناقص‌اند ولی مسیر دارند (G1) | متوسط | Phase 2: مسیر موجود حذف نشود؛ فقط انتخاب جدید مسدود شود؛ گزارش جمعیت |
| R-03 | `journey_state='refund'` legacy و مشتریانی که Outcome مسیرشان را تغییر داده | متوسط | حفظ داده؛ نمایش «legacy»؛ بدون بازنویسی (Invariant 29) |
| R-04 | حذف/تغییر ردیف legacy `financial_cases` دسترسی مالی و مدارک را می‌شکند | بالا | D-03؛ جایگزینی اثرات قبل از هر تغییر |
| R-05 | بستن تخصیص هنگام تکمیل رفع ناقصی برای کار جاری کارشناس تماس | متوسط | فقط `returned` (وضعیت موجود) + Audit؛ بدون حذف |
| R-06 | تداخل تهاتر با خدمات مازاد (G8) در داده‌های تاریخی | متوسط | ردیف‌های قدیمی حفظ؛ Engine v3 فقط برای تصمیم‌های جدید |
| R-07 | `ajax_select_services()` مسیر را **قبل** از ساخت پرونده‌های خدمت ثبت می‌کند؛ شکست بعدی، مسیر «خدمات مازاد» را بدون خدمت باقی می‌گذارد | متوسط | Phase 2/8: ترتیب اتمیک (مسیر و پرونده در یک تراکنش) |
| R-08 | `bcrm_supervisor` با `$crm_all` به همه‌چیز از جمله نارضایتی دسترسی دارد | متوسط | Phase 1: بازبینی صریح؛ Cap برداشته‌شده توسط Admin حفظ می‌شود |
| R-09 | تست‌های static قدیمی ۲۵ FAIL پایهٔ ثابت دارند (pin نسخه 0.8.59.7) و ممکن است رگرسیون واقعی را پنهان کنند | پایین | جدا کردن pinها از assertهای رفتاری در Phase بعدی که فایل‌ها را لمس می‌کند |
| R-10 | نسخهٔ Sales Network محلی (2.0.123) ≠ مبنای سند (2.0.115) ≠ تولید (نامعلوم) | متوسط | D-06 قبل از Phase 9 |
| R-11 | باگ‌های امنیتی ON HOLD (S1/S2، S5، …) | بالا | تصمیم شما؛ در این Phase دست نخورد |

## ۱۵. Implementation Order و وابستگی Phaseها

| Phase | پیش‌نیاز | Reuse | وابستگی به تصمیم |
|---|---|---|---|
| 1 — سازمان/واحد/نقش | — | `service_units`، Ledger نقش/Cap، Seed واحد 0.8.63.0؛ فقط افزودن IT (واحد + ۲ نقش + Cap استعلام IT) و تکمیل مدیر/سرپرست/کارشناس خدمات پس از فروش | D-04 (نقش‌های جدید یا Reuse نقش‌های نارضایتی) |
| 2 — Customer Journey Alignment | 1 | CJA-1…5 طراحی‌شده در Phase 3.1؛ `assignments`/`returned`؛ `customer_tags` | **D-01**، D-03، D-05 |
| 3 — Dissatisfaction/Complaint | 2 | طراحی 3.1: `state`، Engine switch، Tabها، Unit-based assignment | — |
| 4 — Inquiry Engine | 3 | `complaint_inquiries` + پیکربندی واحد 0.8.64.x؛ افزودن IT | — |
| 5 — Decision/Outcome | 3، 4 | `catalog_items` + Outcome policy flags (O-10)؛ Engine v3 بستن G8/G9 | — |
| 6 — Refund Planning | 5 | Plan/Items/Allocations؛ `refunds`/`refund_registers` دست‌نخورده | — |
| 7 — Treasury | 6 | واحد/Cap خزانه؛ O-12 | — |
| 8 — Additional Services Selection | 2 | `service_catalog`، `select_services()`؛ Outbox محلی بدون API خارجی | — |
| 9 — Biawin Integration | 8 + قرارداد واقعی | Revision 3 سند یکپارچگی | **BLOCKED** تا Q08، Q26–Q33 و Part O |
| 10 — Regression/Release | 1–9 | — | — |

**نگاشت به طرح تأییدشدهٔ قبلی (Phase 3.1):** CJA → Phase 2؛ 3.2 + 3.3 → Phase 3 و 5؛ 3.4 → Phase 4؛ 3.5 → Phase 6 (+ تهاتر در Phase 5)؛ 3.6 → Phase 7؛ 3.7 → گزارش‌های Phase 3/6/7. نسخه‌های Migration برنامه‌ریزی‌شده (0.8.65.0–0.8.69.0) با ترتیب جدید قابل تطبیق‌اند. هیچ تصمیم تأییدشدهٔ 3.1 با Master Roadmap تعارض ندارد؛ فقط IT (Phase 1) و D-01 موضوع جدید هستند.

## ۱۶. تست‌ها (اجرا شده در Phase 0)

| مجموعه | نتیجه |
|---|---|
| Static suites (۲۰ فایل `tests/*.php`) | همه اجرا شد. ۱۲ فایل 0 FAIL؛ ۸ فایل جمعاً ۲۵ FAIL، **دقیقاً همان Baseline ثبت‌شده در QA-REPORT-v0.8.64.1** (pin نسخه 0.8.59.7 و gateهای code-only قدیمی). رگرسیون جدیدی نیست. |
| receipt-upload harness | 609/0 |
| refund-regression | 141/0 |
| after-sales-config / foundation / inquiry-hardening / final-hardening | 30/30، 41/0، 20/20، 16/16 |
| شبیه‌سازی به‌روزرسانی روی WordPress واقعی (`run-p21.sh`، 0.8.64.0 → 0.8.64.1، Docker throwaway) | **67/67 PASS، 0 FAIL**: هویت کامل پیش/پس از به‌روزرسانی (کاربران و رمزها، نقش‌ها، کاتالوگ خدمات، واحدها، اعضا، نارضایتی‌ها، استعلام‌ها، کاتالوگ، داده‌های کسب‌وکار، Ledgerها) ۱۲/۱۲؛ اجرای مجدد اجباری Migration از 0.8.64.0 و 0.8.63.2 هر کدام ۱۲/۱۲ یکسان؛ تست‌های عملکردی ۳۱/۳۱؛ debug.log بدون خط افزونه |

**Upgrade Safety (الزام MASTER):** شبیه‌سازی بالا تأیید می‌کند که به‌روزرسانی Baseline فعلی کاربر حذف‌شده را بازسازی نمی‌کند، نقش و Capability را Reset نمی‌کند، تنظیمات Admin را بازنویسی نمی‌کند، کاتالوگ خدمات را Reset نمی‌کند و داده تکراری نمی‌سازد. چون Phase 0 هیچ کدی تغییر نداده، این نتیجه Baseline مرجع Phaseهای بعد است.

## ۱۷. تصمیم‌های باز (نیازمند پاسخ شما)

| کد | تصمیم | چرا لازم است | Phase |
|---|---|---|---|
| **D-01** | «کامل» دقیقاً «مغایرت = 0» است یا Tolerance فعلی (حداقل ۵۰۰٬۰۰۰ تومان، قابل تنظیم فقط به بالا) حفظ شود؟ | PART B «مغایرت = صفر» می‌گوید؛ کد از 0.8.5x کف ۵۰۰٬۰۰۰ دارد و تغییر آن جمعیت را جابه‌جا می‌کند | پیش از 2 |
| D-02 | فاکتورهای `exclude_from_total=1` (مثلاً پیش‌پرداخت تأییدنشده) جزء «مجموع فاکتورها» نیستند — تأیید؟ | تعریف PART B «مجموع مبلغ فاکتورهای متصل» | 2 |
| D-03 | ردیف legacy `financial_cases` (AMB-8): حفظ به‌عنوان رکورد legacy، یا جایگزینی اثرات جانبی و توقف ساخت خودکار؟ | با «تأیید مالی فقط در صورت نیاز» تعارض ظاهری دارد و اثر دسترسی/مدارک/KPI دارد | 2 |
| D-04 | Phase 1: نقش‌های «سرپرست/کارشناس نارضایتی» موجود همان «سرپرست نارضایتی / کارشناسان خدمات پس از فروش» Master هستند (Reuse + برچسب)، یا نقش جدید `کارشناس خدمات پس از فروش` لازم است؟ | جلوگیری از نقش تکراری (Change Control) | 1 |
| D-05 | پایان چرخهٔ «بدون خدمات»: تخصیص فعال کارشناس تماس با کدام وضعیت موجود بسته شود (AMB-6)؟ | `returned` معنای «برگشت به صف» دارد | 2 |
| D-06 | نسخهٔ تولیدی «CRM فروش بیاوین + عملیات و اجرا» کدام است (2.0.115/2.0.116/2.0.123)؟ | مبنای استخراج Part O | پیش از 9 |

## ۱۸. تغییرات این Phase

- **ایجاد شد:** فقط همین فایل `design/phase-0/PHASE-0-AUDIT-REPORT.md` (بیرون از `extracted/`، وارد ZIP نمی‌شود).
- **تغییر نکرد:** هیچ فایل زیر `extracted/`، هیچ ZIP، هیچ نسخهٔ افزونه، هیچ Migration، جدول، نقش، Capability یا UI.
- **محیط تست:** یک کانتینر MySQL و یک سایت WordPress موقت فقط در scratchpad برای شبیه‌سازی به‌روزرسانی.
