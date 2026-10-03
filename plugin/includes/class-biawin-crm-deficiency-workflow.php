<?php

if (!defined('ABSPATH')) {
    exit;
}

class Biawin_CRM_Deficiency_Workflow
{
    public const OPEN_STATUSES = [
        'INCOMPLETE_NEW',
        'INCOMPLETE_ASSIGNED',
        'CUSTOMER_CONTACT_PENDING',
        'DEFICIENCY_UNDER_REVIEW',
        'FINANCE_REVIEW_PENDING',
        'FINANCE_ASSIGNED',
        'FINANCE_UNDER_REVIEW',
        'FINANCE_APPROVED_WITH_INVOICE',
        'FINANCE_APPROVED_WITHOUT_INVOICE',
        'FINANCE_REJECTED',
        'RETURNED_TO_DEFICIENCY',
        'READY_FOR_FINAL_DECISION',
    ];

    public static function status_labels(): array
    {
        return [
            'INCOMPLETE_NEW' => 'ناقص جدید',
            'INCOMPLETE_ASSIGNED' => 'تخصیص رفع ناقصی',
            'CUSTOMER_CONTACT_PENDING' => 'منتظر تماس مشتری',
            'DEFICIENCY_UNDER_REVIEW' => 'در حال بررسی رفع ناقصی',
            'FINANCE_REVIEW_PENDING' => 'ارسال‌شده به مالی',
            'FINANCE_ASSIGNED' => 'تخصیص مالی',
            'FINANCE_UNDER_REVIEW' => 'در حال بررسی مالی',
            'FINANCE_APPROVED_WITH_INVOICE' => 'تأیید مالی با فاکتور',
            'FINANCE_APPROVED_WITHOUT_INVOICE' => 'تأیید مالی بدون فاکتور',
            'FINANCE_REJECTED' => 'عدم تأیید مالی',
            'RETURNED_TO_DEFICIENCY' => 'برگشتی از مالی',
            'READY_FOR_FINAL_DECISION' => 'آماده تصمیم نهایی',
            'COMPLETED' => 'تکمیل‌شده',
            'ARCHIVED' => 'بایگانی‌شده',
        ];
    }

    public static function finance_result_labels(): array
    {
        return [
            'with_invoice' => 'تأیید مالی با فاکتور',
            'without_invoice' => 'تأیید مالی بدون فاکتور',
            'rejected' => 'عدم تأیید مالی',
        ];
    }

