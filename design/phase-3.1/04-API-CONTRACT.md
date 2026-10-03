# Phase 3.1 — سند ۴: API Contract

**Baseline:** v0.8.64.1 · **وضعیت:** ✅ Approved — بازبینی ۵ (FINAL BUSINESS ARCHITECTURE LOCK) · مراجع: [۰۲](02-DATABASE-DESIGN.md) · [۰۳](03-STATE-MACHINE.md) · [۰۵](05-PERMISSION-MATRIX.md)

سه لایه، یک منطق:
1. **Domain Services (PHP)** — تنها محل منطق کسب‌وکار، Transition و Visibility.
2. **REST** (`biawin-crm/v1`) و **AJAX پرتال** (`wp_ajax_bcrm_*`، nonce `bcrm_front_ajax`) — لایه نازک: Capability پایه، Validation، فراخوانی Service.
3. **admin-post** — فرم‌های Admin (الگوی `Biawin_CRM_After_Sales_Config_Admin`).

هیچ Endpoint منطق Transition، Visibility، Responder یا عضویت خزانه را تکرار نمی‌کند.

**مرز Core ([۰۱ §0](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)):** هیچ API فاز ۳ Import، تجمیع پروفایل، فاکتور، مبلغ اعلامی، Completeness، رفع ناقصی، مسیریابی یا انتخاب خدمت را بازپیاده نمی‌کند؛ فقط توابع موجود Core (`set_customer_journey()`، `select_services()`) را با معنای فعلی‌شان فرا می‌خواند و `customers`/`invoices` را فقط می‌خواند.

---

## 0. Endpointهای موجود Core — ارجاع، نه جایگزینی

این Endpointها و Handlerهای **موجود** (0.8.64.1) منطق Core را اجرا می‌کنند؛ فاز ۳ آن‌ها را جایگزین یا تکرار نمی‌کند. تغییرات رفتاری لازم برای انطباق با Journey نهایی (CJA) **در همین Handlerها** و در Track جدای CJA انجام می‌شود ([۰۱ §0.7–0.8](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md))، نه در API فاز ۳.

| مفهوم Core | REST موجود | AJAX / admin-post موجود | تابع Core | CJA |
|---|---|---|---|---|
| تماس و ثبت مبلغ اعلامی | `POST /customers/{id}/interaction` | `bcrm_save_interaction`، `bcrm_front_interaction` | `sync_customer()` | — |
| وضعیت پروفایل (کامل/ناقص) | — (محاسبه داخلی) | `bcrm_preview_reconciliation` | `sync_customer()` | — |
| رفع ناقصی و تأیید مالی اختیاری | `GET /cases/deficiency`، `GET /cases/finance`، `POST /cases/assign`، `GET /cases/{id}`، `/cases/{id}/timeline`، `/cases/{id}/attachments` | `bcrm_deficiency_action`، `bcrm_deficiency_assign` (admin) | `Deficiency_Workflow` | CJA-2/3 |
| تخصیص توسط سرپرست (شامل صف «تکمیل‌شده پس از رفع ناقصی»، `COMPLETED_AFTER_DEFICIENCY`) | — | `bcrm_assign_customers`، `bcrm_return_assignments` (admin-post) | `assignments` (وضعیت‌های موجود `assigned` / `returned`) | CJA-2/3 |
| مسیر مشتری | `POST /customers/{id}/journey` | `bcrm_set_journey`، `bcrm_front_journey` | `set_customer_journey()` | CJA-1/4 |
| خدمات مازاد | `POST /customers/{id}/services` | `bcrm_select_services`، `bcrm_front_services` | `select_services()` | CJA-6 |
| ورود به مسیر ناراضی / ثبت نارضایتی | — | `bcrm_create_complaint`، `bcrm_front_complaint` | `create_complaint()` → فاز ۳ (§3.1) | — |

قواعد:
- API فاز ۳ هیچ Endpoint برای تغییر وضعیت پروفایل، مبلغ اعلامی، فاکتور، رفع ناقصی، تأیید مالی پروفایل، تخصیص تماس، مسیر مشتری یا بایگانی بدون خدمات تعریف نمی‌کند.
- API فاز ۳ هیچ Endpoint برای انتخاب «خدمات مازاد» یا ارسال آن به سامانه بیرونی تعریف نمی‌کند (§7).

