<?php

if (!defined('ABSPATH')) {
    exit;
}

class Biawin_CRM_Workflow_Service
{
    public const PROFILE_PENDING = 'pending';
    public const PROFILE_INCOMPLETE = 'incomplete';
    public const PROFILE_COMPLETE = 'complete';

    public static function default_contact_outcomes(): array
    {
        return [
            ['key'=>'success','label'=>'تماس موفق','operational_status'=>'contact_success','active'=>1,'sort'=>10],
            ['key'=>'no_answer','label'=>'عدم پاسخ','operational_status'=>'no_answer','active'=>1,'sort'=>20],
            ['key'=>'powered_off','label'=>'خاموش','operational_status'=>'powered_off','active'=>1,'sort'=>30],
            ['key'=>'unavailable','label'=>'در دسترس نیست','operational_status'=>'unavailable','active'=>1,'sort'=>40],
            ['key'=>'callback','label'=>'درخواست تماس مجدد','operational_status'=>'callback_requested','active'=>1,'sort'=>50],
            ['key'=>'followup','label'=>'نیازمند پیگیری','operational_status'=>'followup_needed','active'=>1,'sort'=>60],
            ['key'=>'wrong_number','label'=>'شماره اشتباه','operational_status'=>'wrong_number','active'=>1,'sort'=>70],
            ['key'=>'other_person','label'=>'شماره متعلق به شخص دیگر','operational_status'=>'other_person_number','active'=>1,'sort'=>80],
            ['key'=>'refused','label'=>'امتناع از پاسخ','operational_status'=>'refused','active'=>1,'sort'=>90],
            ['key'=>'refund_reported','label'=>'عودت شده است','operational_status'=>'refund_reported','active'=>1,'sort'=>95],
        ];
    }

