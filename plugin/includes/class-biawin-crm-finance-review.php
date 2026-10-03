<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * v0.8.62.28 — Finance receipt review, sales-net style: the finance agent opens a receipt
 * and, in that same window, sees the case context and approves / rejects THAT receipt
 * (with amount / reason) or records the case's final finance result — no page hopping.
 *
 * - Per-receipt review lives in {prefix}bcrm_receipt_reviews (one row per attachment),
 *   created on first use (the activation hook does not run on ZIP updates).
 * - The case-level decision reuses Biawin_CRM_Deficiency_Workflow::process_action()
 *   ('finance_submit'), so every rule/transition/tag of the regular form still applies.
 * - All endpoints: logged-in + nonce (bcrm_fin_review) + can_access_case + capabilities.
 */
class Biawin_CRM_Finance_Review
{
    public const NONCE_ACTION = 'bcrm_fin_review';
    public const SCHEMA_OPTION = 'bcrm_receipt_review_schema';
    public const SCHEMA_VERSION = '1';
    public const REVIEWABLE_STATUSES = ['FINANCE_REVIEW_PENDING', 'FINANCE_ASSIGNED', 'FINANCE_UNDER_REVIEW'];

    private static $table_ready = false;

    public static function init(): void
    {
        add_action('wp_ajax_bcrm_fin_case_review', [__CLASS__, 'ajax_case_review']);
        add_action('wp_ajax_bcrm_fin_receipt_review', [__CLASS__, 'ajax_receipt_review']);
        add_action('wp_ajax_bcrm_fin_case_decide', [__CLASS__, 'ajax_case_decide']);
    }

    public static function table(): string
    {
        return Biawin_CRM_DB::table('receipt_reviews');
    }

