# PHASE 1 — Organization / Units / Roles / Permissions (0.8.65.0)

## ۱. تصمیم‌های قفل‌شده پس از Phase 0 (ثبت رسمی)

| کد | تصمیم | اثر بر Phase 1 | اجرا |
|---|---|---|---|
| D-01 | کامل ⟺ `ABS(declared_paid − invoice_total) = 0`. کف ۵۰۰٬۰۰۰ تعریف کسب‌وکار نیست. | هیچ | Phase 2: ابتدا گزارش Dry-run اثر؛ تغییر جمعیت فقط پس از تأیید صریح |
| D-02 | فاکتورهای `exclude_from_total = 1` جزء مجموع فاکتورها **نیستند** | هیچ | قاعدهٔ فعلی کد (`Customer_Service::recalculate_customer`) همین است؛ تغییری لازم نیست |
| D-03 | `financial_cases` و رفتار تاریخی حفظ می‌شود؛ ناقص ≠ تأیید مالی اجباری | هیچ | Phase 2: پس از نقشهٔ اثرات وابسته |
| D-04 | مدیر خدمات پس از فروش → سرپرست نارضایتی‌ها → کارشناسان خدمات پس از فروش؛ نقش‌های قدیمی حفظ | **اجرا شد** | بخش ۲؛ کاهش دسترسی `bcrm_supervisor`: Deferred to Phase 3 permission migration (بخش ۴) |
| D-05 | پایان «بدون خدمات» = وضعیت پایانی تخصیص؛ نه `returned` | مستندسازی | بخش ۵ |
| D-06 | نسخهٔ تولیدی Sales CRM باز است | هیچ | پیش از Phase 9 |

### قاعدهٔ D-02 (مستند)

```text
invoice_total(customer) = SUM(invoices.amount_paid) WHERE customer_id = customer AND COALESCE(exclude_from_total,0) = 0
```

فاکتور مستثنی‌شده (مثلاً پیش‌پرداخت تأییدنشده) حذف نمی‌شود. فیلدهای `excluded_amount`، `excluded_reason`، `excluded_at` و `excluded_by` حفظ می‌شوند، اما این فاکتور در مقایسه با مبلغ اعلامی شمرده نمی‌شود.

## ۲. ساختار سازمانی پیاده‌شده (روی معماری موجود)

| واحد (`service_units`) | سرپرست واحد (`supervisor_user_id` + عضو `supervisor`) | اعضا (`agent`) | نقش‌ها |
|---|---|---|---|
| `after_sales` «خدمات پس از فروش» | مدیر خدمات پس از فروش | — | `bcrm_after_sales_manager` |
| └ `dissatisfaction` «نارضایتی‌ها» (فرزند) | سرپرست نارضایتی‌ها | کارشناسان خدمات پس از فروش | `bcrm_dissatisfaction_supervisor`، `bcrm_after_sales_agent` |
| `it_engineering` «مهندسین IT» (جدید، سطح اول) | سرپرست IT | کارشناسان IT | `bcrm_it_supervisor`، `bcrm_it_agent` |
| `treasury` «خزانه» | سرپرست خزانه | کارشناسان خزانه | `bcrm_treasury_supervisor`، `bcrm_treasury_agent` (موجود) |

- هیچ `member_role` جدیدی تعریف نشد. سلسله‌مراتب سه‌سطحی خدمات پس از فروش با سلسله‌مراتب واحد (والد/فرزند) بیان می‌شود.
- عضویت‌ها داده‌ای هستند که Admin از «واحدها و اعضا» مدیریت می‌کند. افزونه هیچ عضویتی نمی‌سازد.

## ۳. ماتریس دسترسی نقش‌های Phase 1

| نقش | Capabilityها |
|---|---|
| `bcrm_after_sales_manager` | (موجود) + `view_it_inquiries` |
| `bcrm_dissatisfaction_supervisor` | `read`، `bcrm_work_cases`، `bcrm_complaints`، `view_after_sales`، `view_dissatisfaction`، `manage_dissatisfaction`، `assign_dissatisfaction`، `process_dissatisfaction`، `view_after_sales_reports` |
| `bcrm_after_sales_agent` | `read`، `bcrm_work_cases`، `bcrm_complaints`، `view_after_sales`، `view_dissatisfaction`، `process_dissatisfaction` |
| `bcrm_it_supervisor` / `bcrm_it_agent` | `read`، `bcrm_work_cases`، `view_it_inquiries`، `respond_it_inquiries` |
| `bcrm_complaint_supervisor` / `_agent` (قدیمی) | بدون تغییر (= معادل نهایی) |
| administrator / `bcrm_portal_admin` | + `view_it_inquiries`، `respond_it_inquiries` |

`bcrm_complaints` روی نقش‌های نهایی لازم است، چون کارتابل و پروفایل **موجود** نارضایتی با آن کنترل می‌شوند (تا بازطراحی Phase 3).