---

## 1. Domain Services

| کلاس (`includes/`) | مسئولیت | فاز |
|---|---|---|
| `Biawin_CRM_Outcome_Policy_Service` | خواندن/ذخیره Policy، Validation پیکربندی (۰۲ §3.1)، `supported_flags()` (۰۳ §6) | 3.2 |
| `Biawin_CRM_Unit_People` | Resolver عمومی اعضای واحد: `supervisors(u)`، `agents(u)`، `members(u)`، `is_treasury_member(user)` — Refactor منطق داخلی `get_inquiry_responders()` (رفتار آن بدون تغییر) | 3.2 |
| `Biawin_CRM_Complaint_Service` | ساخت، تخصیص، بررسی، تصمیم، Follow-up، بستن، بازگشایی، Visibility | 3.3 |
| `Biawin_CRM_Complaint_State_Machine` | جدول Transition، Guard، Projection، Log/Timeline | 3.3 |
| `Biawin_CRM_Complaint_Action_Service` | تولید از Policy، شروع/مسدود/تکمیل/لغو؛ تهاتر (`SERVICE_OFFSET`) با تابع مشترک `create_service_cases()` بدون تغییر مسیر مشتری و پیوند `SERVICE_FOLLOWTHROUGH` به `service_cases` موجود (بدون موتور خدمات جدید) | 3.3 (+3.5) |
| `Biawin_CRM_Inquiry_Service` | چرخه Inquiry (توسعه سرویس فاز ۲؛ متدهای فعلی Delegate) | 3.4 |
| `Biawin_CRM_Refund_Plan_Service` | Plan، Item، Allocation، تصمیم، ارسال به خزانه، `refundable()` | 3.5 / 3.6 |
| `Biawin_CRM_Treasury_Service` | ظرفیت، تقویم، Override، صف، ثبت پرداخت | 3.6 |

### 1.1 خروجی
```php
['ok' => true,  'id' => int, 'state' => string, 'version' => int, 'message' => string(fa)]
['ok' => false, 'code' => string, 'message' => string(fa), 'details' => array]
```
سازگار با الگوی فعلی `['ok','message']`. Exception به بیرون پرتاب نمی‌شود.

### 1.2 امضاها

