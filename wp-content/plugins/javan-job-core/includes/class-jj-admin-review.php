<?php
if (!defined('ABSPATH')) exit;

/**
 * پنل بررسی مدارک (اپراتور مدارک / jj_verification_officer) در پیشخوان وردپرس.
 * تکمیل حلقه‌ی فلوچارت ۲: بعد از پرداخت موفق کارفرما/کاریابی (documents_status=pending_review)،
 * اپراتور این‌جا پروفایل و مدارک را می‌بیند و تأیید/رد می‌کند. تأیید = نقش نهایی
 * (jj_employer / jj_agency) فعال می‌شود.
 */
class JJ_Admin_Review {

    public static function init() {
        // اولویت ۲۰: زیرمنو زیر 'jj-settings' است — نگاه کنید به توضیح مشابه
        // در class-jj-admin-jobs-review.php.
        add_action('admin_menu', [__CLASS__, 'add_menu'], 20);
        add_action('admin_notices', [__CLASS__, 'render_pending_notice']);
        add_action('admin_post_jj_review_decision', [__CLASS__, 'handle_decision']);
    }

    private static function allowed() {
        return current_user_can('jj_approve_documents') || current_user_can('manage_options') || current_user_can('jj_manage_all');
    }

    public static function render_pending_notice() {
        global $wpdb;
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && $screen->id === 'javan-job_page_jj-review' ) {
            return; // already on the review page itself
        }
        $prefix = $wpdb->prefix;
        $pending_employers = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}jj_employers WHERE documents_status='pending_review'");
        $pending_agencies  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}jj_agencies WHERE documents_status='pending_review'");
        if ( $pending_employers < 1 && $pending_agencies < 1 ) {
            return;
        }
        $url = admin_url( 'admin.php?page=jj-review' );
        $lines = [];
        if ( $pending_employers > 0 ) {
            $lines[] = sprintf( 'تعداد %d کاربر منتظر تائید مدارک جهت نقش کارفرما می‌باشند.', $pending_employers );
        }
        if ( $pending_agencies > 0 ) {
            $lines[] = sprintf( 'تعداد %d کاربر منتظر تائید مدارک جهت نقش کاریابی می‌باشند.', $pending_agencies );
        }
        echo '<div class="notice notice-warning"><p>' . esc_html( implode( ' ', $lines ) ) . ' ' .
            '<a href="' . esc_url( $url ) . '">مشاهده و بررسی</a></p></div>';
    }

    public static function add_menu() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $pending = (int) $wpdb->get_var("SELECT (SELECT COUNT(*) FROM {$prefix}jj_employers WHERE documents_status='pending_review') + (SELECT COUNT(*) FROM {$prefix}jj_agencies WHERE documents_status='pending_review')");
        $label = 'بررسی مدارک';
        if ($pending > 0) { $label .= ' <span class="awaiting-mod count-' . $pending . '"><span class="pending-count">' . $pending . '</span></span>'; }
        add_submenu_page(
            'jj-settings', 'بررسی مدارک', $label, 'read',
            'jj-review', [__CLASS__, 'render']
        );
    }

    public static function handle_decision() {
        if (!self::allowed()) wp_die('دسترسی مجاز نیست.');
        check_admin_referer('jj_review_decision');
        global $wpdb;
        $prefix = $wpdb->prefix;

        $user_id = (int) ($_POST['user_id'] ?? 0);
        $type = sanitize_text_field($_POST['owner_type'] ?? '');
        $decision = sanitize_text_field($_POST['decision'] ?? '');
        $rejection_reason = isset( $_POST['rejection_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rejection_reason'] ) ) : '';
        if (!$user_id || !in_array($type, ['employer', 'agency'], true) || !in_array($decision, ['approve', 'reject', 'revision'], true)) {
            wp_die('درخواست نامعتبر است.');
        }
        if ('revision' === $decision && '' === trim($rejection_reason)) {
            wp_die('برای «اصلاح شود» باید توضیح اصلاحات را وارد کنید.');
        }

        $reviewer_id = get_current_user_id();
        $new_status = $decision === 'approve' ? 'approved' : ( $decision === 'revision' ? 'needs_revision' : 'rejected' );
        $table = $type === 'employer' ? 'jj_employers' : 'jj_agencies';

        // بررسی حیاتی: باید واقعاً ردیفی با مدارکِ «در انتظار بررسی» برای
        // همین کاربر/نوع وجود داشته باشد. بدون این بررسی، دارنده‌ی نقش محدود
        // jj_approve_documents می‌توانست user_id دلخواه (مثلاً حساب خودش با
        // نقش دیگر، یا هر کاربر دیگری که هرگز مدرکی نفرستاده) را با
        // decision=approve بفرستد و مستقیم نقش jj_employer/jj_agency را
        // بدون هیچ مدرک واقعی به او بدهد — کل گردش‌کار تأیید مدارک را دور می‌زد.
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$prefix}{$table} WHERE user_id=%d AND documents_status='pending_review'", $user_id
        ));
        if (!$existing) {
            wp_die('مدرکی با وضعیت «در انتظار بررسی» برای این کاربر یافت نشد.');
        }

        $wpdb->update($prefix . $table, [
            'documents_status' => $new_status,
            'verified_at' => current_time('mysql'),
            'verified_by' => $reviewer_id,
        ], ['user_id' => $user_id]);
        if ( 'reject' === $decision ) {
            update_user_meta( $user_id, 'jj_rejection_reason', $rejection_reason );
            delete_user_meta( $user_id, 'jj_revision_note' );
        } elseif ( 'revision' === $decision ) {
            update_user_meta( $user_id, 'jj_revision_note', $rejection_reason );
            delete_user_meta( $user_id, 'jj_rejection_reason' );
        } else {
            delete_user_meta( $user_id, 'jj_rejection_reason' );
            delete_user_meta( $user_id, 'jj_revision_note' );
        }

        // فقط مدارکِ همین دسته‌ی «در انتظار بررسی» باید وضعیت بگیرند، نه هر
        // مدرکی که این مالک تا‌به‌حال آپلود کرده — وگرنه این UPDATE، تاریخچه‌ی
        // دورهای قبلیِ تصمیم ادمین (approved/rejected/needs_revision قدیمی) را
        // هم با تصمیم همین دور بازنویسی می‌کرد.
        $wpdb->update($prefix . 'jj_documents', [
            'status' => $new_status,
            'reviewed_by' => $reviewer_id,
            'reviewed_at' => current_time('mysql'),
        ], ['owner_type' => $type, 'owner_id' => $user_id, 'status' => 'pending']);

        if ($decision === 'approve') {
            JJ_Roles::upgrade_user_role($user_id, $type === 'employer' ? 'jj_employer' : 'jj_agency');
        }

        $user = get_userdata($user_id);
        if ($user) {
            $role_label = $type === 'employer' ? 'کارفرما' : 'کاریابی';
            if ($decision === 'approve') {
                // کارفرما هزینه‌ای برای فعال‌سازی نهایی ندارد (فقط کاریابی باید بسته‌ی اشتراکی تهیه کند)،
                // پس نباید به کارفرما گفته شود که باید «مرحله‌ی پرداخت» را تکمیل کند.
                $msg = 'employer' === $type
                    ? "مدارک شما به عنوان {$role_label} تأیید شد. برای تکمیل ثبت‌نام و فعال‌سازی نهایی حساب، وارد سایت شوید."
                    : "مدارک شما به عنوان {$role_label} تأیید شد. برای تکمیل ثبت‌نام و فعال‌سازی نهایی حساب، وارد سایت شوید و مرحله‌ی پرداخت را تکمیل کنید.";
                $notify_type = 'document_approved';
                $title = 'تأیید مدارک';
            } elseif ($decision === 'revision') {
                $msg = "مدارک/اطلاعات ارسالی شما به عنوان {$role_label} نیاز به اصلاح دارد: {$rejection_reason} لطفاً وارد سایت شوید و موارد خواسته‌شده را اصلاح و دوباره ارسال کنید.";
                $notify_type = 'document_needs_revision';
                $title = 'نیاز به اصلاح مدارک';
            } else {
                $msg = "متأسفانه مدارک ارسالی شما به عنوان {$role_label} تأیید نشد. برای اطلاعات بیشتر با پشتیبانی تماس بگیرید.";
                $notify_type = 'document_rejected';
                $title = 'رد مدارک';
            }
            JJ_Notify::send($user_id, $notify_type, $title, $msg);
        // پیامک تایید/رد/اصلاح قبلاً توسط JJ_Notify::send() ارسال می‌شود (خط بالا) — ارسال مستقیم اینجا حذف شد تا پیامک دوبار نرود.
        }

        wp_redirect(admin_url('admin.php?page=jj-review&done=' . $decision));
        exit;
    }

    private static function pending_rows($table, $type) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $name_col = $type === 'employer' ? 'company_name' : 'agency_name';
        $info_col = $type === 'employer' ? 'company_info' : 'owner_info';
        $rows = $wpdb->get_results(
            "SELECT user_id, {$name_col} AS name, {$info_col} AS info, created_at FROM {$prefix}{$table} WHERE documents_status='pending_review' ORDER BY created_at ASC",
            ARRAY_A
        );
        foreach ($rows as &$r) { $r['type'] = $type; }
        return $rows;
    }

    /**
     * کارفرما/کاریابی‌هایی که نه تأیید شده‌اند و نه در صف بررسی‌اند — یعنی
     * جایی وسط مسیر مانده‌اند (پروفایل ناقص، یا پروفایل کامل ولی هنوز مدرکی
     * بارگذاری نشده، یا درخواست اصلاح/رد که بی‌پاسخ مانده).
     *
     * بدون این بخش، چنین کاربری برای مدیر کاملاً نامرئی بود: صف بررسی فقط
     * documents_status='pending_review' را نشان می‌دهد، پس کسی که گیر کرده
     * هیچ‌جای پیشخوان دیده نمی‌شد و تنها راه فهمیدنش این بود که خودش تماس بگیرد.
     */
    private static function stuck_rows($table, $type) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $name_col = $type === 'employer' ? 'company_name' : 'agency_name';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, {$name_col} AS name, documents_status, created_at
             FROM {$prefix}{$table}
             WHERE documents_status NOT IN ('approved', 'pending_review') OR documents_status IS NULL
             ORDER BY created_at ASC LIMIT %d", 200
        ), ARRAY_A);
        foreach ($rows as &$r) {
            $r['type'] = $type;
            $r['doc_count'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}jj_documents WHERE owner_type=%s AND owner_id=%d",
                $type, $r['user_id']
            ));
        }
        return $rows;
    }

    private static function stuck_label($status) {
        $labels = [
            'pending'           => 'پروفایل هنوز تکمیل نشده',
            'pending_documents' => 'پروفایل کامل — هنوز مدرکی بارگذاری نکرده',
            'needs_revision'    => 'اصلاح خواسته شده — پاسخی نداده',
            'rejected'          => 'رد شده',
        ];
        return $labels[$status] ?? ('وضعیت نامشخص: ' . $status);
    }

    private static function summarize_info($json) {
        $data = json_decode($json, true);
        if (!is_array($data)) return '';
        $lines = [];
        $walk = function ($arr, $prefix = '') use (&$walk, &$lines) {
            foreach ($arr as $k => $v) {
                if (is_array($v)) { $walk($v, $prefix . $k . '.'); }
                elseif ($v !== '') { $lines[] = esc_html($prefix . $k . ': ' . $v); }
            }
        };
        $walk($data);
        return implode('<br>', $lines);
    }

    public static function render() {
        if (!self::allowed()) { echo '<div class="wrap"><p>دسترسی مجاز نیست.</p></div>'; return; }
        global $wpdb;
        $prefix = $wpdb->prefix;

        $rows = array_merge(self::pending_rows('jj_employers', 'employer'), self::pending_rows('jj_agencies', 'agency'));
        ?>
        <div class="wrap" dir="rtl">
            <h1>بررسی مدارک کارفرما / کاریابی</h1>
            <?php if (!empty($_GET['done'])): ?>
                <?php
                $done_labels = ['approve' => 'تأیید شد.', 'reject' => 'رد شد.', 'revision' => 'برای اصلاح به کاربر بازگردانده شد.'];
                $done_label = $done_labels[$_GET['done']] ?? 'ثبت شد.';
                ?>
                <div class="notice notice-success"><p><?php echo esc_html($done_label); ?></p></div>
            <?php endif; ?>

            <?php if (empty($rows)): ?>
                <p>هیچ درخواست در انتظار بررسی وجود ندارد.</p>
            <?php else: foreach ($rows as $row):
                $user = get_userdata($row['user_id']);
                $docs = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, file_name FROM {$prefix}jj_documents WHERE owner_type=%s AND owner_id=%d ORDER BY id",
                    $row['type'], $row['user_id']
                ), ARRAY_A);
            ?>
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:16px;margin-bottom:16px;max-width:800px;">
                    <h2 style="margin-top:0;">
                        <?php echo $row['type'] === 'employer' ? '🏢 کارفرما' : '🤝 کاریابی'; ?> —
                        <?php echo esc_html($row['name']); ?>
                    </h2>
                    <p><strong>موبایل:</strong> <?php echo esc_html($user ? $user->user_login : '—'); ?> |
                       <strong>تاریخ ثبت:</strong> <?php echo esc_html($row['created_at']); ?></p>
                    <p style="font-size:13px;color:#555;"><?php echo self::summarize_info($row['info']); ?></p>
                    <p><strong>مدارک:</strong>
                        <?php if (empty($docs)): ?>
                            <span style="color:#dc2626;">هیچ فایلی بارگذاری نشده</span>
                        <?php else: foreach ($docs as $d): $jj_dl_token = class_exists('JJ_Session') ? JJ_Session::issue_token(get_current_user_id()) : ''; $jj_dl_url = rest_url('javanjob/v1/documents/' . $d['id'] . '/download'); if ($jj_dl_token) { $jj_dl_url = add_query_arg('token', $jj_dl_token, $jj_dl_url); } ?>
                            <a href="<?php echo esc_url($jj_dl_url); ?>" target="_blank">📄 <?php echo esc_html($d['file_name']); ?></a>&nbsp;
                        <?php endforeach; endif; ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px;">
                        <?php wp_nonce_field('jj_review_decision'); ?>
                        <input type="hidden" name="action" value="jj_review_decision">
                        <input type="hidden" name="user_id" value="<?php echo (int) $row['user_id']; ?>">
                        <input type="hidden" name="owner_type" value="<?php echo esc_attr($row['type']); ?>">
                        <button type="submit" name="decision" value="approve" class="button button-primary">✔ تأیید</button>
                        <button type="submit" name="decision" value="revision" class="button" onclick="return confirm('برای اصلاح به کاربر بازگردانده شود؟ توضیح اصلاحات را حتماً در باکس زیر بنویسید.')">🛠 اصلاح شود</button>
                        <button type="submit" name="decision" value="reject" class="button" onclick="return confirm('رد شود؟')">✘ رد</button>
                    <textarea name="rejection_reason" placeholder="دلیل رد یا توضیح اصلاحات (برای «اصلاح شود» الزامی است)" style="width:100%;min-height:50px;margin:6px 0;box-sizing:border-box;"></textarea>
  </form>
                </div>
            <?php endforeach; endif; ?>

            <?php
            $stuck = array_merge( self::stuck_rows( 'jj_employers', 'employer' ), self::stuck_rows( 'jj_agencies', 'agency' ) );
            if ( ! empty( $stuck ) ) :
            ?>
                <hr style="margin:28px 0;">
                <h2>در مسیر ثبت‌نام مانده‌اند (هنوز به صف بررسی نرسیده‌اند)</h2>
                <p style="color:#555;max-width:800px;">
                    این‌ها ثبت‌نام را شروع کرده‌اند ولی هنوز پرونده‌شان برای بررسی آماده نیست، پس دکمه‌ی
                    تأیید/رد ندارند. فهرست فقط برای این است که بدانید چه کسی کجا مانده و در صورت نیاز
                    پیگیری کنید.
                </p>
                <table class="widefat" style="max-width:900px;">
                    <thead>
                        <tr>
                            <th>نوع</th><th>نام</th><th>موبایل</th>
                            <th>وضعیت</th><th>مدارک بارگذاری‌شده</th><th>تاریخ ثبت‌نام</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $stuck as $srow ): $suser = get_userdata( $srow['user_id'] ); ?>
                        <tr>
                            <td><?php echo $srow['type'] === 'employer' ? '🏢 کارفرما' : '🤝 کاریابی'; ?></td>
                            <td><?php echo esc_html( $srow['name'] !== '' ? $srow['name'] : '—' ); ?></td>
                            <td><?php echo esc_html( $suser ? $suser->user_login : '—' ); ?></td>
                            <td><?php echo esc_html( self::stuck_label( $srow['documents_status'] ) ); ?></td>
                            <td><?php echo (int) $srow['doc_count']; ?></td>
                            <td><?php echo esc_html( $srow['created_at'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
