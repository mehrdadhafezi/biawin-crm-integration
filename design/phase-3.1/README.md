# Phase 3.1 — Technical Design (Complaint Domain)

**Baseline:** v0.8.64.1 · **قرارداد:** PHASE-3-COMPLAINT-DOMAIN-CONTRACT.md · **وضعیت:** ✅ Approved Architecture Contract — 🔒 بازبینی ۵: FINAL BUSINESS ARCHITECTURE LOCK
این پوشه بیرون از `extracted/` است و وارد ZIP افزونه نمی‌شود. هیچ Production Code، Migration یا ZIP تولید نشده است.

**شروع از:** [PHASE-3.1-ARCHITECTURE-FINAL](PHASE-3.1-ARCHITECTURE-FINAL.md)

| # | سند | محتوا |
|---|---|---|
| — | [PHASE-3.1-ARCHITECTURE-FINAL](PHASE-3.1-ARCHITECTURE-FINAL.md) | خلاصه قرارداد: Journey نهایی، مفاهیم جدا، مسیر مشتری در برابر نتیجه نارضایتی، رفع ناقصی → کامل → تخصیص مجدد، مرز خدمات مازاد، مرز نارضایتی، CURRENT/TARGET، فاز ۳.۲، Migrationها، ابهام‌ها |
| 01 | [ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md) | §0 AUTHORITATIVE CUSTOMER JOURNEY — FINAL (J1–J18، G1–G11، CJA-1…7، تفسیرهای ممنوع)، Decision Log، Mapping، Touchpointها، ابهام‌ها |
| 02 | [DATABASE-DESIGN](02-DATABASE-DESIGN.md) | Reuse ساختارهای Core، نمایش `COMPLETED_AFTER_DEFICIENCY`، جداول نارضایتی، Migrationها، Refund Ledger Rule |
| 03 | [STATE-MACHINE](03-STATE-MACHINE.md) | مرزهای Core (ارجاعی)، پرونده نارضایتی، پیگیری مجدد، استعلام، اقدام، عودت |
| 04 | [API-CONTRACT](04-API-CONTRACT.md) | Endpointهای موجود Core (ارجاع)، Domain Services، REST، AJAX، خطاها، مرز یکپارچگی خدمات مازاد |
| 05 | [PERMISSION-MATRIX](05-PERMISSION-MATRIX.md) | مجوزهای Core Journey، ماتریس نارضایتی، Visibility، استعلام، خزانه، Cutover |

## اصطلاحات (فارسی اول، شناسه فنی کنار آن)

| فارسی | شناسه فنی |
|---|---|
| پروفایل مشتری / شناسه مشتری | `customers` / `customer_id` |
| وضعیت پروفایل: کامل · ناقص | `profile_completeness`: `complete` · `incomplete` |
| فاکتور | `invoice` |
| مبلغ اعلامی مشتری | `declared_paid` |
| رفع ناقصی | `deficiency` |
| تأیید مالی | `finance verification` |
| تکمیل‌شده پس از رفع ناقصی | `COMPLETED_AFTER_DEFICIENCY` |
| تخصیص / تخصیص توسط سرپرست | `assignment` |
| مسیر مشتری: ناراضی · بدون خدمات · خدمات مازاد | `journey_state`: `complaint` · `no_request` · `service_request` |
| بایگانی بدون خدمات | `no-service archive` |
| خدمات مازاد | `additional services` |
| یکپارچگی خدمات مازاد (API / Web Service) | `additional services integration` |
| اشتراک / خدمت / کارت (سامانه Biawin) | `subscription` / `service` / `card` |
| خدمات پس از فروش | `after-sales service` |
| پرونده نارضایتی / وضعیت پرونده | `complaint` / `complaints.state` |
| نتیجه نارضایتی | `resolution_outcome` |
| استعلام | `inquiry` |
| اقدام | `action` |
| عودت | `refund` |
| تهاتر | `offset` |
| پیگیری مجدد | `follow-up` |