    public static function contact_outcomes(bool $active_only = false): array
    {
        $stored = get_option('biawin_crm_contact_outcomes', null);
        $items = is_array($stored) && $stored ? $stored : self::default_contact_outcomes();
        $clean = [];
        $seen = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) continue;
            $key = sanitize_key((string)($item['key'] ?? ''));
            $label = sanitize_text_field((string)($item['label'] ?? ''));
            if ($key === '' || $label === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $status = sanitize_key((string)($item['operational_status'] ?? ''));
            if ($status === '') $status = substr('contact_' . $key, 0, 40);
            $active = !empty($item['active']) ? 1 : 0;
            $sort = isset($item['sort']) ? (int)$item['sort'] : (($idx + 1) * 10);
            $clean[] = ['key'=>$key,'label'=>$label,'operational_status'=>$status,'active'=>$active,'sort'=>$sort];
        }
        usort($clean, static function($a,$b){ return ($a['sort'] <=> $b['sort']) ?: strcmp($a['label'],$b['label']); });
        if ($active_only) $clean = array_values(array_filter($clean, static fn($i)=>!empty($i['active'])));
        return $clean;
    }

    public static function contact_outcome_by_key(string $key): ?array
    {
        $key = sanitize_key($key);
        foreach (self::contact_outcomes(false) as $item) if ($item['key'] === $key) return $item;
        return null;
    }

    public static function contact_outcome_key_from_label(string $label): string
    {
        $label = trim($label);
        foreach (self::contact_outcomes(false) as $item) if ($item['label'] === $label) return $item['key'];
        foreach (self::default_contact_outcomes() as $item) if ($item['label'] === $label) return $item['key'];
        return '';
    }

    public static function contact_outcome_label(string $key, string $fallback = ''): string
    {
        $item = self::contact_outcome_by_key($key);
        return $item ? $item['label'] : $fallback;
    }

    public static function settings(): array
    {
        return [
            'credit_multiplier' => (float) get_option('biawin_crm_credit_multiplier', 2.5),
            'credit_floor' => (float) get_option('biawin_crm_credit_floor', 200000000),
            'credit_cap' => (float) get_option('biawin_crm_credit_cap', 1500000000),
            'reconciliation_tolerance' => max(500000, (float) get_option('biawin_crm_reconciliation_tolerance', 500000)),
        ];
    }

    /**
     * v0.8.66.1 — D-01: the business rule is COMPLETE ⟺ declared amount = invoice total (mismatch 0).
     * Fresh installs run 'zero'. An existing install keeps 'legacy_tolerance' until the transition is approved
     * (switching changes stored profiles lazily on recalculation — see «گزارش‌های مسیر مشتری»). No other value.
     */
    public const COMPLETENESS_RULE_OPTION = 'biawin_crm_completeness_rule';

    public static function completeness_rule(): string
    {
        return get_option(self::COMPLETENESS_RULE_OPTION, '') === 'zero' ? 'zero' : 'legacy_tolerance';
    }

    /** Allowed absolute mismatch for COMPLETE under the active rule (0 under 'zero'). */
    public static function completeness_tolerance(): float
    {
        return self::completeness_rule() === 'zero' ? 0.0 : max(500000, (float) get_option('biawin_crm_reconciliation_tolerance', 500000));
    }

    /** declared vs invoice total → complete? (0.004 absorbs DECIMAL rounding only). */
    public static function amounts_match(float $declared, float $invoice_total): bool
    {
        return abs($declared - $invoice_total) <= self::completeness_tolerance() + 0.004;
    }

    public static function calculate_credit(float $verified_total): float
    {
        if ($verified_total <= 0) return 0.0;
        $s = self::settings();
        $raw = $verified_total * max(0, $s['credit_multiplier']);
        return min(max($raw, max(0, $s['credit_floor'])), max($s['credit_floor'], $s['credit_cap']));
    }

    public static function sync_customer(int $customer_id): bool
    {
        global $wpdb;
        $customer = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Biawin_CRM_DB::table('customers') . ' WHERE id = %d',
            $customer_id
        ));
        if (!$customer) {
            return false;
        }

        $total = (float) $customer->total_paid;
        $declared = $customer->declared_paid === null ? null : (float) $customer->declared_paid;
        $profile = self::PROFILE_PENDING;
        if ($declared !== null) {
            $profile = self::amounts_match($declared, $total) ? self::PROFILE_COMPLETE : self::PROFILE_INCOMPLETE;
        }

        $credit_total = self::calculate_credit($total);
        $reserved = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(credit_amount),0) FROM " . Biawin_CRM_DB::table('service_cases') . " WHERE customer_id=%d AND status IN ('unassigned','assigned','in_progress','waiting')",
            $customer_id
        ));
        $consumed = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(credit_amount),0) FROM " . Biawin_CRM_DB::table('service_cases') . " WHERE customer_id=%d AND status='completed'",
            $customer_id
        ));
        $remaining = $credit_total - $reserved - $consumed;

        $updated = $wpdb->update(Biawin_CRM_DB::table('customers'), [
            'profile_completeness' => $profile,
            'service_credit_total' => $credit_total,
            'service_credit_reserved' => $reserved,
            'service_credit_consumed' => $consumed,
            'service_credit_remaining' => $remaining,
            'updated_at' => Biawin_CRM_DB::now(),
        ], ['id' => $customer_id]);
        if ($updated === false) return false;

        if ($profile === self::PROFILE_INCOMPLETE) {
            if (!self::ensure_financial_case($customer_id, $declared, $total)) return false;
            if (class_exists('Biawin_CRM_Deficiency_Workflow') && !Biawin_CRM_Deficiency_Workflow::ensure_case_for_customer($customer_id, $declared, $total)) return false;
        } elseif ($profile === self::PROFILE_COMPLETE) {
            if (!self::close_open_financial_cases($customer_id)) return false;
        }
        return true;
    }

    public static function ensure_financial_case(int $customer_id, ?float $declared = null, ?float $system_total = null): int
    {
        global $wpdb;
        $table = Biawin_CRM_DB::table('financial_cases');
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE customer_id=%d AND status NOT IN ('resolved','closed','cancelled') ORDER BY id DESC LIMIT 1",
            $customer_id
        ));
        if ($existing) {
            $updated = $wpdb->update($table, [
                'declared_amount' => $declared,
                'system_amount' => $system_total,
                'difference_amount' => ($declared === null || $system_total === null) ? null : $declared - $system_total,
                'updated_at' => Biawin_CRM_DB::now(),
            ], ['id' => $existing]);
            return $updated === false ? 0 : $existing;
        }
        $id = self::insert_case_with_retry($table, 'case_code', 'FIN', [
            'customer_id' => $customer_id,
            'declared_amount' => $declared,
            'system_amount' => $system_total,
            'difference_amount' => ($declared === null || $system_total === null) ? null : $declared - $system_total,
            'status' => 'new',
            'created_by' => get_current_user_id(),
            'created_at' => Biawin_CRM_DB::now(),
            'updated_at' => Biawin_CRM_DB::now(),
        ]);
        if (!$id) return 0;
        self::activity($customer_id, 'financial_case_created', 'پرونده تکمیل مالی ایجاد شد.', 'financial_case', $id);
        return $id;
    }

    private static function close_open_financial_cases(int $customer_id): bool
    {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE " . Biawin_CRM_DB::table('financial_cases') . " SET status='resolved', resolved_by=%d, resolved_at=%s, updated_at=%s WHERE customer_id=%d AND status NOT IN ('resolved','closed','cancelled')",
            get_current_user_id() ?: 0,
            Biawin_CRM_DB::now(),
            Biawin_CRM_DB::now(),
            $customer_id
        ));
        return $result !== false;
    }

    public static function set_customer_journey(int $customer_id, string $journey, string $satisfaction = '', string $note = ''): array
    {
        global $wpdb;
        $allowed = ['no_request', 'complaint', 'service_request'];
        if (!in_array($journey, $allowed, true)) {
            return ['ok' => false, 'message' => 'مسیر انتخاب‌شده معتبر نیست.'];
        }
        if (!self::sync_customer($customer_id)) return ['ok'=>false,'message'=>'بروزرسانی وضعیت پروفایل انجام نشد. دوباره تلاش کنید.'];
        $customer = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Biawin_CRM_DB::table('customers') . ' WHERE id=%d', $customer_id));
        if (!$customer) {
            return ['ok' => false, 'message' => 'پروفایل مشتری پیدا نشد.'];
        }
        // Business rule: financial mismatch must NOT block choosing the customer's journey.
        // Once declared amount is saved, an incomplete profile may keep its selected journey while
        // simultaneously entering financial verification. Service fulfillment still remains gated
        // until finance resolves the mismatch and the profile becomes complete.
        if ($customer->profile_completeness === self::PROFILE_PENDING) {
            return ['ok' => false, 'message' => 'ابتدا مبلغ اعلامی مشتری را وارد و ثبت کنید.'];
        }
        if ($customer->profile_completeness === self::PROFILE_INCOMPLETE) {
            $financial_case_id = self::ensure_financial_case($customer_id, (float)$customer->declared_paid, (float)$customer->total_paid);
            if (!$financial_case_id) {
                return ['ok' => false, 'message' => 'مسیر انتخاب شدنی است اما ایجاد پرونده تأیید مالی ناموفق بود؛ دوباره تلاش کنید.'];
            }
        }
        $updated = $wpdb->update(Biawin_CRM_DB::table('customers'), [
            'journey_state' => $journey,
            'satisfaction_state' => sanitize_key($satisfaction),
            'updated_at' => Biawin_CRM_DB::now(),
        ], ['id' => $customer_id]);
        if ($updated === false) {
            return ['ok' => false, 'message' => 'ذخیره مسیر مشتری در دیتابیس انجام نشد.'];
        }
        self::activity($customer_id, 'journey_changed', $note ?: 'مسیر عملیاتی مشتری تعیین شد.', 'customer', $customer_id, ['journey' => $journey, 'satisfaction' => $satisfaction, 'profile_completeness' => (string)$customer->profile_completeness]);
        return [
            'ok' => true,
            'profile_completeness' => (string)$customer->profile_completeness,
            'message' => $customer->profile_completeness === self::PROFILE_INCOMPLETE
                ? 'مسیر مشتری ثبت شد؛ پروفایل با وضعیت ناقص برای تأیید مالی ارسال شده است.'
                : 'مسیر مشتری ثبت شد.',
        ];
    }

    // ------------------------------------------------------------------ v0.8.66.0 — Core Customer Journey (Master Phase 2)

    /** The three fixed Customer Routes (keys unchanged in the DB). Not an admin catalog. */
    public static function route_labels(): array
    {
        return ['complaint' => 'ناراضی', 'no_request' => 'بدون خدمات', 'service_request' => 'خدمات مازاد'];
    }

    /** Activity written once per completed deficiency case; drives «منتظر تخصیص» → «نیازمند تماس جدید». */
    public const REALLOCATION_MARKER = 'deficiency_reallocation_required';

    /** Latest post-deficiency reallocation marker (only completions on/after v0.8.66.0 have one). */
    public static function reallocation_marker(int $customer_id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, object_id, created_at FROM ' . Biawin_CRM_DB::table('activities') . ' WHERE customer_id=%d AND activity_type=%s ORDER BY id DESC LIMIT 1',
            $customer_id, self::REALLOCATION_MARKER
        ));
        return $row ?: null;
    }

    public static function active_assignment(int $customer_id): ?object
    {
        global $wpdb;
        $a = Biawin_CRM_DB::table('assignments');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $a WHERE id=(SELECT MAX(ax.id) FROM $a ax WHERE ax.customer_id=%d AND ax.status='assigned')", $customer_id));
        return $row ?: null;
    }

    /**
     * Where the customer stands on the locked journey, and whether $user_id may select the Customer Route now.
     * States: pending (no declared amount) · incomplete (رفع ناقصی) · awaiting_reallocation (تکمیل‌شده پس از رفع
     * ناقصی — منتظر تخصیص) · new_contact_required · open.
     * Actor rule (v0.8.66.1, D-07): ONLY the active assigned contact agent. Route selection is not part of
     * bcrm_manage_cases (that capability is untouched; only this gate stopped honouring it).
     * D-08: an open deficiency case locks the route even if the calculation currently shows COMPLETE.
     * New contact (after deficiency reallocation): an interaction by the assignee after the reassignment whose
     * contact outcome is a successful contact (operational_status contact_success) — conservative.
     */
    public static function journey_gate(int $customer_id, int $user_id = 0): array
    {
        global $wpdb;
        $user_id = $user_id ?: get_current_user_id();
        $c = $wpdb->get_row($wpdb->prepare('SELECT id, profile_completeness, journey_state FROM ' . Biawin_CRM_DB::table('customers') . ' WHERE id=%d', $customer_id));
        $gate = ['state' => 'pending', 'can_select' => false, 'message' => '', 'after_deficiency' => false, 'active_agent_id' => 0, 'assigned_at' => null, 'marker_at' => null];
        if (!$c) { $gate['message'] = 'پروفایل مشتری پیدا نشد.'; return $gate; }
        $gate['after_deficiency'] = (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Biawin_CRM_DB::table('customer_tags') . ' WHERE customer_id=%d AND tag=%s LIMIT 1', $customer_id, 'COMPLETED_AFTER_DEFICIENCY'));
        $active = self::active_assignment($customer_id);
        if ($active) { $gate['active_agent_id'] = (int)$active->agent_user_id; $gate['assigned_at'] = (string)$active->assigned_at; }
        if ($c->profile_completeness === self::PROFILE_PENDING) {
            $gate['message'] = 'ابتدا مبلغ اعلامی مشتری را در فرم تماس ثبت کنید.';
            return $gate;
        }
        if ($c->profile_completeness !== self::PROFILE_COMPLETE) {
            $gate['state'] = 'incomplete';
            $gate['message'] = 'پروفایل ناقص است و در رفع ناقصی قرار دارد؛ تا کامل شدن پروفایل، مسیر مشتری قابل انتخاب نیست.';
            return $gate;
        }
        $open_case = class_exists('Biawin_CRM_Deficiency_Workflow') ? (string)$wpdb->get_var($wpdb->prepare('SELECT status FROM ' . Biawin_CRM_DB::table('deficiency_cases') . " WHERE customer_id=%d AND status NOT IN ('COMPLETED','ARCHIVED') ORDER BY id DESC LIMIT 1", $customer_id)) : '';
        if ($open_case !== '') {
            $gate['state'] = 'deficiency_open';
            $gate['message'] = 'پرونده رفع ناقصی این مشتری هنوز باز است؛ تا تکمیل رسمی آن در رفع ناقصی، مسیر مشتری قابل انتخاب نیست.';
            return $gate;
        }
        $marker = self::reallocation_marker($customer_id);
        $post_deficiency = false;
        if ($marker) {
            $gate['marker_at'] = (string)$marker->created_at;
            if (!$active || strcmp((string)$active->assigned_at, (string)$marker->created_at) < 0) {
                $gate['state'] = 'awaiting_reallocation';
                $gate['message'] = 'تکمیل‌شده پس از رفع ناقصی — منتظر تخصیص توسط سرپرست.';
                return $gate;
            }
            $success_keys = self::successful_contact_keys();
            $contact = $success_keys ? (int)$wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . Biawin_CRM_DB::table('interactions') . ' WHERE customer_id=%d AND agent_user_id=%d AND created_at>=%s AND created_at>=%s AND outcome_key IN (' . implode(',', array_fill(0, count($success_keys), '%s')) . ') LIMIT 1',
                array_merge([$customer_id, (int)$active->agent_user_id, (string)$active->assigned_at, (string)$marker->created_at], $success_keys)
            )) : 0;
            if (!$contact) {
                $gate['state'] = 'new_contact_required';
                $gate['message'] = 'تکمیل‌شده پس از رفع ناقصی — کارشناس تخصیص‌یافته باید ابتدا یک تماس جدید موفق با مشتری ثبت کند.';
                return $gate;
            }
            $post_deficiency = true;
        }
        $gate['state'] = 'open';
        $gate['can_select'] = $active && (int)$active->agent_user_id === $user_id;
        $gate['message'] = $gate['can_select'] ? 'آماده انتخاب مسیر مشتری'
            : ($post_deficiency ? 'مسیر این مشتری فقط توسط کارشناس تماس تخصیص‌یافته در تماس جدید انتخاب می‌شود.' : 'مسیر مشتری فقط توسط کارشناس تماس تخصیص‌یافته انتخاب می‌شود.');
        return $gate;
    }

    /** Contact outcomes that mean the customer was actually reached (operational_status contact_success). */
    public static function successful_contact_keys(): array
    {
        $keys = [];
        foreach (self::contact_outcomes(false) as $o) if (($o['operational_status'] ?? '') === 'contact_success') $keys[] = (string)$o['key'];
        return $keys;
    }

    /** Server-side guard used by every route-selecting entry point (UI, AJAX, REST). */
    public static function assert_route_selectable(int $customer_id): array
    {
        $gate = self::journey_gate($customer_id);
        return $gate['can_select'] ? ['ok' => true, 'gate' => $gate] : ['ok' => false, 'message' => $gate['message'] ?: 'انتخاب مسیر مشتری مجاز نیست.', 'gate' => $gate];
    }

    /**
     * The ONLY route-selection entry point for the contact flow. ناراضی hands off to after-sales by creating the
     * complaint (existing flow); بدون خدمات ends the active cycle in the searchable archive; خدمات مازاد marks the
     * route — services are then selected via select_services(), which records the integration boundary.
     */
    public static function select_customer_route(int $customer_id, string $route, array $data = []): array
    {
        if (!isset(self::route_labels()[$route])) return ['ok' => false, 'message' => 'مسیر انتخاب‌شده معتبر نیست.'];
        if (!self::sync_customer($customer_id)) return ['ok' => false, 'message' => 'بروزرسانی وضعیت پروفایل انجام نشد. دوباره تلاش کنید.'];
        $check = self::assert_route_selectable($customer_id);
        if (empty($check['ok'])) return $check;
        if ($route === 'complaint') {
            if (trim((string)($data['description'] ?? '')) === '') return ['ok' => false, 'message' => 'برای مسیر «ناراضی»، فرم ثبت نارضایتی (موضوع و شرح) را تکمیل کنید تا پرونده به خدمات پس از فروش ارجاع شود.'];
            return self::create_complaint($customer_id, $data);
        }
        $satisfaction = $route === 'no_request' ? sanitize_key((string)($data['satisfaction'] ?? '')) : '';
        $result = self::set_customer_journey($customer_id, $route, $satisfaction, 'مسیر مشتری «' . self::route_labels()[$route] . '» توسط کارشناس تماس انتخاب شد.');
        if (!empty($result['ok']) && $route === 'no_request') {
            self::activity($customer_id, 'no_service_archived', 'مسیر «بدون خدمات»: چرخه فعال پایان یافت و پرونده در بایگانی بدون خدمات قرار گرفت.', 'customer', $customer_id, ['satisfaction' => $satisfaction, 'complaint_created' => false, 'biawin_request' => false]);
        }
        return $result;
    }

    /**
     * Called once by every deficiency completion path (same customer_id, invoices, profile). Re-uses the existing
     * tag, ends the previous contact assignment with the existing «returned» status (same effect and audit as the
     * supervisor's «برگشت تخصیص») and writes the reallocation marker. Never selects a route. Idempotent per case.
     */
    public static function on_deficiency_completed(int $customer_id, int $case_id, string $source): void
    {
        global $wpdb;
        if ($customer_id <= 0 || $case_id <= 0) return;
        $activities = Biawin_CRM_DB::table('activities');
        $done = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $activities WHERE customer_id=%d AND activity_type=%s AND object_type='deficiency_case' AND object_id=%d LIMIT 1", $customer_id, self::REALLOCATION_MARKER, $case_id));
        if ($done) return;
        $profile = (string)$wpdb->get_var($wpdb->prepare('SELECT profile_completeness FROM ' . Biawin_CRM_DB::table('customers') . ' WHERE id=%d', $customer_id));
        if ($profile !== self::PROFILE_COMPLETE) return; // business completion point = discrepancy resolved AND profile complete
        if (class_exists('Biawin_CRM_Deficiency_Workflow')) Biawin_CRM_Deficiency_Workflow::tag_customer($customer_id, 'COMPLETED_AFTER_DEFICIENCY', $case_id);

        $assignments = Biawin_CRM_DB::table('assignments');
        $customers = Biawin_CRM_DB::table('customers');
        $now = Biawin_CRM_DB::now();
        $wpdb->query('START TRANSACTION');
        $wpdb->get_var($wpdb->prepare("SELECT id FROM $customers WHERE id=%d FOR UPDATE", $customer_id));
        $active = $wpdb->get_results($wpdb->prepare("SELECT id,agent_user_id FROM $assignments WHERE customer_id=%d AND status='assigned' FOR UPDATE", $customer_id));
        $ok = true;
        if ($active) {
            $ok = $wpdb->query($wpdb->prepare("UPDATE $assignments SET status='returned', completed_at=%s WHERE customer_id=%d AND status='assigned'", $now, $customer_id)) !== false
                && $wpdb->query($wpdb->prepare("UPDATE $customers SET status=CASE WHEN status='assigned' THEN 'new' ELSE status END, updated_at=%s WHERE id=%d", $now, $customer_id)) !== false;
        }
        if (!$ok) { $wpdb->query('ROLLBACK'); error_log('[biawin-crm] deficiency reallocation failed for customer ' . $customer_id); return; }
        $wpdb->query('COMMIT');
        $previous = array_values(array_unique(array_map(static fn($r) => (int)$r->agent_user_id, (array)$active)));
        if ($active) {
            $meta = ['previous_agent_user_ids' => $previous, 'returned_by' => get_current_user_id(), 'reason' => 'deficiency_completed', 'case_id' => $case_id];
            Biawin_CRM_DB::log('customer', $customer_id, 'assignment_returned', null, $meta);
            self::activity($customer_id, 'assignment_returned', 'پس از تکمیل رفع ناقصی، تخصیص تماس قبلی پایان یافت و پروفایل به صف تخصیص سرپرست بازگشت.', 'customer', $customer_id, $meta);
        }
        self::activity($customer_id, self::REALLOCATION_MARKER, 'تکمیل‌شده پس از رفع ناقصی — منتظر تخصیص سرپرست و تماس جدید.', 'deficiency_case', $case_id, ['source' => $source, 'previous_agent_user_ids' => $previous]);
        update_option('bcrm_dashboard_cache_buster', (string) microtime(true), false);
    }

    /** Customers completed after deficiency (v0.8.66.0+) who have no active assignment since completion. */
    public static function awaiting_reallocation_rows(int $limit = 200): array
    {
        global $wpdb;
        $c = Biawin_CRM_DB::table('customers'); $act = Biawin_CRM_DB::table('activities'); $a = Biawin_CRM_DB::table('assignments');
        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.display_name, c.source_customer_id, c.total_paid, c.declared_paid, m.marker_at
             FROM $c c INNER JOIN (SELECT customer_id, MAX(created_at) marker_at FROM $act WHERE activity_type=%s GROUP BY customer_id) m ON m.customer_id=c.id
             WHERE c.profile_completeness='complete'
               AND NOT EXISTS (SELECT 1 FROM $a x WHERE x.customer_id=c.id AND x.status='assigned' AND x.assigned_at>=m.marker_at)
             ORDER BY m.marker_at ASC, c.id ASC LIMIT %d",
            self::REALLOCATION_MARKER, $limit
        ));
    }

    /**
     * After a deficiency completion the reassigned agent's contact must be a NEW interaction: an edit of an
     * interaction created before the current assignment is stored as a new row instead.
     */
    public static function interaction_must_be_new(int $customer_id, string $existing_created_at): bool
    {
        if (!self::reallocation_marker($customer_id)) return false;
        $active = self::active_assignment($customer_id);
        return $active && strcmp($existing_created_at, (string)$active->assigned_at) < 0;
    }

    public static function create_complaint(int $customer_id, array $data): array
    {
        global $wpdb;
        if (!self::sync_customer($customer_id)) return ['ok'=>false,'message'=>'بروزرسانی وضعیت پروفایل انجام نشد. دوباره تلاش کنید.'];
        $customer = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Biawin_CRM_DB::table('customers') . ' WHERE id=%d', $customer_id));
        if (!$customer) {
            return ['ok' => false, 'message' => 'پروفایل مشتری پیدا نشد.'];
        }
        // v0.8.66.0 — a complaint created from the profile IS the selection of the «ناراضی» route (unless the
        // customer is already on that route): same gate as every route selection.
        if ((string)$customer->journey_state !== 'complaint') {
            $check = self::assert_route_selectable($customer_id);
            if (empty($check['ok'])) return $check;
        }
        // v0.8.64.0 — once admins configure complaint subjects, the subject must come from that
        // catalog (dropdown). The label is also stored as a snapshot in `subject`, so the record
        // keeps showing its original subject even if the item is later renamed or deactivated.
        // With no active subjects configured, the legacy free-text subject keeps working.
        $subject = sanitize_text_field($data['subject'] ?? 'شکایت مشتری');
        $subject_id = absint($data['subject_id'] ?? 0);
        if (class_exists('Biawin_CRM_After_Sales_Config')) {
            $subject_cat = Biawin_CRM_After_Sales_Config::CATALOG_SUBJECT;
            if ($subject_id) {
                $item = Biawin_CRM_After_Sales_Config::item($subject_id);
                if (!$item || (string)$item->catalog !== $subject_cat || !(int)$item->is_active) return ['ok' => false, 'message' => 'موضوع نارضایتی انتخاب‌شده معتبر یا فعال نیست.'];
                $subject = (string)$item->label;
            } elseif (Biawin_CRM_After_Sales_Config::items($subject_cat, true)) {
                return ['ok' => false, 'message' => 'موضوع نارضایتی را از فهرست انتخاب کنید.'];
            }
        }
        $row = [
            'customer_id' => $customer_id,
            'subject' => $subject,
            'related_unit' => sanitize_text_field($data['related_unit'] ?? ''),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'priority' => sanitize_key($data['priority'] ?? 'normal'),
            'owner_user_id' => isset($data['owner_user_id']) ? absint($data['owner_user_id']) : null,
            'status' => 'open',
            'created_at' => Biawin_CRM_DB::now(),
        ];
        if ($subject_id) $row['subject_id'] = $subject_id;
        $inserted = $wpdb->insert(Biawin_CRM_DB::table('complaints'), $row);
        $id = (int) $wpdb->insert_id;
        if ($inserted === false || !$id) {
            return ['ok' => false, 'message' => 'ثبت شکایت در دیتابیس انجام نشد.'];
        }
        $route = self::set_customer_journey($customer_id, 'complaint', '', 'شکایت مشتری ثبت شد.');
        if (empty($route['ok'])) {
            $wpdb->delete(Biawin_CRM_DB::table('complaints'), ['id' => $id]);
            return $route;
        }
        self::activity($customer_id, 'complaint_created', 'شکایت جدید ثبت شد.', 'complaint', $id);
        return ['ok' => true, 'id' => $id];
    }


    public static function can_manage_complaint(int $complaint_id, int $user_id = 0): bool
    {
        global $wpdb;
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return false;
        if (current_user_can('bcrm_manage_cases')) return true;
        $user = get_user_by('id', $user_id);
        if (!$user || !$user->has_cap('bcrm_complaints')) return false;
        $complaint = $wpdb->get_row($wpdb->prepare(
            'SELECT owner_user_id,status FROM ' . Biawin_CRM_DB::table('complaints') . ' WHERE id=%d',
            $complaint_id
        ));
        if (!$complaint || $complaint->status !== 'open') return false;
        if (Biawin_CRM_Auth::is_dissatisfaction_supervisor((array)$user->roles)) return true;
        return $complaint->owner_user_id === null || (int)$complaint->owner_user_id === $user_id;
    }

    /**
     * Outcome keys this flow can execute today (each has built-in behaviour below). Labels, order
     * and active state come from the outcome catalog; outcomes an admin adds get their actions in a
     * later phase (catalog meta_json), so the current form only offers handler-backed outcomes.
     */
    public static function complaint_outcome_handlers(): array
    {
        return ['satisfied', 'refund', 'service_request'];
    }

    /** [key => label] for the existing resolve form: active catalog outcomes that have a handler. */
    public static function complaint_resolution_options(): array
    {
        $fallback = ['satisfied' => 'مشتری راضی شد', 'service_request' => 'تبدیل به درخواست خدمت', 'refund' => 'درخواست عودت وجه'];
        if (!class_exists('Biawin_CRM_After_Sales_Config') || !Biawin_CRM_After_Sales_Config::table_ready('catalog_items')) return $fallback;
        $items = Biawin_CRM_After_Sales_Config::items(Biawin_CRM_After_Sales_Config::CATALOG_OUTCOME);
        if (!$items) return $fallback;
        $options = [];
        foreach ($items as $item) {
            if ((int)$item->is_active && in_array((string)$item->item_key, self::complaint_outcome_handlers(), true)) $options[(string)$item->item_key] = (string)$item->label;
        }
        return $options;
    }

    public static function resolve_complaint(int $complaint_id, string $outcome, string $note = ''): array
    {
        global $wpdb;
        $allowed = self::complaint_outcome_handlers();
        if (!in_array($outcome, $allowed, true)) return ['ok' => false, 'message' => 'نتیجه شکایت معتبر نیست.'];
        // v0.8.64.0 — an outcome the admin deactivated in «تنظیمات نارضایتی» is not accepted for new decisions.
        if (class_exists('Biawin_CRM_After_Sales_Config')) {
            $configured = Biawin_CRM_After_Sales_Config::item_by_key(Biawin_CRM_After_Sales_Config::CATALOG_OUTCOME, $outcome);
            if ($configured && !(int)$configured->is_active) return ['ok' => false, 'message' => 'این نتیجه غیرفعال شده است.'];
        }

        $wpdb->query('START TRANSACTION');
        try {
            $complaint = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Biawin_CRM_DB::table('complaints') . ' WHERE id=%d FOR UPDATE', $complaint_id));
            if (!$complaint) throw new RuntimeException('شکایت پیدا نشد.');
            if ((string)$complaint->status !== 'open') throw new RuntimeException('این پرونده نارضایتی قبلاً تعیین تکلیف شده است.');
            if ($outcome === 'refund') {
                $next = self::create_refund((int)$complaint->customer_id, ['complaint_id'=>$complaint_id,'reason'=>$note]);
            } elseif ($outcome === 'service_request') {
                $next = self::set_customer_journey((int)$complaint->customer_id, 'service_request', '', 'شکایت به درخواست خدمت تبدیل شد.');
            } else {
                $next = self::set_customer_journey((int)$complaint->customer_id, 'no_request', 'satisfied', 'مشتری در واحد شکایت راضی شد.');
            }
            if (empty($next['ok'])) throw new RuntimeException($next['message'] ?? 'ثبت نتیجه شکایت انجام نشد.');

            $updated = $wpdb->update(Biawin_CRM_DB::table('complaints'), [
                'resolution' => sanitize_textarea_field($note),
                'resolution_outcome' => $outcome,
                'status' => 'closed',
                'closed_at' => Biawin_CRM_DB::now(),
            ], ['id' => $complaint_id]);
            if ($updated === false) throw new RuntimeException('ذخیره نتیجه شکایت در دیتابیس انجام نشد.');

            self::activity((int)$complaint->customer_id, 'complaint_resolved', 'شکایت تعیین تکلیف شد.', 'complaint', $complaint_id, ['outcome'=>$outcome]);
            $wpdb->query('COMMIT');
            return ['ok'=>true];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return ['ok'=>false,'message'=>$e->getMessage() ?: 'ثبت نتیجه شکایت انجام نشد.'];
        }
    }

    public static function create_refund(int $customer_id, array $data): array
    {
        global $wpdb;
        $table = Biawin_CRM_DB::table('refunds');
        $id = self::insert_case_with_retry($table, 'refund_code', 'REF', [
            'customer_id' => $customer_id,
            'complaint_id' => isset($data['complaint_id']) ? absint($data['complaint_id']) : null,
            'requested_amount' => isset($data['requested_amount']) ? Biawin_CRM_Normalizer::money($data['requested_amount']) : null,
            'reason' => sanitize_textarea_field($data['reason'] ?? ''),
            'status' => 'new',
            'created_by' => get_current_user_id(),
            'created_at' => Biawin_CRM_DB::now(),
            'updated_at' => Biawin_CRM_DB::now(),
        ]);
        if (!$id) return ['ok'=>false,'message'=>'ثبت پرونده عودت در دیتابیس انجام نشد.'];
        $updated = $wpdb->update(Biawin_CRM_DB::table('customers'), ['journey_state' => 'refund', 'updated_at' => Biawin_CRM_DB::now()], ['id' => $customer_id]);
        if ($updated === false) {
            $wpdb->delete($table, ['id'=>$id]);
            return ['ok'=>false,'message'=>'مسیر عودت روی پروفایل مشتری ذخیره نشد.'];
        }
        self::activity($customer_id, 'refund_created', 'پرونده عودت وجه ایجاد شد.', 'refund', $id);
        return ['ok' => true, 'id' => $id];
    }

    public static function active_services(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . Biawin_CRM_DB::table('service_catalog') . " WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
    }

    public static function select_services(int $customer_id, array $service_ids, string $note = ''): array
    {
        global $wpdb;
        $service_ids = array_values(array_unique(array_filter(array_map('absint', $service_ids))));
        if (!$service_ids) return ['ok'=>false,'message'=>'حداقل یک خدمت انتخاب کنید.'];
        // v0.8.66.0 — selecting services = the «خدمات مازاد» route: profile complete + route gate + allowed actor.
        if (!self::sync_customer($customer_id)) return ['ok'=>false,'message'=>'بروزرسانی وضعیت پروفایل انجام نشد. دوباره تلاش کنید.'];
        $check = self::assert_route_selectable($customer_id);
        if (empty($check['ok'])) return $check;

        $wpdb->query('START TRANSACTION');
        try {
            if (!self::sync_customer($customer_id)) throw new RuntimeException('customer sync failed');
            $customer = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Biawin_CRM_DB::table('customers') . ' WHERE id=%d FOR UPDATE', $customer_id));
            if (!$customer) {
                $wpdb->query('ROLLBACK'); return ['ok'=>false,'message'=>'پروفایل مشتری پیدا نشد.'];
            }
            $placeholders = implode(',', array_fill(0, count($service_ids), '%d'));
            $services = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . Biawin_CRM_DB::table('service_catalog') . " WHERE id IN ($placeholders) AND is_active=1", $service_ids));
            if (count($services) !== count($service_ids)) {
                $wpdb->query('ROLLBACK'); return ['ok'=>false,'message'=>'یک یا چند خدمت معتبر یا فعال نیست.'];
            }
            $existing_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT service_id FROM " . Biawin_CRM_DB::table('service_cases') . " WHERE customer_id=%d AND service_id IN ($placeholders) AND status NOT IN ('rejected','cancelled')",
                array_merge([$customer_id], $service_ids)
            ));
            if ($existing_ids) {
                $wpdb->query('ROLLBACK'); return ['ok'=>false,'message'=>'یک یا چند خدمت انتخابی قبلاً برای این مشتری پرونده فعال/تکمیل‌شده دارند.'];
            }
            $reserved = (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(credit_amount),0) FROM ".Biawin_CRM_DB::table('service_cases')." WHERE customer_id=%d AND status IN ('unassigned','assigned','in_progress','waiting')",$customer_id));
            $consumed = (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(credit_amount),0) FROM ".Biawin_CRM_DB::table('service_cases')." WHERE customer_id=%d AND status='completed'",$customer_id));
            $available=(float)$customer->service_credit_total-$reserved-$consumed;
            $sum=array_sum(array_map(fn($x)=>(float)$x->credit_cost,$services));
            // Over-allocation is allowed by business rule. Remaining credit may become negative; UI shows a warning only.
            $created=[];
            foreach($services as $service){
                $table=Biawin_CRM_DB::table('service_cases');
                $case_id=self::insert_case_with_retry($table,'case_code','SRV',[
                    'customer_id'=>$customer_id,'service_id'=>(int)$service->id,
                    'credit_amount'=>(float)$service->credit_cost,'status'=>'unassigned','unit_key'=>$service->unit_key,
                    'note'=>sanitize_textarea_field($note),'created_by'=>get_current_user_id(),'created_at'=>Biawin_CRM_DB::now(),'updated_at'=>Biawin_CRM_DB::now()
                ]);
                if(!$case_id) throw new RuntimeException('service case insert failed');
                $created[]=$case_id;
                self::activity($customer_id,'service_case_created','درخواست خدمت «'.$service->name.'» ایجاد شد.','service_case',$case_id,['service_id'=>(int)$service->id]);
            }
            $journey_updated = $wpdb->update(Biawin_CRM_DB::table('customers'),['journey_state'=>'service_request','updated_at'=>Biawin_CRM_DB::now()],['id'=>$customer_id]);
            if ($journey_updated === false) throw new RuntimeException('customer journey update failed');
            $wpdb->query('COMMIT');
            self::sync_customer($customer_id);
            self::activity($customer_id,'journey_changed','خدمات مشتری انتخاب و پرونده‌های تخصصی ساخته شد.','customer',$customer_id,['journey'=>'service_request']);
            self::record_additional_services_boundary($customer_id, $created, $service_ids);
            return ['ok'=>true,'case_ids'=>$created];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return ['ok'=>false,'message'=>'ایجاد پرونده‌های خدمات انجام نشد.'];
        }
    }

    /**
     * v0.8.66.0 — Biawin Integration Boundary (internal hand-off point only). Reached ONLY from the «خدمات مازاد»
     * route via select_services(); never from ناراضی, Complaint, عودت or تهاتر. No external call, no endpoint, no
     * request/response schema: it records that the selection is ready for the future integration
     * (biawin_status=NOT_SENT) and fires an action the Biawin phase can attach to.
     */
    public static function record_additional_services_boundary(int $customer_id, array $service_case_ids, array $service_ids): void
    {
        $payload = ['route' => 'service_request', 'service_case_ids' => array_values(array_map('intval', $service_case_ids)), 'service_ids' => array_values(array_map('intval', $service_ids)), 'biawin_status' => 'NOT_SENT', 'external_call' => false];
        self::activity($customer_id, 'additional_services_boundary', 'خدمات مازاد در مرز یکپارچگی ثبت شد (ارسالی به CRM فروش بیاوین انجام نشده است).', 'customer', $customer_id, $payload);
        do_action('bcrm_additional_services_boundary', $customer_id, $payload);
    }

    public static function supervised_unit_keys(int $user_id = 0): array
    {
        global $wpdb;
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return [];
        return array_values(array_unique(array_filter($wpdb->get_col($wpdb->prepare(
            'SELECT u.unit_key FROM '.Biawin_CRM_DB::table('service_unit_members').' m INNER JOIN '.Biawin_CRM_DB::table('service_units').' u ON u.id=m.unit_id WHERE m.user_id=%d AND m.member_role=%s AND u.is_active=1',
            $user_id, 'supervisor'
        )))));
    }

    public static function can_supervise_unit(string $unit_key, int $user_id = 0): bool
    {
        if (current_user_can('bcrm_manage_cases')) return true;
        return in_array($unit_key, self::supervised_unit_keys($user_id), true);
    }

    public static function unit_agents(string $unit_key): array
    {
        global $wpdb;
        $unit_id=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Biawin_CRM_DB::table('service_units').' WHERE unit_key=%s AND is_active=1',$unit_key));
        if(!$unit_id)return [];
        $ids=$wpdb->get_col($wpdb->prepare("SELECT user_id FROM ".Biawin_CRM_DB::table('service_unit_members')." WHERE unit_id=%d AND member_role='agent'",$unit_id));
        $out=[]; foreach($ids as $id){$u=get_user_by('id',(int)$id);if($u && !(bool)get_user_meta($u->ID,'bcrm_disabled',true))$out[]=$u;}
        return $out;
    }

    public static function assign_service_case(int $case_id, int $agent_user_id): array
    {
        global $wpdb;
        $case = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Biawin_CRM_DB::table('service_cases') . ' WHERE id=%d', $case_id));
        if (!$case) {
            return ['ok' => false, 'message' => 'پرونده خدمت پیدا نشد.'];
        }
        if (!self::can_supervise_unit((string)$case->unit_key)) {
            return ['ok'=>false,'message'=>'شما سرپرست این واحد خدمات نیستید.'];
        }
        $agent = get_user_by('id', $agent_user_id);
        $allowed_ids=array_map(fn($u)=>(int)$u->ID,self::unit_agents((string)$case->unit_key));
        if (!$agent || !$agent->has_cap('bcrm_services') || (bool)get_user_meta($agent_user_id, 'bcrm_disabled', true) || !in_array($agent_user_id,$allowed_ids,true)) {
            return ['ok' => false, 'message' => 'کارشناس انتخاب‌شده عضو فعال این واحد خدمات نیست.'];
        }
        $updated = $wpdb->update(Biawin_CRM_DB::table('service_cases'), [
            'assigned_user_id' => $agent_user_id,
            'assigned_by' => get_current_user_id(),
            'assigned_at' => Biawin_CRM_DB::now(),
            'status' => 'assigned',
            'updated_at' => Biawin_CRM_DB::now(),
        ], ['id' => $case_id]);
        if ($updated === false) return ['ok' => false, 'message' => 'تخصیص پرونده در دیتابیس ذخیره نشد.'];
        self::activity((int) $case->customer_id, 'service_case_assigned', 'پرونده خدمت به کارشناس تخصیص داده شد.', 'service_case', $case_id, ['agent_user_id' => $agent_user_id]);
        return ['ok' => true];
    }

    public static function update_service_case(int $case_id, string $status, string $note = ''): array
    {
        global $wpdb;
        $allowed = ['unassigned','assigned','in_progress','waiting','completed','rejected','cancelled'];
        if (!in_array($status, $allowed, true)) {
            return ['ok' => false, 'message' => 'وضعیت خدمت معتبر نیست.'];
        }
        $case = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Biawin_CRM_DB::table('service_cases') . ' WHERE id=%d', $case_id));
        if (!$case) {
            return ['ok' => false, 'message' => 'پرونده خدمت پیدا نشد.'];
        }
        if (!current_user_can('bcrm_manage_cases') && (int)$case->assigned_user_id !== get_current_user_id()) {
            return ['ok' => false, 'message' => 'این پرونده خدمت به شما تخصیص داده نشده است.'];
        }
        $data = ['status' => $status, 'note' => sanitize_textarea_field($note), 'updated_at' => Biawin_CRM_DB::now()];
        $data['closed_at'] = in_array($status, ['completed','rejected','cancelled'], true) ? Biawin_CRM_DB::now() : null;
        $updated = $wpdb->update(Biawin_CRM_DB::table('service_cases'), $data, ['id' => $case_id]);
        if ($updated === false) return ['ok' => false, 'message' => 'وضعیت خدمت در دیتابیس ذخیره نشد.'];
        self::activity((int) $case->customer_id, 'service_case_status', 'وضعیت پرونده خدمت تغییر کرد.', 'service_case', $case_id, ['status' => $status, 'note' => $note]);
        self::sync_customer((int) $case->customer_id);
        return ['ok' => true];
    }

    public static function customer_timeline(int $customer_id, int $limit = 100): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT a.*, u.display_name AS actor_name FROM ' . Biawin_CRM_DB::table('activities') . ' a LEFT JOIN ' . $wpdb->users . ' u ON u.ID=a.actor_user_id WHERE a.customer_id=%d ORDER BY a.id DESC LIMIT %d',
            $customer_id,
            $limit
        ));
    }

    public static function activity(int $customer_id, string $type, string $title, string $object_type = 'customer', ?int $object_id = null, array $meta = []): void
    {
        global $wpdb;
        $wpdb->insert(Biawin_CRM_DB::table('activities'), [
            'customer_id' => $customer_id,
            'actor_user_id' => get_current_user_id() ?: null,
            'activity_type' => sanitize_key($type),
            'object_type' => sanitize_key($object_type),
            'object_id' => $object_id,
            'title' => sanitize_text_field($title),
            'meta_json' => $meta ? wp_json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => Biawin_CRM_DB::now(),
        ]);
    }

    private static function insert_case_with_retry(string $table, string $code_field, string $prefix, array $data): int
    {
        global $wpdb;
        for ($attempt=0; $attempt<6; $attempt++) {
            $data[$code_field] = self::next_case_code($prefix, $table);
            $ok = $wpdb->insert($table, $data);
            if ($ok !== false && (int)$wpdb->insert_id > 0) return (int)$wpdb->insert_id;
            usleep(10000 + ($attempt * 5000));
        }
        return 0;
    }

    private static function next_case_code(string $prefix, string $table): string
    {
        global $wpdb;
        $id = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0)+1 FROM $table");
        return $prefix . '-' . date_i18n('Y') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
