<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * v0.8.66.0 — Master Phase 2 read-only reports. Nothing here writes data.
 *  - D-01 dry-run: impact of "COMPLETE ⟺ |declared − invoice_total| = 0" vs the current tolerance rule.
 *  - Reconciliation of existing «خدمات مازاد» customers (journey_state='service_request').
 *  - Post-deficiency reallocation queue.
 */
class Biawin_CRM_Journey_Reports
{
    /** D-01 dry-run. invoice_total = customers.total_paid (D-02: invoices with exclude_from_total=1 already excluded). */
    public static function completeness_dry_run(): array
    {
        global $wpdb;
        $c = Biawin_CRM_DB::table('customers');
        $d = Biawin_CRM_DB::table('deficiency_cases');
        $a = Biawin_CRM_DB::table('assignments');
        $tolerance = max(500000, (float)get_option('biawin_crm_reconciliation_tolerance', 500000)); // legacy rule being replaced
        $diff = 'ABS(c.declared_paid - c.total_paid)';
        $counts = $wpdb->get_row("SELECT COUNT(*) total, SUM(c.profile_completeness='complete') complete, SUM(c.profile_completeness='incomplete') incomplete, SUM(c.profile_completeness NOT IN ('complete','incomplete') OR c.profile_completeness IS NULL) pending FROM $c c", ARRAY_A);
        $change_where = "c.profile_completeness='complete' AND c.declared_paid IS NOT NULL AND $diff > 0.004";
        $change = $wpdb->get_row("SELECT COUNT(*) n,
                SUM(NOT EXISTS (SELECT 1 FROM $d x WHERE x.customer_id=c.id AND x.status NOT IN ('COMPLETED','ARCHIVED'))) new_deficiency,
                SUM(c.journey_state IS NOT NULL AND c.journey_state<>'') with_route,
                SUM(c.journey_state='complaint') route_complaint, SUM(c.journey_state='no_request') route_no_service, SUM(c.journey_state='service_request') route_services, SUM(c.journey_state='refund') route_legacy_refund,
                SUM(EXISTS (SELECT 1 FROM $a y WHERE y.customer_id=c.id AND y.status='assigned')) with_active_assignment,
                SUM(c.completion_source<>'NORMAL') completed_by_workflow
            FROM $c c WHERE $change_where", ARRAY_A);
        $buckets = $wpdb->get_results("SELECT CASE WHEN $diff <= 10000 THEN '≤ 10,000' WHEN $diff <= 100000 THEN '10,001 – 100,000' WHEN $diff <= 250000 THEN '100,001 – 250,000' ELSE '> 250,000' END bucket, COUNT(*) n FROM $c c WHERE $change_where GROUP BY bucket ORDER BY MIN($diff)", ARRAY_A);
        $samples = $wpdb->get_results("SELECT c.id, c.source_customer_id, c.display_name, c.total_paid, c.declared_paid, $diff mismatch, c.journey_state, c.completion_source FROM $c c WHERE $change_where ORDER BY $diff DESC, c.id ASC LIMIT 100", ARRAY_A);
        $open_case_complete = (int)$wpdb->get_var("SELECT COUNT(*) FROM $c c WHERE c.profile_completeness='complete' AND EXISTS (SELECT 1 FROM $d x WHERE x.customer_id=c.id AND x.status NOT IN ('COMPLETED','ARCHIVED'))");
        $t = Biawin_CRM_DB::table('customer_tags'); $act = Biawin_CRM_DB::table('activities');
        $historical_after_deficiency = (int)$wpdb->get_var("SELECT COUNT(*) FROM $c c WHERE EXISTS (SELECT 1 FROM $t g WHERE g.customer_id=c.id AND g.tag='COMPLETED_AFTER_DEFICIENCY') AND NOT EXISTS (SELECT 1 FROM $act m WHERE m.customer_id=c.id AND m.activity_type='" . Biawin_CRM_Workflow_Service::REALLOCATION_MARKER . "')");
        return [
            'rule' => Biawin_CRM_Workflow_Service::completeness_rule(),
            'historical_after_deficiency_without_reallocation' => $historical_after_deficiency,
            'tolerance' => $tolerance,
            'counts' => array_map('intval', (array)$counts),
            'would_change' => array_map('intval', (array)$change),
            'buckets' => $buckets,
            'samples' => $samples,
            'complete_with_open_deficiency_case' => $open_case_complete,
            'incomplete_that_would_become_complete' => 0, // the zero rule is strictly narrower than the tolerance rule
        ];
    }

    /** Existing «خدمات مازاد» customers: where they are, how they got there, what they have. Read-only. */
    public static function additional_services_reconciliation(): array
    {
        global $wpdb;
        $c = Biawin_CRM_DB::table('customers');
        $sc = Biawin_CRM_DB::table('service_cases');
        $co = Biawin_CRM_DB::table('complaints');
        $sv = Biawin_CRM_DB::table('services');
        $act = Biawin_CRM_DB::table('activities');
        $summary = $wpdb->get_row("SELECT COUNT(*) total,
                SUM(c.profile_completeness='complete') complete, SUM(c.profile_completeness='incomplete') incomplete, SUM(c.profile_completeness NOT IN ('complete','incomplete')) pending,
                SUM(EXISTS (SELECT 1 FROM $sc s WHERE s.customer_id=c.id)) with_service_cases,
                SUM(NOT EXISTS (SELECT 1 FROM $sc s WHERE s.customer_id=c.id)) without_service_cases,
                SUM(EXISTS (SELECT 1 FROM $co k WHERE k.customer_id=c.id AND k.resolution_outcome='service_request')) via_complaint_outcome,
                SUM(EXISTS (SELECT 1 FROM $sv i WHERE i.customer_id=c.id)) with_imported_services,
                SUM(EXISTS (SELECT 1 FROM $act b WHERE b.customer_id=c.id AND b.activity_type='additional_services_boundary')) boundary_recorded,
                SUM((SELECT COUNT(*) FROM $sc s2 WHERE s2.customer_id=c.id)) service_cases_total
            FROM $c c WHERE c.journey_state='service_request'", ARRAY_A);
        $case_status = $wpdb->get_results("SELECT s.status, COUNT(*) n, COUNT(DISTINCT s.customer_id) customers FROM $sc s INNER JOIN $c c ON c.id=s.customer_id WHERE c.journey_state='service_request' GROUP BY s.status ORDER BY n DESC", ARRAY_A);
        $cases_other_routes = $wpdb->get_results("SELECT COALESCE(NULLIF(c.journey_state,''),'(بدون مسیر)') journey_state, COUNT(DISTINCT s.customer_id) customers, COUNT(*) cases FROM $sc s INNER JOIN $c c ON c.id=s.customer_id WHERE COALESCE(c.journey_state,'')<>'service_request' GROUP BY journey_state", ARRAY_A);
        $duplicates = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT customer_id, service_id FROM $sc WHERE status NOT IN ('rejected','cancelled') GROUP BY customer_id, service_id HAVING COUNT(*) > 1) x");
        $rows = $wpdb->get_results("SELECT c.id, c.source_customer_id, c.display_name, c.profile_completeness,
                (SELECT COUNT(*) FROM $sc s WHERE s.customer_id=c.id) service_cases,
                (SELECT COUNT(*) FROM $sc s WHERE s.customer_id=c.id AND s.status NOT IN ('completed','rejected','cancelled')) open_cases,
                (SELECT COUNT(*) FROM $sv i WHERE i.customer_id=c.id) imported_services,
                EXISTS (SELECT 1 FROM $co k WHERE k.customer_id=c.id AND k.resolution_outcome='service_request') via_complaint_outcome
            FROM $c c WHERE c.journey_state='service_request' ORDER BY c.id ASC LIMIT 300", ARRAY_A);
        return [
            'summary' => array_map('intval', (array)$summary),
            'case_status' => $case_status,
            'service_cases_on_other_routes' => $cases_other_routes,
            'duplicate_active_service_cases' => $duplicates,
            'biawin_data' => 'none', // no Biawin column/table/ID exists in CRM رضایت‌سازی (verified in code); nothing was sent
            'rows' => $rows,
        ];
    }

    public static function render_page(): void
    {
        if (!current_user_can('bcrm_manage_cases')) wp_die('دسترسی غیرمجاز است.');
        $n = static fn($v) => number_format_i18n((float)$v);
        $dry = self::completeness_dry_run();
        $rec = self::additional_services_reconciliation();
        $queue = Biawin_CRM_Workflow_Service::awaiting_reallocation_rows(200);
        echo '<div class="wrap bcrm" dir="rtl"><h1>گزارش‌های مسیر مشتری</h1><p>این صفحه فقط خواندنی است و هیچ داده‌ای را تغییر نمی‌دهد.</p>';

        echo '<div class="bcrm-card" style="max-width:1200px"><h2>D-01 — Dry-run تعریف «کامل = مغایرت صفر»</h2>';
        echo '<p>قاعده کسب‌وکار: کامل ⟺ مبلغ اعلامی = مجموع فاکتورها (مغایرت ۰). قاعده فعال این نصب: <strong>' . ($dry['rule'] === 'zero' ? 'مغایرت صفر' : ('تلرانس قدیمی (≤ ' . esc_html($n($dry['tolerance'])) . ' تومان) — تا تأیید انتقال')) . '</strong>. مجموع فاکتورها فاکتورهای «exclude_from_total» را شامل نمی‌شود (D-02). <strong>این صفحه چیزی تغییر نمی‌دهد.</strong></p>';
        echo '<p>اثر انتقال (پس از فعال‌سازی، هر پروفایل هنگام بازمحاسبه — مشاهده پروفایل، ثبت تماس، ایمپورت یا تغییر فاکتور — تغییر می‌کند): پروفایل‌های «کامل فقط به‌خاطر تلرانس» ناقص می‌شوند، پرونده رفع ناقصی جدید و ردیف قدیمی financial_cases برایشان ساخته می‌شود، مسیر مشتری‌شان قفل می‌شود (مقدار ثبت‌شده حذف نمی‌شود) و تخصیص تماس آن‌ها دست نمی‌خورد. KPIهای متأثر: کامل/ناقص داشبورد و لیست مشتریان، جمعیت‌های گزارش مدیریتی، کارتابل رفع ناقصی (ورودی جدید)، کارتابل مالی قدیمی و KPI «پرونده مالی».</p>';
        $c = $dry['counts']; $w = $dry['would_change'];
        echo '<table class="widefat striped" style="max-width:760px"><tbody>'
            . '<tr><th>کل پروفایل‌ها</th><td>' . esc_html($n($c['total'] ?? 0)) . '</td></tr>'
            . '<tr><th>کامل (قاعده فعلی)</th><td>' . esc_html($n($c['complete'] ?? 0)) . '</td></tr>'
            . '<tr><th>ناقص (قاعده فعلی)</th><td>' . esc_html($n($c['incomplete'] ?? 0)) . '</td></tr>'
            . '<tr><th>تعیین‌نشده (بدون مبلغ اعلامی)</th><td>' . esc_html($n($c['pending'] ?? 0)) . '</td></tr>'
            . '<tr><th>کامل فقط به‌خاطر تلرانس = کامل → ناقص با قاعده مصوب</th><td><strong>' . esc_html($n($w['n'] ?? 0)) . '</strong></td></tr>'
            . '<tr><th>— ورود جدید به رفع ناقصی (پرونده جدید بالقوه)</th><td>' . esc_html($n($w['new_deficiency'] ?? 0)) . '</td></tr>'
            . '<tr><th>— دارای مسیر ثبت‌شده (ناراضی / بدون خدمات / خدمات مازاد / عودت قدیمی)</th><td>' . esc_html($n($w['with_route'] ?? 0)) . ' (' . esc_html($n($w['route_complaint'] ?? 0) . ' / ' . $n($w['route_no_service'] ?? 0) . ' / ' . $n($w['route_services'] ?? 0) . ' / ' . $n($w['route_legacy_refund'] ?? 0)) . ')</td></tr>'
            . '<tr><th>— دارای تخصیص فعال</th><td>' . esc_html($n($w['with_active_assignment'] ?? 0)) . '</td></tr>'
            . '<tr><th>— قبلاً با گردش‌کار رفع ناقصی/مالی کامل شده</th><td>' . esc_html($n($w['completed_by_workflow'] ?? 0)) . '</td></tr>'
            . '<tr><th>ناقص → کامل با قاعده مصوب</th><td>0 (قاعده مصوب سخت‌گیرانه‌تر است)</td></tr>'
            . '<tr><th>کامل ولی با پرونده رفع ناقصی باز (اکنون مسیرشان قفل است — D-08)</th><td>' . esc_html($n($dry['complete_with_open_deficiency_case'])) . '</td></tr>'
            . '<tr><th>تکمیل‌شده پس از رفع ناقصی پیش از v0.8.66.0 (بدون بازگشت به سرپرست؛ قفل نمی‌شوند)</th><td>' . esc_html($n($dry['historical_after_deficiency_without_reallocation'])) . '</td></tr>'
            . '</tbody></table>';
        if ($dry['buckets']) {
            echo '<h3>توزیع مغایرت در گروه تغییرکننده</h3><table class="widefat striped" style="max-width:480px"><thead><tr><th>مغایرت (تومان)</th><th>تعداد</th></tr></thead><tbody>';
            foreach ($dry['buckets'] as $b) echo '<tr><td>' . esc_html($b['bucket']) . '</td><td>' . esc_html($n($b['n'])) . '</td></tr>';
            echo '</tbody></table>';
        }
        if ($dry['samples']) {
            echo '<h3>نمونه (۱۰۰ مورد با بیشترین مغایرت)</h3><table class="widefat striped"><thead><tr><th>CustomerID</th><th>نام</th><th>مجموع فاکتورها</th><th>مبلغ اعلامی</th><th>مغایرت</th><th>مسیر</th><th>منبع تکمیل</th></tr></thead><tbody>';
            foreach ($dry['samples'] as $r) echo '<tr><td>' . esc_html($r['source_customer_id'] ?: '#' . $r['id']) . '</td><td>' . esc_html($r['display_name'] ?: '-') . '</td><td>' . esc_html($n($r['total_paid'])) . '</td><td>' . esc_html($n($r['declared_paid'])) . '</td><td>' . esc_html($n($r['mismatch'])) . '</td><td>' . esc_html($r['journey_state'] ?: '-') . '</td><td>' . esc_html($r['completion_source']) . '</td></tr>';
            echo '</tbody></table>';
        }
        echo '</div>';

        $s = $rec['summary'];
        echo '<div class="bcrm-card" style="max-width:1200px"><h2>مشتریان موجود «خدمات مازاد» — گزارش تطبیق</h2>';
        echo '<p>محل ذخیره: <code>customers.journey_state = service_request</code>؛ خدمات انتخابی: <code>service_cases</code> (کاتالوگ <code>service_catalog</code>)؛ خدمات واردشده از فایل مرجع: <code>services</code>. هیچ ستون، جدول یا شناسه بیاوین در CRM رضایت‌سازی وجود ندارد و هیچ ارسالی انجام نشده است.</p>';
        echo '<table class="widefat striped" style="max-width:760px"><tbody>'
            . '<tr><th>کل مشتریان با مسیر خدمات مازاد</th><td><strong>' . esc_html($n($s['total'] ?? 0)) . '</strong></td></tr>'
            . '<tr><th>کامل / ناقص / تعیین‌نشده</th><td>' . esc_html($n($s['complete'] ?? 0) . ' / ' . $n($s['incomplete'] ?? 0) . ' / ' . $n($s['pending'] ?? 0)) . '</td></tr>'
            . '<tr><th>دارای پرونده خدمت</th><td>' . esc_html($n($s['with_service_cases'] ?? 0)) . '</td></tr>'
            . '<tr><th>کل پرونده‌های خدمت این مشتریان</th><td>' . esc_html($n($s['service_cases_total'] ?? 0)) . '</td></tr>'
            . '<tr><th>بدون پرونده خدمت (مسیر بدون خدمت ثبت‌شده)</th><td>' . esc_html($n($s['without_service_cases'] ?? 0)) . '</td></tr>'
            . '<tr><th>رسیده از نتیجه قدیمی نارضایتی («تبدیل به درخواست خدمت» — از نظر کسب‌وکار تهاتر، نه مسیر)</th><td>' . esc_html($n($s['via_complaint_outcome'] ?? 0)) . '</td></tr>'
            . '<tr><th>دارای خدمات واردشده از فایل مرجع</th><td>' . esc_html($n($s['with_imported_services'] ?? 0)) . '</td></tr>'
            . '<tr><th>ثبت‌شده در مرز یکپارچگی (از v0.8.66.0)</th><td>' . esc_html($n($s['boundary_recorded'] ?? 0)) . '</td></tr>'
            . '<tr><th>پرونده خدمت فعال تکراری (مشتری + خدمت)</th><td>' . esc_html($n($rec['duplicate_active_service_cases'])) . '</td></tr>'
            . '<tr><th>داده بیاوین</th><td>ندارد</td></tr>'
            . '</tbody></table>';
        if ($rec['case_status']) {
            echo '<h3>وضعیت پرونده‌های خدمت این مشتریان</h3><table class="widefat striped" style="max-width:480px"><thead><tr><th>وضعیت</th><th>پرونده</th><th>مشتری</th></tr></thead><tbody>';
            foreach ($rec['case_status'] as $r) echo '<tr><td>' . esc_html($r['status']) . '</td><td>' . esc_html($n($r['n'])) . '</td><td>' . esc_html($n($r['customers'])) . '</td></tr>';
            echo '</tbody></table>';
        }
        if ($rec['service_cases_on_other_routes']) {
            echo '<h3>پرونده‌های خدمت روی مسیرهای دیگر (خدمات مازاد نیستند)</h3><table class="widefat striped" style="max-width:480px"><thead><tr><th>مسیر فعلی</th><th>مشتری</th><th>پرونده</th></tr></thead><tbody>';
            foreach ($rec['service_cases_on_other_routes'] as $r) echo '<tr><td>' . esc_html($r['journey_state']) . '</td><td>' . esc_html($n($r['customers'])) . '</td><td>' . esc_html($n($r['cases'])) . '</td></tr>';
            echo '</tbody></table>';
        }
        if ($rec['rows']) {
            echo '<h3>فهرست (حداکثر ۳۰۰)</h3><table class="widefat striped"><thead><tr><th>CustomerID</th><th>نام</th><th>پروفایل</th><th>پرونده خدمت</th><th>باز</th><th>خدمات واردشده</th><th>از نتیجه نارضایتی</th></tr></thead><tbody>';
            foreach ($rec['rows'] as $r) echo '<tr><td>' . esc_html($r['source_customer_id'] ?: '#' . $r['id']) . '</td><td>' . esc_html($r['display_name'] ?: '-') . '</td><td>' . esc_html($r['profile_completeness']) . '</td><td>' . esc_html($n($r['service_cases'])) . '</td><td>' . esc_html($n($r['open_cases'])) . '</td><td>' . esc_html($n($r['imported_services'])) . '</td><td>' . ((int)$r['via_complaint_outcome'] ? 'بله' : '—') . '</td></tr>';
            echo '</tbody></table>';
        }
        echo '</div>';

        echo '<div class="bcrm-card" style="max-width:1200px"><h2>تکمیل‌شده پس از رفع ناقصی — منتظر تخصیص (' . esc_html($n(count($queue))) . ')</h2><p>تخصیص از صفحه «تخصیص پرونده».</p>';
        if ($queue) {
            echo '<table class="widefat striped"><thead><tr><th>CustomerID</th><th>نام</th><th>زمان تکمیل</th></tr></thead><tbody>';
            foreach ($queue as $r) echo '<tr><td>' . esc_html($r->source_customer_id ?: '#' . $r->id) . '</td><td>' . esc_html($r->display_name ?: '-') . '</td><td>' . esc_html($r->marker_at) . '</td></tr>';
            echo '</tbody></table>';
        }
        echo '</div></div>';
    }
}