```php
// Outcome Policy (3.2)
Outcome_Policy_Service::get(int $outcome_item_id): array
Outcome_Policy_Service::save(int $outcome_item_id, array $flags, int $actor): array
    // flags: requires_refund, requires_finance, requires_service, requires_follow_up,
    //        requires_inquiry, requires_offset, required_inquiry_unit_id?
Outcome_Policy_Service::supported_flags(): array          // وابسته به نسخه نصب‌شده
Outcome_Policy_Service::is_selectable(string $outcome_key): bool

// Complaint (3.3)
Complaint_Service::create(int $customer_id, array $in, int $actor): array
Complaint_Service::assign(int $id, int $unit_id, int $supervisor_id, int $agent_id, int $expected_version, int $actor): array
Complaint_Service::start_investigation(int $id, int $expected_version, int $actor): array
Complaint_Service::ready_for_decision(int $id, int $expected_version, int $actor): array
Complaint_Service::back_to_investigation(int $id, string $reason, int $expected_version, int $actor): array
Complaint_Service::select_outcome(int $id, string $outcome_key, string $note, ?array $follow_up, int $expected_version, int $actor): array
    // $follow_up (اگر requires_follow_up): ['follow_up_at' => 'Y-m-d H:i', 'responsible_user_id' => int, 'reason' => string]
Complaint_Service::start_follow_up(int $id, int $expected_version, int $actor): array
Complaint_Service::reschedule_follow_up(int $id, string $follow_up_at, int $responsible_user_id, string $reason, int $expected_version, int $actor): array
Complaint_Service::change_decision(int $id, string $reason, int $expected_version, int $actor): array
Complaint_Service::close(int $id, string $note, int $expected_version, int $actor): array
Complaint_Service::reopen(int $id, string $reason, int $expected_version, int $actor): array
Complaint_Service::get(int $id, int $viewer): ?array        // null = عدم دسترسی (عدم افشا)
Complaint_Service::list(array $filters, int $viewer): array  // tab, unit_id, agent_id, customer_id, follow_up_due, q, page, per_page
Complaint_Service::visibility_sql(int $viewer, string $alias): array   // تنها Predicate دسترسی (۰۵ §3)
Complaint_Service::timeline(int $id, int $viewer): array
// RESOLVED متد عمومی ندارد (O-1) — فقط State_Machine::project() داخلی.

// Action
Action_Service::start|block|unblock|complete|cancel(int $action_id, array $in, int $expected_version, int $actor): array

// Inquiry (3.4)
Inquiry_Service::create_draft(int $complaint_id, int $unit_id, string $question, ?int $action_id, int $actor): array
Inquiry_Service::request|claim|release(int $inquiry_id, int $expected_version, int $actor): array
Inquiry_Service::answer(int $inquiry_id, string $answer, ?string $result_key, int $expected_version, int $actor): array
Inquiry_Service::close(int $inquiry_id, string $reason_code, string $note, int $expected_version, int $actor): array
Inquiry_Service::record_manual(int $complaint_id, int $unit_id, string $question, string $answer, string $contacted_at, string $notes, ?int $action_id, int $actor): array
Inquiry_Service::queue_for_responder(int $user_id, array $filters): array
Inquiry_Service::respond_capability(object $unit): string   // ۰۵ §4

// Refund (3.5 / 3.6)
Refund_Plan_Service::create_plan(int $complaint_id, int $actor): array
Refund_Plan_Service::add_item(int $plan_id, float $amount, string $proposed_date, array $allocations /* [invoice_id => amount] */, int $actor): array
Refund_Plan_Service::update_item|remove_item(...)                        // فقط DRAFT
Refund_Plan_Service::submit(int $plan_id, int $expected_version, int $actor): array
Refund_Plan_Service::decide_item(int $item_id, string $decision /* approve|reschedule|reject */, ?string $approved_date, string $note, bool $override, string $override_reason, int $expected_version, int $actor): array
Refund_Plan_Service::send_to_treasury(array $item_ids, int $actor): array
Refund_Plan_Service::invoice_position(int $invoice_id): array
    // ['A'=>…, 'X'=>…, 'P'=>…, 'O'=>…, 'R'=>…, 'reconciliation'=>'NONE|LEDGER_ONLY|CRM_ONLY|MATCHED|MISMATCH'] — ۰۲ §7.3

// Treasury (3.6)
Treasury_Service::capacity(string $date): array          // [capacity, scheduled, remaining, source]
Treasury_Service::queue(array $filters, int $viewer): array
Treasury_Service::record_payment(int $item_id, string $payment_reference, string $paid_at, ?int $attachment_id, int $expected_version, int $actor): array
Treasury_Service::set_rule|set_exception(...)

// تحویل به فرایند موجود خدمات/تهاتر (3.5) — Wrapper نازک، نه موتور جدید
Action_Service::offset_queue(int $viewer, array $filters): array        // اکشن‌های SERVICE_OFFSET واحد تهاتر
Action_Service::offset_select(int $action_id, array $service_ids, string $note, int $expected_version, int $actor): array
    // = Workflow_Service::create_service_cases(customer, service_ids, note) — تابع مشترک استخراج‌شده از select_services()
    //   (همان منطق کاتالوگ/Guard/credit_amount؛ بدون نوشتن journey_state؛ select_services() عیناً بدون تغییر رفتار)
    //   + UPDATE service_cases SET complaint_action_id = action_id  برای case_idهای برگشتی  (فقط ستون additive)
    //   + A5 سیستمی
Action_Service::link_service_cases(int $action_id, array $service_case_ids, int $expected_version, int $actor): array
    // SERVICE_FOLLOWTHROUGH: فقط پیوند service_cases موجود همان مشتری؛ هیچ ساخت/تغییر وضعیت خدمت
```

