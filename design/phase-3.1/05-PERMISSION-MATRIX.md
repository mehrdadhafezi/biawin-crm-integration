# Phase 3.1 — سند ۵: Permission Matrix

**Baseline:** v0.8.64.1 · **وضعیت:** ✅ Approved — بازبینی ۵ (FINAL BUSINESS ARCHITECTURE LOCK) · مراجع: [۰۳](03-STATE-MACHINE.md) · [۰۴](04-API-CONTRACT.md)

## 1. اصول

1. **دو لایه، هر دو لازم:** Capability **و** قاعده سطر (Membership / Assignment / مسئول اکشن / عضویت خزانه)، مگر Capabilityهای سازمانی صریح §3.
2. **Role وردپرس به‌تنهایی مالکیت نمی‌دهد (C9)**؛ مالکیت = `assigned_unit_id` / `assigned_supervisor_id` / `assigned_agent_id`.
3. **بدون Capability تکراری:** ۲۲ Capability فاز ۱/۲ reuse؛ فقط ۲ Capability جدید (§6).
4. **Provisioning فقط additive و Ledger-based**؛ هیچ حذف/بازنشانی.
5. **یک Predicate دسترسی:** `Complaint_Service::visibility_sql()`؛ یک نگاشت پاسخ: `Inquiry_Service::respond_capability()`؛ یک بررسی خزانه: `Unit_People::is_treasury_member()`.
6. **عدم افشا:** خارج از Visibility → `NOT_FOUND`.
7. **هیچ مجوز فاز ۳ اجازه نوشتن منطق Core را نمی‌دهد** ([۰۱ §0](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)): مسیریابی، Completeness، رفع ناقصی، فاکتورها و انتخاب خدمت فقط با مجوزها و توابع موجود خودشان انجام می‌شوند.

تعاریف: `S(u)` سرپرستان واحد (`supervisor_user_id` + اعضای `supervisor`)؛ `A(u)` کارشناسان `agent`؛ `R(u)` = `get_inquiry_responders(u)`؛ **مسئول** = `assigned_agent_id`؛
**مدیر خدمات پس از فروش (ASM)** = کاربر با `manage_after_sales`؛
**سرپرست نارضایتی (CS)** = کاربر ∈ `S(c.assigned_unit_id)` یا ∈ `S(واحدهای والد آن)`، با `assign_dissatisfaction`؛
**عضو خزانه (TM)** = عضو (هر نقش) یک واحد **فعال** با `unit_type='treasury'`.

---

## 2. ماتریس عملیات