## ۴. `bcrm_supervisor` — Deferred to Phase 3 permission migration

اصلاح پیش از تأیید (۲۰۲۶-۱۰-۰۲): هیچ کاهشی اعمال نمی‌شود و رفتار «سرپرست CRM» عیناً مثل 0.8.64.1 می‌ماند.
- روی نصب‌های موجود و نصب تازه، این دسترسی‌ها حفظ می‌شوند: ۶ Cap نارضایتی خدمات پس از فروش، `bcrm_complaints` و `bcrm_manage_cases`.
- جداسازی سرپرست CRM از مرز نارضایتی (حذف Capها و بازبینی دروازهٔ `bcrm_manage_cases` در `can_manage_complaint()`) بخشی از Migration کنترل‌شدهٔ دسترسی‌ها در **Phase 3** است.

## ۴.۱ مرز معنایی دسترسی‌های IT

| Capability | معنای دقیق | آنچه **نمی‌دهد** |
|---|---|---|
| `view_it_inquiries` | مشاهدهٔ استعلام‌های IT و نتیجهٔ آن‌ها **در پرونده‌های خدمات پس از فروش** | مدیریت واحد IT، اعضا، تخصیص یا تنظیمات |
| `respond_it_inquiries` | پاسخ به استعلام‌های IT ارسال‌شده به واحد | همان |

- محدودهٔ این دو Cap با Capهای استعلام مالی/وام/خدمات یکی است.
- مدیر خدمات پس از فروش فقط `view_it_inquiries` دارد و مدیر IT **نیست**.
- مدیریت واحد/اعضا/کاربران/تخصیص پشت `bcrm_manage_cases` است و پیکربندی استعلام واحدها پشت `manage_inquiry_units`. مدیر خدمات پس از فروش هیچ‌کدام را ندارد؛ آزمون واقعی: ویرایش واحد IT، تنظیم استعلام آن و ساخت کاربر IT برای او رد می‌شود.
- پیاده‌سازی این Capها تا Phase 4 (Inquiry) تغییر نمی‌کند.

## ۴.۲ مدل سازمانی (تأییدشده)

```text
واحد خدمات پس از فروش (after_sales)
    ├── مدیر خدمات پس از فروش            ← supervisor واحد after_sales
    └── حوزه نارضایتی‌ها (dissatisfaction، parent_unit_id = after_sales)
            ├── سرپرست نارضایتی‌ها         ← supervisor
            └── کارشناسان خدمات پس از فروش ← member_role = agent

واحد مهندسین IT (it_engineering، سطح اول، مستقل)
    ├── سرپرست IT                         ← supervisor
    └── کارشناسان IT                      ← agent

واحد خزانه (treasury)
    ├── سرپرست خزانه                      ← supervisor
    └── کارشناسان خزانه                   ← agent
```

فقط از `service_units`، `service_unit_members`، `parent_unit_id` و `member_role` (supervisor/agent) استفاده شده است. هیچ موتور سازمانی دیگری وجود ندارد.

## ۵. D-05 — وضعیت پایانی تخصیص برای «بدون خدمات» (تعریف برای Phase 2)

| وضعیت فعلی `assignments.status` | معنا |
|---|---|
| `assigned` | تخصیص فعال (Guard یکتا: `active_customer_guard`) |
| `returned` | برگشت به صف تخصیص‌نیافته (سرپرست) |
| `duplicate_closed` | بستن خودکار تخصیص فعال تکراری (Repair) |

**نتیجه:** هیچ وضعیت پایانی «تکمیل‌شده» در موتور فعلی وجود ندارد. **پیشنهاد برای Phase 2 (در Phase 1 پیاده نشده است):**
- وضعیت جدید `completed` به‌عنوان مقدار جدید همان ستون `VARCHAR(40)`، بدون تغییر schema.
- `completed_at` = زمان ثبت مسیر بدون خدمات.
- Generated column `active_customer_guard` فقط `assigned` را فعال می‌داند، پس `completed` به‌طور خودکار غیرفعال است.
- استخر سرپرست («بدون تخصیص فعال») باید مشتریان `completed` + بایگانی بدون خدمات را از صف تخصیص مجدد مستثنا کند.
- `returned` بازتعریف نمی‌شود.

## ۶. وابستگی Phase 2

- **D-01:** گزارش Dry-run (جمعیت کامل/ناقص فعلی، تغییرکننده‌ها، ورود جدید به رفع ناقصی، پرونده‌های جدید بالقوه) **قبل از** هر تغییر رفتار. هشدار: `activate()` در هر Upgrade، `backfill_deficiency_cases()` را با Tolerance اجرا می‌کند. تغییر Tolerance بدون کنترل، پرونده‌های رفع ناقصی جدید می‌سازد.
- **D-03:** نقشهٔ اثرات `financial_cases` پیش از هر تغییر در ساخت خودکار.
- **D-05:** وضعیت `completed`.
- CJA-1…5 (طراحی تأییدشدهٔ 3.1).