    public static function ensure_case_for_customer(int $customer_id, ?float $reported_amount = null, ?float $registered_amount = null): int
    {
        global $wpdb;
        if ($customer_id <= 0) return 0;
        $customers = Biawin_CRM_DB::table('customers');
        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers WHERE id=%d", $customer_id));
        if (!$customer) return 0;
        $reported = $reported_amount ?? ($customer->declared_paid === null ? null : (float)$customer->declared_paid);
        $registered = $registered_amount ?? (float)$customer->total_paid;
        $difference = ($reported === null) ? (float)$customer->discrepancy_amount : ($reported - $registered);
        $tolerance = Biawin_CRM_Workflow_Service::completeness_tolerance();
        if ($reported !== null && abs((float) $difference) <= $tolerance) {
            $existing = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $cases WHERE customer_id=%d AND status NOT IN ('COMPLETED','ARCHIVED') ORDER BY id DESC LIMIT 1",
                $customer_id
            ));
            if ($existing) {
                $wpdb->update($cases, [
                    'status' => 'COMPLETED',
                    'current_department' => 'deficiency',
                    'final_decision' => 'auto_tolerance',
                    'completed_at' => Biawin_CRM_DB::now(),
                    'updated_at' => Biawin_CRM_DB::now(),
                ], ['id' => $existing]);
                self::action($existing, $customer_id, 'deficiency', 'CASE_AUTO_COMPLETED_BY_TOLERANCE', null, 'COMPLETED', ['difference_amount'=>$difference,'tolerance'=>$tolerance]);
                self::after_completion($customer_id, $existing, 'auto_tolerance'); // v0.8.66.0
            }
            return 0;
        }
        $existing = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $cases WHERE customer_id=%d AND status NOT IN ('COMPLETED','ARCHIVED') ORDER BY id DESC LIMIT 1",
            $customer_id
        ));
        $now = Biawin_CRM_DB::now();
        if ($existing) {
            $wpdb->update($cases, [
                'reported_amount' => $reported,
                'registered_invoice_amount' => $registered,
                'difference_amount' => $difference,
                'updated_at' => $now,
            ], ['id' => $existing]);
            return $existing;
        }
        $inserted = $wpdb->insert($cases, [
            'case_code' => self::next_case_code(),
            'customer_id' => $customer_id,
            'status' => 'INCOMPLETE_NEW',
            'reported_amount' => $reported,
            'registered_invoice_amount' => $registered,
            'difference_amount' => $difference,
            'current_department' => 'deficiency',
            'entered_at' => $now,
            'created_by' => get_current_user_id() ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === false || !(int)$wpdb->insert_id) return 0;
        $case_id = (int)$wpdb->insert_id;
        self::tag_customer($customer_id, 'DEFICIENCY_CASE', $case_id);
        self::action($case_id, $customer_id, 'deficiency', 'PROFILE_MARKED_INCOMPLETE', null, 'INCOMPLETE_NEW', ['difference_amount'=>$difference]);
        return $case_id;
    }

    public static function can_access_case(object $case, int $user_id = 0): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return false;
        if (current_user_can('bcrm_manage_cases') || current_user_can('manage_options')) return true;
        $user = get_user_by('id', $user_id);
        $roles = $user ? (array)$user->roles : [];
        if (in_array('bcrm_deficiency_supervisor', $roles, true) && current_user_can('view_deficiency_cases')) return true;
        if (in_array('bcrm_financial_supervisor', $roles, true) && current_user_can('view_finance_cases')) return true;
        if (current_user_can('process_deficiency_case') && (int)$case->deficiency_agent_id === $user_id) return true;
        if (current_user_can('review_finance_case') && (int)$case->finance_agent_id === $user_id) return true;
        return false;
    }

    public static function user_can_see_customer(int $customer_id, int $user_id = 0): bool
    {
        global $wpdb;
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return false;
        if (current_user_can('bcrm_manage_cases') || current_user_can('manage_options')) return true;
        $case = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . " WHERE customer_id=%d AND status NOT IN ('COMPLETED','ARCHIVED') ORDER BY id DESC LIMIT 1",
            $customer_id
        ));
        return $case ? self::can_access_case($case, $user_id) : false;
    }

    public static function assign_cases(array $case_ids, int $agent_id, string $department = 'deficiency', int $limit = 100): array
    {
        global $wpdb;
        $case_ids = array_values(array_unique(array_filter(array_map('absint', $case_ids))));
        $limit = max(1, min(10000, $limit));
        if (!$case_ids) {
            $status_filter = $department === 'finance' ? "status='FINANCE_REVIEW_PENDING'" : "status IN ('INCOMPLETE_NEW','RETURNED_TO_DEFICIENCY')";
            $case_ids = array_map('intval', $wpdb->get_col("SELECT id FROM " . Biawin_CRM_DB::table('deficiency_cases') . " WHERE $status_filter ORDER BY difference_amount DESC,id ASC LIMIT $limit"));
        } else {
            $case_ids = array_slice($case_ids, 0, $limit);
        }
        $agent = $agent_id ? get_user_by('id', $agent_id) : false;
        if (!$agent || (bool)get_user_meta($agent_id, 'bcrm_disabled', true)) return ['ok'=>false,'message'=>'کارشناس انتخاب‌شده معتبر یا فعال نیست.'];
        if ($department === 'finance' && !$agent->has_cap('review_finance_case')) return ['ok'=>false,'message'=>'کاربر انتخاب‌شده کارشناس مالی نیست.'];
        if ($department !== 'finance' && !$agent->has_cap('process_deficiency_case')) return ['ok'=>false,'message'=>'کاربر انتخاب‌شده کارشناس رفع ناقصی نیست.'];

        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $assigned = 0;
        foreach ($case_ids as $case_id) {
            $wpdb->query('START TRANSACTION');
            try {
                $case = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cases WHERE id=%d FOR UPDATE", $case_id));
                if (!$case || in_array((string)$case->status, ['COMPLETED','ARCHIVED'], true)) throw new RuntimeException('skip');
                if ($department === 'finance') {
                    if ((string)$case->status !== 'FINANCE_REVIEW_PENDING') throw new RuntimeException('skip');
                    $to = 'FINANCE_ASSIGNED';
                    $data = ['finance_agent_id'=>$agent_id,'finance_assigned_at'=>Biawin_CRM_DB::now(),'status'=>$to,'current_department'=>'finance','updated_at'=>Biawin_CRM_DB::now()];
                    $event = 'FINANCE_ASSIGNED';
                } else {
                    if (!in_array((string)$case->status, ['INCOMPLETE_NEW','INCOMPLETE_ASSIGNED','RETURNED_TO_DEFICIENCY','READY_FOR_FINAL_DECISION'], true)) throw new RuntimeException('skip');
                    $to = 'INCOMPLETE_ASSIGNED';
                    $data = ['deficiency_agent_id'=>$agent_id,'assigned_at'=>Biawin_CRM_DB::now(),'status'=>$to,'current_department'=>'deficiency','updated_at'=>Biawin_CRM_DB::now()];
                    $event = 'DEFICIENCY_ASSIGNED';
                }
                $ok = $wpdb->update($cases, $data, ['id'=>$case_id]);
                if ($ok === false) throw new RuntimeException('db');
                self::action($case_id, (int)$case->customer_id, $department, $event, (string)$case->status, $to, ['agent_user_id'=>$agent_id]);
                $wpdb->query('COMMIT');
                $assigned++;
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
            }
        }
        return ['ok'=>true,'assigned'=>$assigned];
    }

    public static function assign_cases_multi(array $case_ids, array $agent_ids, string $department = 'deficiency', int $limit = 100): array
    {
        $agent_ids = array_values(array_unique(array_filter(array_map('absint', $agent_ids))));
        if (!$agent_ids) return ['ok'=>false,'message'=>'حداقل یک کارشناس انتخاب کنید.'];
        $total = 0;
        $idx = 0;
        $case_ids = array_values(array_unique(array_filter(array_map('absint', $case_ids))));
        if (!$case_ids) {
            global $wpdb;
            $status_filter = $department === 'finance' ? "status='FINANCE_REVIEW_PENDING'" : "status IN ('INCOMPLETE_NEW','RETURNED_TO_DEFICIENCY')";
            $case_ids = array_map('intval', $wpdb->get_col("SELECT id FROM " . Biawin_CRM_DB::table('deficiency_cases') . " WHERE $status_filter ORDER BY difference_amount DESC,id ASC LIMIT " . max(1, min(10000, $limit))));
        }
        foreach (array_slice($case_ids, 0, max(1, min(10000, $limit))) as $case_id) {
            $agent_id = $agent_ids[$idx % count($agent_ids)];
            $idx++;
            $result = self::assign_cases([$case_id], $agent_id, $department, 1);
            if (!empty($result['ok'])) $total += (int)($result['assigned'] ?? 0);
        }
        return ['ok'=>true,'assigned'=>$total];
    }

    /**
     * v0.8.62.15: تخصیص دسته‌ای از روی فایل اکسل/CSV شامل Customer ID.
     * هر سطر فایل به یک مشتری resolve می‌شود (id عددی / customer_code / source_customer_id)،
     * سپس «همه پرونده‌های بازِ» آن مشتری (به‌جز COMPLETED/ARCHIVED) به «یک کارشناس واحد» تخصیص می‌یابد.
     * هیچ سطری بی‌جواب نمی‌ماند: هر Customer ID یا تخصیص می‌خورد یا در not_found/no_case با شماره سطر برمی‌گردد.
     *
     * @param string $path        مسیر موقت فایل آپلودی
     * @param string $file_name   نام اصلی فایل (تشخیص xlsx/csv)
     * @param int    $agent_id    کارشناس مقصد (فقط یک نفر)
     * @param string $department  deficiency | finance
     * @return array{ok:bool,message:string,assigned:int,duplicate_ids:int,not_found:array,no_case:array,errors:array}
     */
    public static function assign_from_customer_file(string $path, string $file_name, int $agent_id, string $department = 'deficiency'): array
    {
        if (!class_exists('Biawin_CRM_Refund_Register')) {
            require_once BIAWIN_CRM_DIR . 'includes/class-biawin-crm-refund-register.php';
        }
        $ext = strtolower(pathinfo(sanitize_file_name($file_name), PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            return ['ok' => false, 'message' => 'فقط فایل XLSX یا CSV پذیرفته می‌شود. فایل XLS قدیمی را اول به XLSX تبدیل کنید.', 'assigned' => 0, 'duplicate_ids' => 0, 'not_found' => [], 'no_case' => [], 'errors' => []];
        }
        if (!is_file($path)) {
            return ['ok' => false, 'message' => 'فایل آپلودشده پیدا نشد.', 'assigned' => 0, 'duplicate_ids' => 0, 'not_found' => [], 'no_case' => [], 'errors' => []];
        }
        $agent_id = absint($agent_id);
        $agent = $agent_id ? get_user_by('id', $agent_id) : false;
        if (!$agent || (bool)get_user_meta($agent_id, 'bcrm_disabled', true)) {
            return ['ok' => false, 'message' => 'کارشناس مقصد معتبر یا فعال نیست.', 'assigned' => 0, 'duplicate_ids' => 0, 'not_found' => [], 'no_case' => [], 'errors' => []];
        }
        if ($department === 'finance' && !$agent->has_cap('review_finance_case')) {
            return ['ok' => false, 'message' => 'کاربر انتخاب‌شده کارشناس مالی نیست.', 'assigned' => 0, 'duplicate_ids' => 0, 'not_found' => [], 'no_case' => [], 'errors' => []];
        }
        if ($department !== 'finance' && !$agent->has_cap('process_deficiency_case')) {
            return ['ok' => false, 'message' => 'کاربر انتخاب‌شده کارشناس رفع ناقصی نیست.', 'assigned' => 0, 'duplicate_ids' => 0, 'not_found' => [], 'no_case' => [], 'errors' => []];
        }

        $parsed = Biawin_CRM_Refund_Register::parse_upload($path, $file_name);
        if (!empty($parsed['errors'])) {
            return ['ok' => false, 'message' => 'خواندن فایل انجام نشد: ' . implode(' | ', array_map('strval', $parsed['errors'])), 'assigned' => 0, 'duplicate_ids' => 0, 'not_found' => [], 'no_case' => [], 'errors' => (array)$parsed['errors']];
        }

        // Customer ID را از هر ستونی برمی‌داریم؛ هدرها نرمال می‌شوند تا CustomerID/customerid/Customer ID یکی باشند.
        $ids = [];
        $dup = 0;
        foreach ((array)$parsed['rows'] as $row) {
            $raw = '';
            foreach ((array)$row as $header => $value) {
                $canon = Biawin_CRM_Refund_Register::canonical_header((string)$header);
                if ($canon === 'customer id') {
                    $raw = trim((string)$value);
                    break;
                }
            }
            if ($raw === '') {
                foreach ((array)$row as $value) {
                    if (trim((string)$value) !== '') { $raw = trim((string)$value); break; }
                }
            }
            if ($raw === '') continue;
            $key = strtolower($raw);
            if (isset($ids[$key])) { $dup++; continue; }
            $ids[$key] = $raw;
        }
        if (!$ids) {
            return ['ok' => false, 'message' => 'هیچ Customer ID معتبری در فایل پیدا نشد (ستون CustomerID یا اولین ستون پر).', 'assigned' => 0, 'duplicate_ids' => 0, 'not_found' => [], 'no_case' => [], 'errors' => []];
        }
        if (count($ids) > 10000) {
            $ids = array_slice($ids, 0, 10000, true);
        }

        global $wpdb;
        $customers = Biawin_CRM_DB::table('customers');
        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $assigned = 0;
        $not_found = [];
        $no_case = [];
        $line = 1; // سطر ۱ اکسل = سرستون
        foreach ($ids as $key => $raw) {
            $line++;
            $customer_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $customers WHERE source_customer_id=%s", $raw));
            if (!$customer_id && ctype_digit($raw)) $customer_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $customers WHERE id=%d", (int)$raw));
            if (!$customer_id && ctype_digit($raw)) $customer_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $customers WHERE customer_code=%s", $raw));
            if (!$customer_id) { $not_found[] = ['row' => $line, 'key' => $raw]; continue; }
            $open_ids = array_map('intval', (array)$wpdb->get_col($wpdb->prepare("SELECT id FROM $cases WHERE customer_id=%d AND status NOT IN ('COMPLETED','ARCHIVED') ORDER BY id ASC", $customer_id)));
            if (!$open_ids) { $no_case[] = ['row' => $line, 'key' => $raw]; continue; }
            $result = self::assign_cases($open_ids, $agent_id, $department, count($open_ids));
            if (empty($result['ok'])) { $no_case[] = ['row' => $line, 'key' => $raw]; continue; }
            $assigned += (int)($result['assigned'] ?? 0);
            Biawin_CRM_DB::log('deficiency_case', $customer_id, 'case_assigned_from_excel', null, [
                'customer_id' => $customer_id, 'customer_key' => $raw, 'agent_user_id' => $agent_id,
                'department' => $department, 'cases' => count($open_ids), 'assigned' => (int)($result['assigned'] ?? 0),
            ]);
        }

        $message = sprintf('%s پرونده به کارشناس «%s» تخصیص داده شد.', number_format_i18n($assigned), $agent->display_name);
        if ($not_found) $message .= ' ' . sprintf('%s شناسه در سیستم پیدا نشد.', number_format_i18n(count($not_found)));
        if ($no_case) $message .= ' ' . sprintf('%s مشتری پرونده باز نداشت.', number_format_i18n(count($no_case)));
        if ($dup) $message .= ' ' . sprintf('%s شناسه تکراری نادیده گرفته شد.', number_format_i18n($dup));
        Biawin_CRM_DB::log('deficiency_case', null, 'case_assignment_excel_batch', null, [
            'file_name' => $file_name, 'department' => $department, 'agent_user_id' => $agent_id,
            'unique_ids' => count($ids), 'duplicates' => $dup, 'assigned' => $assigned,
            'not_found' => count($not_found), 'no_case' => count($no_case),
        ]);
        return ['ok' => true, 'message' => $message, 'assigned' => $assigned, 'duplicate_ids' => $dup, 'not_found' => $not_found, 'no_case' => $no_case, 'errors' => []];
    }

    public static function return_assignments(array $case_ids, string $department = 'deficiency', int $agent_id = 0): array
    {
        global $wpdb;
        $case_ids = array_values(array_unique(array_filter(array_map('absint', $case_ids))));
        $agent_id = absint($agent_id);
        if (!$case_ids) return ['ok'=>false,'message'=>'پرونده‌ای برای برگشت انتخاب نشده است.'];
        // v0.8.62.12: agent_id=0 مجاز شد — «همه کارشناسان»؛ ملکیت واقعی هر ردیف در تراکنش بررسی می‌شود.
        if (!$agent_id) {
            $cases_t = Biawin_CRM_DB::table('deficiency_cases');
            $agent_col_t = $department === 'finance' ? 'finance_agent_id' : 'deficiency_agent_id';
            $returnable_status_t = $department === 'finance' ? 'FINANCE_ASSIGNED' : 'INCOMPLETE_ASSIGNED';
            $owned = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT $agent_col_t FROM $cases_t WHERE id IN (" . implode(',', array_fill(0, count($case_ids), '%d')) . ") AND $agent_col_t IS NOT NULL AND status=%s", array_merge($case_ids, [$returnable_status_t]))));
            if (!$owned) return ['ok'=>false,'message'=>'هیچ تخصیص فعالی برای برگشت در انتخاب شما پیدا نشد.'];
            $returned = 0;
            foreach ($owned as $aid) {
                $r = self::return_assignments($case_ids, $department, $aid);
                $returned += (int)($r['returned'] ?? 0);
            }
            return ['ok'=>true,'returned'=>$returned];
        }
        if (!$agent_id) return ['ok'=>false,'message'=>'برای برگشت تخصیص، ابتدا کارشناس را انتخاب کنید.'];
        if (!$case_ids) return ['ok'=>false,'message'=>'پرونده‌ای برای برگشت انتخاب نشده است.'];
        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $agent_col = $department === 'finance' ? 'finance_agent_id' : 'deficiency_agent_id';
        $returnable_status = $department === 'finance' ? 'FINANCE_ASSIGNED' : 'INCOMPLETE_ASSIGNED';
        $returned = 0;
        foreach ($case_ids as $case_id) {
            $wpdb->query('START TRANSACTION');
            try {
                $case = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cases WHERE id=%d FOR UPDATE", $case_id));
                if (!$case || in_array((string)$case->status, ['COMPLETED','ARCHIVED'], true)) throw new RuntimeException('skip');
                if ((int)($case->{$agent_col} ?? 0) !== $agent_id || (string)$case->status !== $returnable_status) throw new RuntimeException('skip');
                if ($department === 'finance') {
                    $data = ['finance_agent_id'=>null,'finance_assigned_at'=>null,'status'=>'FINANCE_REVIEW_PENDING','current_department'=>'finance','updated_at'=>Biawin_CRM_DB::now()];
                    $to = 'FINANCE_REVIEW_PENDING';
                    $event = 'FINANCE_ASSIGNMENT_RETURNED';
                } else {
                    $data = ['deficiency_agent_id'=>null,'assigned_at'=>null,'status'=>'INCOMPLETE_NEW','current_department'=>'deficiency','updated_at'=>Biawin_CRM_DB::now()];
                    $to = 'INCOMPLETE_NEW';
                    $event = 'DEFICIENCY_ASSIGNMENT_RETURNED';
                }
                $ok = $wpdb->update($cases, $data, ['id'=>$case_id]);
                if ($ok === false) throw new RuntimeException('db');
                self::action($case_id, (int)$case->customer_id, $department, $event, (string)$case->status, $to, ['agent_user_id'=>$agent_id]);
                $wpdb->query('COMMIT');
                $returned++;
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
            }
        }
        return ['ok'=>true,'returned'=>$returned];
    }

    public static function process_action(array $raw): array
    {
        global $wpdb;
        $case_id = absint($raw['case_id'] ?? 0);
        $action = sanitize_key($raw['case_action'] ?? '');
        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $case = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cases WHERE id=%d", $case_id));
        if (!$case) return ['ok'=>false,'message'=>'پرونده پیدا نشد.'];
        if (!self::can_access_case($case)) return ['ok'=>false,'message'=>'دسترسی به این پرونده مجاز نیست.'];
        if (in_array((string)$case->status, ['COMPLETED','ARCHIVED'], true)) return ['ok'=>false,'message'=>'پرونده بسته شده و قابل تغییر نیست.'];

        if ($action === 'contact') {
            return self::record_contact($case, $raw);
        }
        if ($action === 'deficiency_submit') {
            return self::process_deficiency_submit($case, $raw);
        }
        if ($action === 'deficiency_result') {
            return self::record_deficiency_result($case, $raw);
        }
        if ($action === 'send_finance') {
            return self::send_to_finance($case, $raw);
        }
        if ($action === 'finance_submit') {
            return self::process_finance_submit($case, $raw);
        }
        if ($action === 'finance_result') {
            return self::record_finance_result($case, $raw);
        }
        if ($action === 'upload_document') {
            return self::upload_document($case, $raw);
        }
        if ($action === 'complete' || $action === 'archive') {
            return self::final_decision($case, $action, sanitize_textarea_field(wp_unslash($raw['description'] ?? '')), $raw);
        }
        return ['ok'=>false,'message'=>'اقدام انتخاب‌شده معتبر نیست.'];
    }

    private static function process_deficiency_submit(object $case, array $raw): array
    {
        $step = sanitize_key($raw['workflow_step'] ?? '');
        if ($step === 'contact') return self::record_contact($case, $raw);
        if ($step === 'result') return self::record_deficiency_result($case, $raw);
        if ($step === 'send_finance') return self::send_to_finance($case, $raw);
        if ($step === 'return_invoice') return self::record_returned_invoice($case, $raw);
        if ($step === 'complete_corrected') return self::complete_with_corrected_declared_amount($case, $raw);
        if ($step === 'complete') return self::final_decision($case, 'complete', sanitize_textarea_field(wp_unslash($raw['description'] ?? '')), $raw);
        if ($step === 'archive') return self::final_decision($case, 'archive', sanitize_textarea_field(wp_unslash($raw['description'] ?? '')), $raw);
        return ['ok'=>false,'message'=>'مرحله انتخاب‌شده معتبر نیست.'];
    }

    private static function process_finance_submit(object $case, array $raw): array
    {
        return self::record_finance_result($case, $raw);
    }

    private static function upload_document(object $case, array $raw): array
    {
        if (!current_user_can('process_deficiency_case') && !current_user_can('review_finance_case') && !current_user_can('manage_deficiency_cases')) return ['ok'=>false,'message'=>'دسترسی آپلود مدرک ندارید.'];
        $saved = self::save_uploaded_document($case, $raw, 'case_document');
        if (empty($saved['ok'])) return $saved;
        self::action((int)$case->id, (int)$case->customer_id, (string)$case->current_department, 'DOCUMENT_UPLOADED', (string)$case->status, (string)$case->status, [
            'document_type'=>$saved['document_type'],
            'attachment_id'=>(int)$saved['attachment_id'],
            'receipt_amount'=>$saved['receipt_amount'] ?? null,
            'description'=>$saved['description'] ?? '',
        ]);
        return ['ok'=>true,'message'=>'مدرک با موفقیت بارگذاری شد.'];
    }

    /** Original request keys (sparse-safe) of the files actually received for a field. */
    private static function uploaded_file_indexes(string $field): array
    {
        return Biawin_CRM_Upload_Normalizer::uploaded_keys($field);
    }

    /** Presence check by ORIGINAL request key; never by compacted position. */
    private static function has_uploaded_file(string $field, ?int $index = null): bool
    {
        $files = Biawin_CRM_Upload_Normalizer::normalize_field($field);
        if ($index === null) return (bool)$files;
        return isset($files[$index]);
    }

    /** Request-scoped file identity: SHA-256 of the actual uploaded content (filename fallback). */
    private static function uploaded_file_signature(string $field, int $index = 0): string
    {
        $files = Biawin_CRM_Upload_Normalizer::normalize_field($field);
        $file = $files[$index] ?? null;
        if (!$file) return '';
        return Biawin_CRM_Upload_Normalizer::content_hash($file)
            ?: ('name:' . strtolower($file['name']) . '|size:' . (int)$file['size']);
    }

    /**
     * Saves ONE canonical uploaded file (by ORIGINAL request key) to the media library and
     * inserts the attachment DB row. Fails the row explicitly; never borrows another row's file.
     */
    private static function save_uploaded_document(object $case, array $raw, string $field = 'case_document'): array
    {
        global $wpdb;
        $normalized = Biawin_CRM_Upload_Normalizer::normalize_field($field);
        $index = isset($raw['_file_index']) ? $raw['_file_index'] : null;
        if ($index !== null && !isset($normalized[$index])) {
            Biawin_CRM_Upload_Normalizer::log('row_missing_file', ['field'=>$field,'key'=>(string)$index]);
        }
        $candidate = null;
        if ($index !== null && isset($normalized[$index])) {
            $candidate = $normalized[$index]; // exact original request key only — no positional fallback
        } elseif ($index === null && $normalized) {
            $candidate = reset($normalized); // single-file inputs (upload_document action / REST)
        }
        if (!$candidate) {
            return ['ok'=>false,'message'=>'فایل مدرک انتخاب نشده است.'];
        }
        if ((int)$candidate['error'] !== UPLOAD_ERR_OK) {
            Biawin_CRM_Upload_Normalizer::log('upload_php_error', ['field'=>$field,'key'=>(string)$candidate['key'],'php_error'=>(int)$candidate['error']]);
            $known = [
                UPLOAD_ERR_INI_SIZE => 'حجم فایل از سقف upload_max_filesize سرور بیشتر است.',
                UPLOAD_ERR_FORM_SIZE => 'حجم فایل از سقف تعیین‌شده در فرم بیشتر است.',
                UPLOAD_ERR_PARTIAL => 'فایل ناقص منتقل شد؛ دوباره تلاش کنید.',
                UPLOAD_ERR_NO_FILE => 'فایلی آپلود نشد.',
            ];
            $message = $known[(int)$candidate['error']] ?? 'آپلود فایل با خطای PHP متوقف شد (کد ' . (int)$candidate['error'] . ').';
            return ['ok'=>false,'message'=>$message];
        }
        $size = (int)$candidate['size'];
        if ($size > Biawin_CRM_Upload_Normalizer::MAX_FILE_BYTES) {
            return ['ok'=>false,'message'=>'حجم فایل نباید بیشتر از ' . (int)round(Biawin_CRM_Upload_Normalizer::MAX_FILE_BYTES / 1048576) . ' مگابایت باشد.'];
        }
        if ($size <= 0 || (string)$candidate['name'] === '') {
            return ['ok'=>false,'message'=>'فایل ارسالی نامعتبر است.'];
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        // media_handle_upload() reads the $_FILES entry itself and requires a scalar entry,
        // so hand it exactly THIS row's canonical file under a private temp field.
        // The row→file selection above stays keyed by the ORIGINAL request key.
        // v0.8.61.4 — staged receipts are queued files on disk, NOT PHP-managed uploads:
        // media_handle_upload() runs is_uploaded_file() and would fail EVERY staged row
        // in real WordPress (the local harness copied files blindly, masking this).
        // The staging branch therefore uses media_handle_sideload(), WP's native path
        // for on-disk sources; inline browser uploads keep media_handle_upload().
        // v0.8.62.27 — ANY format is accepted: the filename is sanitized (server-active
        // extensions neutralized) and WordPress' per-role file-type allowlist is bypassed
        // with test_type=false, which otherwise rejected HEIC/DNG/Office/ZIP… for agents.
        $safe_name = Biawin_CRM_Upload_Normalizer::safe_filename((string)$candidate['name'], (string)$candidate['type']);
        $mime = Biawin_CRM_Upload_Normalizer::detect_mime((string)$candidate['tmp_name'], $safe_name, (string)$candidate['type']);
        $is_staged = ($field === 'bcrm_case_document_upload') || !is_uploaded_file((string)$candidate['tmp_name']);
        if ($is_staged) {
            $attachment_id = self::import_file_to_media((string)$candidate['tmp_name'], $safe_name, $mime);
        } else {
            $upload_field = Biawin_CRM_Upload_Normalizer::UPLOAD_FIELD;
            $had_temp_field = array_key_exists($upload_field, $_FILES);
            $prev_temp_field = $had_temp_field ? $_FILES[$upload_field] : null;
            $_FILES[$upload_field] = [
                'name' => $safe_name,
                'type' => $mime,
                'tmp_name' => (string)$candidate['tmp_name'],
                'error' => (int)$candidate['error'],
                'size' => (int)$candidate['size'],
            ];
            $attachment_id = media_handle_upload($upload_field, 0, ['post_mime_type' => $mime], [
                'test_form' => false,
                'test_type' => false,
            ]);
            if ($had_temp_field) $_FILES[$upload_field] = $prev_temp_field;
            else unset($_FILES[$upload_field]);
        }
        if (is_wp_error($attachment_id)) {
            Biawin_CRM_Upload_Normalizer::log('media_handle_upload_failed', ['field'=>$field,'key'=>(string)$candidate['key'],'error'=>$attachment_id->get_error_message()]);
            return ['ok'=>false,'message'=>'آپلود مدرک ناموفق بود: ' . $attachment_id->get_error_message()];
        }
        $document_type = sanitize_key($raw['document_type'] ?? 'document');
        $note = sanitize_text_field(wp_unslash($raw['description'] ?? ''));
        $receipt_amount = isset($raw['receipt_amount']) && trim((string)$raw['receipt_amount']) !== '' ? Biawin_CRM_Normalizer::money($raw['receipt_amount']) : null;
        if ($receipt_amount !== null) {
            $note = trim($note . ' | مبلغ فیش: ' . number_format_i18n((float)$receipt_amount));
        }
        $saved = $wpdb->insert(Biawin_CRM_DB::table('attachments'), [
            'customer_id'=>(int)$case->customer_id,
            'object_type'=>'deficiency_case',
            'object_id'=>(int)$case->id,
            'wp_attachment_id'=>(int)$attachment_id,
            'file_kind'=>$document_type,
            'note'=>$note,
            'uploaded_by'=>get_current_user_id() ?: null,
            'created_at'=>Biawin_CRM_DB::now(),
        ]);
        if ($saved === false || !(int)$wpdb->insert_id) {
            wp_delete_attachment((int)$attachment_id, true); // no orphaned media files
            Biawin_CRM_Upload_Normalizer::log('attachment_db_insert_failed', ['case_id'=>(int)$case->id,'wp_attachment_id'=>(int)$attachment_id]);
            return ['ok'=>false,'message'=>'فایل آپلود شد اما ثبت آن در پرونده انجام نشد.'];
        }
        return ['ok'=>true,'attachment_id'=>(int)$wpdb->insert_id,'wp_attachment_id'=>(int)$attachment_id,'document_type'=>$document_type,'description'=>$note,'receipt_amount'=>$receipt_amount,'request_key'=>$candidate['key'],'content_hash'=>Biawin_CRM_Upload_Normalizer::content_hash($candidate)];
    }

    /**
     * v0.8.62.27 — media import for an on-disk file of ANY format. media_handle_sideload()
     * hard-codes its overrides (so the per-role file-type allowlist always applied); this is
     * the same sequence with test_type=false: move into uploads, insert the attachment post,
     * generate metadata (thumbnails are suppressed by the finalize caller).
     *
     * @return int|WP_Error
     */
    private static function import_file_to_media(string $path, string $name, string $mime)
    {
        $file = ['name'=>$name, 'type'=>$mime, 'tmp_name'=>$path, 'error'=>UPLOAD_ERR_OK, 'size'=>(int)@filesize($path)];
        $moved = wp_handle_sideload($file, ['test_form'=>false, 'test_type'=>false]);
        if (!is_array($moved) || isset($moved['error']) || empty($moved['file'])) {
            $error = is_array($moved) && isset($moved['error']) ? (string)$moved['error'] : 'انتقال فایل به پوشه رسانه ناموفق بود.';
            return new WP_Error('bcrm_sideload_failed', $error);
        }
        $title = (string)preg_replace('/\.[^.]+$/', '', $name);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $mime,
            'guid'           => (string)($moved['url'] ?? ''),
            'post_parent'    => 0,
            'post_title'     => sanitize_text_field($title !== '' ? $title : $name),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], (string)$moved['file'], 0, true);
        if (is_wp_error($attachment_id) || !(int)$attachment_id) {
            @unlink((string)$moved['file']);
            return is_wp_error($attachment_id) ? $attachment_id : new WP_Error('bcrm_attachment_insert_failed', 'ثبت فایل در کتابخانه رسانه ناموفق بود.');
        }
        if (strpos($mime, 'image/') === 0 || $mime === 'application/pdf') {
            try {
                $meta = wp_generate_attachment_metadata((int)$attachment_id, (string)$moved['file']);
                if (is_array($meta)) wp_update_attachment_metadata((int)$attachment_id, $meta);
            } catch (Throwable $e) {
                // Metadata (dimensions/preview) is cosmetic; an undecodable HEIC must not fail the receipt.
                Biawin_CRM_Upload_Normalizer::log('attachment_metadata_failed', ['wp_attachment_id'=>(int)$attachment_id, 'error'=>$e->getMessage()]);
            }
        }
        return (int)$attachment_id;
    }

    /**
     * Public materialization entry for staged receipts (Biawin_CRM_Receipt_Staging).
     * The staged file is placed in the private scalar $_FILES field by the caller;
     * this runs the exact same media + DB + integrity path as an inline upload.
     */
    public static function save_uploaded_document_for_staging(object $case, array $raw): array
    {
        return self::save_uploaded_document($case, $raw, 'bcrm_case_document_upload');
    }

    private static function record_contact(object $case, array $raw): array
    {
        global $wpdb;
        if (!current_user_can('process_deficiency_case') && !current_user_can('manage_deficiency_cases')) return ['ok'=>false,'message'=>'دسترسی ثبت تماس ندارید.'];
        $outcome = sanitize_key($raw['contact_outcome'] ?? '');
        // v0.8.62.20: نتیجه تماس جدید «در انتظار ارسال فیش» (awaiting_receipt) — پرونده در انتظار مشتری می‌ماند
        $allowed = ['answered','no_answer','powered_off','unavailable','wrong_number','follow_up','awaiting_receipt'];
        if (!in_array($outcome, $allowed, true)) return ['ok'=>false,'message'=>'نتیجه تماس معتبر نیست.'];
        $follow = Biawin_CRM_Normalizer::date(wp_unslash($raw['follow_up_date'] ?? '')); // v0.8.62.21: شمسی یا میلادی → میلادی
        if ($outcome === 'follow_up' && $follow === '') return ['ok'=>false,'message'=>'برای پیگیری مجدد، تاریخ پیگیری الزامی است.'];
        $to = in_array($outcome, ['follow_up','awaiting_receipt'], true) ? 'CUSTOMER_CONTACT_PENDING' : 'DEFICIENCY_UNDER_REVIEW';
        $meta = ['contact_outcome'=>$outcome,'follow_up_date'=>$follow,'description'=>sanitize_textarea_field(wp_unslash($raw['description'] ?? ''))];
        return self::update_status((int)$case->id, (int)$case->customer_id, 'deficiency', 'CUSTOMER_CONTACT_ATTEMPT', (string)$case->status, $to, [
            'last_contact_outcome'=>$outcome,
            'follow_up_at'=>$follow ?: null,
            'updated_at'=>Biawin_CRM_DB::now(),
        ], $meta);
    }

    private static function record_deficiency_result(object $case, array $raw): array
    {
        global $wpdb;
        if (!current_user_can('process_deficiency_case') && !current_user_can('manage_deficiency_cases')) return ['ok'=>false,'message'=>'دسترسی ثبت نتیجه ندارید.'];
        $result = sanitize_key($raw['deficiency_result'] ?? '');
        $allowed = ['resolved','real_mismatch','extra_payment_claim','missing_invoice','incomplete_invoice','needs_finance','needs_more_review','other'];
        if (!in_array($result, $allowed, true)) return ['ok'=>false,'message'=>'نتیجه بررسی معتبر نیست.'];
        $description = sanitize_textarea_field(wp_unslash($raw['description'] ?? ''));
        $to = ($result === 'resolved') ? 'READY_FOR_FINAL_DECISION' : 'DEFICIENCY_UNDER_REVIEW';
        $data = [
            'deficiency_result'=>$result,
            'customer_claimed_amount'=>isset($raw['customer_claimed_amount']) && $raw['customer_claimed_amount'] !== '' ? Biawin_CRM_Normalizer::money($raw['customer_claimed_amount']) : null,
            'confirmed_difference_amount'=>isset($raw['confirmed_difference_amount']) && $raw['confirmed_difference_amount'] !== '' ? Biawin_CRM_Normalizer::money($raw['confirmed_difference_amount']) : null,
            'deficiency_description'=>$description,
            'updated_at'=>Biawin_CRM_DB::now(),
        ];
        return self::update_status((int)$case->id, (int)$case->customer_id, 'deficiency', 'DEFICIENCY_RESULT_RECORDED', (string)$case->status, $to, $data, $data);
    }

    private static function send_to_finance(object $case, array $raw): array
    {
        global $wpdb;
        if (!current_user_can('send_case_to_finance') && !current_user_can('manage_deficiency_cases')) return ['ok'=>false,'message'=>'دسترسی ارسال به مالی ندارید.'];
        if (!in_array((string)$case->status, ['DEFICIENCY_UNDER_REVIEW','INCOMPLETE_ASSIGNED','CUSTOMER_CONTACT_PENDING','READY_FOR_FINAL_DECISION','RETURNED_TO_DEFICIENCY'], true)) {
            // v0.8.61.5 — this guard previously rejected SILENTLY: the browser got the
            // generic message, the log had nothing, and support had no way to see why a
            // fully-staged batch never finalized (the 22-Sep 10:03 log has 18 staged
            // files with no finalize trace at all). Log the refusal with the actual
            // status and tell the user what that status means.
            $status_hints = [
                'FINANCE_REVIEW_PENDING'      => 'پرونده قبلاً به مالی ارسال شده و در انتظار بررسی مالی است؛ ارسال مجدد لازم نیست.',
                'FINANCE_APPROVED'            => 'پرونده توسط مالی تایید شده است.',
                'FINANCE_RETURNED'            => 'پرونده در وضعیت برگشتی از مالی است.',
                'CASE_COMPLETED'              => 'پرونده تکمیل شده است.',
                'CASE_ARCHIVED'               => 'پرونده بایگانی شده است.',
                'RETURNED_INVOICE_RECORDED'   => 'فاکتور برگشتی این پرونده ثبت شده است.',
            ];
            $hint = $status_hints[(string)$case->status] ?? ('وضعیت فعلی پرونده: ' . (string)$case->status . '؛ صفحه را تازه‌سازی کنید و وضعیت پرونده را بررسی کنید.');
            Biawin_CRM_Upload_Normalizer::log('send_to_finance_status_guard_blocked', ['case_id'=>(int)$case->id,'status'=>(string)$case->status]);
            return ['ok'=>false,'message'=>'پرونده در این وضعیت قابل ارسال به مالی نیست. ' . $hint];
        }
        $description = sanitize_textarea_field(wp_unslash($raw['description'] ?? ''));
        if ($description === '') return ['ok'=>false,'message'=>'توضیحات ارسال به مالی الزامی است.'];
        $refund_status = !empty($raw['refund_status']) && sanitize_key((string)$raw['refund_status']) === 'refunded' ? 'refunded' : '';
        // Detect a request truncated by post_max_size BEFORE acting on it (never continue silently).
        $early_limit = Biawin_CRM_Upload_Normalizer::check_server_limits(0);
        if (empty($early_limit['ok'])) {
            Biawin_CRM_Upload_Normalizer::log('request_truncated_post_max_size', ['case_id'=>(int)$case->id]);
            return ['ok'=>false,'message'=>$early_limit['message'],'upload_accounting'=>['received_count'=>0,'valid_count'=>0,'uploaded_count'=>0,'persisted_count'=>0,'duplicate_count'=>0,'failed_count'=>0]];
        }
        // --- Staged path (v0.8.61.0): the browser uploads every receipt file
        // individually BEFORE submit, so server caps (the per-request multi-file
        // ini and post_max_size) can never truncate the batch anymore. The final
        // submit carries only the staged ids + amounts; here we validate them 1:1
        // and materialize the real attachments. With staging ids present, server
        // multi-file limits are irrelevant BY CONSTRUCTION — no files ride along.
        $staged_ids_raw = isset($raw['staged_receipt_ids']) ? (array)$raw['staged_receipt_ids'] : [];
        $staged_ids = [];
        foreach ($staged_ids_raw as $sid) {
            $sid = trim((string)$sid);
            if ($sid !== '') $staged_ids[] = $sid;
        }
        if ($staged_ids) {
            if (!class_exists('Biawin_CRM_Receipt_Staging')) {
                return ['ok'=>false,'message'=>'سرویس آپلود مرحله‌ای در دسترس نیست.','upload_accounting'=>['received_count'=>count($staged_ids),'valid_count'=>0,'uploaded_count'=>0,'persisted_count'=>0,'duplicate_count'=>0,'failed_count'=>count($staged_ids)]];
            }
            $staged_amounts_raw = isset($raw['staged_receipt_amounts']) ? array_values((array)$raw['staged_receipt_amounts']) : [];
            $result = Biawin_CRM_Receipt_Staging::finalize_for_case($case, $staged_ids, $staged_amounts_raw, $description);
            $accounting = $result['accounting'] ?? [];
            if (empty($result['ok'])) {
                Biawin_CRM_Upload_Normalizer::log('staged_finalize_failed', ['case_id'=>(int)$case->id,'ids'=>count($staged_ids),'reason'=>(string)($result['message'] ?? '')]);
                return ['ok'=>false,'message'=>(string)($result['message'] ?? 'ثبت فیش‌ها ناموفق بود.'),'upload_accounting'=>$accounting];
            }
            $documents = $result['documents'];
            $total_receipt_amount = 0.0;
            foreach ($documents as $doc) $total_receipt_amount += (float)($doc['receipt_amount'] ?? 0);
            Biawin_CRM_Upload_Normalizer::log('staged_receipt_summary', ['case_id'=>(int)$case->id,'received_count'=>$accounting['received_count'] ?? count($documents),'uploaded_count'=>count($documents),'duplicate_count'=>$accounting['duplicate_count'] ?? 0,'attachment_ids'=>array_map(static fn($doc)=>(int)($doc['attachment_id'] ?? 0), $documents)]);
            $accounting_full = $accounting + ['attachment_ids'=>array_map(static fn($doc)=>(int)($doc['attachment_id'] ?? 0), $documents)];
            self::tag_customer((int)$case->customer_id, 'SENT_TO_FINANCE', (int)$case->id);
            if ($refund_status === 'refunded') self::tag_customer((int)$case->customer_id, 'REFUND_COMPLETED_SENT_TO_FINANCE', (int)$case->id);
            $moved = self::update_status((int)$case->id, (int)$case->customer_id, 'deficiency', 'CASE_SENT_TO_FINANCE', (string)$case->status, 'FINANCE_REVIEW_PENDING', [
                'current_department'=>'finance',
                'sent_to_finance_at'=>Biawin_CRM_DB::now(),
                'deficiency_description'=>$description,
                'updated_at'=>Biawin_CRM_DB::now(),
            ], ['description'=>$description,'difference_amount'=>(float)$case->difference_amount,'send_receipt'=>1,'refund_status'=>$refund_status,'receipt_amount'=>$total_receipt_amount,'receipt_count'=>count($documents),'attachment_ids'=>$accounting_full['attachment_ids']]);
            if (empty($moved['ok'])) {
                // Status transition failed AFTER successful materialization: roll this
                // request's uploads back; previous-round attachments stay untouched.
                foreach ($documents as $doc) {
                    $att_id = (int)($doc['attachment_id'] ?? 0);
                    if ($att_id > 0) $wpdb->delete(Biawin_CRM_DB::table('attachments'), ['id'=>$att_id]);
                    $wp_att = (int)($doc['wp_attachment_id'] ?? 0);
                    if ($wp_att > 0) wp_delete_attachment($wp_att, true);
                }
                Biawin_CRM_Upload_Normalizer::log('status_transition_failed_after_staged_uploads', ['case_id'=>(int)$case->id,'uploaded_count'=>count($documents),'reason'=>(string)($moved['message'] ?? '')]);
                return ['ok'=>false,'message'=>(string)($moved['message'] ?? 'عملیات انجام نشد.'),'upload_accounting'=>$accounting_full + ['persisted_count'=>0,'failed_count'=>count($documents)]];
            }
            // v0.8.62.27: consume ONLY what this submit persisted — other cases / users / in-flight uploads stay.
            Biawin_CRM_Receipt_Staging::consume($staged_ids);
            return ['ok'=>true,'message'=>'عملیات با موفقیت ثبت شد — ' . number_format_i18n(count($documents)) . ' فیش ثبت شد.','upload_accounting'=>$accounting_full];
        }
        $receipt_rows = (array)($raw['receipt_amounts'] ?? []);
        if (!$receipt_rows && isset($raw['receipt_amount'])) $receipt_rows = [$raw['receipt_amount']];
        // Pairing contract (THE fix for the multi-file data-integrity bug):
        // the browser posts receipt_amounts[] and case_documents[] in the SAME DOM row order,
        // and PHP densifies the VALUES of both arrays on arrival (file keys may stay sparse).
        // Pairing is therefore done by validated ORDER after a strict count check — never by
        // array key, and never by re-indexing one side with array_values() while the other
        // keeps its own keys. A count mismatch means a lost/truncated/malformed request:
        // fail loud, never guess and never borrow another row's file.
        $normalized_files = Biawin_CRM_Upload_Normalizer::normalize_field('case_documents');
        $receipt_file_keys = array_keys($normalized_files);
        $has_receipt_amount = false;
        foreach ($receipt_rows as $amount_raw) {
            if (Biawin_CRM_Normalizer::money($amount_raw) > 0) { $has_receipt_amount = true; break; }
        }
        $send_receipt = !empty($raw['send_receipt']) || $has_receipt_amount || $normalized_files || self::has_uploaded_file('case_document');
        $documents = [];
        $seen_receipt_files = [];
        $received_keys = $receipt_file_keys;
        $uploaded_keys = [];
        $failed_keys = [];
        $duplicate_keys = [];
        $duplicate_count = 0;
        $rolled_back_attachment_ids = [];
        $rollback_error = '';
        $total_receipt_amount = 0.0;
        $roll_back_request = static function () use ($wpdb, &$documents, &$rolled_back_attachment_ids): void {
            // Roll back everything persisted in THIS request; previous-round attachments stay untouched.
            foreach ($documents as $doc) {
                $att_id = (int)($doc['attachment_id'] ?? 0);
                if ($att_id > 0) $wpdb->delete(Biawin_CRM_DB::table('attachments'), ['id'=>$att_id]);
                $wp_att = (int)($doc['wp_attachment_id'] ?? 0);
                if ($wp_att > 0) { wp_delete_attachment($wp_att, true); $rolled_back_attachment_ids[] = $wp_att; }
            }
            $documents = [];
        };
        if ($send_receipt) {
            $declared_rows = count($receipt_rows);
            $limit_check = Biawin_CRM_Upload_Normalizer::check_server_limits(count($received_keys), $declared_rows);
            if (empty($limit_check['ok'])) {
                Biawin_CRM_Upload_Normalizer::log('server_limit_truncation', ['case_id'=>(int)$case->id,'received'=>count($received_keys),'declared_rows'=>$declared_rows,'max_uploads_ini'=>Biawin_CRM_Upload_Normalizer::max_uploads_ini()]);
                return ['ok'=>false,'message'=>$limit_check['message'] . "\n" . Biawin_CRM_Upload_Normalizer::server_limit_notice('رفع ناقصی'),'upload_accounting'=>['received_count'=>count($received_keys),'valid_count'=>0,'uploaded_count'=>0,'persisted_count'=>0,'duplicate_count'=>0,'failed_count'=>count($received_keys)]];
            }
            // Canonical row list: one entry per uploaded file, in DOM/request order.
            // Legacy single-file field (case_document) pairs with the first amount row.
            $pairs = [];
            foreach ($normalized_files as $key => $entry) {
                $pairs[] = ['key'=>$key, 'entry'=>$entry, 'field'=>'case_documents'];
            }
            if (!$pairs) {
                $single = Biawin_CRM_Upload_Normalizer::normalize_field('case_document');
                if ($single) $pairs[] = ['key'=>0, 'entry'=>reset($single), 'field'=>'case_document'];
            }
            if (count($receipt_rows) !== count($pairs)) {
                $message = $pairs ? 'مبلغ همه فیش‌های ارسال به مالی الزامی است.' : 'فایل همه فیش‌های ارسال به مالی الزامی است.';
                Biawin_CRM_Upload_Normalizer::log('receipt_pair_count_mismatch', ['case_id'=>(int)$case->id,'amount_rows'=>count($receipt_rows),'file_rows'=>count($pairs)]);
                return ['ok'=>false,'message'=>$message,'upload_accounting'=>['received_count'=>count($received_keys),'valid_count'=>0,'uploaded_count'=>0,'persisted_count'=>0,'duplicate_count'=>0,'failed_count'=>max(count($receipt_rows), count($pairs))]];
            }
            foreach ($pairs as $i => $pair) {
                $idx = (int)$pair['key'];
                $receipt_amount = Biawin_CRM_Normalizer::money($receipt_rows[$i] ?? '');
                if ($receipt_amount <= 0) {
                    $roll_back_request();
                    Biawin_CRM_Upload_Normalizer::log('row_failed_missing_amount', ['case_id'=>(int)$case->id,'row_position'=>$i,'rolled_back_wp_attachments'=>count($rolled_back_attachment_ids)]);
                    return ['ok'=>false,'message'=>'مبلغ همه فیش‌های ارسال به مالی الزامی است.','upload_accounting'=>['received_count'=>count($received_keys),'valid_count'=>count($seen_receipt_files),'uploaded_count'=>count($uploaded_keys),'persisted_count'=>0,'duplicate_count'=>$duplicate_count,'failed_count'=>count($failed_keys)+1]];
                }
                $entry = $pair['entry'];
                // Request-scoped identity: SHA-256 of the real content; two different files
                // with the same filename are both accepted, the same content only once.
                $signature = Biawin_CRM_Upload_Normalizer::content_hash($entry);
                if ($signature === '') $signature = 'name:' . strtolower((string)$entry['name']) . '|size:' . (int)$entry['size'];
                if (isset($seen_receipt_files[$signature])) {
                    // v0.8.61.4 — same content as an earlier row in THIS request: each
                    // visible row is a real receipt line with its own amount, so it is
                    // STILL persisted on this row (each inline row has its own uploaded
                    // tmp file). duplicate_count only reports the content repeat; the
                    // old "skip the repeat" behavior silently dropped rows + amounts.
                    $duplicate_keys[] = $idx;
                    $duplicate_count++;
                    Biawin_CRM_Upload_Normalizer::log('duplicate_receipt_row_content_repeat', ['case_id'=>(int)$case->id,'row_position'=>$i]);
                } else {
                    $seen_receipt_files[$signature] = true;
                }
                $document = self::save_uploaded_document($case, ['document_type'=>'bank_receipt','receipt_amount'=>$receipt_amount,'description'=>$description,'_file_index'=>$pair['key']] + $raw, $pair['field']);
                if (empty($document['ok'])) {
                    $roll_back_request();
                    $rollback_error = (string)($document['message'] ?? 'آپلود فیش ناموفق بود.');
                    $failed_keys[] = $idx;
                    Biawin_CRM_Upload_Normalizer::log('receipt_row_failed_rollback', ['case_id'=>(int)$case->id,'row_position'=>$i,'rolled_back_wp_attachments'=>count($rolled_back_attachment_ids),'failed_count'=>count($failed_keys)]);
                    return ['ok'=>false,'message'=>'آپلود فیش‌ها کامل نشد: ' . $rollback_error,'upload_accounting'=>['received_count'=>count($received_keys),'valid_count'=>count($seen_receipt_files),'uploaded_count'=>count($uploaded_keys),'persisted_count'=>0,'duplicate_count'=>$duplicate_count,'failed_count'=>count($failed_keys)]];
                }
                $documents[] = $document;
                $uploaded_keys[] = $idx;
                $total_receipt_amount += $receipt_amount;
            }
            if (!$documents) {
                if ($duplicate_count > 0 && count($received_keys) === $duplicate_count) {
                    return ['ok'=>false,'message'=>'همه فیش‌های انتخابی تکراری هستند و قبلاً بارگذاری شده‌اند.','upload_accounting'=>['received_count'=>count($received_keys),'valid_count'=>count($seen_receipt_files),'uploaded_count'=>0,'persisted_count'=>0,'duplicate_count'=>$duplicate_count,'failed_count'=>0]];
                }
                Biawin_CRM_Upload_Normalizer::log('no_valid_receipts', ['case_id'=>(int)$case->id,'received'=>count($received_keys)]);
                return ['ok'=>false,'message'=>'حداقل یک فیش برای ارسال به مالی الزامی است.','upload_accounting'=>['received_count'=>count($received_keys),'valid_count'=>0,'uploaded_count'=>0,'persisted_count'=>0,'duplicate_count'=>$duplicate_count,'failed_count'=>count($failed_keys)]];
            }
            Biawin_CRM_Upload_Normalizer::log('receipt_upload_summary', ['case_id'=>(int)$case->id,'received_count'=>count($received_keys),'uploaded_count'=>count($uploaded_keys),'duplicate_count'=>$duplicate_count,'attachment_ids'=>array_map(static fn($doc)=>(int)($doc['attachment_id'] ?? 0), $documents),'uploaded_row_keys'=>$uploaded_keys,'failed_row_keys'=>$failed_keys,'duplicate_row_keys'=>$duplicate_keys]);
        }
        $accounting = [
            'received_count'=>count($received_keys),
            'valid_count'=>count($seen_receipt_files),
            'uploaded_count'=>count($uploaded_keys),
            'persisted_count'=>count($documents),
            'duplicate_count'=>$duplicate_count,
            'failed_count'=>count($failed_keys),
            'attachment_ids'=>array_map(static fn($doc)=>(int)($doc['attachment_id'] ?? 0), $documents),
        ];
        self::tag_customer((int)$case->customer_id, 'SENT_TO_FINANCE', (int)$case->id);
        if ($refund_status === 'refunded') self::tag_customer((int)$case->customer_id, 'REFUND_COMPLETED_SENT_TO_FINANCE', (int)$case->id);
        $moved = self::update_status((int)$case->id, (int)$case->customer_id, 'deficiency', 'CASE_SENT_TO_FINANCE', (string)$case->status, 'FINANCE_REVIEW_PENDING', [
            'current_department'=>'finance',
            'sent_to_finance_at'=>Biawin_CRM_DB::now(),
            'deficiency_description'=>$description,
            'updated_at'=>Biawin_CRM_DB::now(),
        ], ['description'=>$description,'difference_amount'=>(float)$case->difference_amount,'send_receipt'=>$send_receipt ? 1 : 0,'refund_status'=>$refund_status,'receipt_amount'=>$total_receipt_amount,'receipt_count'=>count($documents),'attachment_ids'=>$accounting['attachment_ids']]);
        if (empty($moved['ok'])) {
            // Status transition failed AFTER successful uploads: roll this request's
            // uploads back so we never keep uploaded receipts without a sent case.
            foreach ($documents as $doc) {
                $att_id = (int)($doc['attachment_id'] ?? 0);
                if ($att_id > 0) $wpdb->delete(Biawin_CRM_DB::table('attachments'), ['id'=>$att_id]);
                $wp_att = (int)($doc['wp_attachment_id'] ?? 0);
                if ($wp_att > 0) wp_delete_attachment($wp_att, true);
            }
            Biawin_CRM_Upload_Normalizer::log('status_transition_failed_after_uploads', ['case_id'=>(int)$case->id,'uploaded_count'=>count($documents),'reason'=>(string)($moved['message'] ?? '')]);
            return ['ok'=>false,'message'=>(string)($moved['message'] ?? 'عملیات انجام نشد.'),'upload_accounting'=>$accounting + ['persisted_count'=>0,'failed_count'=>count($documents)]];
        }
        return ['ok'=>true,'message'=>'عملیات با موفقیت ثبت شد.','upload_accounting'=>$accounting];
    }

    private static function record_returned_invoice(object $case, array $raw): array
    {
        global $wpdb;
        if (!current_user_can('process_deficiency_case') && !current_user_can('manage_deficiency_cases')) return ['ok'=>false,'message'=>'دسترسی ثبت فاکتور برگشتی ندارید.'];
        if ((string)$case->status !== 'RETURNED_TO_DEFICIENCY') {
            // v0.8.61.5 — log the silent guard rejection like send_to_finance's guard.
            Biawin_CRM_Upload_Normalizer::log('return_invoice_status_guard_blocked', ['case_id'=>(int)$case->id,'status'=>(string)$case->status]);
            return ['ok'=>false,'message'=>'ثبت فاکتور برگشتی فقط برای پرونده برگشتی از مالی فعال است (وضعیت فعلی: ' . (string)$case->status . ').'];
        }
        $invoice_code = sanitize_text_field(wp_unslash($raw['return_invoice_number'] ?? ''));
        $amount = Biawin_CRM_Normalizer::money($raw['return_invoice_amount'] ?? 0);
        $phone = Biawin_CRM_Normalizer::phone(sanitize_text_field(wp_unslash($raw['return_customer_phone'] ?? '')));
        $description = sanitize_textarea_field(wp_unslash($raw['description'] ?? ''));
        if ($invoice_code === '') return ['ok'=>false,'message'=>'شماره فاکتور الزامی است.'];
        if ($amount <= 0) return ['ok'=>false,'message'=>'مبلغ فاکتور الزامی است.'];
        $created = $phone !== ''
            ? Biawin_CRM_Customer_Service::create_manual_invoice_with_phone((int)$case->customer_id, $phone, $invoice_code, $amount, $description, sanitize_text_field(wp_unslash($raw['return_invoice_name'] ?? '')))
            : Biawin_CRM_Customer_Service::create_manual_invoice((int)$case->customer_id, $invoice_code, $amount, $description, sanitize_text_field(wp_unslash($raw['return_invoice_name'] ?? '')));
        if (empty($created['ok'])) return $created;
        $customers = Biawin_CRM_DB::table('customers');
        $customer = $wpdb->get_row($wpdb->prepare("SELECT declared_paid,total_paid,journey_state FROM $customers WHERE id=%d", (int)$case->customer_id));
        $declared = $customer && $customer->declared_paid !== null ? (float)$customer->declared_paid : (float)$case->reported_amount;
        $registered = $customer ? (float)$customer->total_paid : ((float)$case->registered_invoice_amount + $amount);
        $difference = $declared - $registered;
        $tolerance = Biawin_CRM_Workflow_Service::completeness_tolerance();
        $to = abs($difference) <= $tolerance ? 'READY_FOR_FINAL_DECISION' : 'DEFICIENCY_UNDER_REVIEW';
        $meta = ['invoice_number'=>$invoice_code,'invoice_amount'=>$amount,'customer_phone'=>$phone,'description'=>$description,'difference_amount'=>$difference];
        return self::update_status((int)$case->id, (int)$case->customer_id, 'deficiency', 'RETURNED_INVOICE_RECORDED', (string)$case->status, $to, [
            'current_department'=>'deficiency',
            'registered_invoice_amount'=>$registered,
            'difference_amount'=>$difference,
            'deficiency_description'=>$description,
            'updated_at'=>Biawin_CRM_DB::now(),
        ], $meta);
    }

    private static function complete_with_corrected_declared_amount(object $case, array $raw): array
    {
        global $wpdb;
        if (!current_user_can('process_deficiency_case') && !current_user_can('manage_deficiency_cases')) return ['ok'=>false,'message'=>'دسترسی تکمیل پرونده ندارید.'];
        $customers = Biawin_CRM_DB::table('customers');
        $customer = $wpdb->get_row($wpdb->prepare("SELECT total_paid,declared_paid,journey_state FROM $customers WHERE id=%d", (int)$case->customer_id));
        if (!$customer) return ['ok'=>false,'message'=>'پروفایل مشتری پیدا نشد.'];
        $corrected = trim((string)($raw['corrected_declared_paid'] ?? '')) !== ''
            ? Biawin_CRM_Normalizer::money($raw['corrected_declared_paid'])
            : (float)$customer->total_paid;
        $tolerance = Biawin_CRM_Workflow_Service::completeness_tolerance();
        if (abs($corrected - (float)$customer->total_paid) > $tolerance + 0.004) {
            return ['ok'=>false,'message'=>'مبلغ اصلاح‌شده هنوز با مجموع فاکتورها مغایرت دارد و قابل تکمیل مستقیم نیست.'];
        }
        $description = sanitize_textarea_field(wp_unslash($raw['description'] ?? ''));
        $updated = $wpdb->update($customers, [
            'declared_paid'=>$corrected,
            'profile_completeness'=>'complete',
            'completion_source'=>'DEFICIENCY_WORKFLOW',
            'discrepancy_amount'=>$corrected - (float)$customer->total_paid,
            'updated_at'=>Biawin_CRM_DB::now(),
        ], ['id'=>(int)$case->customer_id]);
        if ($updated === false) return ['ok'=>false,'message'=>'اصلاح مبلغ اعلامی ذخیره نشد.'];
        Biawin_CRM_Workflow_Service::sync_customer((int)$case->customer_id);
        self::tag_customer((int)$case->customer_id, 'COMPLETED_AFTER_DEFICIENCY', (int)$case->id);
        $result = self::update_status((int)$case->id, (int)$case->customer_id, 'deficiency', 'CASE_COMPLETED_WITH_CORRECTED_DECLARED', (string)$case->status, 'COMPLETED', [
            'final_decision'=>'complete_corrected',
            'completed_at'=>Biawin_CRM_DB::now(),
            'updated_at'=>Biawin_CRM_DB::now(),
        ], ['corrected_declared_paid'=>$corrected,'previous_declared_paid'=>$customer->declared_paid,'description'=>$description,'journey_state'=>(string)$customer->journey_state]);
        if (!empty($result['ok'])) self::after_completion((int)$case->customer_id, (int)$case->id, 'complete_corrected'); // v0.8.66.0
        return $result;
    }

    private static function record_finance_result(object $case, array $raw): array
    {
        if (!current_user_can('review_finance_case') && !current_user_can('manage_finance_cases')) return ['ok'=>false,'message'=>'دسترسی ثبت نتیجه مالی ندارید.'];
        if (!in_array((string)$case->status, ['FINANCE_REVIEW_PENDING','FINANCE_ASSIGNED','FINANCE_UNDER_REVIEW'], true)) return ['ok'=>false,'message'=>'پرونده در این وضعیت قابل ثبت نتیجه مالی نیست.'];
        $result = sanitize_key($raw['finance_result'] ?? '');
        if (!isset(self::finance_result_labels()[$result])) return ['ok'=>false,'message'=>'نتیجه مالی معتبر نیست.'];
        $meta = ['finance_result'=>$result,'description'=>sanitize_textarea_field(wp_unslash($raw['description'] ?? ''))];
        $finance_approved_amount = 0.0;
        if ($result === 'with_invoice') {
            $invoice_code = sanitize_text_field(wp_unslash($raw['invoice_number'] ?? ''));
            $customer_phone = Biawin_CRM_Normalizer::phone(sanitize_text_field(wp_unslash($raw['customer_phone'] ?? '')));
            $amount = Biawin_CRM_Normalizer::money($raw['invoice_amount'] ?? 0);
            $invoice_date = Biawin_CRM_Normalizer::date(wp_unslash($raw['invoice_date'] ?? '')); // v0.8.62.21: شمسی یا میلادی → میلادی
            if ($invoice_code === '' && $customer_phone === '') return ['ok'=>false,'message'=>'حداقل شماره فاکتور یا شماره تماس مشتری الزامی است.'];
            if ($amount <= 0 || $invoice_date === '') return ['ok'=>false,'message'=>'تاریخ و مبلغ فاکتور الزامی است.'];
            if ($invoice_code === '') {
                $invoice_code = 'FIN-PHONE-' . (int)$case->id . '-' . time();
            }
            $created = Biawin_CRM_Customer_Service::create_manual_invoice((int)$case->customer_id, $invoice_code, $amount, $meta['description'], sanitize_text_field(wp_unslash($raw['seller'] ?? '')));
            if (empty($created['ok'])) return $created;
            $finance_approved_amount = $amount;
            $to = 'RETURNED_TO_DEFICIENCY';
            $meta += ['invoice_number'=>$invoice_code,'customer_phone'=>$customer_phone,'invoice_date'=>$invoice_date,'invoice_amount'=>$amount,'receipt_attachment_id'=>absint($raw['receipt_attachment_id'] ?? 0)];
            self::tag_customer((int)$case->customer_id, 'FINANCE_APPROVED_WITH_INVOICE', (int)$case->id);
        } elseif ($result === 'without_invoice') {
            $approved = Biawin_CRM_Normalizer::money($raw['approved_amount'] ?? 0);
            if ($approved <= 0) return ['ok'=>false,'message'=>'مبلغ تأییدشده الزامی است.'];
            $to = 'RETURNED_TO_DEFICIENCY';
            $finance_approved_amount = $approved;
            $meta += [
                'approved_amount'=>$approved,
                'payment_date'=>Biawin_CRM_Normalizer::date(wp_unslash($raw['payment_date'] ?? '')), // v0.8.62.21: شمسی یا میلادی → میلادی
                'tracking_number'=>sanitize_text_field(wp_unslash($raw['tracking_number'] ?? '')),
                'bank_or_gateway'=>sanitize_text_field(wp_unslash($raw['bank_or_gateway'] ?? '')),
                'notice'=>'واحد تایید مالی شماره موبایل ' . self::primary_phone((int)$case->customer_id) . ' در ساختار اکسل مالی به فیش مذکور الصاق گردد',
            ];
            self::tag_customer((int)$case->customer_id, 'FINANCE_APPROVED_WITHOUT_INVOICE', (int)$case->id);
        } else {
            $reason = sanitize_key($raw['reject_reason'] ?? '');
            $allowed = ['invalid_receipt','payment_not_found','amount_mismatch','not_customer_payment','invalid_invoice','insufficient_info','invalid_document','other'];
            if (!in_array($reason, $allowed, true)) return ['ok'=>false,'message'=>'دلیل عدم تأیید مالی الزامی است.'];
            if ($reason === 'other' && $meta['description'] === '') return ['ok'=>false,'message'=>'برای دلیل سایر، توضیحات الزامی است.'];
            $to = 'RETURNED_TO_DEFICIENCY';
            $meta += ['reject_reason'=>$reason];
            self::tag_customer((int)$case->customer_id, 'FINANCE_REJECTED', (int)$case->id);
        }
        // v0.8.62.28 — per-receipt review outcome (approved/rejected counts + approved sum) travels with the decision.
        if (class_exists('Biawin_CRM_Finance_Review')) $meta['receipt_reviews'] = Biawin_CRM_Finance_Review::summary((int)$case->id);
        if ($result !== 'rejected') self::tag_customer((int)$case->customer_id, 'FINANCE_APPROVED', (int)$case->id);
        $specific_event = [
            'with_invoice' => 'FINANCE_APPROVED_WITH_INVOICE',
            'without_invoice' => 'FINANCE_APPROVED_WITHOUT_INVOICE',
            'rejected' => 'FINANCE_REJECTED',
        ][$result];
        self::action((int)$case->id, (int)$case->customer_id, 'finance', $specific_event, (string)$case->status, $specific_event, $meta);
        $returned = self::update_status((int)$case->id, (int)$case->customer_id, 'finance', 'CASE_RETURNED_FROM_FINANCE', (string)$case->status, $to, [
            'status'=>$to,
            'current_department'=>'deficiency',
            'finance_result'=>$result,
            'finance_result_json'=>wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'finance_finished_at'=>Biawin_CRM_DB::now(),
            'updated_at'=>Biawin_CRM_DB::now(),
        ], $meta);
        if (empty($returned['ok']) || $result === 'rejected') return $returned;
        $fresh = self::get_case((int)$case->id);
        // v0.8.66.1 — D-10: with_invoice already put the invoice into total_paid (create_manual_invoice → recalculation);
        // adding the same amount again as «approved» double-counted it. Only a payment WITHOUT an invoice is added on top.
        $completed = $fresh ? self::maybe_auto_complete_after_finance($fresh, $finance_approved_amount, $result === 'with_invoice') : null;
        return is_array($completed) ? $completed : $returned;
    }

    private static function primary_phone(int $customer_id): string
    {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare(
            'SELECT normalized_phone FROM ' . Biawin_CRM_DB::table('phones') . ' WHERE customer_id=%d ORDER BY is_primary DESC,id ASC LIMIT 1',
            $customer_id
        ));
    }

    private static function maybe_auto_complete_after_finance(object $case, float $finance_approved_amount, bool $amount_is_invoice = false): ?array
    {
        global $wpdb;
        $customers = Biawin_CRM_DB::table('customers');
        $customer = $wpdb->get_row($wpdb->prepare("SELECT declared_paid,total_paid,journey_state FROM $customers WHERE id=%d", (int)$case->customer_id));
        if (!$customer) return null;
        $declared = $customer->declared_paid === null ? (float)$case->reported_amount : (float)$customer->declared_paid;
        if ($declared <= 0) return null;
        if ($amount_is_invoice) {
            $reconciled = (float)$customer->total_paid; // the approved invoice is already part of the invoice total
        } else {
            $registered = max((float)$customer->total_paid, (float)$case->registered_invoice_amount);
            $reconciled = $registered + max(0, $finance_approved_amount);
        }
        $registered = (float)$customer->total_paid;
        $tolerance = Biawin_CRM_Workflow_Service::completeness_tolerance();
        if (abs($declared - $reconciled) > $tolerance + 0.004) return null;

        $updated = $wpdb->update($customers, [
            'declared_paid'=>$declared,
            'profile_completeness'=>'complete',
            'completion_source'=>'FINANCE_WORKFLOW',
            'status'=>'verified',
            'discrepancy_amount'=>0,
            'updated_at'=>Biawin_CRM_DB::now(),
        ], ['id'=>(int)$case->customer_id]);
        if ($updated === false) return ['ok'=>false,'message'=>'تکمیل خودکار پروفایل بعد از تأیید مالی ذخیره نشد.'];

        self::tag_customer((int)$case->customer_id, 'DEFICIENCY_RESOLVED', (int)$case->id);
        self::tag_customer((int)$case->customer_id, 'COMPLETED_AFTER_DEFICIENCY', (int)$case->id);
        self::tag_customer((int)$case->customer_id, 'FINANCE_RECONCILED', (int)$case->id);
        $result = self::update_status((int)$case->id, (int)$case->customer_id, 'deficiency', 'CASE_AUTO_COMPLETED_AFTER_FINANCE', (string)$case->status, 'COMPLETED', [
            'current_department'=>'deficiency',
            'final_decision'=>'auto_finance_reconciled',
            'final_approved_amount'=>$reconciled,
            'completed_at'=>Biawin_CRM_DB::now(),
            'updated_at'=>Biawin_CRM_DB::now(),
        ], [
            'declared_paid'=>$declared,
            'registered_invoice_amount'=>$registered,
            'finance_approved_amount'=>$finance_approved_amount,
            'reconciled_amount'=>$reconciled,
            'journey_state'=>(string)$customer->journey_state,
        ]);
        if (!empty($result['ok'])) self::after_completion((int)$case->customer_id, (int)$case->id, 'auto_finance_reconciled'); // v0.8.66.0
        return $result;
    }

    private static function get_case(int $case_id): ?object
    {
        global $wpdb;
        $case = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=%d', $case_id));
        return $case ?: null;
    }

    private static function final_decision(object $case, string $decision, string $description, array $raw = []): array
    {
        global $wpdb;
        if (!current_user_can('complete_deficiency_case') && !current_user_can('archive_deficiency_case') && !current_user_can('manage_deficiency_cases')) return ['ok'=>false,'message'=>'دسترسی تصمیم نهایی ندارید.'];
        if (!in_array((string)$case->status, ['READY_FOR_FINAL_DECISION','RETURNED_TO_DEFICIENCY'], true)) return ['ok'=>false,'message'=>'پرونده هنوز آماده تصمیم نهایی نیست.'];
        $to = $decision === 'complete' ? 'COMPLETED' : 'ARCHIVED';
        $data = ['final_decision'=>$decision,'updated_at'=>Biawin_CRM_DB::now()];
        $meta = ['description'=>$description,'decision'=>$decision];
        if ($decision === 'complete') {
            $final_approved_amount = isset($raw['final_approved_amount']) && trim((string)$raw['final_approved_amount']) !== ''
                ? Biawin_CRM_Normalizer::money($raw['final_approved_amount'])
                : 0;
            if ($final_approved_amount > 0) {
                $data['final_approved_amount'] = $final_approved_amount;
                $meta['final_approved_amount'] = $final_approved_amount;
            }
            $data['completed_at'] = Biawin_CRM_DB::now();
            $wpdb->update(Biawin_CRM_DB::table('customers'), [
                'profile_completeness'=>'complete',
                'completion_source'=>'DEFICIENCY_WORKFLOW',
                'status'=>'verified',
                'updated_at'=>Biawin_CRM_DB::now(),
            ], ['id'=>(int)$case->customer_id]);
            self::tag_customer((int)$case->customer_id, 'DEFICIENCY_RESOLVED', (int)$case->id);
            self::tag_customer((int)$case->customer_id, 'COMPLETED_AFTER_DEFICIENCY', (int)$case->id);
        } else {
            $data['archived_at'] = Biawin_CRM_DB::now();
            self::tag_customer((int)$case->customer_id, 'ARCHIVED_AFTER_DEFICIENCY', (int)$case->id);
        }
        $result = self::update_status((int)$case->id, (int)$case->customer_id, 'deficiency', $decision === 'complete' ? 'CASE_COMPLETED' : 'CASE_ARCHIVED', (string)$case->status, $to, $data, $meta);
        if (!empty($result['ok']) && $decision === 'complete') self::after_completion((int)$case->customer_id, (int)$case->id, 'final_decision_complete'); // v0.8.66.0
        return $result;
    }

    /**
     * v0.8.66.0 — single hand-off from every deficiency completion path to the core journey: same customer,
     * tag reuse, end of the previous contact assignment (existing «returned»), reallocation marker.
     * Finance Verification stays optional: the hand-off is identical with or without the finance department.
     */
    private static function after_completion(int $customer_id, int $case_id, string $source): void
    {
        if (class_exists('Biawin_CRM_Workflow_Service')) Biawin_CRM_Workflow_Service::on_deficiency_completed($customer_id, $case_id, $source);
    }

    private static function update_status(int $case_id, int $customer_id, string $department, string $event, string $from, string $to, array $data, array $meta): array
    {
        global $wpdb;
        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $data['status'] = $to;
        $wpdb->query('START TRANSACTION');
        try {
            $locked = $wpdb->get_row($wpdb->prepare("SELECT status FROM $cases WHERE id=%d FOR UPDATE", $case_id));
            if (!$locked) throw new RuntimeException('پرونده پیدا نشد.');
            if ((string)$locked->status !== $from) throw new RuntimeException('وضعیت پرونده همزمان تغییر کرده است؛ صفحه را تازه‌سازی کنید.');
            $ok = $wpdb->update($cases, $data, ['id'=>$case_id, 'status'=>$from]);
            if ($ok === false) throw new RuntimeException('ذخیره وضعیت انجام نشد.');
            self::action($case_id, $customer_id, $department, $event, $from, $to, $meta);
            $wpdb->query('COMMIT');
            return ['ok'=>true,'message'=>'عملیات با موفقیت ثبت شد.'];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return ['ok'=>false,'message'=>$e->getMessage() ?: 'عملیات انجام نشد.'];
        }
    }

    public static function action(int $case_id, int $customer_id, string $department, string $type, ?string $from, ?string $to, array $meta = []): void
    {
        global $wpdb;
        $now = Biawin_CRM_DB::now();
        $wpdb->insert(Biawin_CRM_DB::table('case_actions'), [
            'case_id'=>$case_id,
            'customer_id'=>$customer_id,
            'department'=>sanitize_key($department),
            'action_type'=>sanitize_text_field($type),
            'from_status'=>$from,
            'to_status'=>$to,
            'user_id'=>get_current_user_id() ?: null,
            'description'=>isset($meta['description']) ? sanitize_textarea_field((string)$meta['description']) : null,
            'metadata_json'=>$meta ? wp_json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'created_at'=>$now,
        ]);
        Biawin_CRM_Workflow_Service::activity($customer_id, $type, self::event_label($type), 'deficiency_case', $case_id, ['department'=>$department,'from_status'=>$from,'to_status'=>$to] + $meta);
        Biawin_CRM_DB::log('deficiency_case', $case_id, $type, ['from_status'=>$from], ['to_status'=>$to,'metadata'=>$meta]);
    }

    public static function tag_customer(int $customer_id, string $tag, ?int $case_id = null): void
    {
        global $wpdb;
        $table = Biawin_CRM_DB::table('customer_tags');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (customer_id,tag,case_id,created_by,created_at) VALUES (%d,%s,%d,%d,%s) ON DUPLICATE KEY UPDATE case_id=VALUES(case_id)",
            $customer_id, sanitize_text_field($tag), $case_id ?: 0, get_current_user_id() ?: 0, Biawin_CRM_DB::now()
        ));
    }

    /** v0.8.62.12: «هر کارشناس چند پرونده در هر وضعیت دارد» — گزارش وضعیت تخصیص هر کارشناس. */
    public static function agent_assignment_report(string $department = 'deficiency'): array
    {
        global $wpdb;
        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $agent_col = $department === 'finance' ? 'finance_agent_id' : 'deficiency_agent_id';
        $rows = $wpdb->get_results("SELECT $agent_col AS agent_id, status, COUNT(*) AS total FROM $cases WHERE $agent_col IS NOT NULL GROUP BY $agent_col, status ORDER BY total DESC");
        $report = [];
        foreach ((array)$rows as $r) {
            $aid = (int)$r->agent_id;
            if (!isset($report[$aid])) {
                $u = get_user_by('id', $aid);
                $report[$aid] = [
                    'agent_id' => $aid,
                    'display_name' => $u ? $u->display_name : ('کاربر #' . $aid),
                    'user_login' => $u ? $u->user_login : '',
                    'total' => 0,
                    'statuses' => [],
                ];
            }
            $report[$aid]['statuses'][(string)$r->status] = (int)$r->total;
            $report[$aid]['total'] += (int)$r->total;
        }
        usort($report, fn($a, $b) => $b['total'] <=> $a['total']);
        return $report;
    }

    /** v0.8.62.12: شمارش صف پرونده‌های قابل‌برگشت (وضعیت‌های انتخابی، بدون جداسازی کارشناس) برای سرصفحه برگشت. */
    public static function returnable_queue_count(string $department = 'deficiency', array $statuses = []): int
    {
        global $wpdb;
        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $agent_col = $department === 'finance' ? 'finance_agent_id' : 'deficiency_agent_id';
        $allowed = $department === 'finance'
            ? ['FINANCE_ASSIGNED']
            : ['INCOMPLETE_ASSIGNED'];
        $wanted = array_values(array_intersect($allowed, array_map('strval', $statuses)));
        if (!$wanted) $wanted = $allowed;
        $in = implode(',', array_fill(0, count($wanted), '%s'));
        $sql = "SELECT COUNT(*) FROM $cases WHERE $agent_col IS NOT NULL AND status IN ($in)";
        return (int)$wpdb->get_var($wpdb->prepare($sql, ...$wanted));
    }

    /** v0.8.62.12: صف پرونده‌های تخصیص‌یافته برای برگشت — فیلتر کارشناس + وضعیت‌ها + صفحه‌بندی. */
    public static function returnable_queue_rows(string $department = 'deficiency', int $agent_id = 0, array $statuses = [], int $per_page = 20, int $page = 1): array
    {
        global $wpdb;
        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $customers = Biawin_CRM_DB::table('customers');
        $agent_col = $department === 'finance' ? 'finance_agent_id' : 'deficiency_agent_id';
        $allowed = $department === 'finance' ? ['FINANCE_ASSIGNED'] : ['INCOMPLETE_ASSIGNED'];
        $wanted = array_values(array_intersect($allowed, array_map('strval', $statuses)));
        if (!$wanted) $wanted = $allowed;
        $where = ["d.$agent_col IS NOT NULL", 'd.status IN (' . implode(',', array_fill(0, count($wanted), '%s')) . ')'];
        $args = $wanted;
        if ($agent_id > 0) { $where[] = "d.$agent_col=%d"; $args[] = $agent_id; }
        $where_sql = implode(' AND ', $where);
        $per_page = max(1, min(200, $per_page));
        $page = max(1, $page);
        $base = "FROM $cases d LEFT JOIN $customers c ON c.id=d.customer_id WHERE $where_sql";
        $total = (int)$wpdb->get_var('SELECT COUNT(*) ' . ($args ? $wpdb->prepare($base, ...$args) : $base));
        $sql = "SELECT d.*,c.display_name,c.customer_code,c.source_customer_id,c.sales_manager " . $base . " ORDER BY d.updated_at DESC,d.id DESC LIMIT %d OFFSET %d";
        $args[] = $per_page;
        $args[] = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args));
        return ['rows' => (array)$rows, 'total' => $total, 'per_page' => $per_page, 'page' => $page, 'pages' => (int)ceil($total / $per_page)];
    }

    /** v0.8.62.12: ثابت‌های وضعیت‌های قابل‌برگشت برای UI (برچسب فارسی + کد). */
    public static function returnable_status_options(string $department = 'deficiency'): array
    {
        $labels = self::status_labels();
        $keys = $department === 'finance' ? ['FINANCE_ASSIGNED'] : ['INCOMPLETE_ASSIGNED'];
        $out = [];
        foreach ($keys as $k) $out[$k] = $labels[$k] ?? $k;
        return $out;
    }

    public static function render_admin_page(string $mode = 'deficiency'): void
    {
        global $wpdb;
        $is_finance = $mode === 'finance';
        $cap = $is_finance ? 'view_finance_cases' : 'view_deficiency_cases';
        if (!current_user_can($cap) && !current_user_can('bcrm_manage_cases')) wp_die('دسترسی غیرمجاز است.');
        self::maybe_notice();
        $tab = sanitize_key($_GET['tab'] ?? 'dashboard');
        $case_id = absint($_GET['case_id'] ?? 0);
        echo '<div class="wrap bcrm bcrm-workflow-page" dir="rtl"><h1>' . esc_html($is_finance ? 'Workflow تأیید مالی' : 'Workflow رفع ناقصی') . '</h1>';
        if ($case_id) {
            self::render_case_detail($case_id, $is_finance);
            echo '</div>';
            return;
        }
        self::render_tabs($tab, $is_finance);
        if ($tab === 'dashboard') self::render_dashboard($is_finance);
        elseif ($tab === 'assign') self::render_assignment_panel($is_finance);
        elseif ($tab === 'return') self::render_return_panel($is_finance);
        elseif ($tab === 'reports') self::render_reports($is_finance);
        else self::render_case_table($tab, $is_finance);
        echo '</div>';
    }

    private static function render_tabs(string $active, bool $finance): void
    {
        $base = admin_url('admin.php?page=' . ($finance ? 'biawin-crm-financial' : 'biawin-crm-deficiency'));
        $tabs = $finance ? [
            'dashboard'=>'داشبورد','new'=>'جدید','assigned'=>'تخصیص‌یافته','review'=>'در حال بررسی','approved'=>'تأییدشده','rejected'=>'عدم تأیید','returned'=>'برگشت داده‌شده','assign'=>'تخصیص','return'=>'برگشت تخصیص','reports'=>'گزارش',
        ] : [
            'dashboard'=>'داشبورد','new'=>'جدید','assigned'=>'تخصیص‌یافته','review'=>'در حال بررسی','contact'=>'منتظر مشتری','finance'=>'ارسال‌شده به مالی','returned'=>'برگشتی از مالی','decision'=>'آماده تصمیم نهایی','completed'=>'تکمیل‌شده','archived'=>'بایگانی','assign'=>'تخصیص','return'=>'برگشت تخصیص','reports'=>'گزارش',
        ];
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key=>$label) echo '<a class="nav-tab ' . ($active === $key ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('tab', $key, $base)) . '">' . esc_html($label) . '</a>';
        echo '</nav>';
    }

    private static function render_dashboard(bool $finance): void
    {
        global $wpdb;
        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $today = gmdate('Y-m-d 00:00:00', current_time('timestamp'));
        $stats = $finance ? [
            ['label'=>'در صف مالی','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE current_department='finance' AND status='FINANCE_REVIEW_PENDING'"),'help'=>'پرونده‌هایی که از رفع ناقصی به مالی ارسال شده‌اند و هنوز به کارشناس مالی تخصیص ندارند.'],
            ['label'=>'تخصیص مالی','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE current_department='finance' AND status='FINANCE_ASSIGNED'"),'help'=>'پرونده‌های مالی تخصیص‌داده‌شده که هنوز بررسی مالی روی آن‌ها شروع نشده است.'],
            ['label'=>'در حال بررسی مالی','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE current_department='finance' AND status='FINANCE_UNDER_REVIEW'"),'help'=>'پرونده‌هایی که کارشناس مالی در حال بررسی فیش یا مدارک آن‌هاست.'],
            ['label'=>'تایید مالی','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE finance_result IN ('with_invoice','without_invoice')"),'help'=>'پرونده‌هایی که نتیجه مالی آن‌ها تایید با فاکتور یا بدون فاکتور ثبت شده است.'],
            ['label'=>'عدم تایید مالی','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE finance_result='rejected'"),'help'=>'پرونده‌هایی که فیش یا ادعای پرداخت آن‌ها در مالی رد شده است.'],
            ['label'=>'برگشت به رفع ناقصی','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE status='RETURNED_TO_DEFICIENCY'"),'help'=>'پرونده‌هایی که پس از بررسی مالی دوباره به واحد رفع ناقصی برگشته‌اند.'],
        ] : [
            ['label'=>'ناقص جدید','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE current_department='deficiency' AND status='INCOMPLETE_NEW'"),'help'=>'پرونده‌های ناقص آماده تخصیص که هنوز به کارشناس رفع ناقصی داده نشده‌اند.'],
            ['label'=>'تخصیص یافته','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE current_department='deficiency' AND status='INCOMPLETE_ASSIGNED'"),'help'=>'پرونده‌هایی که به کارشناس رفع ناقصی تخصیص داده شده‌اند و هنوز وارد بررسی نشده‌اند.'],
            ['label'=>'در حال بررسی','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE current_department='deficiency' AND status='DEFICIENCY_UNDER_REVIEW'"),'help'=>'پرونده‌هایی که کارشناس رفع ناقصی نتیجه تماس یا بررسی اولیه را روی آن‌ها ثبت کرده است.'],
            ['label'=>'منتظر مشتری','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE current_department='deficiency' AND status='CUSTOMER_CONTACT_PENDING'"),'help'=>'پرونده‌هایی که برای تماس یا پیگیری دوباره مشتری منتظر مانده‌اند.'],
            ['label'=>'ارسال شده به مالی','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE current_department='finance' AND status IN ('FINANCE_REVIEW_PENDING','FINANCE_ASSIGNED','FINANCE_UNDER_REVIEW')"),'help'=>'پرونده‌هایی که از رفع ناقصی برای تایید مالی ارسال شده‌اند؛ ممکن است همراه فیش یا با وضعیت عودت شده باشند.'],
            ['label'=>'برگشتی از مالی','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE current_department='deficiency' AND status='RETURNED_TO_DEFICIENCY'"),'help'=>'پرونده‌هایی که مالی آن‌ها را بررسی کرده و برای ادامه کار به رفع ناقصی برگردانده است.'],
            ['label'=>'آماده تصمیم نهایی','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE current_department='deficiency' AND status='READY_FOR_FINAL_DECISION'"),'help'=>'پرونده‌هایی که بررسی رفع ناقصی آن‌ها کامل شده و فقط تصمیم نهایی مانده است.'],
            ['label'=>'تکمیل شده امروز','value'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $cases WHERE status='COMPLETED' AND completed_at >= %s", $today)),'help'=>'پرونده‌هایی که امروز تکمیل و بسته شده‌اند.'],
            ['label'=>'بایگانی شده','value'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE status='ARCHIVED'"),'help'=>'پرونده‌هایی که با تصمیم نهایی بایگانی شده‌اند.'],
        ];
        echo '<div class="bcrm-grid">';
        foreach ($stats as $stat) echo '<div class="bcrm-card"><strong>' . number_format_i18n((int)$stat['value']) . '</strong><span class="bcrm-kpi-label">' . esc_html($stat['label']) . '<button type="button" class="bcrm-kpi-help" title="' . esc_attr($stat['help']) . '" aria-label="' . esc_attr($stat['help']) . '">?</button></span></div>';
        echo '</div>';
        $agent_col = $finance ? 'finance_agent_id' : 'deficiency_agent_id';
        $rows = $wpdb->get_results("SELECT $agent_col agent_id, COUNT(*) total FROM $cases WHERE $agent_col IS NOT NULL GROUP BY $agent_col ORDER BY total DESC LIMIT 20");
        echo '<h2>تعداد پرونده هر کارشناس</h2><table class="widefat striped"><thead><tr><th>کارشناس</th><th>تعداد</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="2">هنوز تخصیصی ثبت نشده است.</td></tr>';
        foreach ($rows as $r) { $u = get_user_by('id', (int)$r->agent_id); echo '<tr><td>' . esc_html($u ? $u->display_name : ('User #' . (int)$r->agent_id)) . '</td><td>' . number_format_i18n((int)$r->total) . '</td></tr>'; }
        echo '</tbody></table>';
    }

    private static function render_case_table(string $tab, bool $finance, int $agent_filter = 0): void
    {
        global $wpdb;
        [$where, $args] = self::tab_where($tab, $finance, 'd');
        $agent_filter = absint($agent_filter);
        if ($agent_filter) {
            $where[] = ($finance ? 'd.finance_agent_id' : 'd.deficiency_agent_id') . '=%d';
            $args[] = $agent_filter;
        }
        if (!current_user_can('bcrm_manage_cases')) {
            $uid = get_current_user_id();
            $roles = (array)wp_get_current_user()->roles;
            if ($finance && !in_array('bcrm_financial_supervisor', $roles, true)) { $where[] = 'd.finance_agent_id=%d'; $args[] = $uid; }
            if (!$finance && !in_array('bcrm_deficiency_supervisor', $roles, true)) { $where[] = 'd.deficiency_agent_id=%d'; $args[] = $uid; }
        }
        $search = sanitize_text_field(wp_unslash($_GET['bcrm_s'] ?? ($_GET['s'] ?? '')));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(d.case_code LIKE %s OR c.display_name LIKE %s OR c.customer_code LIKE %s OR c.source_customer_id LIKE %s OR c.sales_manager LIKE %s OR EXISTS(SELECT 1 FROM " . Biawin_CRM_DB::table('phones') . " p WHERE p.customer_id=c.id AND p.normalized_phone LIKE %s) OR EXISTS(SELECT 1 FROM " . Biawin_CRM_DB::table('invoices') . " i WHERE i.customer_id=c.id AND i.invoice_code LIKE %s))";
            array_push($args, $like, $like, $like, $like, $like, $like, $like);
        }
        // v0.8.62.12: صفحه‌بندی واقعی + انتخاب تعداد ردیف در هر صفحه (10/20/50/100/200).
        $per_page = (int)($_GET['bcrm_pp'] ?? 20);
        $per_page = in_array($per_page, [10, 20, 50, 100, 200], true) ? $per_page : 20;
        $paged = max(1, (int)($_GET['bcrm_paged'] ?? 1));
        $base_from = ' FROM ' . Biawin_CRM_DB::table('deficiency_cases') . " d LEFT JOIN " . Biawin_CRM_DB::table('customers') . " c ON c.id=d.customer_id WHERE " . implode(' AND ', $where);
        $count_sql = 'SELECT COUNT(*)' . $base_from;
        if ($args) $count_sql = $wpdb->prepare($count_sql, ...$args);
        $total = (int)$wpdb->get_var($count_sql);
        $pages = max(1, (int)ceil($total / $per_page));
        $paged = min($paged, $pages);
        $offset = ($paged - 1) * $per_page;
        $sql = "SELECT d.*,c.display_name,c.customer_code,c.source_customer_id,c.sales_manager,(SELECT normalized_phone FROM " . Biawin_CRM_DB::table('phones') . " p WHERE p.customer_id=d.customer_id ORDER BY p.is_primary DESC,p.id LIMIT 1) phone" . $base_from . " ORDER BY d.updated_at DESC,d.id DESC LIMIT %d OFFSET %d";
        $args[] = $per_page;
        $args[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args));
        $page_slug = $finance ? 'biawin-crm-financial' : 'biawin-crm-deficiency';
        $pp_form = '<form method="get" style="display:inline"><input type="hidden" name="page" value="' . esc_attr($page_slug) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '"><input type="hidden" name="bcrm_s" value="' . esc_attr($search) . '">' . ($agent_filter ? '<input type="hidden" name="return_agent_id" value="' . $agent_filter . '">' : '') . '<label>نمایش <select name="bcrm_pp" onchange="this.form.submit()">';
        foreach ([10, 20, 50, 100, 200] as $n) $pp_form .= '<option value="' . $n . '"' . selected($per_page, $n, false) . '>' . number_format_i18n($n) . '</option>';
        $pp_form .= '</select> در صفحه</label> <button class="button">نمایش</button></form>';
        echo '<div class="bcrm-table-toolbar" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:12px 0"><form method="get" style="display:inline"><input type="hidden" name="page" value="' . esc_attr($page_slug) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">' . ($agent_filter ? '<input type="hidden" name="return_agent_id" value="' . $agent_filter . '">' : '') . '<input name="bcrm_s" value="' . esc_attr($search) . '" placeholder="جستجو: Case ID، Customer ID، موبایل، نام، فاکتور، مدیر فروش"> <button class="button">جستجو</button></form>' . $pp_form . '<span class="description">' . number_format_i18n($total) . ' پرونده — صفحه ' . number_format_i18n($paged) . ' از ' . number_format_i18n($pages) . '</span></div>';
        $table_uid = 'bcrm-ck-' . md5($tab . '|' . $agent_filter . '|' . $search);
        echo '<table class="widefat striped"><thead><tr><td class="check-column" style="padding:8px"><input type="checkbox" id="' . esc_attr($table_uid) . '" aria-label="انتخاب همه در این صفحه" onclick="var boxes=document.querySelectorAll(\'input[data-bcrm-group=' . esc_js($table_uid) . ']\');for(var i=0;i<boxes.length;i++)boxes[i].checked=this.checked;"></td><th>Case</th><th>مشتری</th><th>موبایل</th><th>مدیر فروش</th><th>مبلغ اعلامی</th><th>مجموع فاکتور</th><th>اختلاف</th><th>وضعیت</th><th>اقدام</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="10">پرونده‌ای برای بررسی وجود ندارد.</td></tr>';
        foreach ($rows as $r) {
            $url = add_query_arg(['page'=>$finance?'biawin-crm-financial':'biawin-crm-deficiency','case_id'=>(int)$r->id], admin_url('admin.php'));
            echo '<tr><td><input form="bcrm-case-assign-form" type="checkbox" data-bcrm-group="' . esc_attr($table_uid) . '" name="case_ids[]" value="' . (int)$r->id . '"></td><td>' . esc_html($r->case_code) . '</td><td>' . esc_html($r->display_name ?: '-') . '<br><small>' . esc_html($r->source_customer_id ?: $r->customer_code ?: '-') . '</small></td><td>' . esc_html($r->phone ?: '-') . '</td><td>' . esc_html($r->sales_manager ?: '-') . '</td><td>' . self::money($r->reported_amount) . '</td><td>' . self::money($r->registered_invoice_amount) . '</td><td><strong class="bcrm-diff-amount">' . self::money(abs((float)$r->difference_amount)) . '</strong></td><td>' . esc_html(self::status_labels()[$r->status] ?? $r->status) . '</td><td><a class="button" href="' . esc_url($url) . '">بررسی</a>' . ($finance ? ' <button type="button" class="button button-primary" data-bcrm-fin-review data-case-id="' . (int)$r->id . '">فیش‌ها و تأیید/رد</button>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        // v0.8.62.12: ناوبری صفحه‌بندی.
        if ($pages > 1) {
            echo '<div class="tablenav" style="margin:8px 0"><div class="tablenav-pages">';
            if ($paged > 1) echo '<a class="button" href="' . esc_url(add_query_arg(['bcrm_paged' => $paged - 1, 'bcrm_pp' => $per_page])) . '">&laquo; قبلی</a> ';
            echo '<span class="displaying-num">صفحه ' . number_format_i18n($paged) . ' از ' . number_format_i18n($pages) . '</span>';
            if ($paged < $pages) echo ' <a class="button" href="' . esc_url(add_query_arg(['bcrm_paged' => $paged + 1, 'bcrm_pp' => $per_page])) . '">بعدی &raquo;</a>';
            echo '</div></div>';
        }
    }

    private static function render_assignment_panel(bool $finance): void
    {
        $roles = $finance ? ['bcrm_financial_agent'] : ['bcrm_deficiency_agent'];
        $agents = array_values(array_filter(get_users(['role__in'=>$roles, 'orderby'=>'display_name']), fn($u)=>!(bool)get_user_meta($u->ID,'bcrm_disabled',true)));
        $return_agent_id = absint($_GET['return_agent_id'] ?? 0);
        echo '<div class="bcrm-card"><h2>' . esc_html($finance ? 'تخصیص پرونده‌های مالی' : 'تخصیص پرونده‌های رفع ناقصی') . '</h2><form id="bcrm-case-assign-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bcrm_deficiency_assign');
        echo '<input type="hidden" name="action" value="bcrm_deficiency_assign"><input type="hidden" name="department" value="' . esc_attr($finance ? 'finance' : 'deficiency') . '"><input type="hidden" name="return_agent_id" value="' . (int)$return_agent_id . '"><label>کارشناسان برای تخصیص<br><select name="agent_user_ids[]" multiple size="6">';
        foreach ($agents as $u) echo '<option value="' . (int)$u->ID . '">' . esc_html($u->display_name . ' (' . $u->user_login . ')') . '</option>';
        echo '</select></label> <label>تعداد از صف<br><input type="number" name="limit" min="1" max="10000" value="100"></label> <button class="button button-primary">اجرای تخصیص / توزیع مساوی</button><button class="button" name="return_assignment" value="1" formnovalidate onclick="return confirm(\'تخصیص پرونده‌های انتخاب‌شده برای همین کارشناس برگشت داده شود؟\')">برگشت تخصیص انتخاب‌شده‌ها</button><p class="description">برای برگشت تخصیص بهتر است از تب مستقل «برگشت تخصیص» استفاده کنید؛ این دکمه فقط انتخاب‌های همین صفحه را برمی‌گرداند.</p></form></div>';
        // v0.8.62.15: آپلود فایل اکسل Customer ID ها و تخصیص همه پرونده‌های باز آن‌ها به «یک» کارشناس واحد.
        echo '<div class="bcrm-card" style="margin-top:16px"><h2>تخصیص از فایل اکسل (Customer ID)</h2><p class="description">فایل اکسل/CSV شامل ستون <strong>CustomerID</strong> را آپلود کنید؛ همه پرونده‌های بازِ هر مشتری (به‌جز تکمیل‌شده/بایگانی‌شده) به کارشناس انتخابی تخصیص می‌یابد. شناسه‌های تکراری فقط یک‌بار پردازش می‌شوند. فرمت‌های مجاز: XLSX و CSV.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'پرونده‌های بازِ همه Customer ID های فایل به کارشناس انتخابی تخصیص داده شود؟\')">';
        wp_nonce_field('bcrm_deficiency_assign_upload');
        echo '<input type="hidden" name="action" value="bcrm_deficiency_assign_upload"><input type="hidden" name="department" value="' . esc_attr($finance ? 'finance' : 'deficiency') . '">';
        echo '<label>فایل اکسل Customer ID ها<br><input type="file" name="assign_customer_file" accept=".xlsx,.csv" required></label> ';
        echo '<label>کارشناس مقصد<br><select name="agent_user_id" required><option value="">انتخاب کارشناس</option>';
        foreach ($agents as $u) echo '<option value="' . (int)$u->ID . '">' . esc_html($u->display_name . ' (' . $u->user_login . ')') . '</option>';
        echo '</select></label> <button class="button button-primary button-hero">تخصیص از فایل اکسل</button>';
        echo '</form></div>';
        echo '<h2>' . esc_html($finance ? 'صف آماده تخصیص مالی' : 'صف آماده تخصیص رفع ناقصی') . '</h2>';
        self::render_case_table('new', $finance);
        // v0.8.62.12: گزارش وضعیت دقیق هر کارشناس — چه تعداد از هر وضعیت دارد.
        echo '<h2>' . esc_html($finance ? 'وضعیت تخصیص هر کارشناس مالی' : 'وضعیت تخصیص هر کارشناس رفع ناقصی') . '</h2>';
        $report = self::agent_assignment_report($finance ? 'finance' : 'deficiency');
        $labels = self::status_labels();
        $cols = $finance
            ? ['FINANCE_ASSIGNED' => 'تخصیص مالی', 'FINANCE_UNDER_REVIEW' => 'در حال بررسی', 'FINANCE_APPROVED_WITH_INVOICE' => 'تایید با فاکتور', 'FINANCE_APPROVED_WITHOUT_INVOICE' => 'تایید بدون فاکتور', 'FINANCE_REJECTED' => 'عدم تایید']
            : ['INCOMPLETE_ASSIGNED' => 'تخصیص‌یافته', 'DEFICIENCY_UNDER_REVIEW' => 'در حال بررسی', 'CUSTOMER_CONTACT_PENDING' => 'منتظر مشتری', 'FINANCE_REVIEW_PENDING' => 'ارسال به مالی', 'RETURNED_TO_DEFICIENCY' => 'برگشتی از مالی', 'READY_FOR_FINAL_DECISION' => 'آماده تصمیم نهایی'];
        echo '<table class="widefat striped"><thead><tr><th>کارشناس</th><th>جمع کل</th>';
        foreach ($cols as $lb) echo '<th>' . esc_html($lb) . '</th>';
        echo '</tr></thead><tbody>';
        if (!$report) echo '<tr><td colspan="' . (count($cols) + 2) . '">هنوز تخصیصی ثبت نشده است.</td></tr>';
        foreach ($report as $row) {
            echo '<tr><td><strong>' . esc_html($row['display_name']) . '</strong>' . ($row['user_login'] ? ' <small>(' . esc_html($row['user_login']) . ')</small>' : '') . '</td><td><strong>' . number_format_i18n($row['total']) . '</strong></td>';
            foreach ($cols as $code => $lb) {
                $n = (int)($row['statuses'][$code] ?? 0);
                echo '<td>' . ($n ? '<a href="' . esc_url(add_query_arg(['tab' => 'return', 'return_agent_id' => $row['agent_id'], 'return_status[]' => $code])) . '">' . number_format_i18n($n) . '</a>' : '<span style="opacity:.4">0</span>') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table><p class="description">روی هر عدد کلیک کنید تا همان پرونده‌ها در تب «برگشت تخصیص» باز شود.</p>';
        echo '<p><a class="button button-secondary" href="' . esc_url(add_query_arg('tab', 'return')) . '">رفتن به تب «برگشت تخصیص» &larr;</a></p>';
    }

    /**
     * v0.8.62.12: تب مستقل «برگشت تخصیص» — انتخاب کارشناس + فیلتر وضعیت + صفحه‌بندی + انتخاب‌همه.
     * سرپرست می‌تواند تعیین کند کدام وضعیت‌ها برگردند؛ صف با COUNT واقعی صفحه‌بندی می‌شود.
     */
    private static function render_return_panel(bool $finance): void
    {
        $roles = $finance ? ['bcrm_financial_agent'] : ['bcrm_deficiency_agent'];
        $agents = array_values(array_filter(get_users(['role__in'=>$roles, 'orderby'=>'display_name']), fn($u)=>!(bool)get_user_meta($u->ID,'bcrm_disabled',true)));
        $return_agent_id = absint($_GET['return_agent_id'] ?? 0);
        $status_filter = isset($_GET['return_status']) && is_array($_GET['return_status']) ? array_map('sanitize_key', (array)$_GET['return_status']) : [];
        $status_filter = array_values(array_filter($status_filter));
        $per_page = (int)($_GET['bcrm_pp'] ?? 20);
        $per_page = in_array($per_page, [10, 20, 50, 100, 200], true) ? $per_page : 20;
        $paged = max(1, (int)($_GET['bcrm_paged'] ?? 1));
        $page_slug = $finance ? 'biawin-crm-financial' : 'biawin-crm-deficiency';
        echo '<div class="bcrm-card"><h2>برگشت تخصیص' . ($finance ? ' مالی' : ' رفع ناقصی') . '</h2>';
        echo '<form method="get" style="margin:8px 0;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap"><input type="hidden" name="page" value="' . esc_attr($page_slug) . '"><input type="hidden" name="tab" value="return">';
        echo '<label>کارشناس<br><select name="return_agent_id"><option value="0">همه کارشناسان</option>';
        foreach ($agents as $u) echo '<option value="' . (int)$u->ID . '"' . selected($return_agent_id, (int)$u->ID, false) . '>' . esc_html($u->display_name . ' (' . $u->user_login . ')') . '</option>';
        echo '</select></label> ';
        echo '<label>وضعیت‌های برگشت (چندتایی)<br><select name="return_status[]" multiple size="3">';
        foreach (self::returnable_status_options($finance ? 'finance' : 'deficiency') as $code => $label) echo '<option value="' . esc_attr($code) . '"' . (in_array($code, $status_filter, true) ? ' selected' : '') . '>' . esc_html($label) . '</option>';
        echo '</select></label> ';
        echo '<label>نمایش<br><select name="bcrm_pp" onchange="this.form.submit()">';
        foreach ([10, 20, 50, 100, 200] as $n) echo '<option value="' . $n . '"' . selected($per_page, $n, false) . '>' . number_format_i18n($n) . '</option>';
        echo '</select></label> ';
        echo '<button class="button button-primary">نمایش</button></form>';
        echo '<p class="description">خالی بودن وضعیت‌ها = همه وضعیت‌های قابل‌برگشت. با Ctrl می‌توانید چند وضعیت را همزمان انتخاب کنید.</p></div>';
        $queue = self::returnable_queue_rows($finance ? 'finance' : 'deficiency', $return_agent_id, $status_filter, $per_page, $paged);
        $total = (int)$queue['total'];
        $pages = max(1, (int)$queue['pages']);
        $paged = min($paged, $pages);
        echo '<form id="bcrm-case-assign-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bcrm_deficiency_assign');
        echo '<input type="hidden" name="action" value="bcrm_deficiency_assign"><input type="hidden" name="department" value="' . esc_attr($finance ? 'finance' : 'deficiency') . '"><input type="hidden" name="return_agent_id" value="' . (int)$return_agent_id . '"><input type="hidden" name="return_assignment" value="1">';
        echo '<p><strong>' . number_format_i18n($total) . '</strong> پرونده تخصیص‌یافته مطابق فیلتر — صفحه ' . number_format_i18n($paged) . ' از ' . number_format_i18n($pages) . '.</p>';
        $table_uid = 'bcrm-ck-return';
        echo '<table class="widefat striped"><thead><tr><td class="check-column" style="padding:8px"><input type="checkbox" id="' . esc_attr($table_uid) . '" aria-label="انتخاب همه در این صفحه" onclick="var boxes=document.querySelectorAll(\'input[data-bcrm-group=' . esc_js($table_uid) . ']\');for(var i=0;i<boxes.length;i++)boxes[i].checked=this.checked;"></td><th>Case</th><th>مشتری</th><th>کارشناس</th><th>مبلغ اعلامی</th><th>مجموع فاکتور</th><th>اختلاف</th><th>وضعیت</th><th>اقدام</th></tr></thead><tbody>';
        if (!$queue['rows']) echo '<tr><td colspan="9">پرونده‌ای مطابق فیلتر پیدا نشد.</td></tr>';
        foreach ($queue['rows'] as $r) {
            $url = add_query_arg(['page'=>$page_slug,'case_id'=>(int)$r->id], admin_url('admin.php'));
            $au = (int)(($finance ? $r->finance_agent_id : $r->deficiency_agent_id) ?? 0);
            $au_user = $au ? get_user_by('id', $au) : null;
            $au_name = $au_user ? $au_user->display_name : '-';
            echo '<tr><td><input form="bcrm-case-assign-form" type="checkbox" data-bcrm-group="' . esc_attr($table_uid) . '" name="case_ids[]" value="' . (int)$r->id . '"></td><td>' . esc_html($r->case_code) . '</td><td>' . esc_html($r->display_name ?: '-') . '<br><small>' . esc_html($r->source_customer_id ?: $r->customer_code ?: '-') . '</small></td><td>' . esc_html($au_name) . '</td><td>' . self::money($r->reported_amount) . '</td><td>' . self::money($r->registered_invoice_amount) . '</td><td><strong class="bcrm-diff-amount">' . self::money(abs((float)$r->difference_amount)) . '</strong></td><td>' . esc_html(self::status_labels()[$r->status] ?? $r->status) . '</td><td><a class="button" href="' . esc_url($url) . '">بررسی</a>' . ($finance ? ' <button type="button" class="button button-primary" data-bcrm-fin-review data-case-id="' . (int)$r->id . '">فیش‌ها و تأیید/رد</button>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><button class="button button-primary button-hero" onclick="return confirm(\'پرونده‌های انتخاب‌شده به صف تخصیص‌نیافته برمی‌گردند؟\')">برگشت تخصیص انتخاب‌شده‌ها</button> <span class="description">فقط پرونده‌های همچنان در وضعیت تخصیص‌یافته برگردانده می‌شوند؛ پرونده‌های در جریان بررسی دست‌نخورده می‌مانند.</span></p></form>';
        if ($pages > 1) {
            echo '<div class="tablenav" style="margin:8px 0"><div class="tablenav-pages">';
            $nav_base = ['tab'=>'return','return_agent_id'=>$return_agent_id,'bcrm_pp'=>$per_page];
            if ($status_filter) { foreach ($status_filter as $sf) $nav_base['return_status[]'][] = $sf; }
            if ($paged > 1) echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($nav_base, ['bcrm_paged'=>$paged-1]), admin_url('admin.php?page=' . $page_slug))) . '">&laquo; قبلی</a> ';
            echo '<span class="displaying-num">صفحه ' . number_format_i18n($paged) . ' از ' . number_format_i18n($pages) . '</span>';
            if ($paged < $pages) echo ' <a class="button" href="' . esc_url(add_query_arg(array_merge($nav_base, ['bcrm_paged'=>$paged+1]), admin_url('admin.php?page=' . $page_slug))) . '">بعدی &raquo;</a>';
            echo '</div></div>';
        }
    }
    private static function render_case_detail(int $case_id, bool $finance): void
    {
        global $wpdb;
        $case = $wpdb->get_row($wpdb->prepare('SELECT d.*,c.display_name,c.customer_code,c.source_customer_id,c.sales_manager,c.total_paid,c.declared_paid,c.profile_completeness FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' d LEFT JOIN ' . Biawin_CRM_DB::table('customers') . ' c ON c.id=d.customer_id WHERE d.id=%d', $case_id));
        if (!$case || !self::can_access_case($case)) { echo '<div class="notice notice-error"><p>پرونده پیدا نشد یا دسترسی ندارید.</p></div>'; return; }
        // v0.8.62.24 — پنل تشخیص آپلود فیش: وضعیت نشست آپلود + سقف‌های سرور + آخرین رویدادهای ثبت‌شده،
        // تا «حتی یک فیش هم ثبت نمی‌شود» بدون حدس و از روی شواهد واقعی سرور قابل تشخیص باشد.
        self::render_upload_diagnostics($finance);
        $phones = $wpdb->get_col($wpdb->prepare('SELECT normalized_phone FROM ' . Biawin_CRM_DB::table('phones') . ' WHERE customer_id=%d ORDER BY is_primary DESC,id', (int)$case->customer_id));
        $invoices = $wpdb->get_results($wpdb->prepare('SELECT invoice_code,amount_paid,seller_action_year,sales_manager FROM ' . Biawin_CRM_DB::table('invoices') . ' WHERE customer_id=%d ORDER BY id DESC LIMIT 50', (int)$case->customer_id));
        $actions = $wpdb->get_results($wpdb->prepare('SELECT a.*,u.display_name FROM ' . Biawin_CRM_DB::table('case_actions') . ' a LEFT JOIN ' . $wpdb->users . ' u ON u.ID=a.user_id WHERE a.case_id=%d ORDER BY a.id DESC LIMIT 100', $case_id));
        $attachments = $wpdb->get_results($wpdb->prepare('SELECT at.*,p.post_title FROM ' . Biawin_CRM_DB::table('attachments') . ' at LEFT JOIN ' . $wpdb->posts . ' p ON p.ID=at.wp_attachment_id WHERE at.object_type=%s AND at.object_id=%d ORDER BY at.id DESC', 'deficiency_case', $case_id));
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=' . ($finance ? 'biawin-crm-financial' : 'biawin-crm-deficiency'))) . '">بازگشت</a>';
        echo '<section class="bcrm-card bcrm-case-detail"><h2>' . esc_html($case->case_code) . ' - ' . esc_html($case->display_name ?: 'پروفایل بدون نام') . '</h2><p>Customer ID: ' . esc_html($case->source_customer_id ?: $case->customer_code ?: (string)$case->customer_id) . ' | مدیر فروش: ' . esc_html($case->sales_manager ?: '-') . ' | موبایل‌ها: ' . esc_html($phones ? implode('، ', $phones) : '-') . '</p>';
        // v0.8.62.23 — پنل تایید مالی به سبک پلاگین فروش‌نت: فهرست فیش‌ها با مبلغِ هر فیش
        // (از یادداشت «مبلغ فیش: …» ردیف) + جمع کل + کارت مبلغ اعلامی و باقیمانده.
        // v0.8.62.24 — تحلیل به متد مشترک منتقل شد تا پنل تشخیص آپلود هم از همان اعداد استفاده کند.
        $receipts = self::analyze_case_receipts(is_array($attachments) ? $attachments : []);
        $remaining_amount = max(0, (float)$case->reported_amount - (float)$receipts['total']);
        echo '<div class="bcrm-grid"><div class="bcrm-card"><span>مبلغ اعلامی</span><strong>' . self::money($case->reported_amount) . '</strong></div><div class="bcrm-card"><span>مجموع فاکتورها</span><strong>' . self::money($case->registered_invoice_amount) . '</strong></div><div class="bcrm-card is-danger"><span>اختلاف</span><strong>' . self::money(abs((float)$case->difference_amount)) . '</strong></div><div class="bcrm-card"><span>وضعیت</span><strong>' . esc_html(self::status_labels()[$case->status] ?? $case->status) . '</strong></div></div></section>';
        echo '<h2>فاکتورها</h2><table class="widefat striped"><thead><tr><th>کد</th><th>مبلغ</th><th>سال</th><th>مدیر فروش</th></tr></thead><tbody>';
        if (!$invoices) echo '<tr><td colspan="4">فاکتوری ثبت نشده است.</td></tr>';
        foreach ($invoices as $i) echo '<tr><td>' . esc_html($i->invoice_code) . '</td><td>' . self::money($i->amount_paid) . '</td><td>' . esc_html((string)($i->seller_action_year ?: '-')) . '</td><td>' . esc_html($i->sales_manager ?: '-') . '</td></tr>';
        echo '</tbody></table>';
        echo '<h2>مدارک آپلودشده</h2><table class="widefat striped"><thead><tr><th>نوع</th><th>توضیح</th><th>فایل</th><th>زمان</th></tr></thead><tbody>';
        if (!$attachments) echo '<tr><td colspan="4">مدرکی برای این پرونده ثبت نشده است.</td></tr>';
        foreach ($attachments as $att) {
            $url = wp_get_attachment_url((int)$att->wp_attachment_id);
            echo '<tr><td>' . esc_html($att->file_kind ?: '-') . '</td><td>' . esc_html($att->note ?: '-') . '</td><td>' . ($url ? '<a target="_blank" rel="noopener" href="' . esc_url($url) . '">' . esc_html($att->post_title ?: 'مشاهده فایل') . '</a>' : '-') . '</td><td>' . esc_html($att->created_at) . '</td></tr>';
        }
        echo '</tbody></table>';
        // v0.8.62.23 — فهرست فیش‌های مالی به سبک پلاگین فروش‌نت: کارت جمع + ردیف هر فیش با مبلغش.
        if ($receipts['rows']) {
            // v0.8.62.28 — بررسی فیش به سبک فروش‌نت: پنجره نمایش فیش = محل تأیید/رد همان فیش و ثبت نتیجه مالی.
            $reviews = class_exists('Biawin_CRM_Finance_Review') ? Biawin_CRM_Finance_Review::reviews_for_case($case_id) : [];
            $review_labels = class_exists('Biawin_CRM_Finance_Review') ? Biawin_CRM_Finance_Review::status_labels() : [];
            $pending_n = 0;
            foreach ($receipts['rows'] as $row) if (!isset($reviews[(int)$row['att']->id])) $pending_n++;
            $in_finance = in_array((string)$case->status, ['FINANCE_REVIEW_PENDING','FINANCE_ASSIGNED','FINANCE_UNDER_REVIEW'], true);
            echo '<div class="bcrm-fin-review-bar"><h2>فیش‌های این پرونده</h2><button type="button" class="button button-primary bcrm-fin-review-open" data-bcrm-fin-review data-case-id="' . (int)$case_id . '">' . esc_html($finance && $in_finance ? 'بررسی فیش‌ها و ثبت نتیجه مالی' : 'مشاهده فیش‌ها') . ($finance && $in_finance && $pending_n ? ' (' . number_format_i18n($pending_n) . ' بررسی‌نشده)' : '') . '</button></div><div class="bcrm-receipts-panel">';
            echo '<div class="bcrm-grid bcrm-receipts-totals">';
            echo '<div class="bcrm-card"><span>مبلغ اعلامی مشتری</span><strong>' . self::money($case->reported_amount) . '</strong></div>';
            echo '<div class="bcrm-card"><span>جمع فیش‌ها (' . number_format_i18n(count($receipts['rows'])) . ' فیش)</span><strong>' . self::money($receipts['total']) . '</strong></div>';
            echo '<div class="bcrm-card' . ($remaining_amount > 0 ? ' is-danger' : '') . '"><span>باقیمانده تا مبلغ اعلامی</span><strong>' . self::money($remaining_amount) . '</strong></div>';
            echo '</div><div class="bcrm-receipt-rows">';
            foreach ($receipts['rows'] as $row) {
                $att = $row['att'];
                $url = wp_get_attachment_url((int)$att->wp_attachment_id);
                echo '<div class="bcrm-receipt-card">';
                echo '<div class="bcrm-receipt-card-main"><strong>' . esc_html($att->post_title ?: ('فیش #' . (int)$att->id)) . '</strong>';
                echo '<span>' . esc_html($att->file_kind === 'bank_receipt' ? 'فیش بانکی' : (string)($att->file_kind ?: 'مدرک')) . ' · ' . esc_html((string)$att->created_at) . '</span>';
                echo '<small>' . esc_html((string)preg_replace('/\s*\|\s*مبلغ فیش:.*/u', '', (string)$att->note) ?: 'بدون توضیح') . '</small></div>';
                echo '<div class="bcrm-receipt-card-amount"><span>مبلغ فیش</span><strong>' . ($row['amount'] !== null ? self::money($row['amount']) : 'نامشخص') . '</strong></div>';
                $rv = $reviews[(int)$att->id] ?? null;
                $rv_status = $rv ? (string)$rv->review_status : 'pending';
                echo '<div class="bcrm-receipt-card-review"><span class="bcrm-fin-badge is-' . esc_attr($rv_status) . '" data-bcrm-review-badge="' . (int)$att->id . '">' . esc_html($review_labels[$rv_status] ?? 'بررسی‌نشده') . '</span>' . ($rv && $rv_status === 'approved' && $rv->approved_amount !== null ? '<small>' . esc_html(self::money($rv->approved_amount)) . '</small>' : '') . '</div>';
                echo '<div class="bcrm-receipt-card-actions"><button type="button" class="button button-primary" data-bcrm-fin-review data-case-id="' . (int)$case_id . '" data-attachment-id="' . (int)$att->id . '">' . esc_html($finance && $in_finance ? 'بررسی و تأیید/رد' : 'مشاهده فیش') . '</button>' . ($url ? '<a class="button" target="_blank" rel="noopener" href="' . esc_url($url) . '">فایل اصلی</a>' : '') . '</div>';
                echo '</div>';
            }
            echo '</div></div>';
        }
        self::render_action_forms($case, $finance, $attachments, $receipts, $remaining_amount);
        echo '<h2>Timeline پرونده</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>کاربر</th><th>رویداد</th><th>از</th><th>به</th><th>جزئیات</th></tr></thead><tbody>';
        foreach ($actions as $a) echo '<tr><td>' . esc_html($a->created_at) . '</td><td>' . esc_html($a->display_name ?: 'سیستم') . '</td><td>' . esc_html(self::event_label($a->action_type)) . '</td><td>' . esc_html(self::status_labels()[$a->from_status] ?? (string)$a->from_status) . '</td><td>' . esc_html(self::status_labels()[$a->to_status] ?? (string)$a->to_status) . '</td><td>' . self::format_timeline_meta((string)$a->metadata_json) . '</td></tr>';
        echo '</tbody></table>';
    }

    /**
     * v0.8.62.24 — تحلیل فیش‌های یک پرونده از روی ردیف‌های attachments (مبلغ هر فیش از یادداشت).
     * همان منطق closure قبلی render_case_detail؛ اکنون مشترک بین پنل فیش‌ها و پنل تشخیص آپلود.
     */
    public static function analyze_case_receipts(array $atts): array
    {
        $list = ['rows' => [], 'total' => 0.0, 'unknown' => 0];
        foreach ($atts as $att) {
            $has_amount = (bool)preg_match('/مبلغ فیش:\s*([\d\x{06F0}-\x{06F9}]+(?:[.,،][\d\x{06F0}-\x{06F9}]+)*)/u', (string)$att->note, $m);
            $amount = $has_amount ? Biawin_CRM_Normalizer::money($m[1]) : null;
            if ($has_amount && $amount > 0) $list['total'] += $amount;
            else $list['unknown']++;
            $list['rows'][] = ['att' => $att, 'amount' => $amount];
        }
        return $list;
    }

    /**
     * v0.8.62.24 — وضعیت تشخیصی آپلود فیش برای پنل «وضعیت آپلود فیش‌ها»:
     * حساب/نشست کاربر، سقف‌های PHP، فایل‌های در صف مرحله‌ای، فضای دیسک و آخرین رویدادهای آپلود.
     * هیچ گاردی اینجا اعمال نمی‌شود — صرفاً مشاهده‌پذیری؛ هر تلاش آپلود (موفق یا ناموفق) که
     * به سرور رسیده باشد در audit_log ثبت شده و اینجا دیده می‌شود.
     */
    public static function upload_diagnostic_state(): array
    {
        global $wpdb;
        $token = '';
        try { $token = (string)Biawin_CRM_Receipt_Staging::fixed_token(); } catch (Throwable $e) { $token = ''; }
        // v0.8.62.27 — صف اختصاصی هر کاربر و هر پرونده (جدول receipt_stage)؛ دیگر صف مشترک سایت نیست.
        $case_id = absint($_GET['case_id'] ?? 0);
        $pool_available = (bool)Biawin_CRM_Receipt_Staging::pool_available();
        $staged_count = $pool_available ? (int)Biawin_CRM_Receipt_Staging::pool_count($case_id) : 0;
        $staged_total = $pool_available ? (int)Biawin_CRM_Receipt_Staging::pool_count(null) : 0;
        $storage_ok = Biawin_CRM_Receipt_Staging::storage_dir() !== '';
        $session_count = 0;
        if (function_exists('wp_get_session_token') && function_exists('wp_get_all_sessions')) {
            $sessions = wp_get_all_sessions();
            $session_count = is_array($sessions) ? count($sessions) : 0;
        } elseif (class_exists('WP_Session_Tokens')) {
            try { $session_count = count(WP_Session_Tokens::get_instance(get_current_user_id())->get_all()); } catch (Throwable $e) { $session_count = 0; }
        }
        $upload_dir = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => sys_get_temp_dir()];
        $basedir = is_array($upload_dir) && !empty($upload_dir['basedir']) ? (string)$upload_dir['basedir'] : sys_get_temp_dir();
        // v0.8.62.25 hotfix — هاست‌های اشتراکی disk_free_space را در disable_functions می‌بندند؛
        // در PHP 8 تابع غیرفعال مثل تعریف‌نشده است و بدون این گارد کل صفحه پرونده فتال می‌شد.
        // v0.8.63.2: routed through File_Safety (also checks disable_functions/is_callable, never throws).
        $disk_free = Biawin_CRM_File_Safety::disk_free_bytes($basedir);
        $rows = $wpdb->get_results('SELECT action, after_json, created_at FROM ' . Biawin_CRM_DB::table('audit_log') . " WHERE object_type='upload' ORDER BY id DESC LIMIT 30");
        $recent = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (strpos((string)$row->action, 'receipt_') !== 0) continue;
            $recent[] = $row;
            if (count($recent) >= 10) break;
        }
        return [
            'logged_in'             => (bool)get_current_user_id(),
            'session_count'         => $session_count,
            'staging_token_present' => $token !== '',
            'staged_count'          => $staged_count,
            'staged_total'          => $staged_total,
            'pool_available'        => $pool_available,
            'storage_ok'            => $storage_ok,
            'max_file_bytes'        => Biawin_CRM_Upload_Normalizer::effective_max_file_bytes(),
            'max_file_uploads'      => Biawin_CRM_Upload_Normalizer::max_uploads_ini(),
            'upload_max_filesize'   => trim((string)@ini_get('upload_max_filesize')),
            'post_max_size'         => trim((string)@ini_get('post_max_size')),
            'disk_free_bytes'       => $disk_free,
            'recent'                => $recent,
        ];
    }

    /** v0.8.62.24 — پنل تشخیص آپلود فیش روی صفحه پرونده (هر دو نمای رفع ناقصی و مالی). */
    private static function render_upload_diagnostics(bool $finance): void
    {
        $d = self::upload_diagnostic_state();
        $staged_n = (int)$d['staged_count'];
        $has_staged = $staged_n > 0;
        $pool_broken = !(bool)$d['pool_available'];
        $case_id = (int)($_GET['case_id'] ?? 0);
        $chip = static function (string $label, string $value, string $cls): string {
            return '<span class="bcrm-ud-chip ' . esc_attr($cls) . '">' . esc_html($label) . ': <b>' . esc_html($value) . '</b></span>';
        };
        $disk = $d['disk_free_bytes'] === null ? 'نامشخص' : number_format_i18n(round((float)$d['disk_free_bytes'] / 1048576)) . ' MB';
        echo '<details class="bcrm-upload-diagnostics"><summary>وضعیت آپلود فیش‌ها (تشخیص مشکل آپلود)</summary><div class="bcrm-upload-diagnostics-body">';
        echo '<div class="bcrm-ud-chips">';
        echo $chip('حساب کاربری', $d['logged_in'] ? 'وارد شده' : 'خارج از حساب', $d['logged_in'] ? 'is-ok' : 'is-bad');
        echo $chip('نشست ورود', $d['session_count'] > 0 ? 'فعال (' . number_format_i18n($d['session_count']) . ')' : 'قابل تأیید نیست', $d['session_count'] > 0 ? 'is-ok' : 'is-warn');
        echo $chip('توکن آپلود', $d['staging_token_present'] ? 'موجود' : 'ناموجود', $d['staging_token_present'] ? 'is-ok' : 'is-bad');
        echo $chip('صف آپلود (جدول دیتابیس)', $pool_broken ? 'ساخته نشد' : 'سالم', $pool_broken ? 'is-bad' : 'is-ok');
        echo $chip('پوشه موقت فیش‌ها', $d['storage_ok'] ? 'قابل نوشتن' : 'غیرقابل نوشتن', $d['storage_ok'] ? 'is-ok' : 'is-bad');
        echo $chip('فیش‌های آپلودشده شما برای این پرونده (منتظر ثبت نهایی)', number_format_i18n($staged_n), $has_staged ? 'is-ok' : 'is-warn');
        if ((int)$d['staged_total'] > $staged_n) echo $chip('فیش‌های منتظر شما در پرونده‌های دیگر', number_format_i18n((int)$d['staged_total'] - $staged_n), 'is-warn');
        echo $chip('حداکثر حجم هر فیش', number_format_i18n(round((int)$d['max_file_bytes'] / 1048576)) . ' MB', 'is-ok');
        echo $chip('سقف فایل همزمان سرور (max_file_uploads)', ($d['max_file_uploads'] > 0 ? number_format_i18n($d['max_file_uploads']) : 'نامحدود') . ' — روی آپلود مرحله‌ای اثری ندارد', 'is-ok');
        echo $chip('سقف حجم هر فایل (upload_max_filesize)', $d['upload_max_filesize'] !== '' ? $d['upload_max_filesize'] : 'نامشخص', 'is-ok');
        echo $chip('سقف حجم هر درخواست (post_max_size)', $d['post_max_size'] !== '' ? $d['post_max_size'] : 'نامشخص', 'is-ok');
        echo $chip('فضای خالی دیسک', $disk, ($d['disk_free_bytes'] !== null && $d['disk_free_bytes'] < 20 * 1048576) ? 'is-bad' : 'is-ok');
        echo '</div>';
        if ($pool_broken) {
            echo '<div class="bcrm-ud-hint"><b>جدول صف آپلود در دیتابیس ساخته نشد</b> — کاربر دیتابیس وردپرس اجازه CREATE TABLE ندارد یا خطای دیتابیس رخ داده؛ رویداد «ساخت جدول صف آپلود ناموفق» در فهرست پایین علت دقیق را نشان می‌دهد.</div>';
        } elseif ($has_staged) {
            echo '<div class="bcrm-ud-hint"><b>' . esc_html(number_format_i18n($staged_n)) . ' فیش شما برای همین پرونده روی سرور آپلود شده و منتظر «ثبت نهایی» است.</b> با باز کردن فرم «ارسال به تایید مالی» همین فیش‌ها خودکار در فرم بازیابی می‌شوند؛ مبلغ‌ها را کنترل و «ثبت نهایی» را بزنید — فایل‌ها دوباره آپلود نمی‌شوند. صف هر کاربر و هر پرونده جداست و ثبت یک همکار روی فیش‌های شما اثری ندارد.</div>';
        } else {
            echo '<div class="bcrm-ud-hint">هیچ فیشی از شما برای این پرونده در صف نیست. گام‌ها: ۱) مرحله اقدام را روی «ارسال به تایید مالی» بگذارید و تیک «ارسال فیش» را بزنید. ۲) فایل‌ها را انتخاب کنید (می‌توانید چند فایل را یک‌جا انتخاب کنید؛ برای هر فایل یک ردیف ساخته می‌شود) — هر فایل همان لحظه آپلود و وضعیتش کنار ردیف نمایش داده می‌شود. ۳) مبلغ هر فیش را وارد و «ثبت نهایی» را بزنید. اگر خطایی دیدید، فهرست پایین دقیق‌ترین رویداد ثبت‌شده در سرور را نشان می‌دهد.</div>';
        }
        echo '<div class="bcrm-ud-section">آخرین رویدادهای آپلود ثبت‌شده در سرور (جدیدترین اول)</div>';
        if (!$d['recent']) {
            echo '<ul class="bcrm-ud-list"><li>هیچ رویداد آپلودی ثبت نشده — یعنی هیچ درخواست آپلودی به سرور نرسیده است. اگر دکمه ثبت را زده‌اید و اینجا چیزی نیست، مشکل در سمت مرورگر/شبکه است (کش صفحه قدیمی، افزونه مرورگر یا قطع درخواست).</li></ul>';
        } else {
            echo '<ul class="bcrm-ud-list">';
            foreach ($d['recent'] as $row) echo '<li>' . self::format_upload_event($row) . '</li>';
            echo '</ul>';
        }
        echo '</div></details>';
    }

    /** v0.8.62.24 — یک رویداد آپلود را برای پنل تشخیص به فارسی و با جزئیات کلیدی می‌سازد. */
    private static function format_upload_event(object $row): string
    {
        $event = preg_replace('/^receipt_/', '', (string)$row->action);
        $labels = [
            'stage_request_received'=>'درخواست آپلود رسید',
            'stage_auth_rejected'=>'اعتبارسنجی آپلود رد شد',
            'stage_auth_token_fallback'=>'اعتبارسنجی با توکن جایگزین انجام شد (nonce منقضی)',
            'stage_no_payload'=>'فایل به سرور نرسید',
            'stage_sideload_failed'=>'ذخیره فیش روی دیسک ناموفق',
            'receipt_staged'=>'فیش در صف قرار گرفت',
            'receipt_staged_duplicate'=>'محتوای تکراری؛ همان فیش قبلی استفاده شد',
            'staged_finalize_failed'=>'ثبت نهایی دسته فیش‌ها ناموفق',
            'staged_receipt_summary'=>'ثبت نهایی فیش‌ها انجام شد',
            'send_to_finance_status_guard_blocked'=>'ارسال به مالی به‌دلیل وضعیت پرونده رد شد',
            'finalize_unknown_staged_id'=>'فیش ارسالی در صف سرور پیدا نشد (منقضی/حذف‌شده)',
            'staging_lock_timeout'=>'صف آپلود قدیمی شلوغ بود (نسخه‌های قبل از 0.8.62.27)',
            'stage_table_create_failed'=>'ساخت جدول صف آپلود ناموفق',
            'stage_storage_unavailable'=>'پوشه موقت فیش‌ها قابل نوشتن نیست',
            'stage_move_failed'=>'ذخیره فیش روی دیسک ناموفق',
            'stage_db_insert_failed'=>'ثبت فیش در صف دیتابیس ناموفق',
            'stage_rejected_size'=>'فیش به‌خاطر حجم زیاد رد شد',
            'stage_post_max_exceeded'=>'حجم درخواست از post_max_size سرور بیشتر بود',
            'attachment_metadata_failed'=>'ساخت پیش‌نمایش فیش ناموفق (فیش ثبت شد)',
            'finalize_staged_file_missing'=>'فایل فیش در سرور پیدا نشد',
            'finalize_row_failed'=>'ثبت یکی از فیش‌ها ناموفق بود',
            'finalize_row_source_copy_failed'=>'آماده‌سازی فایل فیش ناموفق بود',
            'finalize_id_amount_count_mismatch'=>'ناهم‌خوانی تعداد فیش و مبالغ',
            'finalize_repeated_content_row'=>'محتوای تکراری در ثبت نهایی (با مبلغ خودش ثبت شد)',
            'request_truncated_post_max_size'=>'درخواست به‌خاطر سقف post_max_size بریده شد',
            'server_limit_truncation'=>'درخواست ناقص رسید (سقف سرور)',
            'receipt_pair_count_mismatch'=>'ناهم‌خوانی ردیف‌های فیش و فایل',
            'row_failed_missing_amount'=>'ردیف بدون مبلغ رد شد',
            'receipt_row_failed_rollback'=>'آپلود ردیف ناموفق؛ همه تغییرات این درخواست برگشت خورد',
            'duplicate_receipt_row_content_repeat'=>'محتوای تکراری در همین درخواست (با مبلغ خودش ثبت شد)',
            'media_handle_upload_failed'=>'انتقال فایل به کتابخانه رسانه ناموفق',
            'attachment_db_insert_failed'=>'ثبت فیش در دیتابیس ناموفق',
            'upload_php_error'=>'آپلود با خطای PHP متوقف شد',
            'row_missing_file'=>'ردیفی بدون فایل رسید',
            'malformed_multipart_entry'=>'داده فایل ناقص/خراب رسید',
            'staged_filetype_rejected'=>'فرمت فیش مجاز نیست',
        ];
        // Biawin_CRM_Upload_Normalizer::log() خودش پیشوند receipt_ را اپند می‌کند؛ پس دقیقاً همان
        // یک پیشوند حذف می‌شود تا نام اصلی رویداد (مثل receipt_staged یا stage_request_received) بماند.
        $event = preg_replace('/^receipt_/', '', (string)$row->action);
        $label = $labels[$event] ?? $event;
        $ctx = json_decode((string)($row->after_json ?? ''), true);
        $parts = [];
        if (is_array($ctx)) {
            foreach (['size'=>'حجم','received_count'=>'دریافتی','uploaded_count'=>'ثبت‌شده','failed_count'=>'ناموفق','ids'=>'تعداد شناسه','missing_count'=>'پیدا‌نشده','case_id'=>'پرونده','content_length'=>'حجم درخواست','logged_in'=>'وارد‌شده','nonce_ok'=>'nonce','token_ok'=>'توکن','error'=>'خطا','php_error'=>'کد خطای PHP','name'=>'فایل','staged_id'=>'شناسه','staged_count'=>'تعداد در صف','reason'=>'دلیل'] as $k => $lbl) {
                if (!isset($ctx[$k]) || $ctx[$k] === '' || $ctx[$k] === null) continue;
                $v = $ctx[$k];
                if ($k === 'ids' && is_array($v)) $v = count($v);
                $parts[] = $lbl . ': ' . (is_scalar($v) ? (string)$v : (string)wp_json_encode($v, JSON_UNESCAPED_UNICODE));
                if (count($parts) >= 4) break;
            }
        }
        return esc_html($label) . ' — ' . esc_html((string)$row->created_at) . ($parts ? ' · ' . esc_html(implode(' | ', $parts)) : '');
    }

    /** Server-side simultaneous-file cap, shown to agents BEFORE they build a doomed 28-row request. */
    private static function receipt_limit_hint(): string
    {
        $mb = (int)round(Biawin_CRM_Upload_Normalizer::effective_max_file_bytes() / 1048576);
        return 'تعداد فیش‌ها نامحدود و فرمت آزاد است (عکس، HEIC، PDF، اسکن، Word/Excel، ZIP و…). می‌توانید چند فایل را یک‌جا انتخاب کنید؛ برای هر فایل یک ردیف با مبلغ جدا ساخته می‌شود. هر فایل بلافاصله پس از انتخاب آپلود می‌شود و وضعیتش کنار همان ردیف نمایش داده می‌شود (عکس‌های حجیم پیش از ارسال خودکار کم‌حجم می‌شوند). حداکثر حجم هر فایل ' . $mb . ' مگابایت.';
    }

    private static function render_action_forms(object $case, bool $finance, array $attachments = [], array $receipts = [], float $remaining_amount = 0.0): void
    {
        echo '<div class="bcrm-grid bcrm-workflow-actions">';
        if (!$finance) {
            echo '<form class="bcrm-card bcrm-workflow-final-form" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'اقدام انتخاب‌شده ثبت نهایی شود؟\')"><h3>ثبت نهایی رفع ناقصی</h3>';
            self::form_base($case, 'deficiency_submit');
            echo '<label>مرحله اقدام<select name="workflow_step" required><option value="">انتخاب کنید</option><option value="contact">ثبت تماس</option><option value="result">ثبت نتیجه بررسی</option><option value="send_finance">ارسال به تایید مالی</option>' . ((string)$case->status === 'RETURNED_TO_DEFICIENCY' ? '<option value="return_invoice">ثبت فاکتور برگشتی از مالی</option>' : '') . '<option value="complete_corrected">اصلاح مبلغ اعلامی و تکمیل</option><option value="complete">تکمیل پروفایل</option><option value="archive">بایگانی</option></select></label>';
            echo '<div class="bcrm-form-section" data-bcrm-step="contact"><h4>ثبت تماس</h4><label>نتیجه تماس<select name="contact_outcome"><option value="">انتخاب نتیجه تماس</option><option value="answered">پاسخ داد</option><option value="no_answer">پاسخ نداد</option><option value="powered_off">خاموش</option><option value="unavailable">در دسترس نیست</option><option value="wrong_number">شماره اشتباه</option><option value="follow_up">پیگیری مجدد</option><option value="awaiting_receipt">در انتظار ارسال فیش</option></select></label><label>تاریخ پیگیری<div class="bcrm-date-picker-wrap"><input type="text" name="follow_up_date" data-bcrm-jalali-date readonly placeholder="انتخاب تاریخ شمسی"><button type="button" class="bcrm-date-picker-button" data-bcrm-open-jalali aria-label="باز کردن تقویم شمسی">📅</button></div></label></div>';
            echo '<div class="bcrm-form-section" data-bcrm-step="result"><h4>نتیجه بررسی</h4><label>نتیجه رفع ناقصی<select name="deficiency_result"><option value="">انتخاب نتیجه رفع ناقصی</option><option value="resolved">مغایرت وجود ندارد / رفع شد</option><option value="real_mismatch">مغایرت واقعی است</option><option value="extra_payment_claim">مشتری ادعای واریز اضافه دارد</option><option value="missing_invoice">فاکتور ثبت نشده</option><option value="incomplete_invoice">اطلاعات فاکتور ناقص است</option><option value="needs_finance">نیازمند بررسی مالی</option><option value="needs_more_review">نیازمند بررسی بیشتر</option><option value="other">سایر</option></select></label><label>مبلغ ادعایی مشتری<input type="text" inputmode="numeric" name="customer_claimed_amount" placeholder="مثلا 1,000,000"></label><label>مبلغ اختلاف تاییدشده<input type="text" inputmode="numeric" name="confirmed_difference_amount" placeholder="مثلا 500,000"></label></div>';
            echo '<div class="bcrm-form-section" data-bcrm-step="send_finance"><h4>ارسال به تایید مالی</h4><label class="bcrm-check-row"><input type="checkbox" name="send_receipt" value="1" data-bcrm-receipt-toggle> ارسال فیش</label><label class="bcrm-check-row"><input type="checkbox" name="refund_status" value="refunded"> وضعیت عودت: عودت شده</label><div data-bcrm-receipt-fields><p class="bcrm-receipt-limit-hint" data-bcrm-receipt-limit>' . esc_html(self::receipt_limit_hint()) . '</p><p class="bcrm-stage-status" data-bcrm-stage-status aria-live="polite"></p><div data-bcrm-receipts><div class="bcrm-receipt-row"><label>مبلغ فیش<input type="text" inputmode="numeric" name="receipt_amounts[]" placeholder="مثلا 1,000,000"></label><label>فایل فیش<input type="file" name="case_documents[]" multiple></label><span class="bcrm-receipt-row-status" data-bcrm-row-status aria-live="polite"></span><button type="button" class="button" data-bcrm-remove-receipt>حذف</button></div></div><button type="button" class="button" data-bcrm-add-receipt>+ افزودن فیش</button></div></div>';
            echo '<div class="bcrm-form-section" data-bcrm-step="return_invoice"><h4>ثبت فاکتور برگشتی از مالی</h4><label>شماره فاکتور<input type="text" name="return_invoice_number" placeholder="شماره فاکتور"></label><label>مبلغ فاکتور<input type="text" inputmode="numeric" name="return_invoice_amount" placeholder="مثلا 1,000,000"></label><label>شماره تماس مشتری<input type="tel" name="return_customer_phone" placeholder="09..."></label><label>نام/شرح فاکتور<input type="text" name="return_invoice_name" placeholder="اختیاری"></label></div>';
            echo '<div class="bcrm-form-section" data-bcrm-step="complete_corrected complete"><h4>تکمیل پروفایل</h4><label>مبلغ اعلامی اصلاح‌شده<input type="text" inputmode="numeric" name="corrected_declared_paid" placeholder="مثلا 10,050,001"></label><label>مبلغ تایید شده نهایی<input type="text" inputmode="numeric" name="final_approved_amount" placeholder="مثلا 100,000,000"></label></div>';
            echo '<textarea name="description" placeholder="توضیحات"></textarea><button class="button button-primary">ثبت نهایی</button></form>';
        } else {
            $phone = self::primary_phone((int)$case->customer_id);
            $notice = 'واحد تایید مالی شماره موبایل ' . ($phone ?: 'مشتری') . ' در ساختار اکسل مالی به فیش مذکور الصاق گردد';
            echo '<form class="bcrm-card bcrm-workflow-final-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="var r=this.finance_result.value;if(r===\'without_invoice\'){return confirm(\'' . esc_js($notice) . '\');}return confirm(\'نتیجه مالی ثبت و پرونده به رفع ناقصی برگردد؟\')"><h3>ثبت نهایی تایید مالی</h3>';
            self::form_base($case, 'finance_submit');
            // v0.8.62.23 — خلاصه مالی به سبک فروش‌نت: جمع فیش‌ها و باقیمانده، بالای فرم.
            echo '<div class="bcrm-grid bcrm-finance-summary">';
            echo '<div class="bcrm-card"><span>جمع فیش‌های ثبت‌شده</span><strong>' . self::money($receipts['total'] ?? 0) . '</strong></div>';
            echo '<div class="bcrm-card"><span>مبلغ اعلامی مشتری</span><strong>' . self::money($case->reported_amount) . '</strong></div>';
            echo '<div class="bcrm-card' . ($remaining_amount > 0 ? ' is-danger' : '') . '"><span>باقیمانده تا مبلغ اعلامی</span><strong>' . self::money($remaining_amount) . '</strong></div>';
            echo '</div>';
            echo '<label>نتیجه مالی<select name="finance_result" required><option value="">انتخاب نتیجه</option><option value="with_invoice">تایید مالی با فاکتور</option><option value="without_invoice">تایید مالی بدون فاکتور</option><option value="rejected">عدم تایید مالی</option></select></label>';
            echo '<div class="bcrm-form-section" data-bcrm-finance="with_invoice"><h4>فیش مرتبط</h4><select name="receipt_attachment_id"><option value="">انتخاب فیش</option>';
            foreach ($attachments as $att) {
                $kind = $att->file_kind ?: 'document';
                echo '<option value="' . (int)$att->id . '">' . esc_html($kind . ' #' . (int)$att->id . ' - ' . ($att->note ?: $att->post_title ?: 'فایل')) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="bcrm-form-section" data-bcrm-finance="with_invoice"><h4>تایید با فاکتور</h4><label>شماره فاکتور<input type="text" name="invoice_number" placeholder="شماره فاکتور"></label><label>شماره تماس مشتری<input type="tel" name="customer_phone" placeholder="09..."></label><label>تاریخ فاکتور<div class="bcrm-date-picker-wrap"><input type="text" name="invoice_date" data-bcrm-jalali-date readonly placeholder="انتخاب تاریخ شمسی"><button type="button" class="bcrm-date-picker-button" data-bcrm-open-jalali aria-label="باز کردن تقویم شمسی">📅</button></div></label><label>مبلغ فاکتور<input type="text" inputmode="numeric" name="invoice_amount" placeholder="مثلا 1,000,000"></label></div>';
            echo '<div class="bcrm-form-section" data-bcrm-finance="without_invoice"><h4>تایید بدون فاکتور</h4><label>مبلغ تاییدشده بدون فاکتور<input type="text" inputmode="numeric" name="approved_amount" data-bcrm-prefill placeholder="مثلا ' . esc_attr(number_format((float)$remaining_amount, 0, '.', ',')) . '"></label><label>تاریخ پرداخت<div class="bcrm-date-picker-wrap"><input type="text" name="payment_date" data-bcrm-jalali-date readonly placeholder="انتخاب تاریخ شمسی"><button type="button" class="bcrm-date-picker-button" data-bcrm-open-jalali aria-label="باز کردن تقویم شمسی">📅</button></div></label><label>شماره پیگیری<input type="text" name="tracking_number" placeholder="شماره پیگیری"></label><label>بانک/درگاه<input type="text" name="bank_or_gateway" placeholder="بانک یا درگاه"></label></div>';
            echo '<div class="bcrm-form-section" data-bcrm-finance="rejected"><h4>عدم تایید مالی</h4><select name="reject_reason"><option value="">دلیل رد</option><option value="invalid_receipt">فیش نامعتبر</option><option value="payment_not_found">واریز پیدا نشد</option><option value="amount_mismatch">مبلغ مغایر است</option><option value="not_customer_payment">واریزی متعلق به مشتری نیست</option><option value="invalid_invoice">فاکتور نامعتبر</option><option value="insufficient_info">اطلاعات ناکافی</option><option value="invalid_document">مدرک نامعتبر</option><option value="other">سایر</option></select></div>';
            echo '<textarea name="description" placeholder="توضیحات مالی"></textarea><button class="button button-primary">ثبت نهایی</button></form>';
        }
        echo '</div>';
    }

    private static function form_base(object $case, string $action): void
    {
        wp_nonce_field('bcrm_deficiency_action_' . (int)$case->id);
        echo '<input type="hidden" name="action" value="bcrm_deficiency_action"><input type="hidden" name="case_id" value="' . (int)$case->id . '"><input type="hidden" name="case_action" value="' . esc_attr($action) . '">';
    }

    private static function format_timeline_meta(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !$data) return '-';
        $labels = [
            'description'=>'توضیحات',
            'decision'=>'تصمیم',
            'contact_outcome'=>'نتیجه تماس',
            'follow_up_date'=>'تاریخ پیگیری',
            'deficiency_result'=>'نتیجه رفع ناقصی',
            'customer_claimed_amount'=>'مبلغ ادعایی مشتری',
            'confirmed_difference_amount'=>'مبلغ اختلاف تاییدشده',
            'difference_amount'=>'مبلغ اختلاف',
            'receipt_amount'=>'مبلغ فیش',
            'send_receipt'=>'ارسال فیش',
            'refund_status'=>'وضعیت عودت',
            'receipt_count'=>'تعداد فیش',
            'attachment_ids'=>'شناسه‌های فیش',
            'attachment_id'=>'شناسه فیش',
            'receipt_attachment_id'=>'فیش مرتبط',
            'return_invoice_number'=>'شماره فاکتور برگشتی',
            'return_invoice_amount'=>'مبلغ فاکتور برگشتی',
            'return_customer_phone'=>'شماره تماس فاکتور برگشتی',
            'return_invoice_name'=>'شرح فاکتور برگشتی',
            'invoice_number'=>'شماره فاکتور',
            'customer_phone'=>'شماره تماس مشتری',
            'invoice_date'=>'تاریخ فاکتور',
            'invoice_amount'=>'مبلغ فاکتور',
            'approved_amount'=>'مبلغ تاییدشده',
            'payment_date'=>'تاریخ پرداخت',
            'tracking_number'=>'شماره پیگیری',
            'bank_or_gateway'=>'بانک/درگاه',
            'reject_reason'=>'دلیل رد',
            'notice'=>'اطلاعیه',
            'final_approved_amount'=>'مبلغ تایید شده نهایی',
            'corrected_declared_paid'=>'مبلغ اعلامی اصلاح‌شده',
            'previous_declared_paid'=>'مبلغ اعلامی قبلی',
            'journey_state'=>'مسیر',
            'reconciled_amount'=>'مبلغ تراز شده',
            'registered_invoice_amount'=>'مجموع فاکتورها',
            'finance_approved_amount'=>'مبلغ تایید مالی',
            'attachment_id'=>'فیش',
            'receipt_reviews'=>'بررسی فیش‌ها',
        ];
        $pairs = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '' || $value === []) continue;
            if ($key === 'receipt_reviews' && is_array($value)) { // v0.8.62.28
                $value = number_format_i18n((int)($value['approved_count'] ?? 0)) . ' تأیید (' . number_format_i18n((float)($value['approved_amount'] ?? 0)) . ')، ' . number_format_i18n((int)($value['rejected_count'] ?? 0)) . ' رد';
            }
            if ($key === 'attachment_id') $value = '#' . (int)$value;
            if (is_array($value)) $value = implode('، ', array_map('strval', $value));
            if ($key === 'send_receipt') $value = ((int)$value === 1) ? 'بله' : 'خیر';
            if ($key === 'refund_status' && $value === 'refunded') $value = 'عودت شده';
            if (in_array($key, ['follow_up_date','invoice_date','payment_date'], true) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$value)) { // v0.8.62.21: نمایش شمسی تاریخ‌های میلادی ذخیره‌شده
                try {
                    $dt = new DateTimeImmutable((string)$value, function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Asia/Tehran'));
                    [$gy2, $gm2, $gd2] = Biawin_CRM_Normalizer::jalali_to_gregorian((int)$dt->format('Y') + 0, (int)$dt->format('n'), (int)$dt->format('j'));
                    // از میلادی به شمسی مستقیم نیاز است؛ از الگوریتم معکوس خودکفایی می‌کنیم:
                    [$jy2, $jm2, $jd2] = self::gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('n'), (int)$dt->format('j'));
                    $value = sprintf('%04d/%02d/%02d', $jy2, $jm2, $jd2);
                } catch (Exception $e) { /* fallback: میلادی نمایش داده می‌شود */ }
            }
            if (is_numeric($value) && preg_match('/amount|paid|مبلغ|invoice_amount|receipt_amount/i', (string)$key)) {
                $value = number_format_i18n((float)$value);
            }
            $pairs[] = '<span class="bcrm-meta-item"><strong>' . esc_html($labels[$key] ?? (string)$key) . ':</strong> ' . esc_html((string)$value) . '</span>';
        }
        return $pairs ? '<div class="bcrm-timeline-meta">' . implode('', $pairs) . '</div>' : '-';
    }

    private static function render_reports(bool $finance): void
    {
        global $wpdb;
        $cases = Biawin_CRM_DB::table('deficiency_cases');
        $incoming = (int)$wpdb->get_var("SELECT COUNT(*) FROM $cases");
        $completed = (int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE status='COMPLETED'");
        $archived = (int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE status='ARCHIVED'");
        $sent = (int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE sent_to_finance_at IS NOT NULL");
        $with = (int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE finance_result='with_invoice'");
        $without = (int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE finance_result='without_invoice'");
        $rejected = (int)$wpdb->get_var("SELECT COUNT(*) FROM $cases WHERE finance_result='rejected'");
        $actions = Biawin_CRM_DB::table('case_actions');
        $refunded = (int)$wpdb->get_var("SELECT COUNT(DISTINCT case_id) FROM $actions WHERE action_type='CASE_SENT_TO_FINANCE' AND metadata_json LIKE '%\"refund_status\":\"refunded\"%'");
        echo '<div class="bcrm-grid">';
        foreach (['ورودی'=>$incoming,'تکمیل'=>$completed,'بایگانی'=>$archived,'ارسال به مالی'=>$sent,'ارسال به مالی با وضعیت عودت شده'=>$refunded,'تأیید با فاکتور'=>$with,'تأیید بدون فاکتور'=>$without,'عدم تأیید'=>$rejected] as $label=>$value) echo '<div class="bcrm-card"><strong>' . number_format_i18n($value) . '</strong><span>' . esc_html($label) . '</span></div>';
        echo '</div><p><a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bcrm_deficiency_export'), 'bcrm_deficiency_export')) . '">Export CSV پرونده‌ها</a> <a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bcrm_deficiency_export_excel'), 'bcrm_deficiency_export')) . '">خروجی اکسل کامل (پرونده‌ها + اقدام‌ها و ریز وضعیت‌ها + خلاصه)</a></p>';
    }

    public static function export_csv(): void
    {
        global $wpdb;
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="biawin-deficiency-cases-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Customer ID','نام','موبایل','مدیر فروش','مبلغ اعلامی','مجموع فاکتور','مبلغ اختلاف','وضعیت','کارشناس رفع ناقصی','کارشناس مالی','نتیجه مالی','وضعیت عودت','تصمیم نهایی','تاریخ ورود','تاریخ تکمیل']);
        $rows = $wpdb->get_results('SELECT d.*,c.display_name,c.source_customer_id,c.customer_code,c.sales_manager,(SELECT normalized_phone FROM ' . Biawin_CRM_DB::table('phones') . ' p WHERE p.customer_id=d.customer_id ORDER BY p.is_primary DESC,p.id LIMIT 1) phone FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' d LEFT JOIN ' . Biawin_CRM_DB::table('customers') . ' c ON c.id=d.customer_id ORDER BY d.id DESC');
        $refund_rows = $wpdb->get_col("SELECT DISTINCT case_id FROM " . Biawin_CRM_DB::table('case_actions') . " WHERE action_type='CASE_SENT_TO_FINANCE' AND metadata_json LIKE '%\"refund_status\":\"refunded\"%'");
        $refund_map = array_fill_keys(array_map('intval', (array)$refund_rows), true);
        foreach ($rows as $r) {
            $du = $r->deficiency_agent_id ? get_user_by('id', (int)$r->deficiency_agent_id) : null;
            $fu = $r->finance_agent_id ? get_user_by('id', (int)$r->finance_agent_id) : null;
            fputcsv($out, [$r->source_customer_id ?: $r->customer_code ?: $r->customer_id, $r->display_name, $r->phone, $r->sales_manager, $r->reported_amount, $r->registered_invoice_amount, $r->difference_amount, self::status_labels()[$r->status] ?? $r->status, $du ? $du->display_name : '', $fu ? $fu->display_name : '', self::finance_result_labels()[$r->finance_result] ?? $r->finance_result, !empty($refund_map[(int)$r->id]) ? 'عودت شده' : '', $r->final_decision, $r->entered_at, $r->completed_at]);
        }
        fclose($out);
        exit;
    }

    /**
     * v0.8.62.17: خروجی اکسل چندشیتی گزارش Workflow. (v0.8.62.18: ستون‌های ریز وضعیت + تاریخ/ساعت/انجام‌دهنده آخرین اقدام در شیت پرونده‌ها؛ v0.8.62.19: ستون «مرحله اقدام» از گزینه‌های workflow_step)
     * شیت ۱ «پرونده‌ها» = ستون‌های CSV + وضعیت/ریز وضعیت و آخرین اقدام؛ شیت ۲ «اقدام‌ها» = تک‌تک رویدادهای Workflow با ریز وضعیت
     * و داده‌های هر مرحله (نتیجه تماس/بررسی، مبالغ ادعایی/تاییدشده/اصلاحی، فاکتور برگشتی، فیش‌ها،
     * تاریخ پیگیری) که از metadata_json هر رویداد استخراج می‌شود؛ شیت ۳ «خلاصه» = شمارش هر
     * (رویداد × زیر وضعیت).
     */
    public static function export_excel(): void
    {
        global $wpdb;
        // --- شیت ۱: پرونده‌ها (مثل CSV) ---
        $cases = $wpdb->get_results('SELECT d.*,c.display_name,c.source_customer_id,c.customer_code,c.sales_manager,(SELECT normalized_phone FROM ' . Biawin_CRM_DB::table('phones') . ' p WHERE p.customer_id=d.customer_id ORDER BY p.is_primary DESC,p.id LIMIT 1) phone FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' d LEFT JOIN ' . Biawin_CRM_DB::table('customers') . ' c ON c.id=d.customer_id ORDER BY d.id DESC');
        $refund_map = array_fill_keys(array_map('intval', (array)$wpdb->get_col("SELECT DISTINCT case_id FROM " . Biawin_CRM_DB::table('case_actions') . " WHERE action_type='CASE_SENT_TO_FINANCE' AND metadata_json LIKE '%\\\"refund_status\\\":\\\"refunded\\\"%'")), true);
        $num = static function ($v) { return ($v === null || $v === '') ? '' : (float)$v; };
        // v0.8.62.18: برچسب‌های ریز وضعیت — مشترک بین شیت «پرونده‌ها» (آخرین اقدام) و شیت «اقدام‌ها»
        $contact_labels = ['answered'=>'پاسخ داد','no_answer'=>'پاسخ نداد','powered_off'=>'خاموش','unavailable'=>'در دسترس نیست','wrong_number'=>'شماره اشتباه','follow_up'=>'پیگیری مجدد','awaiting_receipt'=>'در انتظار ارسال فیش'];
        $result_labels = ['resolved'=>'مغایرت وجود ندارد / رفع شد','real_mismatch'=>'مغایرت واقعی است','extra_payment_claim'=>'ادعای واریز اضافه','missing_invoice'=>'فاکتور ثبت نشده','incomplete_invoice'=>'اطلاعات فاکتور ناقص است','needs_finance'=>'نیازمند بررسی مالی','needs_more_review'=>'نیازمند بررسی بیشتر','other'=>'سایر'];
        $finance_labels = self::finance_result_labels();
        $sub_label = static function (string $key, string $raw) use ($contact_labels, $result_labels, $finance_labels): string {
            if ($raw === '') return '';
            if ($key === 'document_type') return (string)$raw;
            if ($key === 'refund_status') return $raw === 'refunded' ? 'عودت شده' : (string)$raw;
            return (string)($contact_labels[$raw] ?? $result_labels[$raw] ?? $finance_labels[$raw] ?? $raw);
        };
        $sub_of_meta = static function (array $meta) use ($sub_label): string {
            foreach (['contact_outcome','deficiency_result','finance_result','document_type','refund_status'] as $k) {
                if (isset($meta[$k]) && (string)$meta[$k] !== '') return $sub_label($k, (string)$meta[$k]);
            }
            return '';
        };
        // v0.8.62.19: «مرحله اقدام» — همان گزینه‌های سلکت workflow_step، از action_type هر رویداد
        $step_labels = [
            'CUSTOMER_CONTACT_ATTEMPT'=>'ثبت تماس',
            'DEFICIENCY_RESULT_RECORDED'=>'ثبت نتیجه بررسی',
            'CASE_SENT_TO_FINANCE'=>'ارسال به تایید مالی',
            'RETURNED_INVOICE_RECORDED'=>'ثبت فاکتور برگشتی از مالی',
            'CASE_COMPLETED_WITH_CORRECTED_DECLARED'=>'اصلاح مبلغ اعلامی و تکمیل',
            'CASE_COMPLETED'=>'تکمیل پروفایل',
            'CASE_ARCHIVED'=>'بایگانی',
            'FINANCE_APPROVED_WITH_INVOICE'=>'ثبت نتیجه مالی',
            'FINANCE_APPROVED_WITHOUT_INVOICE'=>'ثبت نتیجه مالی',
            'FINANCE_REJECTED'=>'ثبت نتیجه مالی',
            'PROFILE_MARKED_INCOMPLETE'=>'ورود به Workflow',
            'DEFICIENCY_CASE_BACKFILLED'=>'ساخت پرونده قدیمی',
            'DEFICIENCY_ASSIGNED'=>'تخصیص',
            'FINANCE_ASSIGNED'=>'تخصیص',
            'DOCUMENT_UPLOADED'=>'بارگذاری مدرک',
            'CASE_RETURNED_FROM_FINANCE'=>'برگشت از مالی',
            'CASE_AUTO_COMPLETED_BY_TOLERANCE'=>'تکمیل خودکار',
            'CASE_AUTO_COMPLETED_AFTER_FINANCE'=>'تکمیل خودکار',
        ];
        $step_of = static function (string $type) use ($step_labels): string {
            return (string)($step_labels[$type] ?? '');
        };
        // v0.8.62.18: آخرین اقدام هر پرونده → مرحله اقدام + ریز وضعیت + تاریخ/ساعت/انجام‌دهنده آخرین اقدام
        $last_map = [];
        foreach ((array)$wpdb->get_results('SELECT la.id,la.case_id,la.action_type,la.created_at,la.metadata_json,u.display_name user_name FROM ' . Biawin_CRM_DB::table('case_actions') . " la LEFT JOIN {$wpdb->users} u ON u.ID=la.user_id WHERE la.id IN (SELECT MAX(id) FROM " . Biawin_CRM_DB::table('case_actions') . ' GROUP BY case_id)') as $lr) {
            $cid = (int)$lr->case_id;
            if (!isset($last_map[$cid]) || (int)$lr->id > (int)$last_map[$cid]->id) $last_map[$cid] = $lr;
        }
        $case_headers = ['Case','CustomerID','نام مشتری','موبایل','مدیر فروش','مبلغ اعلامی','مجموع فاکتور','مبلغ اختلاف','وضعیت','مرحله اقدام','ریز وضعیت','تاریخ آخرین اقدام','ساعت آخرین اقدام','آخرین اقدام‌کننده','کارشناس رفع ناقصی','کارشناس مالی','نتیجه مالی','وضعیت عودت','تصمیم نهایی','تاریخ ورود','تاریخ تکمیل'];
        $case_rows = [];
        foreach ($cases as $r) {
            $du = $r->deficiency_agent_id ? get_user_by('id', (int)$r->deficiency_agent_id) : null;
            $fu = $r->finance_agent_id ? get_user_by('id', (int)$r->finance_agent_id) : null;
            $last = $last_map[(int)$r->id] ?? null;
            $last_meta = $last ? json_decode((string)$last->metadata_json, true) : [];
            if (!is_array($last_meta)) $last_meta = [];
            $last_sub = $last ? $sub_of_meta($last_meta) : '';
            $last_step = $last ? $step_of((string)$last->action_type) : '';
            $last_at = $last ? (string)$last->created_at : '';
            $case_rows[] = [(string)$r->case_code, (string)($r->source_customer_id ?: $r->customer_code ?: $r->customer_id), (string)$r->display_name, (string)$r->phone, (string)$r->sales_manager, $num($r->reported_amount), $num($r->registered_invoice_amount), $num($r->difference_amount), (string)(self::status_labels()[$r->status] ?? $r->status), $last_step, $last_sub, $last_at !== '' ? substr($last_at, 0, 10) : '', $last_at !== '' ? substr($last_at, 11, 8) : '', $last ? (string)($last->user_name ?? '') : '', $du ? $du->display_name : '', $fu ? $fu->display_name : '', (string)(self::finance_result_labels()[$r->finance_result] ?? $r->finance_result), !empty($refund_map[(int)$r->id]) ? 'عودت شده' : '', (string)$r->final_decision, (string)$r->entered_at, (string)$r->completed_at];
        }

        // --- شیت ۲: اقدام‌ها (رویداد + ریز وضعیت) ---
        $actions = (array)$wpdb->get_results('SELECT a.*,u.display_name user_name,d.case_code,c.source_customer_id,c.customer_code,c.display_name customer_name FROM ' . Biawin_CRM_DB::table('case_actions') . " a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id LEFT JOIN " . Biawin_CRM_DB::table('deficiency_cases') . ' d ON d.id=a.case_id LEFT JOIN ' . Biawin_CRM_DB::table('customers') . " c ON c.id=a.customer_id ORDER BY a.id DESC LIMIT 20000");
        $actions = array_reverse($actions); // نمایش زمانی صعودی
        $action_headers = ['Case','CustomerID','مشتری','رویداد','مرحله اقدام','از وضعیت','به وضعیت','کاربر','زمان','زیر وضعیت / نتیجه','مبلغ ادعایی مشتری','اختلاف تاییدشده','مبلغ اعلامی اصلاح‌شده','مبلغ تایید نهایی','مبلغ فاکتور برگشتی','شماره فاکتور برگشتی','شماره تماس ثبت‌شده','وضعیت عودت','تعداد فیش','جمع مبلغ فیش‌ها','تاریخ پیگیری','توضیحات'];
        $action_rows = [];
        $pivot = [];
        foreach ($actions as $a) {
            $meta = json_decode((string)$a->metadata_json, true);
            if (!is_array($meta)) $meta = [];
            $sub = $sub_of_meta($meta);
            $row = [
                (string)$a->case_code,
                (string)($a->source_customer_id ?: $a->customer_code),
                (string)$a->customer_name,
                (string)self::event_label((string)$a->action_type),
                $step_of((string)$a->action_type),
                (string)(self::status_labels()[$a->from_status] ?? (string)$a->from_status),
                (string)(self::status_labels()[$a->to_status] ?? (string)$a->to_status),
                (string)$a->user_name,
                (string)$a->created_at,
                $sub,
                $num($meta['customer_claimed_amount'] ?? ''),
                $num($meta['confirmed_difference_amount'] ?? ''),
                $num($meta['corrected_declared_paid'] ?? ''),
                $num($meta['final_approved_amount'] ?? ''),
                $num($meta['invoice_amount'] ?? ''),
                (string)($meta['invoice_number'] ?? ''),
                (string)($meta['customer_phone'] ?? ''),
                isset($meta['refund_status']) && $meta['refund_status'] === 'refunded' ? 'عودت شده' : '',
                isset($meta['receipt_count']) && $meta['receipt_count'] !== '' ? (int)$meta['receipt_count'] : '',
                $num($meta['receipt_amount'] ?? ''),
                (string)($meta['follow_up_date'] ?? ''),
                (string)($a->description ?: ($meta['description'] ?? '')),
            ];
            $action_rows[] = $row;
            $pivot_key = $row[3] . ' | ' . ($sub !== '' ? $sub : '—');
            $pivot[$pivot_key] = ($pivot[$pivot_key] ?? 0) + 1;
        }
        $summary_headers = ['رویداد | زیر وضعیت','تعداد'];
        $summary_rows = [];
        arsort($pivot);
        foreach ($pivot as $key => $count) $summary_rows[] = [(string)$key, (int)$count];

        self::xlsx_write_multi('biawin-deficiency-workflow-' . gmdate('Ymd-His') . '.xlsx', [
            'پرونده‌ها' => ['name' => 'پرونده‌ها', 'headers' => $case_headers, 'rows' => $case_rows],
            'اقدام‌ها' => ['name' => 'اقدام‌ها', 'headers' => $action_headers, 'rows' => $action_rows],
            'خلاصه' => ['name' => 'خلاصه', 'headers' => $summary_headers, 'rows' => $summary_rows],
        ]);
    }

    /** v0.8.62.17: نویسنده XLSX چندشیتی (بدون وابستگی خارجی، مثل xlsx_write پرتال). */
    private static function xlsx_write_multi(string $file_name, array $sheets): void
    {
        $esc = static function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $col_name = static function (int $i): string {
            $s = '';
            for (++$i; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
            return $s;
        };
        $sheet_xml = static function (array $headers, array $rows) use ($esc, $col_name): string {
            $row_xml = static function (int $rn, array $cells) use ($esc, $col_name): string {
                $x = '<row r="' . $rn . '">';
                foreach ($cells as $i => $v) {
                    $ref = $col_name($i) . $rn;
                    if (is_int($v) || is_float($v)) $x .= '<c r="' . $ref . '"><v>' . $esc($v) . '</v></c>';
                    else $x .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $esc($v) . '</t></is></c>';
                }
                return $x . '</row>';
            };
            $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $row_xml(1, $headers);
            $rn = 1;
            foreach ($rows as $row) $xml .= $row_xml(++$rn, array_values($row));
            return $xml . '</sheetData></worksheet>';
        };
        $safe_name = static function (string $name, int $idx): string {
            $name = trim(preg_replace('/[\\[\]\:\*\?\/\\\\]/', ' ', $name));
            if ($name === '') $name = 'Sheet' . $idx;
            return mb_substr($name, 0, 31);
        };
        // اسم شیت می‌تواند کلید آرایه باشد یا فیلد name.
        $normalized = [];
        foreach ($sheets as $key => $sheet) {
            if (!is_array($sheet)) continue;
            if (!isset($sheet['name'])) $sheet['name'] = (string)$key;
            $normalized[] = $sheet;
        }
        $sheets = $normalized;
        $content_types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $wb_sheets = '';
        $wb_rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $content_types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb_sheets .= '<sheet name="' . $esc($safe_name((string)($sheet['name'] ?? ('Sheet' . $n)), $n)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wb_rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $content_types .= '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $wb = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb_sheets . '</sheets></workbook>';
        $wb_rels .= '</Relationships>';
        // v0.8.63.2 — build the file FIRST (private temp dir, checked writes, guaranteed cleanup),
        // send headers only when it is complete. Previously tempnam() could return false on a full
        // disk and ZipArchive::open(false) is a TypeError fatal in PHP 8; close() was never checked.
        Biawin_CRM_File_Safety::quiet_output();
        Biawin_CRM_File_Safety::sweep_stale();
        $parts = ['[Content_Types].xml' => $content_types, '_rels/.rels' => $rels, 'xl/workbook.xml' => $wb, 'xl/_rels/workbook.xml.rels' => $wb_rels];
        foreach ($sheets as $i => $sheet) {
            $parts['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $sheet_xml((array)($sheet['headers'] ?? []), (array)($sheet['rows'] ?? []));
        }
        $tmp_dir = Biawin_CRM_File_Safety::make_temp_dir('workflow-xlsx');
        $xlsx = $tmp_dir !== '' ? Biawin_CRM_File_Safety::build_zip($tmp_dir, 'report.xlsx', $parts) : '';
        if ($xlsx === '') {
            if ($tmp_dir !== '') Biawin_CRM_File_Safety::remove($tmp_dir);
            Biawin_CRM_DB::log('export', null, 'workflow_excel_export_failed', null, ['file' => $file_name]);
            wp_die('ساخت فایل اکسل گزارش ممکن نشد (فضای موقت سرور در دسترس نیست یا پر است). لطفاً بعداً دوباره تلاش کنید یا خروجی CSV را بگیرید.', 'خروجی گزارش', ['response' => 503]);
        }
        Biawin_CRM_File_Safety::send_file($xlsx, trim($file_name, '"'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        Biawin_CRM_File_Safety::remove($tmp_dir);
        exit;
    }

    private static function tab_where(string $tab, bool $finance, string $alias): array
    {
        $map = $finance ? [
            'new'=>["$alias.status='FINANCE_REVIEW_PENDING'", []],
            'assigned'=>["$alias.status='FINANCE_ASSIGNED'", []],
            'review'=>["$alias.status='FINANCE_UNDER_REVIEW'", []],
            'approved'=>["$alias.finance_result IN ('with_invoice','without_invoice')", []],
            'rejected'=>["$alias.finance_result='rejected'", []],
            'returned'=>["$alias.status='RETURNED_TO_DEFICIENCY'", []],
        ] : [
            'new'=>["$alias.status='INCOMPLETE_NEW'", []],
            'assigned'=>["$alias.status='INCOMPLETE_ASSIGNED'", []],
            'review'=>["$alias.status='DEFICIENCY_UNDER_REVIEW'", []],
            'contact'=>["$alias.status='CUSTOMER_CONTACT_PENDING'", []],
            'finance'=>["$alias.status IN ('FINANCE_REVIEW_PENDING','FINANCE_ASSIGNED','FINANCE_UNDER_REVIEW')", []],
            'returned'=>["$alias.status='RETURNED_TO_DEFICIENCY'", []],
            'decision'=>["$alias.status='READY_FOR_FINAL_DECISION'", []],
            'completed'=>["$alias.status='COMPLETED'", []],
            'archived'=>["$alias.status='ARCHIVED'", []],
        ];
        return isset($map[$tab]) ? [[ $map[$tab][0] ], $map[$tab][1]] : [["$alias.status NOT IN ('COMPLETED','ARCHIVED')"], []];
    }

    private static function next_case_code(): string
    {
        global $wpdb;
        $id = (int)$wpdb->get_var('SELECT COALESCE(MAX(id),0)+1 FROM ' . Biawin_CRM_DB::table('deficiency_cases'));
        return 'DEF-' . date_i18n('Y') . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    }

    private static function event_label(string $event): string
    {
        $labels = [
            'PROFILE_MARKED_INCOMPLETE'=>'پروفایل ناقص وارد Workflow شد',
            'DEFICIENCY_CASE_BACKFILLED'=>'پرونده رفع ناقصی برای پروفایل ناقص قدیمی ساخته شد',
            'DEFICIENCY_ASSIGNED'=>'پرونده رفع ناقصی تخصیص داده شد',
            'CUSTOMER_CONTACT_ATTEMPT'=>'تماس با مشتری ثبت شد',
            'DEFICIENCY_RESULT_RECORDED'=>'نتیجه رفع ناقصی ثبت شد',
            'CASE_SENT_TO_FINANCE'=>'پرونده به مالی ارسال شد',
            'FINANCE_ASSIGNED'=>'پرونده مالی تخصیص داده شد',
            'FINANCE_APPROVED_WITH_INVOICE'=>'تأیید مالی با فاکتور ثبت شد',
            'FINANCE_APPROVED_WITHOUT_INVOICE'=>'تأیید مالی بدون فاکتور ثبت شد',
            'FINANCE_REJECTED'=>'عدم تأیید مالی ثبت شد',
            'CASE_RETURNED_FROM_FINANCE'=>'پرونده از مالی برگشت داده شد',
            'CASE_COMPLETED'=>'پرونده تکمیل شد',
            'CASE_ARCHIVED'=>'پرونده بایگانی شد',
            'DOCUMENT_UPLOADED'=>'مدرک پرونده آپلود شد',
            'RETURNED_INVOICE_RECORDED'=>'فاکتور برگشتی از مالی ثبت شد',
            'CASE_AUTO_COMPLETED_BY_TOLERANCE'=>'پرونده به دلیل اختلاف مجاز تکمیل شد',
            'CASE_COMPLETED_WITH_CORRECTED_DECLARED'=>'پرونده با اصلاح مبلغ اعلامی تکمیل شد',
            'CASE_AUTO_COMPLETED_AFTER_FINANCE'=>'پرونده بعد از تایید مالی خودکار تکمیل شد',
            'RECEIPT_APPROVED'=>'فیش توسط مالی تأیید شد',
            'RECEIPT_REJECTED'=>'فیش توسط مالی رد شد',
            'RECEIPT_REVIEW_RESET'=>'بررسی فیش به حالت «بررسی‌نشده» برگشت',
        ];
        return $labels[$event] ?? $event;
    }

    /** v0.8.62.28 — public wrappers for the finance receipt-review modal (Biawin_CRM_Finance_Review). */
    public static function label_for_event(string $event): string
    {
        return self::event_label($event);
    }

    /** MySQL datetime → «1405/07/07 10:01» (Jalali). Empty/invalid → '-'. */
    public static function jalali_datetime(?string $mysql): string
    {
        $ts = $mysql ? strtotime((string)$mysql) : false;
        if (!$ts) return '-';
        [$jy, $jm, $jd] = self::gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i', $ts));
    }

    private static function money($value): string
    {
        if ($value === null || $value === '') return '-';
        return number_format_i18n((float)$value);
    }

    /** v0.8.62.21: میلادی → شمسی برای نمایش تاریخ‌های ذخیره‌شده در Timeline/جزئیات. */
    private static function gregorian_to_jalali(int $gy, int $gm, int $gd): array
    {
        $gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053)); $days %= 12053;
        $jy += 4 * intdiv($days, 1461); $days %= 1461;
        if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
        if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
        else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
        return [$jy, $jm, $jd];
    }

    private static function maybe_notice(): void
    {
        if (!empty($_GET['bcrm_message'])) echo '<div class="notice notice-success inline"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bcrm_message']))) . '</p></div>';
        if (!empty($_GET['bcrm_error'])) echo '<div class="notice notice-error inline"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bcrm_error']))) . '</p></div>';
    }
}