| عملیات | Capability | قاعده سطر |
|---|---|---|
| ثبت نارضایتی (T1) = مسیر مشتری «ناراضی» | `bcrm_work_cases` (همان مجوز موجود مسیریابی) | `can_access_customer`؛ `set_customer_journey('complaint')` موجود موفق؛ پروفایل `complete` (R-1) |
| مشاهده Workspace / لیست | `view_dissatisfaction` | Visibility §3 |
| تخصیص / تخصیص مجدد (T2, T13) | `assign_dissatisfaction` | CS یا ASM؛ کارشناس مقصد ∈ `A(unit مقصد)` |
| شروع بررسی، آماده تصمیم، بازگشت (T3, T4, T6, T7) | `process_dissatisfaction` | مسئول یا CS |
| انتخاب Outcome (T8) | `process_dissatisfaction` | مسئول یا CS؛ مسئول Follow-up ∈ `A(unit) ∪ S(unit)` |
| شروع / زمان‌بندی مجدد پیگیری (T14, T15) | `process_dissatisfaction` | مسئول پیگیری، مسئول پرونده یا CS |
| تغییر تصمیم (T10) | `assign_dissatisfaction` | CS یا ASM |
| **RESOLVED** | — | **هیچ کاربر؛ فقط سیستم (O-1)** |
| **بستن (T11)** | `assign_dissatisfaction` (CS) یا `manage_after_sales` (ASM) | **فقط CS یا ASM (O-2)**؛ کارشناس مسئول نمی‌بندد |
| **بازگشایی (T12)** | همان | **فقط CS یا ASM** |
| ساخت/ارسال/بستن Inquiry (I1, I2, I6–I8, M1, M2) | `process_dissatisfaction` | مسئول یا CS |
| Claim / Release / Answer (I3–I5) — D8 | `respond_capability(unit مقصد)` (§4) | ∈ `R(unit مقصد)` و `unit_accepts_inquiries` |
| بستن به دلیل `unit_unavailable` (I9) | `assign_dissatisfaction` | CS یا ASM |
| اکشن `FINANCE_VERIFICATION` | `respond_finance_inquiries` | ∈ `R(واحد مالی اکشن)` |
| اکشن `INQUIRY` / `REFUND_PLAN` / پیوند `SERVICE_FOLLOWTHROUGH` | `process_dissatisfaction` | مسئول یا CS |
| **انتخاب خدمت تهاتر** (اکشن `SERVICE_OFFSET` → `create_service_cases()` مشترک، بدون تغییر مسیر مشتری) | `bcrm_services` | عضو «واحد تهاتر» (`biawin_crm_offset_unit_id`)؛ کارشناس نارضایتی انتخاب‌کننده نهایی نیست |
| مسدود/لغو اکشن mandatory | `assign_dissatisfaction` | CS یا ASM |
| **تأیید/جایگزینی/رد تاریخ عودت و ارسال به خزانه (R2–R5, R7)** | `approve_refund_schedule` | **TM (O-12)** |
| **Override ظرفیت** | `override_treasury_capacity` | **TM (O-12)** + دلیل |
| مشاهده صف خزانه | `view_treasury` | — (خلاصه محدود L4) |
| **پردازش پرداخت (کار روی صف: آماده‌سازی، درخواست زمان‌بندی مجدد)** | `process_refund_payment` | **TM (O-12)** |
| **ثبت پرداخت (R6)** | `process_refund_payment` | **TM (O-12)** |
| تنظیم ظرفیت/تقویم | `manage_treasury_capacity` | TM |
| موضوعات (Admin) | `manage_dissatisfaction_subjects` | — |
| Outcomeها و Policyها (Admin) | `manage_dissatisfaction_outcomes` | — |
| واحدهای قابل استعلام (Admin) | `manage_inquiry_units` | — |
| واحد Intake، Engine Switch | `manage_after_sales_settings` | — |
| گزارش‌ها (3.7) | `view_after_sales_reports` | داده تجمیعی؛ لیست سطری با Visibility §3 |

---

## 3. Visibility

**کامل** — `view_dissatisfaction` و یکی از:

| # | شرط |
|---|---|
| V1 | `manage_dissatisfaction` (سطح سازمانی — Capability، نه Role) |
| V2 | `c.assigned_agent_id = v` |
| V3 | `v ∈ S(c.assigned_unit_id)` یا ∈ `S(واحدهای والد)` |
| V4 | `c.assigned_unit_id IS NULL` و `v ∈ S(intake unit)` |
| V5 | `v` مسئول Follow-up `SCHEDULED` پرونده |

**محدود** (خلاصه پرونده + همان آیتم):

| # | شرط | محتوا |
|---|---|---|
| L1 | پاسخ‌دهنده واجد شرایط یک Inquiry غیر DRAFT (§4) | همان Inquiry |
| L2 | مسئول یک اکشن باز (مثلاً واحد مالی) | همان اکشن |
| L3 | `approve_refund_schedule` + TM، Plan `SUBMITTED`/`DECIDED` | Plan |
| L4 | `view_treasury`، Item `SENT_TO_TREASURY`/`PAID` | مشتری، پرونده، فاکتور، مبلغ، تاریخ، وضعیت |
| L5 | `can_access_customer` | پنل پروفایل: موضوع/وضعیت پرونده‌ها (رفتار فعلی؛ بدون عملیات) |
| L6 | `bcrm_services` + عضو «واحد تهاتر»، اکشن `SERVICE_OFFSET` باز | خلاصه پرونده + مشتری برای انتخاب خدمت |