    public static function ensure_table(): bool
    {
        if (self::$table_ready) return true;
        if (get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION) {
            self::$table_ready = true;
            return true;
        }
        global $wpdb;
        $table = self::table();
        $charset = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $wpdb->last_error = '';
        $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
            attachment_id BIGINT UNSIGNED NOT NULL,
            case_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            review_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            approved_amount DECIMAL(20,2) NULL,
            review_note TEXT NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            PRIMARY KEY (attachment_id),
            KEY case_id (case_id)
        ) $charset");
        if ((string)$wpdb->last_error !== '') return false;
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        self::$table_ready = true;
        return true;
    }

    public static function status_labels(): array
    {
        return ['pending' => 'بررسی‌نشده', 'approved' => 'تأییدشده', 'rejected' => 'ردشده'];
    }

    /** @return array<int,object> attachment_id => review row */
    public static function reviews_for_case(int $case_id): array
    {
        if (!self::ensure_table()) return [];
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE case_id=%d', $case_id));
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) $out[(int)$row->attachment_id] = $row;
        return $out;
    }

    /** Compact outcome stored with the case-level finance decision. */
    public static function summary(int $case_id): array
    {
        $s = ['approved_count' => 0, 'rejected_count' => 0, 'approved_amount' => 0.0];
        foreach (self::reviews_for_case($case_id) as $row) {
            if ($row->review_status === 'approved') { $s['approved_count']++; $s['approved_amount'] += (float)$row->approved_amount; }
            elseif ($row->review_status === 'rejected') $s['rejected_count']++;
        }
        return $s;
    }

    private static function can_review(object $case): bool
    {
        if (!in_array((string)$case->status, self::REVIEWABLE_STATUSES, true)) return false;
        return current_user_can('review_finance_case') || current_user_can('approve_finance_case') || current_user_can('reject_finance_case') || current_user_can('manage_finance_cases') || current_user_can('bcrm_manage_cases');
    }

    private static function can_decide(object $case): bool
    {
        if (!in_array((string)$case->status, self::REVIEWABLE_STATUSES, true)) return false;
        return current_user_can('review_finance_case') || current_user_can('manage_finance_cases');
    }

    /** Auth + load the case, or send a JSON error and exit. */
    private static function guard_case(): object
    {
        if (!is_user_logged_in()) wp_send_json(['ok' => false, 'message' => 'نشست شما منقضی شده است؛ دوباره وارد شوید.'], 401);
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_send_json(['ok' => false, 'message' => 'اعتبار صفحه منقضی شده است؛ صفحه را دوباره بارگذاری کنید.', 'code' => 'nonce'], 403);
        global $wpdb;
        $case_id = absint($_POST['case_id'] ?? 0);
        $case = $case_id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=%d', $case_id)) : null;
        if (!$case) wp_send_json(['ok' => false, 'message' => 'پرونده پیدا نشد.'], 404);
        $can_view = current_user_can('view_finance_cases') || current_user_can('view_deficiency_cases') || current_user_can('bcrm_manage_cases');
        if (!$can_view || !Biawin_CRM_Deficiency_Workflow::can_access_case($case)) wp_send_json(['ok' => false, 'message' => 'دسترسی به این پرونده مجاز نیست.'], 403);
        return $case;
    }

    /** Everything the modal needs for one case. */
    public static function payload(object $case): array
    {
        global $wpdb;
        $case_id = (int)$case->id;
        $customer = $wpdb->get_row($wpdb->prepare('SELECT display_name, customer_code, source_customer_id, sales_manager FROM ' . Biawin_CRM_DB::table('customers') . ' WHERE id=%d', (int)$case->customer_id));
        $phones = $wpdb->get_col($wpdb->prepare('SELECT normalized_phone FROM ' . Biawin_CRM_DB::table('phones') . ' WHERE customer_id=%d ORDER BY is_primary DESC,id', (int)$case->customer_id));
        $atts = $wpdb->get_results($wpdb->prepare('SELECT at.*, p.post_title FROM ' . Biawin_CRM_DB::table('attachments') . ' at LEFT JOIN ' . $wpdb->posts . ' p ON p.ID=at.wp_attachment_id WHERE at.object_type=%s AND at.object_id=%d ORDER BY at.id ASC', 'deficiency_case', $case_id));
        $atts = is_array($atts) ? $atts : [];
        $analysis = Biawin_CRM_Deficiency_Workflow::analyze_case_receipts($atts);
        $reviews = self::reviews_for_case($case_id);
        $labels = self::status_labels();
        $receipts = [];
        $approved_total = 0.0; $pending = 0; $approved_n = 0; $rejected_n = 0;
        foreach ($analysis['rows'] as $row) {
            $att = $row['att'];
            $wp_id = (int)$att->wp_attachment_id;
            $url = $wp_id && function_exists('wp_get_attachment_url') ? (string)wp_get_attachment_url($wp_id) : '';
            $mime = $wp_id && function_exists('get_post_mime_type') ? (string)get_post_mime_type($wp_id) : '';
            $path = strtolower((string)parse_url($url, PHP_URL_PATH));
            $kind = (strpos($mime, 'image/') === 0 || preg_match('/\.(jpe?g|png|gif|webp|avif|bmp)$/', $path)) ? 'image' : (($mime === 'application/pdf' || substr($path, -4) === '.pdf') ? 'pdf' : 'file');
            if (in_array($mime, ['image/heic', 'image/heif', 'image/tiff'], true) || preg_match('/\.(heic|heif|tiff?)$/', $path)) $kind = 'file'; // most browsers cannot render these inline
            $review = $reviews[(int)$att->id] ?? null;
            $status = $review ? (string)$review->review_status : 'pending';
            if ($status === 'approved') { $approved_n++; $approved_total += (float)$review->approved_amount; }
            elseif ($status === 'rejected') $rejected_n++;
            else $pending++;
            $uploader = $att->uploaded_by ? get_user_by('id', (int)$att->uploaded_by) : false;
            $reviewer = ($review && $review->reviewed_by) ? get_user_by('id', (int)$review->reviewed_by) : false;
            $receipts[] = [
                'id'            => (int)$att->id,
                'title'         => (string)($att->post_title ?: ('فیش #' . (int)$att->id)),
                'kind'          => $kind,
                'mime'          => $mime,
                'url'           => $url,
                'is_receipt'    => (string)$att->file_kind === 'bank_receipt',
                'amount'        => $row['amount'],
                'note'          => trim((string)preg_replace('/\s*\|?\s*مبلغ فیش:.*/u', '', (string)$att->note)),
                'uploaded_at'   => Biawin_CRM_Deficiency_Workflow::jalali_datetime((string)$att->created_at),
                'uploaded_by'   => $uploader ? (string)$uploader->display_name : '',
                'review'        => [
                    'status'      => $status,
                    'label'       => $labels[$status] ?? $status,
                    'amount'      => $review && $review->approved_amount !== null ? (float)$review->approved_amount : null,
                    'note'        => $review ? (string)$review->review_note : '',
                    'by'          => $reviewer ? (string)$reviewer->display_name : '',
                    'at'          => $review ? Biawin_CRM_Deficiency_Workflow::jalali_datetime((string)$review->reviewed_at) : '',
                ],
            ];
        }
        $history = [];
        $actions = $wpdb->get_results($wpdb->prepare('SELECT a.action_type, a.description, a.metadata_json, a.created_at, u.display_name FROM ' . Biawin_CRM_DB::table('case_actions') . ' a LEFT JOIN ' . $wpdb->users . ' u ON u.ID=a.user_id WHERE a.case_id=%d ORDER BY a.id DESC LIMIT 15', $case_id));
        foreach (is_array($actions) ? $actions : [] as $a) {
            $meta = json_decode((string)$a->metadata_json, true);
            $note = (string)($a->description ?? '');
            if ($note === '' && is_array($meta)) $note = (string)($meta['note'] ?? ($meta['reason'] ?? ''));
            $history[] = [
                'label' => Biawin_CRM_Deficiency_Workflow::label_for_event((string)$a->action_type),
                'by'    => (string)($a->display_name ?: 'سیستم'),
                'at'    => Biawin_CRM_Deficiency_Workflow::jalali_datetime((string)$a->created_at),
                'note'  => $note,
            ];
        }
        $status_labels = Biawin_CRM_Deficiency_Workflow::status_labels();
        $reported = (float)$case->reported_amount;
        return [
            'case' => [
                'id'               => $case_id,
                'code'             => (string)$case->case_code,
                'status'           => (string)$case->status,
                'status_label'     => $status_labels[(string)$case->status] ?? (string)$case->status,
                'customer_name'    => $customer ? (string)($customer->display_name ?: 'پروفایل بدون نام') : '-',
                'customer_code'    => $customer ? (string)($customer->source_customer_id ?: $customer->customer_code) : '',
                'sales_manager'    => $customer ? (string)$customer->sales_manager : '',
                'phones'           => array_values(array_map('strval', is_array($phones) ? $phones : [])),
                'reported_amount'  => $reported,
                'registered_amount'=> (float)$case->registered_invoice_amount,
                'difference'       => abs((float)$case->difference_amount),
                'receipts_total'   => (float)$analysis['total'],
                'approved_total'   => $approved_total,
                'remaining'        => max(0, $reported - (float)$analysis['total']),
                'description'      => (string)($case->deficiency_description ?? ''),
            ],
            'counts'   => ['total' => count($receipts), 'pending' => $pending, 'approved' => $approved_n, 'rejected' => $rejected_n],
            'receipts' => $receipts,
            'history'  => $history,
            'perms'    => ['review' => self::can_review($case), 'decide' => self::can_decide($case)],
            'reject_reasons' => [
                'invalid_receipt' => 'فیش نامعتبر', 'payment_not_found' => 'واریز پیدا نشد', 'amount_mismatch' => 'مبلغ مغایر است',
                'not_customer_payment' => 'واریزی متعلق به مشتری نیست', 'invalid_invoice' => 'فاکتور نامعتبر', 'insufficient_info' => 'اطلاعات ناکافی',
                'invalid_document' => 'مدرک نامعتبر', 'other' => 'سایر',
            ],
        ];
    }

    /** AJAX: modal data for one case. */
    public static function ajax_case_review(): void
    {
        nocache_headers();
        $case = self::guard_case();
        self::ensure_table();
        wp_send_json(['ok' => true] + self::payload($case));
    }

    /** AJAX: approve / reject / reset ONE receipt (attachment) of the case. */
    public static function ajax_receipt_review(): void
    {
        $case = self::guard_case();
        $result = self::review_receipt($case, absint($_POST['attachment_id'] ?? 0), sanitize_key(wp_unslash($_POST['decision'] ?? '')), (string)wp_unslash($_POST['amount'] ?? ''), sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')));
        if (empty($result['ok'])) wp_send_json($result, 400);
        global $wpdb;
        $fresh = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Biawin_CRM_DB::table('deficiency_cases') . ' WHERE id=%d', (int)$case->id));
        wp_send_json($result + self::payload($fresh ?: $case));
    }

    /** Core per-receipt review (shared by AJAX and tests). */
    public static function review_receipt(object $case, int $attachment_id, string $decision, string $amount_raw = '', string $note = ''): array
    {
        if (!self::can_review($case)) {
            return ['ok' => false, 'message' => in_array((string)$case->status, self::REVIEWABLE_STATUSES, true) ? 'دسترسی بررسی فیش ندارید.' : 'این پرونده در وضعیت بررسی مالی نیست؛ بررسی فیش فقط در صف مالی ممکن است.'];
        }
        if (!in_array($decision, ['approve', 'reject', 'reset'], true)) return ['ok' => false, 'message' => 'اقدام نامعتبر است.'];
        if (!self::ensure_table()) return ['ok' => false, 'message' => 'جدول بررسی فیش‌ها در دیتابیس ساخته نشد.'];
        global $wpdb;
        $att = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Biawin_CRM_DB::table('attachments') . ' WHERE id=%d AND object_type=%s AND object_id=%d', $attachment_id, 'deficiency_case', (int)$case->id));
        if (!$att) return ['ok' => false, 'message' => 'این فیش متعلق به این پرونده نیست.'];
        $table = self::table();
        $receipt_amount = null;
        if (preg_match('/مبلغ فیش:\s*([\d\x{06F0}-\x{06F9}]+(?:[.,،][\d\x{06F0}-\x{06F9}]+)*)/u', (string)$att->note, $m)) $receipt_amount = Biawin_CRM_Normalizer::money($m[1]);
        if ($decision === 'reset') {
            $wpdb->delete($table, ['attachment_id' => $attachment_id]);
            Biawin_CRM_Deficiency_Workflow::action((int)$case->id, (int)$case->customer_id, 'finance', 'RECEIPT_REVIEW_RESET', (string)$case->status, (string)$case->status, ['attachment_id' => $attachment_id]);
            return ['ok' => true, 'message' => 'فیش به حالت «بررسی‌نشده» برگشت.'];
        }
        $amount = null;
        if ($decision === 'approve') {
            $amount = trim($amount_raw) !== '' ? Biawin_CRM_Normalizer::money($amount_raw) : $receipt_amount;
            if ($amount === null || $amount <= 0) return ['ok' => false, 'message' => 'مبلغ تأییدشده این فیش را وارد کنید.'];
        } elseif (trim($note) === '') {
            return ['ok' => false, 'message' => 'دلیل رد این فیش را بنویسید.'];
        }
        $data = [
            'case_id'         => (int)$case->id,
            'review_status'   => $decision === 'approve' ? 'approved' : 'rejected',
            'approved_amount' => $amount,
            'review_note'     => $note,
            'reviewed_by'     => get_current_user_id() ?: null,
            'reviewed_at'     => Biawin_CRM_DB::now(),
        ];
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE attachment_id=%d", $attachment_id));
        $saved = $exists ? $wpdb->update($table, $data, ['attachment_id' => $attachment_id]) : $wpdb->insert($table, ['attachment_id' => $attachment_id] + $data);
        if ($saved === false) return ['ok' => false, 'message' => 'ذخیره نتیجه بررسی فیش ناموفق بود.'];
        $event = $decision === 'approve' ? 'RECEIPT_APPROVED' : 'RECEIPT_REJECTED';
        $meta = ['attachment_id' => $attachment_id, 'receipt_amount' => $receipt_amount];
        if ($amount !== null) $meta['approved_amount'] = $amount;
        if ($note !== '') $meta['description'] = $note;
        Biawin_CRM_Deficiency_Workflow::action((int)$case->id, (int)$case->customer_id, 'finance', $event, (string)$case->status, (string)$case->status, $meta);
        return ['ok' => true, 'message' => $decision === 'approve' ? 'فیش تأیید شد.' : 'فیش رد شد.'];
    }

    /** AJAX: the case-level finance result, recorded from inside the review window. */
    public static function ajax_case_decide(): void
    {
        $case = self::guard_case();
        if (!self::can_decide($case)) wp_send_json(['ok' => false, 'message' => 'ثبت نتیجه نهایی مالی برای این پرونده/کاربر مجاز نیست.'], 403);
        $fields = ['finance_result', 'invoice_number', 'customer_phone', 'invoice_date', 'invoice_amount', 'approved_amount', 'payment_date', 'tracking_number', 'bank_or_gateway', 'reject_reason', 'description', 'receipt_attachment_id'];
        $raw = ['case_id' => (int)$case->id, 'case_action' => 'finance_submit'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) $raw[$f] = is_scalar($_POST[$f]) ? wp_unslash((string)$_POST[$f]) : '';
        }
        $result = Biawin_CRM_Deficiency_Workflow::process_action($raw);
        wp_send_json(['ok' => !empty($result['ok']), 'message' => (string)($result['message'] ?? (!empty($result['ok']) ? 'نتیجه مالی ثبت شد.' : 'ثبت نتیجه مالی ناموفق بود.'))], !empty($result['ok']) ? 200 : 400);
    }
}