### 1.3 تراکنش و همزمانی
- `START TRANSACTION` → `SELECT … FOR UPDATE` روی ریشه → بررسی `expected_version` → Guard (شامل Capability + قاعده سطر ۰۵) → نوشتن → Projection → Log/Timeline → `COMMIT`.
- `expected_version` نامطابق → `VERSION_CONFLICT`.
- ثبت پرداخت idempotent روی `(item_id, payment_reference)`؛ مرجع متفاوت روی Item پرداخت‌شده → `ALREADY_PAID`.
- `offset_select()`: `create_service_cases()` + پیوند + تکمیل اکشن در یک تراکنش؛ خطای تابع مشترک → اکشن تغییر نمی‌کند و پیام همان تابع برگردانده می‌شود. هیچ مسیر مشتری تغییر نمی‌کند.

---

## 2. REST (`/wp-json/biawin-crm/v1`)

احراز هویت: Cookie + `X-WP-Nonce` (`wp_rest`). `permission_callback` فقط Capability پایه؛ قاعده سطر (Visibility، Membership، عضویت خزانه) در Service.

### 2.1 Complaint

| Method | Route | Capability پایه | Body / Query |
|---|---|---|---|
| POST | `/customers/{id}/complaints` | `bcrm_work_cases` + `can_access_customer` | `subject_id`, `description`, `priority?` |
| GET | `/complaints` | `view_dissatisfaction` | `tab`, `unit_id`, `agent_id`, `follow_up_due`, `q`, `page`, `per_page≤100` → `{items[], total, counts_by_tab{}}` |
| GET | `/complaints/{id}` | `view_dissatisfaction` | → پرونده + `allowed_transitions[]` + `version` + Follow-up فعال |
| GET | `/complaints/{id}/timeline` | `view_dissatisfaction` | |
| POST | `/complaints/{id}/assign` | `assign_dissatisfaction` | `unit_id`, `supervisor_id`, `agent_id`, `version` |
| POST | `/complaints/{id}/transition` | `process_dissatisfaction` | `action ∈ {start, ready_for_decision, back_to_investigation, start_follow_up, change_decision, close, reopen}`, `reason?`, `version` — **`resolve` وجود ندارد (O-1)** |
| POST | `/complaints/{id}/outcome` | `process_dissatisfaction` | `outcome_key`, `note`, `follow_up?{follow_up_at, responsible_user_id, reason}`, `version` → `{state, actions[], follow_up?}` |
| POST | `/complaints/{id}/follow-up` | `process_dissatisfaction` | `follow_up_at`, `responsible_user_id`, `reason`, `version` (زمان‌بندی مجدد) |
| POST | `/complaints/{id}/resolve` | **موجود — حفظ** (Visibility جدید) | `outcome`, `note` → Adapter §5 |

`close`/`reopen`/`change_decision` علاوه بر Capability پایه، قاعده سطر ۰۵ §2 (مدیر یا سرپرست نارضایتی) را در Service بررسی می‌کنند.

### 2.2 Inquiry

| Method | Route | Capability پایه | Body |
|---|---|---|---|
| POST | `/complaints/{id}/inquiries` | `process_dissatisfaction` | `unit_id`, `question`, `mode`, `action_id?`, (`answer`, `contacted_at`, `notes` برای manual) |
| POST | `/inquiries/{id}/request` · `/close` | `process_dissatisfaction` | `version`, (`reason_code`, `note`) |
| POST | `/inquiries/{id}/claim` · `/release` · `/answer` | Capability پاسخ واحد (۰۵ §4) | `version`, (`answer`, `result_key?`) |
| GET | `/inquiries/queue` | هر Capability پاسخ واحد | `status`, `unit_id` |
| POST | `/inquiries/{id}/attachments` | پاسخ‌دهنده یا کارشناس مسئول | multipart (زنجیره آپلود موجود؛ `object_type='complaint_inquiry'`) |

### 2.3 Action / Refund / تحویل تهاتر / Treasury