کارشناسان غیرمسئول واحد پرونده را نمی‌بینند (بدون Self-assign).

### 3.1 جایگزینی منطق فعلی
| امروز | پس از Cutover |
|---|---|
| `can_manage_complaint()`: `bcrm_manage_cases` یا Role `bcrm_complaint_supervisor` | §2 + §3 |
| `frontend.php` L869/871/2027: `status='open' AND owner…` | `visibility_sql()` با `state <> 'CLOSED'` |
| منوی «نارضایتی‌ها» با `bcrm_complaints` | منوی legacy حفظ؛ Workspace با `view_dissatisfaction` |

---

## 4. پاسخ Inquiry — D8

```text
can_respond(v, inquiry) = v ∈ get_inquiry_responders(unit)
                       AND unit_accepts_inquiries(unit)
                       AND user_can(v, respond_capability(unit))
```

| `unit_type` مقصد | `respond_capability` |
|---|---|
| `finance` | `respond_finance_inquiries` |
| `loan` | `respond_loan_inquiries` |
| `service`, `service_group` | `respond_service_inquiries` |
| `treasury`، `after_sales`، `dissatisfaction`، `other`، سایر | `respond_unit_inquiries` (جدید) |

---

## 5. قاعده خزانه — O-12

```text
treasury_allowed(v, op) = user_can(v, cap(op)) AND Unit_People::is_treasury_member(v)
cap(approve_schedule | send_to_treasury) = approve_refund_schedule
cap(override_capacity)                   = override_treasury_capacity
cap(process_payment | record_payment)    = process_refund_payment
cap(capacity_settings)                   = manage_treasury_capacity
```
- Capability به‌تنهایی کافی نیست؛ عضویت از `service_unit_members` (هر نقش) یا `supervisor_user_id` واحد خزانه فعال.
- در هر تراکنش دوباره بررسی می‌شود (لغو عضویت فوراً اثر دارد).
- **اثر بر نقش‌ها:** `bcrm_after_sales_manager` امروز `approve_refund_schedule` دارد ولی برای تأیید برنامه عودت باید **عضو واحد خزانه** باشد (O-13 در ۰۱ §6).

---

## 6. Capabilityهای جدید (0.8.65.0، Ledger additive، `CAPS_MARKER_VERSION` 5 → 6)

| Capability | Label | اعطای پیش‌فرض |
|---|---|---|
| `respond_unit_inquiries` | پاسخ استعلام سایر واحدها | `administrator`, `bcrm_portal_admin`, `bcrm_treasury_supervisor`, `bcrm_treasury_agent` |
| `override_treasury_capacity` | Override ظرفیت خزانه | `administrator`, `bcrm_portal_admin` (اعطای فردی از «کاربران پرتال») |

هیچ نقش کارشناس عادی Capability جدید مدیریتی نمی‌گیرد.

### 6.1 اعطای فعلی (فاز ۱ — بدون تغییر) و اثر

| Role | Capabilityهای مرتبط | اثر |
|---|---|---|
| `administrator`, `bcrm_portal_admin` | همه | V1؛ ASM؛ عملیات خزانه فقط با TM |
| `bcrm_after_sales_manager` | view/manage/assign/process dissatisfaction، `manage_after_sales`، reports، settings، subjects، outcomes، view_*_inquiries، view_treasury، `approve_refund_schedule` | V1؛ ASM (بستن/بازگشایی)؛ تأیید عودت فقط با TM |
| `bcrm_supervisor`, `bcrm_complaint_supervisor` | view/manage/assign/process dissatisfaction، reports | V1 (به‌دلیل `manage_dissatisfaction`)؛ **بستن/بازگشایی فقط اگر CS واحد پرونده باشند** (`manage_after_sales` ندارند) |
| `bcrm_complaint_agent` | view_after_sales، view_dissatisfaction، process_dissatisfaction | V2؛ بدون بستن |
| `bcrm_financial_*` | view/respond finance inquiries | L1/L2 با عضویت واحد مالی |
| `bcrm_service_*`, `bcrm_loan_*` | view/respond service/loan inquiries | L1 |
| `bcrm_treasury_supervisor` | view_treasury، manage_treasury، manage_treasury_capacity، process_refund_payment | L4؛ پرداخت و ظرفیت با TM |
| `bcrm_treasury_agent` | view_treasury، process_refund_payment | L4؛ پرداخت با TM |

