<?php
if (!defined('ABSPATH')) exit;

class JJ_Roles {

    /** نگاشت نقش‌ها طبق سند معماری مرحله ۱ */
    const ROLES = [
        'jj_basic_user'            => 'کاربر ساده',
        'jj_jobseeker'              => 'کارجوی فردی',
        'jj_team_leader'            => 'سرپرست اکیپ',
        'jj_employer'               => 'کارفرما',
        'jj_agency'                 => 'کاریابی',
        'jj_owner'                  => 'مدیر کل',
        'jj_master_admin'           => 'مدیر ارشد',
        'jj_admin_l2'               => 'ادمین سطح ۲',
        'jj_verification_officer'   => 'اپراتور مدارک',
        'jj_support'                => 'پشتیبان',
        'jj_finance'                => 'حسابداری',
        'jj_content_mod'            => 'مدیر محتوا',
        'jj_tech_admin'             => 'مدیر فنی',
        // نقش‌های میانی: بین انتخاب نقش و تأیید نهایی مدارک توسط اپراتور
        // (رفع باگ: بدون این‌ها کاربر در ورود بعدی دوباره به صفحه‌ی
        // انتخاب نقش برمی‌گشت چون هنوز jj_basic_user بود)
        // نام این دو نقش قبلاً «در انتظار تأیید مدارک» بود، ولی نقش همان
        // لحظه‌ی انتخاب نقش داده می‌شود — پیش از پرکردن پروفایل و پیش از
        // بارگذاری هر مدرکی. نتیجه این بود که مدیر در فهرست کاربران کسی را
        // «در انتظار تأیید مدارک» می‌دید که هنوز هیچ مدرکی نفرستاده بود، و
        // بعد در صف بررسی مدارک — که درست هم بود — چیزی پیدا نمی‌کرد.
        // این نقش هر سه مرحله‌ی pending و pending_documents و pending_review
        // را پوشش می‌دهد، پس نامش باید همان «در جریان بودن ثبت‌نام» باشد، نه
        // ادعای مرحله‌ای مشخص. وضعیت دقیق هر کاربر در صفحه‌ی «بررسی مدارک» است.
        'jj_employer_pending'       => 'کارفرما (ثبت‌نام در جریان)',
        'jj_agency_pending'         => 'کاریابی (ثبت‌نام در جریان)',
    ];

    /** Capability های اختصاصی این پلتفرم (علاوه بر read/edit_posts استاندارد وردپرس) */
    const CAPS = [
        'jj_view_identity',      // مشاهده اطلاعات هویتی محرمانه (فقط بعد از قرارداد فعال)
        'jj_manage_teams',
        'jj_apply_jobs',
        'jj_post_jobs',
        'jj_approve_documents',
        'jj_manage_finance',
        'jj_manage_content',
        'jj_manage_tech',
        'jj_manage_users',
        'jj_manage_all',          // فقط مدیر کل و مدیر ارشد
    ];

    public static function init() {
        // جای قلاب‌های آینده (مثلاً بازتنظیم دوره‌ای دسترسی‌ها) در صورت نیاز مراحل بعد.
    }

    public static function register_roles() {
        foreach (self::ROLES as $slug => $label) {
            if (!get_role($slug)) {
                add_role($slug, $label, ['read' => true]);
            }
        }
        self::sync_role_names();
        self::apply_capabilities();
    }

    /**
     * نام نمایشی نقش‌ها را با ROLES هم‌تراز می‌کند.
     *
     * add_role() فقط وقتی نقش وجود نداشته باشد کار می‌کند، و وردپرس نام
     * نمایشی را یک‌بار در آپشن wp_user_roles می‌نویسد؛ پس تغییر نام در این
     * کلاس به‌تنهایی هیچ اثری روی سایتی که نقش‌هایش قبلاً ساخته شده ندارد.
     * این متد اختلاف را پیدا و همان آپشن را به‌روز می‌کند. عمداً حذف و
     * ساختِ دوباره‌ی نقش انجام نمی‌شود، چون دسترسی‌ها و انتساب‌ها را به خطر
     * می‌انداخت — فقط نام عوض می‌شود.
     */
    public static function sync_role_names() {
        if (!function_exists('wp_roles')) {
            return;
        }
        $wp_roles = wp_roles();
        $changed = false;
        foreach (self::ROLES as $slug => $label) {
            if (isset($wp_roles->roles[$slug]) && ($wp_roles->roles[$slug]['name'] ?? '') !== $label) {
                $wp_roles->roles[$slug]['name'] = $label;
                $wp_roles->role_names[$slug]    = $label;
                $changed = true;
            }
        }
        if ($changed) {
            update_option($wp_roles->role_key, $wp_roles->roles);
        }
    }

    private static function apply_capabilities() {
        $map = [
            'jj_basic_user'            => [],
            'jj_jobseeker'              => ['jj_apply_jobs'],
            'jj_team_leader'            => ['jj_apply_jobs', 'jj_manage_teams'],
            // توجه: jj_view_identity عمداً اینجا داده نمی‌شود. طبق سند نقش‌ها،
            // اطلاعات هویتی فقط بعد از عقد قرارداد رسمی و به‌صورت مختص همان
            // پرونده باز می‌شود، نه به‌عنوان یک Capability دائمیِ کل نقش.
            // این Capability باید به‌صورت per-contract در فلوچارت ۷ (قرارداد) اعمال شود.
            'jj_employer'               => ['jj_post_jobs'],
            'jj_agency'                 => ['jj_post_jobs', 'jj_manage_teams'],
            'jj_employer_pending'       => [],
            'jj_agency_pending'         => [],
            'jj_owner'                  => ['jj_manage_all'],
            'jj_master_admin'           => ['jj_manage_all'],
            'jj_admin_l2'               => ['jj_approve_documents', 'jj_manage_users', 'jj_view_identity'],
            'jj_verification_officer'   => ['jj_approve_documents'],
            'jj_support'                => [],
            'jj_finance'                => ['jj_manage_finance'],
            'jj_content_mod'            => ['jj_manage_content'],
            'jj_tech_admin'             => ['jj_manage_tech'],
            'administrator'       => self::CAPS,
        ];

        foreach ($map as $slug => $caps) {
            $role = get_role($slug);
            if (!$role) continue;
            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    public static function upgrade_user_role($user_id, $new_role) {
        if (!array_key_exists($new_role, self::ROLES)) {
            return new WP_Error('invalid_role', 'نقش نامعتبر است.');
        }
        $user = new WP_User($user_id);
        $user->set_role($new_role);
        return true;
    }
}


if (!function_exists('jj_roles_sync_admin_caps')) {
    function jj_roles_sync_admin_caps() {
        // گیت از '2' به '3' رفت تا تغییر نام نقش‌های «ثبت‌نام در جریان» یک‌بار
        // روی سایتی که نقش‌هایش از قبل ساخته شده هم اعمال شود.
        if (get_option('jj_roles_admin_cap_synced') === '3') return;
        JJ_Roles::register_roles();
        update_option('jj_roles_admin_cap_synced', '3');
    }
    add_action('admin_init', 'jj_roles_sync_admin_caps');
}