| Method | Route | Capability پایه | قاعده سطر (Service) |
|---|---|---|---|
| POST | `/actions/{id}/{start\|block\|unblock\|complete\|cancel}` | بسته به نوع (۰۵ §2) | مسئول اکشن |
| POST | `/complaints/{id}/refund-plans` | `process_dissatisfaction` | مسئول/سرپرست |
| POST/PATCH/DELETE | `/refund-plans/{id}/items[/{item}]` | `process_dissatisfaction` | فقط DRAFT |
| POST | `/refund-plans/{id}/submit` | `process_dissatisfaction` | |
| POST | `/refund-items/{id}/decision` | `approve_refund_schedule` (+ `override_treasury_capacity` برای Override) | **عضو واحد خزانه** (O-12) |
| POST | `/refund-items/send-to-treasury` | `approve_refund_schedule` | **عضو واحد خزانه** |
| GET | `/invoices/{id}/refund-position` | `process_dissatisfaction` یا `view_treasury` | → `A, X, P, O, R, reconciliation` |
| GET | `/treasury/queue` | `view_treasury` | |
| POST | `/refund-items/{id}/payment` | `process_refund_payment` | **عضو واحد خزانه** |
| GET/POST | `/treasury/capacity` · `/treasury/calendar` | `view_treasury` / `manage_treasury_capacity` | عضو خزانه برای POST |
| GET | `/offset-queue` | `bcrm_services` | عضو «واحد تهاتر» |
| POST | `/actions/{id}/offset-selection` | `bcrm_services` | عضو «واحد تهاتر»؛ `service_ids[]` از `service_catalog` موجود → `create_service_cases()` مشترک (بدون تغییر مسیر مشتری) |
| POST | `/actions/{id}/service-cases` | `process_dissatisfaction` | مسئول/سرپرست؛ فقط پیوند `service_cases` موجود همان مشتری (`SERVICE_FOLLOWTHROUGH`) |

### 2.4 Admin (3.2، admin-post)
| Action | Capability | توضیح |
|---|---|---|
| `bcrm_cfg_outcome_policy_save` | `manage_dissatisfaction_outcomes` | ذخیره Flagها + Validation ۰۲ §3.1 |
| `bcrm_cfg_intake_unit_save` | `manage_after_sales_settings` | Option واحد Intake |
| (موجود) `bcrm_cfg_catalog_*`, `bcrm_cfg_units_save` | فاز ۲/۲.۱ | بدون تغییر |

### 2.5 کدهای خطا

| code | HTTP | معنا |
|---|---|---|
| `FORBIDDEN` | 403 | Capability پایه ندارد |
| `NOT_FOUND` | 404 | وجود ندارد یا Visibility ندارد |
| `INVALID_INPUT` | 400 | Validation |
| `INVALID_TRANSITION` | 409 | از وضعیت فعلی مجاز نیست (شامل هر تلاش برای RESOLVED دستی) |
| `GUARD_FAILED` | 422 | `details.guard` |
| `VERSION_CONFLICT` | 409 | |
| `NOT_ACTOR` | 403 | Capability دارد ولی قاعده سطر برقرار نیست (مثلاً سرپرست واحد نیست) |
| `NOT_TREASURY_MEMBER` | 403 | O-12 |
| `UNIT_NOT_ELIGIBLE` | 422 | واحد غیرفعال یا استعلام‌پذیر نیست |
| `NO_RESPONDERS` | 422 | |
| `NOT_RESPONDER` | 403 | D8 |
| `OUTCOME_INACTIVE` | 422 | |
| `OUTCOME_NOT_SUPPORTED` | 422 | Flag پشتیبانی‌نشده در نسخه نصب‌شده (۰۳ §6) |
| `FOLLOW_UP_REQUIRED` | 422 | Outcome پیگیری بدون تاریخ/مسئول/دلیل |
| `POLICY_INVALID` | 422 | پیکربندی Policy نامعتبر (۰۲ §3.1) |
| `ROUTING_FAILED` | 422 | تابع موجود `set_customer_journey()` مسیر «نارضایتی» را نپذیرفت (پیام همان تابع، مثلاً «ابتدا مبلغ اعلامی…») |
| `PROFILE_NOT_COMPLETE` | 422 | R-1: پروفایل `complete` نیست؛ ابتدا گردش‌کار رفع ناقصی |
| `OFFSET_UNIT_MISSING` | 422 | «واحد تهاتر» تعریف/فعال نیست (Outcome تهاتر قابل انتخاب نیست) |
| `NOT_OFFSET_UNIT_MEMBER` | 403 | انتخاب خدمت تهاتر فقط برای عضو واحد تهاتر |
| `SERVICE_SELECTION_FAILED` | 422 | ساخت پرونده خدمت (منطق موجود) خطا داد (پیام همان منطق) |
| `ALLOCATION_MISMATCH` | 422 | |
| `ALLOCATION_EXCEEDS_REFUNDABLE` | 422 | ۰۲ §7.3 |
| `OVER_CAPACITY` | 422 | |
| `ALREADY_PAID` | 409 | |
| `INTAKE_UNIT_MISSING` | — | هشدار (پرونده بدون واحد ساخته می‌شود) |