اگر Business دید سازمانی سرپرستان را محدود به واحد بخواهد، Admin `manage_dissatisfaction` را از آن نقش‌ها برمی‌دارد (Ledger برنمی‌گرداند).

---

### 6.2 مجوزهای Customer Journey (Core — خارج از فاز ۳؛ فقط Capabilityهای موجود)

هیچ نقش کسب‌وکاری یا Capability جدیدی برای Journey تعریف نمی‌شود؛ ستون TARGET قاعده سطر لازم را با همان Capabilityهای موجود بیان می‌کند و فاصله‌ها در Track CJA اجرا می‌شوند ([۰۱ §0.7](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)).

| عملیات Core | بازیگر | Capability موجود | CURRENT (0.8.64.1) | TARGET (قرارداد) |
|---|---|---|---|---|
| تخصیص مشتری به کارشناس تماس (اولیه و «تکمیل‌شده پس از رفع ناقصی») | سرپرست (تخصیص توسط سرپرست) | `bcrm_manage_cases` (صفحه «تخصیص پرونده»: `bcrm_assign_customers` / `bcrm_return_assignments`) | سرپرست تخصیص می‌دهد؛ صف «تکمیل‌شده پس از رفع ناقصی» وجود ندارد | همان موتور؛ مشتری تکمیل‌شده پس از رفع ناقصی با وضعیت موجود `returned` به استخر برمی‌گردد و با فیلتر تگ «تکمیل‌شده پس از رفع ناقصی» (`COMPLETED_AFTER_DEFICIENCY`) دیده می‌شود (CJA-2/3) |
| تماس و ثبت مبلغ اعلامی | کارشناس تماس | `bcrm_work_cases` + دسترسی به مشتری | همان | همان |
| بررسی پروفایل ناقص | کارشناسان رفع ناقصی | `view_deficiency_cases`، `process_deficiency_case`، `complete_deficiency_case` (سرپرست: `assign_deficiency_cases`، `manage_deficiency_cases`) | همان | همان؛ فقط پروفایل ناقص |
| تأیید مالی | کارشناسان/سرپرست تأیید مالی | `view_finance_cases`، `review_finance_case`، `approve_finance_case`، `reject_finance_case`، `return_finance_case` | پرونده دپارتمان مالی فقط با `send_case_to_finance` رفع ناقصی (اختیاری) — اما `sync_customer()` برای هر ناقص پرونده legacy `financial_cases` هم می‌سازد | فقط وقتی رفع ناقصی ارسال کند (AMB-8 / CJA-1) |
| انتخاب مسیر مشتری (ناراضی / بدون خدمات / خدمات مازاد) | کارشناس تماس **تخصیص‌یافته** | `bcrm_work_cases` | هر کاربر با `bcrm_work_cases` و دسترسی به مشتری، حتی برای پروفایل ناقص و بدون تماس جدید | فقط پروفایل کامل؛ فقط کارشناس تماس دارای تخصیص فعال؛ پس از رفع ناقصی فقط پس از تماس جدید (CJA-1/4) |
| ثبت «بدون خدمات» و بایگانی | کارشناس تماس تخصیص‌یافته | `bcrm_work_cases` | `no_request` ثبت می‌شود؛ لیست بایگانی ندارد | ثبت نتیجه → بایگانی بدون خدمات (پایان چرخه فعال)؛ مشاهده/جست‌وجو برای دارندگان دسترسی موجود لیست مشتریان (CJA-5) |
| انتخاب خدمات مازاد | کارشناس تماس تخصیص‌یافته | `bcrm_work_cases` (`bcrm_select_services`) | همان؛ پرونده خدمت داخلی | همان؛ سپس مرز API بیرونی (CJA-6) |
| مرز یکپارچگی بیرونی خدمات مازاد (تنها مسیر یکپارچگی Biawin) | سامانه | — | وجود ندارد | در قرارداد یکپارچگی؛ هیچ مجوز نارضایتی/تهاتر/عودت به آن دسترسی ندارد جداگانه؛ هیچ مجوز یا نقش در فاز ۳.۱ |
| ورود به خدمات پس از فروش (ناراضی) و رسیدگی | کارشناسان نارضایتی | `bcrm_complaints` (legacy)، `view_dissatisfaction`، `process_dissatisfaction` | پرونده حتی برای ناقص ساخته می‌شود | فقط از مسیر ناراضی و پروفایل کامل (R-1، §2) |

