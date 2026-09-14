<?php
if (!defined('ABSPATH')) exit;

/**
 * داشبورد آماری کلی برای مدیر کل: تعداد کاربران هر نقش، آگهی‌ها، درخواست‌ها،
 * قراردادها، و درآمد واقعی (فقط تراکنش‌های موفق).
 */
class JJ_Admin_Dashboard {

    public static function init() {
        // اولویت ۲۰: زیرمنو زیر 'jj-settings' است — نگاه کنید به توضیح مشابه
        // در class-jj-admin-jobs-review.php.
        add_action('admin_menu', [__CLASS__, 'add_menu'], 20);
    }

    private static function allowed() {
        return current_user_can('manage_options') || current_user_can('jj_manage_all');
    }

    public static function add_menu() {
        add_submenu_page('jj-settings', 'داشبورد آماری', 'داشبورد آماری', 'read', 'jj-dashboard', [__CLASS__, 'render'], 0);
    }

    private static function role_counts() {
        $counts = count_users();
        $roles = [
            'jj_basic_user' => 'کاربر ساده', 'jj_jobseeker' => 'کارجوی فردی', 'jj_team_leader' => 'سرپرست اکیپ',
            'jj_employer' => 'کارفرما', 'jj_employer_pending' => 'کارفرما (ثبت‌نام در جریان)',
            'jj_agency' => 'کاریابی', 'jj_agency_pending' => 'کاریابی (ثبت‌نام در جریان)',
        ];
        $out = [];
        foreach ($roles as $slug => $label) {
            $out[] = ['label' => $label, 'count' => (int) ($counts['avail_roles'][$slug] ?? 0)];
        }
        return ['total' => (int) $counts['total_users'], 'rows' => $out];
    }

    private static function job_counts() {
        $counts = wp_count_posts('noo_job');
        return [
            'publish' => (int) ($counts->publish ?? 0),
            'pending' => (int) ($counts->pending ?? 0),
            'trash'   => (int) ($counts->trash ?? 0),
        ];
    }

    private static function table_status_counts($table, $group_col = 'status') {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $rows = $wpdb->get_results("SELECT {$group_col} AS k, COUNT(*) AS c FROM {$prefix}{$table} GROUP BY {$group_col}", ARRAY_A);
        $out = [];
        foreach ($rows as $r) { $out[$r['k']] = (int) $r['c']; }
        return $out;
    }

    private static function revenue_summary() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT payment_type, COUNT(*) AS cnt, SUM(amount) AS total_rial FROM {$prefix}jj_payments WHERE status='success' GROUP BY payment_type ORDER BY total_rial DESC",
            ARRAY_A
        );
        $labels = [
            'jobseeker_registration' => 'ثبت‌نام کارجو', 'team_leader_registration' => 'ثبت‌نام سرپرست اکیپ',
            'employer_registration' => 'ثبت‌نام کارفرما', 'agency_registration' => 'ثبت‌نام کاریابی',
            'team_roster_batch' => 'افزودن اعضای اکیپ (فاکتور جمعی)', 'contract_jobseeker' => 'کمیسیون قرارداد (کارجو)',
            'contract_employer' => 'کمیسیون قرارداد (کارفرما)', 'contract_team' => 'کمیسیون قرارداد گروهی (کارفرما)',
            'contract_team_member' => 'کمیسیون قرارداد گروهی (عضو)', 'contract_team_lumpsum' => 'کمیسیون قرارداد گروهی (یکجا)',
            'contract_agency' => 'قرارداد کاریابی-کارفرما',
        ];
        $total = 0;
        $out = [];
        foreach ($rows as $r) {
            $total += (int) $r['total_rial'];
            $out[] = ['label' => $labels[$r['payment_type']] ?? $r['payment_type'], 'count' => (int) $r['cnt'], 'toman' => intdiv((int) $r['total_rial'], 10)];
        }
        return ['total_toman' => intdiv($total, 10), 'rows' => $out];
    }

    public static function render() {
        if (!self::allowed()) { echo '<div class="wrap"><p>دسترسی مجاز نیست.</p></div>'; return; }
        global $wpdb;
        $prefix = $wpdb->prefix;

        $users = self::role_counts();
        $jobs = self::job_counts();
        $apps = self::table_status_counts('jj_applications');
        $contracts = self::table_status_counts('jj_contracts');
        $revenue = self::revenue_summary();
        $teams_active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}jj_teams WHERE status='active'");
        $members_confirmed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}jj_team_members WHERE status='confirmed'");

        $app_labels = ['pending' => 'در انتظار بررسی', 'accepted' => 'پذیرفته‌شده', 'rejected' => 'رد شده'];
        $contract_labels = ['draft_pending' => 'در انتظار بررسی کاریابی', 'pending_payment' => 'در انتظار پرداخت', 'active' => 'فعال', 'rejected' => 'رد شده'];

        $card = function ($title, $value, $color = '#0d9488') {
            printf(
                '<div style="background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.1);padding:16px 20px;min-width:150px;">
                    <div style="font-size:13px;color:#6b7280;margin-bottom:6px;">%s</div>
                    <div style="font-size:24px;font-weight:bold;color:%s;">%s</div>
                </div>',
                esc_html($title), esc_attr($color), esc_html($value)
            );
        };
        ?>
        <div class="wrap" dir="rtl">
            <h1>داشبورد آماری کاریابی جوان</h1>

            <h2 style="margin-top:24px;">کاربران (مجموع: <?php echo esc_html($users['total']); ?>)</h2>
            <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;">
                <?php foreach ($users['rows'] as $r) { $card($r['label'], $r['count']); } ?>
            </div>

            <h2>آگهی‌های شغلی</h2>
            <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;">
                <?php
                $card('منتشرشده', $jobs['publish'], '#059669');
                $card('در انتظار بررسی', $jobs['pending'], '#d97706');
                $card('رد شده', $jobs['trash'], '#dc2626');
                ?>
            </div>

            <h2>درخواست‌های شغلی</h2>
            <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;">
                <?php foreach ($app_labels as $key => $label) { $card($label, $apps[$key] ?? 0); } ?>
            </div>

            <h2>قراردادها</h2>
            <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;">
                <?php foreach ($contract_labels as $key => $label) { $card($label, $contracts[$key] ?? 0); } ?>
            </div>

            <h2>اکیپ‌ها</h2>
            <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;">
                <?php
                $card('اکیپ‌های فعال', $teams_active);
                $card('اعضای تأییدشده', $members_confirmed);
                ?>
            </div>

            <h2>درآمد (فقط تراکنش‌های موفق)</h2>
            <div style="background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.1);padding:20px;max-width:600px;margin-bottom:24px;">
                <div style="font-size:28px;font-weight:bold;color:#0d9488;margin-bottom:14px;">
                    <?php echo number_format($revenue['total_toman']); ?> تومان
                </div>
                <?php if (empty($revenue['rows'])): ?>
                    <p style="color:#6b7280;">هنوز تراکنش موفقی ثبت نشده.</p>
                <?php else: ?>
                    <table class="widefat">
                        <thead><tr><th>نوع</th><th>تعداد</th><th>مبلغ (تومان)</th></tr></thead>
                        <tbody>
                        <?php foreach ($revenue['rows'] as $r): ?>
                            <tr><td><?php echo esc_html($r['label']); ?></td><td><?php echo esc_html($r['count']); ?></td><td><?php echo number_format($r['toman']); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