پیام‌ها فارسی؛ `code` ثابت انگلیسی.

---

## 3. رفتارهای کلیدی

### 3.1 ساخت (C3)
Complaint ادامه مسیر مشتری «ناراضی» است (فقط پروفایل کامل — [۰۱ §0](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)) ([۰۱ §4.3](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md))؛ فرم/AJAX فعلی پروفایل (`bcrm_front_complaint`, `bcrm_create_complaint`) و REST جدید → `Complaint_Service::create()`:
1. `Workflow_Service::set_customer_journey(customer, 'complaint')` **موجود** (خودش `sync_customer()` و Guardهای Core را اجرا می‌کند؛ idempotent وقتی مسیر از قبل `complaint` است). شکست → `ROUTING_FAILED` با پیام همان تابع.
2. Gate R-1: `profile_completeness = 'complete'`؛ وگرنه `PROFILE_NOT_COMPLETE` («ابتدا مغایرت در گردش‌کار رفع ناقصی رفع شود») — پرونده ساخته نمی‌شود.
3. `subject_id` الزامی وقتی کاتالوگ فعال است؛ `assigned_unit_id` = Intake (اگر فعال)، وگرنه NULL + هشدار.
4. درج Complaint (`state=NEW`) در تراکنش خود دامنه. دامنه نارضایتی هیچ ستون `customers` را مستقیم نمی‌نویسد.

### 3.2 تخصیص (C9)
واحد فعال با `unit_type='dissatisfaction'` (یا زیرواحد Intake)؛ سرپرست ∈ `Unit_People::supervisors(unit)`؛ کارشناس ∈ `Unit_People::agents(unit)`؛ `owner_user_id` = کارشناس.

### 3.3 انتخاب Outcome
یک تراکنش: Guard T8 (شامل `is_selectable`) → ذخیره Outcome → Follow-up یا تولید Actionها → Projection → پاسخ. Outcome هیچ نوشتنی روی `customers` ندارد (O-3).

---

## 4. AJAX پرتال
`add_action('wp_ajax_bcrm_…')` + `check_ajax_referer('bcrm_front_ajax','nonce')` + `wp_send_json_success/error`؛ نام‌ها `bcrm_complaint_*`, `bcrm_followup_*`, `bcrm_inquiry_*`, `bcrm_refund_*`, `bcrm_treasury_*`؛ همان Serviceها، خطا `{code, message}`.

---

## 5. Adapter سازگاری `resolve_complaint` (C2)

| فراخوان | Engine `legacy` | Engine `v3` |
|---|---|---|
| فرم پروفایل `bcrm_front_complaint_resolution` | رفتار 0.8.64.1 | ۰۳ §2.8 (فقط انتخاب Outcome) |
| `POST /complaints/{id}/resolve` | رفتار 0.8.64.1 | همان Adapter؛ پاسخ `{ok, message, state}` |
| `complaint_resolution_options()` | Outcomeهای دارای Handler | Outcomeهای فعال و `is_selectable` بدون `requires_follow_up` |

---

## 6. رویدادهای Timeline (`activities`)