کارشناس رفع ناقصی پس از تکمیل، مسیر مشتری را انتخاب نمی‌کند (تخصیص فعال تماس ندارد). هیچ Capability فاز ۳ اجازه هیچ‌کدام از عملیات بالا را نمی‌دهد یا آن را محدود نمی‌کند.

## 7. چک‌لیست Cutover (پیش‌شرط `biawin_crm_complaint_engine = v3`)

0. Core Journey Alignment (CJA-1…7، [۰۱ §0.8](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)) تأیید و منتشر شده است.
1. واحد «نارضایتی‌ها» فعال؛ `biawin_crm_complaint_intake_unit_id` به آن اشاره دارد.
2. حداقل یک سرپرست (CS) و کارشناسان `agent` برای واحد Intake؛ کاربران `bcrm_complaint_agent` عضو `agent` شده‌اند.
3. حداقل یک کاربر ASM (`manage_after_sales`).
4. اگر Outcome فعالی `requires_refund` دارد: واحد خزانه فعال با عضو؛ کاربر(ان) تأییدکننده برنامه عودت **عضو واحد خزانه** (O-12/O-13)؛ ظرفیت پیش‌فرض تعریف شده.
5. اگر `requires_finance`: واحد `finance` فعال، استعلام‌پذیر، با پاسخ‌دهنده دارای `respond_finance_inquiries`.
6. اگر Outcome فعالی `requires_offset` دارد (پیش‌فرض: `service_request`): «واحد تهاتر» فعال با عضو دارای `bcrm_services` و Option `biawin_crm_offset_unit_id` تنظیم شده.
7. هر Outcome فعال `is_selectable` (Flagها در نسخه نصب‌شده پشتیبانی می‌شوند — ۰۳ §6).
8. صفحه Admin تا رفع موارد بالا Engine Switch را غیرفعال و علت را نمایش می‌دهد.
9. پس از سوئیچ: سرپرست پرونده‌های تاریخی `NEW` را از تب «ورودی‌های جدید» تخصیص می‌دهد.

---

## 8. Static guardها
- هیچ بررسی Role برای مالکیت/بستن پرونده.
- هیچ `UPDATE`/`INSERT`/`DELETE` روی `customers`، `invoices`، `assignments`، `customer_tags`، `phones`، `invoice_phones`، `deficiency_cases`، `case_actions`، `financial_cases`، `service_catalog`، `refund_registers` در کلاس‌های فاز ۳؛ تنها نوشتن روی `service_cases` = ستون `complaint_action_id`.
- هیچ `INSERT` در `service_cases` خارج از `Workflow_Service::create_service_cases()` مشترک (که `select_services()` نیز از آن استفاده می‌کند)؛ هیچ نوشتن `journey_state` در کلاس‌های فاز ۳.
- هیچ Query پرونده خارج از `visibility_sql()` در UI/REST/AJAX.
- هیچ Endpoint/متد عمومی که `state='RESOLVED'` بنویسد.
- `respond_*` فقط از `respond_capability()`؛ عملیات خزانه فقط با `treasury_allowed()`.
- Capabilityهای جدید فقط در `after_sales_capabilities()`/`after_sales_caps()` با افزایش Marker.