| رویداد | activity_type | object_type |
|---|---|---|
| Complaint Created | `complaint_created` (موجود) | complaint |
| Assigned | `complaint_assigned` / `complaint_reassigned` | complaint |
| (هر Transition) | `complaint_state_changed` | complaint |
| Inquiry Sent | `inquiry_requested` | complaint_inquiry |
| Manual Inquiry Added | `inquiry_manual_recorded` | complaint_inquiry |
| Inquiry Responded | `inquiry_answered` | complaint_inquiry |
| Customer Contacted | `complaint_contact` (+ `interactions.complaint_id`) | complaint |
| Outcome Selected | `outcome_selected` | complaint |
| Follow-up | `follow_up_scheduled` / `follow_up_rescheduled` / `follow_up_started` | complaint |
| Action | `action_started` / `action_blocked` / `action_completed` / `action_cancelled`  | complaint |
| Refund Schedule Created | `refund_plan_submitted` | refund_plan |
| Manager Approved | `refund_item_approved` / `_rescheduled` / `_rejected` | refund_item |
| Sent to Treasury | `refund_item_sent_to_treasury` | refund_item |
| Invoice Paid | `refund_item_paid` | refund_item |
| Complaint Resolved | `complaint_resolved` (معنای جدید؛ فقط سیستمی) | complaint |
| Complaint Closed / Reopened | `complaint_closed` / `complaint_reopened` | complaint |
| تحویل تهاتر / خدمات | `service_offset_selected` / `service_cases_linked` (+ رویداد موجود `service_case_created` از منطق مشترک ساخت پرونده خدمت) | complaint |

`complaint_resolved` پیش از `biawin_crm_complaint_cutover_at` به معنای «بسته شد» بوده و legacy تفسیر می‌شود.

---

## 7. Additional Services Integration Boundary (خارج از فاز ۳)

مسیر مشتری «خدمات مازاد» متعلق به CRM Core است و نتیجه نارضایتی نیست ([۰۱ §0.5](01-ARCHITECTURE-AUDIT-AND-DOMAIN-MAPPING.md)):
```text
CRM → انتخاب خدمات مازاد (کارشناس تماس، select_services() موجود) → کاتالوگ خدمات CRM (service_catalog) → service_cases
    → API / Web Service  ← مرز یکپارچگی
    → سامانه بیرونی Biawin / پلتفرم خدمات
```
- قرارداد این API **جداگانه** طراحی می‌شود؛ این سند هیچ Endpoint، فیلد، فرمت، احراز هویت یا رفتار همگام‌سازی برای آن تعریف نمی‌کند و هیچ پیاده‌سازی در فاز ۳ ندارد.
- قیود ثابت برای طراحی آینده: `service_catalog` تنها منبع حقیقت خدمات CRM است (بدون کاتالوگ دوم)؛ رکورد CRM همان `service_cases` با `customer_id` موجود است؛ مرز یکپارچگی منطق مسیر مشتری یا کامل/ناقص را تغییر نمی‌دهد.
- **تنها** مسیری که وارد این یکپارچگی می‌شود «خدمات مازاد» است؛ هیچ Endpoint یا اقدام ناراضی، نارضایتی، خدمات پس از فروش، عودت یا تهاتر به این مرز وصل نمی‌شود. تهاتر (نتیجه نارضایتی) داخلی است؛ استفاده آتی از Biawin فقط با تصمیم جدا (AMB-9).
- **مدل خدماتی Biawin (الزام مفهومی):** مشتری ← اشتراک (Subscription) ← چند خدمت که در UI به‌صورت «کارت» نمایش داده می‌شوند (مثلاً ۱۰، ۱۲، ۱۵ یا بیشتر) ← صف عملیات، سرپرست و گردش‌کار اختصاصی هر خدمت/کارت.
- **مشتریان موجود:** یکپارچگی باید مشتریان جدید **و** مشتریانی که امروز مسیر خدمات مازاد دارند را پوشش دهد: ایجاد اولیه، همگام‌سازی، تطبیق، Idempotency و جلوگیری از تکرار مشتری/اشتراک/خدمت در Biawin.
- هیچ فیلد، Endpoint، جدول بیرونی یا پیاده‌سازی در این سند؛ قرارداد پس از بررسی سند اختصاصی Web Service سامانه Biawin تعریف می‌شود (AMB-10).
- امروز (CURRENT) `select_services()` پرونده‌های خدمت را برای کارتابل داخلی واحدهای خدمات می‌سازد و هیچ ارسال بیرونی وجود ندارد (G7)؛ TARGET ارسال از طریق این مرز است؛ PHASE: قرارداد یکپارچگی (CJA-6).
