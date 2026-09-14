<?php
if (!defined('ABSPATH')) exit;

/**
 * صفحه‌ی کاربری کامل فلوچارت‌های ۱ و ۲: ورود/OTP، انتخاب نقش، و برای
 * کارفرما/کاریابی: فرم پروفایل کامل (سند سناریو نهایی، فرم ۵ و ۶)،
 * آپلود مدارک، و پرداخت زیبال.
 * استفاده: شورت‌کد [jj_login]
 */
class JJ_Frontend {

    public static function init() {
        add_shortcode('jj_login', [__CLASS__, 'render_login']);
        add_shortcode('jj_account', [__CLASS__, 'render_account']);
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_logged_in']);
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_account_page']);
        add_filter('wp_nav_menu_objects', [__CLASS__, 'inject_user_menu_label'], 20, 2);
        add_filter('walker_nav_menu_start_el', [__CLASS__, 'add_account_menu_dropdown'], 10, 4);
        add_filter('nav_menu_css_class', [__CLASS__, 'add_account_menu_li_class'], 10, 3);
        add_action('wp_footer', [__CLASS__, 'print_account_menu_dropdown_assets']);
    }

        /**
     * اگر کاربر از قبل وارد شده (کوکی وردپرس هنوز معتبره) و ثبت‌نامش هم کامل
     * شده — یعنی دیگه در میانه‌ی انتخاب‌نقش/تکمیل‌پروفایل/آپلود مدارک نیست —
     * ورود دوباره به صفحه‌ی jj-login (چه از خودِ سایت، چه از آیکون اپِ نصب‌شده
     * روی صفحه‌ی اصلی موبایل که همیشه همین صفحه رو باز می‌کنه) دیگه دوباره
     * فرم «شماره موبایل» رو نشون نمی‌ده؛ مستقیم به صفحه‌ی اصلی سایت می‌ره.
     * کاربرانی که هنوز ثبت‌نامشون کامل نشده (jj_basic_user یا در انتظار
     * تأیید کارفرما/کاریابی) از این ریدایرکت مستثنی‌اند تا مسیر تکمیل
     * ثبت‌نامشون (از جمله رفع‌باگ resumeEmployerAgencyFlow) دست‌نخورده بمونه.
     */
    public static function maybe_redirect_logged_in() {
        if (!is_page('jj-login') || !is_user_logged_in()) return;
        // بازگشت از درگاه پرداخت (مثلاً بعد از تأیید بسته‌ی اشتراک کارجو/سرپرست اکیپ —
        // که نقششون قبل از پرداخت نهایی می‌شه) نباید ریدایرکت بشه، وگرنه کاربر
        // پیام نتیجه‌ی پرداخت رو اصلاً نمی‌بینه.
        if (!empty($_GET['jj_payment'])) return;
        $roles = (array) wp_get_current_user()->roles;
        $needs_onboarding = array_intersect($roles, ['jj_basic_user', 'jj_employer_pending', 'jj_agency_pending']);
        $has_final_role = array_intersect($roles, ['jj_jobseeker', 'jj_team_leader', 'jj_employer', 'jj_agency']);
        if (!$needs_onboarding && $has_final_role) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }
	
	    /**
		     * وقتی کاربر وارد شده باشد، آیتم منوی «ورود / ثبت‌نام» را با نام و عکس او جایگزین می‌کند.
			      */
	public static function inject_user_menu_label($items, $args) {
		if (!is_user_logged_in()) return $items;
		$user = wp_get_current_user();
		$display = $user->display_name ? $user->display_name : $user->user_login;
		$avatar_url = self::resolve_menu_avatar_url($user);
		if ($avatar_url) {
			$avatar = '<img src="' . esc_url($avatar_url) . '" width="22" height="22" class="jj-menu-avatar" alt="" />';
		} else {
			$avatar = get_avatar($user->ID, 22, '', '', ['class' => 'jj-menu-avatar']);
		}
		foreach ($items as $item) {
			$title = isset($item->title) ? $item->title : '';
			if (strpos($title, 'ثبت') !== false || strpos($title, 'ورود') !== false) {
				// طبق درخواست صریح مدیر سایت: این آیتم منو دیگر مستقیماً به صفحه‌ی ورود/ثبت‌نام
				// لینک نمی‌شود؛ صفحه‌ی /account/ یک صفحه‌ی کاملاً جدا و اختصاصی برای پروفایل و
				// داشبورد است. کلیک روی این آیتم (به‌جای رفتن مستقیم) یک منوی کشویی با دو گزینه‌ی
				// «پروفایل/داشبورد» و «خروج» باز می‌کند (نگاه کنید به add_account_menu_dropdown()).
				$item->title = $avatar . '<span class="jj-menu-username">' . esc_html($display) . '</span>';
				$item->url = home_url('/account/');
				$item->jj_account_menu = true;
			}
		}
		return $items;
	}

	/**
	 * برای آیتم منوی نام‌کاربر (که در inject_user_menu_label علامت‌گذاری شده)، یک منوی
	 * کشویی با دو گزینه‌ی «پروفایل/داشبورد» و «خروج» را درست بعد از تگ <a> همان آیتم
	 * (و همچنان داخل همان <li>) اضافه می‌کند. از فیلتر هسته‌ی وردپرس walker_nav_menu_start_el
	 * استفاده شده چون HTML کامل و نهاییِ هر آیتم منو (شامل <a href>...</a>) را در اختیار می‌گذارد؛
	 * بنابراین به‌جای تو در تو کردن یک <a> داخل <a> دیگر (که HTML نامعتبر است)، این منو به‌عنوان
	 * یک عنصر خواهر و هم‌سطح بعد از بستنِ تگ <a> اضافه می‌شود.
	 */
	public static function add_account_menu_dropdown($item_output, $item, $depth, $args) {
		if (empty($item->jj_account_menu)) return $item_output;
		$account_url = esc_url(home_url('/account/'));
		$logout_url  = esc_url(wp_logout_url(home_url('/')));
		$logout_rest_url = esc_js(esc_url_raw(rest_url('javanjob/v1/logout')));
		$logout_onclick = "try{var t=localStorage.getItem('jj_auth_token');}catch(e){t=null;} try{localStorage.removeItem('jj_auth_token');}catch(e){} try{document.cookie='jj_auth_token=;path=/;max-age=0';}catch(e){} if(t){try{fetch('" . $logout_rest_url . "',{method:'POST',headers:{'X-JJ-Auth':t},credentials:'same-origin',keepalive:true}).catch(function(){});}catch(e){}}";
		$item_output .= '<div class="jj-user-menu-dropdown">'
			. '<a href="' . $account_url . '">پروفایل/داشبورد</a>'
			. '<a href="' . $logout_url . '" onclick="' . esc_attr($logout_onclick) . '">خروج</a>'
			. '</div>';
		return $item_output;
	}

	/** به <li> آیتم منوی نام‌کاربر کلاس jj-user-menu-li می‌دهد تا منوی کشویی بتواند نسبت به آن position:relative موضع‌گیری شود. */
	public static function add_account_menu_li_class($classes, $item, $args) {
		if (!empty($item->jj_account_menu)) { $classes[] = 'jj-user-menu-li'; }
		return $classes;
	}

	/** CSS و جاوااسکریپت سراسری (همه‌ی صفحات سایت) برای باز/بسته‌شدن منوی کشویی «پروفایل/داشبورد و خروج». */
	public static function print_account_menu_dropdown_assets() {
		if (!is_user_logged_in()) return;
		?>
		<style>
		.jj-user-menu-li { position: relative; }
		.jj-user-menu-dropdown {
			display: none; position: absolute; <?php echo is_rtl() ? 'right:0;' : 'left:0;'; ?> top: 100%; margin-top: 6px;
			background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.14);
			min-width: 170px; z-index: 99999; padding: 6px; text-align: right;
		}
		.jj-user-menu-dropdown.jj-open { display: block; }
		.jj-user-menu-dropdown a { display: block; padding: 9px 12px; font-size: 13px; color: #14213d !important; text-decoration: none; border-radius: 7px; white-space: nowrap; }
		.jj-user-menu-dropdown a:hover { background: #f3f4f6; }
		</style>
		<script>
		(function () {
			document.addEventListener('click', function (e) {
				if (e.target.closest('.jj-user-menu-dropdown')) return;
				var li = e.target.closest('.jj-user-menu-li');
				var openDd = document.querySelector('.jj-user-menu-dropdown.jj-open');
				if (li) {
					e.preventDefault();
					var dd = li.querySelector('.jj-user-menu-dropdown');
					var wasOpen = dd.classList.contains('jj-open');
					if (openDd) openDd.classList.remove('jj-open');
					if (!wasOpen) dd.classList.add('jj-open');
					return;
				}
				if (openDd) openDd.classList.remove('jj-open');
			});
		})();
		</script>
		<?php
	}

	/**
	 * آدرس عکس آواتار مناسب کاربر برای نمایش در منو (و صفحه‌ی پروفایل) را برمی‌گرداند:
	 * ابتدا عکسی که خودِ کاربر آپلود کرده (ستون avatar_attachment_id در جدول متناظر با
	 * نوع کاربری‌اش)، وگرنه آواتار پیش‌فرضِ متناسب با نوع کاربری از رسانه‌های پیشخوان
	 * وردپرس، و در نهایت اگر هیچ‌کدام یافت نشد رشته‌ی خالی (برای بازگشت به get_avatar()).
	 */
	public static function resolve_menu_avatar_url($user) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$user_id = (int) $user->ID;
		$roles = (array) $user->roles;
		$attachment_id = 0;
		$role_key = 'jobseeker';

		if (in_array('jj_team_leader', $roles, true)) {
			$role_key = 'team-leader';
			$attachment_id = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT avatar_attachment_id FROM {$prefix}jj_teams WHERE leader_user_id=%d AND status='active'", $user_id
			));
		} elseif (user_can($user, 'jj_apply_jobs')) {
			$role_key = 'jobseeker';
			$attachment_id = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT avatar_attachment_id FROM {$prefix}jj_jobseekers WHERE user_id=%d", $user_id
			));
		} elseif (array_intersect(['jj_employer', 'jj_employer_pending'], $roles)) {
			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT avatar_attachment_id, company_info FROM {$prefix}jj_employers WHERE user_id=%d", $user_id
			), ARRAY_A);
			$attachment_id = $row ? (int) $row['avatar_attachment_id'] : 0;
			$applicant_type = '';
			if ($row && !empty($row['company_info'])) {
				$info = json_decode($row['company_info'], true);
				$applicant_type = isset($info['applicant_type']) ? $info['applicant_type'] : '';
			}
			$role_key = ('contractor' === $applicant_type) ? 'employer-contractor' : 'employer-company';
		} elseif (array_intersect(['jj_agency', 'jj_agency_pending'], $roles)) {
			$role_key = 'agency';
			$attachment_id = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT avatar_attachment_id FROM {$prefix}jj_agencies WHERE user_id=%d", $user_id
			));
		}

		if ($attachment_id) {
			$url = wp_get_attachment_url($attachment_id);
			if ($url) return $url;
		}

		// آواتارهای پیش‌فرض بر اساس نوع کاربری (از پیش در رسانه‌های سایت بارگذاری شده‌اند).
		$default_avatar_ids = [
			'jobseeker'           => 2861,
			'team-leader'         => 2860,
			'employer-company'    => 2864,
			'employer-contractor' => 2865,
			'agency'              => 2866,
		];
		if (isset($default_avatar_ids[$role_key])) {
			$url = wp_get_attachment_url($default_avatar_ids[$role_key]);
			if ($url) return $url;
		}

		return '';
	}

    private static function jj_icon($name) {
        $icons = [
            'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
            'account' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M14 10h4M14 14h4"/></svg>',
            'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>',
            'star' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>',
            'briefcase' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
            'doc' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/></svg>',
            'building' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18"/><path d="M9 8h1M9 12h1M9 16h1M14 8h1M14 12h1M14 16h1"/></svg>',
            'money' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-1 1-1.5 3-1.5s3 .8 3 2-1 1.7-3 2-3 1-3 2 1.3 2 3 2 3-.5 3-1.5"/></svg>',
            'flag' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4"/><path d="M5 4h13l-3 4 3 4H5"/></svg>',
        ];
        return isset($icons[$name]) ? $icons[$name] : '';
    }

    private static function jj_stepper($steps, $current) {
        $html = '<div class="jj-stepper">';
        $total = count($steps);
        foreach ($steps as $i => $step) {
            $state = $i < $current ? 'jj-step-done' : ($i === $current ? 'jj-step-active' : '');
            $html .= '<div class="jj-stepper-item ' . $state . '" data-stepper-index="' . $i . '"><div class="jj-stepper-circle">' . self::jj_icon($step['icon']) . '</div><div class="jj-stepper-label">' . esc_html($step['label']) . '</div></div>';
            if ($i < $total - 1) {
                $line_state = $i < $current ? 'jj-step-done' : '';
                $html .= '<div class="jj-stepper-line ' . $line_state . '"></div>';
            }
        }
        $html .= '</div>';
        return $html;
    }

    public static function render_login() {
        if (!is_user_logged_in()) {
            $cookie_user_id = JJ_Session::resolve_user_id_from_cookie();
            if ($cookie_user_id) wp_set_current_user($cookie_user_id);
        }
        $rest_url = esc_url(rest_url('javanjob/v1/'));
        $initial_token = is_user_logged_in() ? JJ_Session::issue_token(get_current_user_id()) : '';
        $i18n_all = [];
        foreach (array_keys(JJ_I18N::LANGS) as $lc) { $i18n_all[$lc] = JJ_I18N::dictionary($lc); }

        // یکسان‌سازیِ تشخیصِ زبان (۱۴۰۵/۰۶/۱۴): این ویجت (ورود/داشبورد) از تابعی
        // به نام jj_i18n_current_locale() استفاده می‌کرد که در هیچ‌کجای پلاگین
        // تعریف نشده بود — یعنی function_exists() همیشه false برمی‌گشت و
        // $__jj_locale برای همه‌ی بازدیدکننده‌ها، همیشه و بدون استثنا، دقیقاً
        // «fa» بود؛ نه تنظیم زبانِ مرورگر و نه انتخابِ دستیِ قبلیِ کاربر هیچ‌کدام
        // اثری نداشتند (و چون setLang() سمتِ کلاینت هم فقط localStorage را
        // می‌نوشت نه کوکی را، همین «fa»یِ ثابت در هر بارگذاریِ بعدیِ صفحه، حتی
        // localStorage را هم بازنویسی و انتخابِ قبلیِ کاربر را پاک می‌کرد).
        // صفحات اشتراک/قیمت‌گذاریِ سایت (subscription-phase*.php) از قبل و
        // به‌درستی از JJ_I18N::current_lang() استفاده می‌کنند — همان تابعی که
        // به‌ترتیب: ترجیحِ ذخیره‌شده‌ی کاربرِ واردشده، پارامترِ ?lang=، کوکیِ
        // jj_lang (که اکنون setLang() آن را می‌نویسد — نگاه کنید به پایینِ همین
        // فایل)، و در نهایت Accept-Language مرورگر را بررسی می‌کند. این‌جا هم از
        // همان تابعِ واحد استفاده می‌شود تا کل سایت یک رفتارِ یکسان داشته باشد.
        $__jj_locale = JJ_I18N::current_lang();
        $__jj_dir = in_array($__jj_locale, JJ_I18N::RTL_LANGS, true) ? 'rtl' : 'ltr';

        ob_start();
        ?>
        <div id="jj-app" class="jj-wrap" dir="<?php echo esc_attr($__jj_dir); ?>">
            <div style="position:fixed;top:14px;left:14px;z-index:60;">
                <!-- یکسان‌سازیِ تشخیصِ زبان (۱۴۰۵/۰۶/۱۴): این دراپ‌داون قبلاً با
                     display:none کاملاً مخفی بود؛ یعنی حتی با تعمیرِ تشخیصِ زبان،
                     کاربر راهی برای انتخابِ دستیِ زبان نداشت. طبق تأییدِ مدیر سایت
                     نمایان شد. -->
                <select id="jj-lang-switch" style="border:1px solid #d1d5db;border-radius:8px;padding:6px 10px;font-size:12.5px;background:#fff;cursor:pointer;">
                    <?php foreach (JJ_I18N::LANGS as $code => $label): ?>
                        <?php /* یکسان‌سازیِ چندزبانگی (۱۴۰۵/۰۶/۱۶): روسی حالا در سراسر سایت
                             (سوئیچر قالب + jj_i18n_current_locale()) زبانی کاملاً معتبر و
                             قابل‌انتخاب است؛ نادیده‌گرفتنش فقط همین‌جا باعث می‌شد کاربری که
                             از سوئیچرِ بالای سایت روسی را انتخاب کرده، داخل همین ویجت نتواند
                             دوباره آن را از این فهرست انتخاب کند. */ ?>
                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="jj-help-fab-wrap" style="position:fixed;bottom:20px;left:20px;z-index:50;display:flex;flex-direction:column;align-items:center;gap:4px;">
                <button id="jj-help-fab" data-i18n="help_title" data-i18n-title title="راهنما" style="background:#14213d;background-image:url('https://javanjob.ir/wp-content/uploads/2026/08/avatar-site-guide.webp');background-size:cover;background-position:center;color:#fff;border:none;border-radius:50%;width:46px;height:46px;font-size:20px;cursor:pointer;box-shadow:0 3px 10px rgba(0,0,0,.2);"></button>
                <span data-i18n="site_guide_label" style="font-size:10.5px;color:#14213d;background:#fff;padding:1px 7px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.15);white-space:nowrap;">راهنمای سایت</span>
            </div>
            <button id="jj-install-fab" style="display:none;position:fixed;bottom:172px;left:20px;z-index:50;background:#c9a227;color:#14213d;border:none;border-radius:50%;width:46px;height:46px;font-size:20px;cursor:pointer;box-shadow:0 3px 10px rgba(0,0,0,.2);" data-i18n="install_app" data-i18n-title title="نصب اپلیکیشن روی گوشی">⬇️</button>
            <div id="jj-ai-fab-wrap" style="position:fixed;bottom:96px;left:20px;z-index:50;display:flex;flex-direction:column;align-items:center;gap:4px;">
                <button id="jj-ai-fab" style="background:#4f46e5;background-image:url('https://javanjob.ir/wp-content/uploads/2026/08/avatar-ai-expert-1.webp');background-size:cover;background-position:center;color:#fff;border:none;border-radius:50%;width:46px;height:46px;font-size:20px;cursor:pointer;box-shadow:0 3px 10px rgba(0,0,0,.2);" data-i18n="ai_advisor" data-i18n-title title="کارشناس مشاور"></button>
                <span data-i18n="ai_expert_label" style="font-size:10.5px;color:#14213d;background:#fff;padding:1px 7px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.15);white-space:nowrap;">کارشناس هوش مصنوعی</span>
            </div>
            <div id="jj-ai-panel" style="display:none;position:fixed;bottom:170px;left:20px;z-index:50;width:320px;max-width:calc(100vw - 40px);height:420px;max-height:calc(100vh - 160px);background:#fff;border-radius:14px;box-shadow:0 8px 26px rgba(0,0,0,.2);flex-direction:column;overflow:hidden;" dir="<?php echo esc_attr($__jj_dir); ?>">
                <div style="background:#4f46e5;color:#fff;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;">
                    <strong id="jj-ai-title" data-i18n="ai_advisor" style="font-size:14px;">کارشناس مشاور</strong>
                    <span>
                        <button id="jj-ai-clear" data-i18n="clear_chat_title" data-i18n-title title="پاک‌کردن گفت‌وگو" style="background:none;border:none;color:#fff;cursor:pointer;font-size:13px;margin-left:8px;">🗑️</button>
                        <button id="jj-ai-close" style="background:none;border:none;color:#fff;cursor:pointer;font-size:16px;">✕</button>
                    </span>
                </div>
                <div id="jj-ai-messages" style="flex:1;overflow-y:auto;padding:12px;font-size:13px;background:#f9fafb;"></div>
                <div style="display:flex;border-top:1px solid #eee;padding:8px;gap:6px;">
                    <input type="text" id="jj-ai-input" data-i18n="chat_message_ph" data-i18n-placeholder placeholder="پیام خود را بنویسید..." style="flex:1;border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;font-size:13px;">
                    <button id="jj-ai-send" data-i18n="submit" style="background:#4f46e5;color:#fff;border:none;border-radius:8px;padding:8px 14px;cursor:pointer;font-size:13px;">ارسال</button>
                </div>
            </div>
            <div id="jj-help-panel" style="display:none;position:fixed;bottom:94px;left:20px;z-index:50;width:300px;max-width:calc(100vw - 40px);background:#fff;border-radius:12px;box-shadow:0 8px 26px rgba(0,0,0,.18);padding:16px;text-align:right;" dir="<?php echo esc_attr($__jj_dir); ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <strong id="jj-help-title" data-i18n="help_title">راهنما</strong>
                    <button id="jj-help-close" style="background:none;border:none;font-size:16px;cursor:pointer;color:#6b7280;">✕</button>
                </div>
                <p id="jj-help-body" style="font-size:13px;color:#374151;line-height:1.8;margin:0 0 10px;"></p>
                <div id="jj-help-contact" style="font-size:12.5px;color:#374151;border-top:1px solid #eee;padding-top:8px;"></div>
            </div>
            <div id="jj-profile-summary" style="display:none;margin:0 auto 12px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;font-size:13px;text-align:right;"></div>
            <div class="jj-card">

            <div class="jj-step" data-step="docs-pending" style="display:none">
                <h2 id="jj-dp-title"></h2>
                <p class="jj-sub" id="jj-dp-desc"></p>
            </div>

            <div class="jj-step" data-step="payment-result" style="display:none">
                    <h2 id="jj-pr-title"></h2>
                    <p class="jj-sub" id="jj-pr-desc"></p>
                </div>

                <div class="jj-step" data-step="phone">
                    <h2 data-i18n="phone_step_title">ورود / ثبت‌نام</h2>
                    <p class="jj-sub" data-i18n="phone_step_desc">شماره موبایل خود را وارد کنید</p>
                    <div class="jj-login-method-toggle" id="jj-login-method-toggle" style="display:flex;gap:8px;margin-bottom:12px;"><button type="button" class="jj-method-btn jj-method-active" id="jj-method-otp-btn" data-i18n="login_method_otp" style="flex:1;padding:9px;border-radius:8px;border:1px solid #d1d5db;background:#14213d;color:#fff;cursor:pointer;font-size:13px;">کد یکبار مصرف</button><button type="button" class="jj-method-btn" id="jj-method-password-btn" data-i18n="login_method_password" style="flex:1;padding:9px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#14213d;cursor:pointer;font-size:13px;">رمز عبور</button></div>
                    <div class="jj-phone-row">
                        <select id="jj-country-code" class="jj-input">
                            <option value="98" data-iran="1" selected>🇮🇷 ‎+98</option>
                            <option value="93">🇦🇫 +93</option>
                            <option value="964">🇮🇶 +964</option>
                            <option value="90">🇹🇷 +90</option>
                            <option value="971">🇦🇪 +971</option>
                            <option value="968">🇴🇲 +968</option>
                            <option value="974">🇶🇦 +974</option>
                            <option value="965">🇰🇼 +965</option>
                            <option value="966">🇸🇦 +966</option>
                            <option value="7">🇷🇺 +7</option>
                            <option value="374">🇦🇲 +374</option>
                            <option value="995">🇬🇪 +995</option>
                            <option value="994">🇦🇿 +994</option>
                            <option value="992">🇹🇯 +992</option>
                            <option value="996">🇰🇬 +996</option>
                            <option value="998">🇺🇿 +998</option>
                        </select>
                        <input type="tel" id="jj-phone" class="jj-input" data-i18n="phone_placeholder" data-i18n-placeholder placeholder="09xxxxxxxxx" maxlength="14" inputmode="numeric" />
                    </div>
                    <div id="jj-password-row" style="display:none;margin-bottom:12px;"><input type="password" id="jj-login-password" class="jj-input" style="width:100%;box-sizing:border-box;" data-i18n-placeholder placeholder="رمز عبور" data-i18n="password_ph" autocomplete="current-password"></div>
                    <button class="jj-btn" id="jj-btn-request" data-i18n="send_code">دریافت کد</button>
                    <div class="jj-msg" id="jj-msg-phone"></div>
                </div>

                <div class="jj-step" data-step="otp" style="display:none">
                    
                    <?php echo self::jj_stepper([ ['icon'=>'check','label'=>JJ_I18N::t('verify', $__jj_locale)], ['icon'=>'account','label'=>JJ_I18N::t('stepper_account_info', $__jj_locale)], ['icon'=>'user','label'=>JJ_I18N::t('stepper_personal_info', $__jj_locale)] ], 0); ?>
                    <h2 data-i18n="otp_step_title">کد تایید</h2>
                    <p class="jj-sub"><span data-i18n="otp_step_desc">کد ۵ رقمی ارسال‌شده به</span> <span id="jj-phone-display"></span> <span data-i18n="otp_step_desc_suffix">را وارد کنید</span></p>
                    <input type="tel" id="jj-code" class="jj-input jj-input-otp" data-i18n="otp_code_placeholder" data-i18n-placeholder placeholder="١٢٣٤٥" maxlength="5" inputmode="numeric" />
                    <button class="jj-btn" id="jj-btn-verify" data-i18n="verify">تایید</button>
                    <div class="jj-timer" id="jj-timer"></div>
                    <button class="jj-btn-link" id="jj-btn-resend" data-i18n="resend_code" disabled>ارسال مجدد کد</button>
                    <div class="jj-msg" id="jj-msg-otp"></div>
                </div>

                <div class="jj-step" data-step="role" style="display:none">
                    
                    <?php echo self::jj_stepper([ ['icon'=>'check','label'=>JJ_I18N::t('verify', $__jj_locale)], ['icon'=>'account','label'=>JJ_I18N::t('stepper_account_info', $__jj_locale)], ['icon'=>'user','label'=>JJ_I18N::t('stepper_personal_info', $__jj_locale)] ], 1); ?>
                    <h2 data-i18n="role_step_title">می‌خواهید به عنوان چه کسی فعالیت کنید؟</h2>
                    <div id="jj-role-back-row" style="display:none;margin-bottom:8px;">
                        <button class="jj-btn-link" id="jj-btn-role-back"><span class="jj-back-arrow">→</span> <span data-i18n="back">انصراف و بازگشت</span></button>
                    </div>
                    <p class="jj-sub" id="jj-role-current-note" style="display:none;"></p>
                    <div class="jj-role-name-row" id="jj-role-name-row" style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;"><input type="text" id="jj-role-first-name" class="jj-input" style="flex:1;min-width:140px;" placeholder="نام" data-i18n-placeholder data-i18n="first_name_ph" required><input type="text" id="jj-role-last-name" class="jj-input" style="flex:1;min-width:140px;" placeholder="نام خانوادگی" data-i18n-placeholder data-i18n="last_name_ph" required></div>
                    <div class="jj-role-grid">
                        <button class="jj-role-card" data-role="jobseeker"><img class="jj-role-icon" src="https://javanjob.ir/wp-content/uploads/2026/08/avatar-job-seeker.webp" alt="">
                            <span class="jj-role-title" data-i18n="role_jobseeker">کارجوی فردی</span>
                            <span class="jj-role-desc" data-i18n="role_jobseeker_desc">به‌دنبال فرصت شغلی برای خودم</span>
                        </button>
                        <button class="jj-role-card" data-role="employer"><img class="jj-role-icon" src="https://javanjob.ir/wp-content/uploads/2026/08/avatar-employer-company.webp" alt="">
                            <span class="jj-role-title" data-i18n="role_employer">کارفرما</span>
                            <span class="jj-role-desc" data-i18n="role_employer_desc">نیاز به نیروی کار دارم</span>
                        </button>
                        <button class="jj-role-card" data-role="agency"><img class="jj-role-icon" src="https://javanjob.ir/wp-content/uploads/2026/08/avatar-agency.webp" alt="">
                            <span class="jj-role-title" data-i18n="role_agency">کاریابی</span>
                            <span class="jj-role-desc" data-i18n="role_agency_desc">همکاری به‌عنوان دفتر کاریابی</span>
                        </button>
                    </div>
                    <div class="jj-role-extra" id="jj-role-extra" style="display:none">
                        <input type="text" id="jj-role-extra-input" class="jj-input" />
                        <button class="jj-btn" id="jj-btn-role-submit" data-i18n="submit_and_continue">ثبت و ادامه</button>
                    </div>
                    <div class="jj-msg" id="jj-msg-role"></div>
                </div>

                <div class="jj-step jj-wide" data-step="profile" style="display:none">
                    
                    <?php echo self::jj_stepper([ ['icon'=>'check','label'=>JJ_I18N::t('verify', $__jj_locale)], ['icon'=>'account','label'=>JJ_I18N::t('stepper_account_info', $__jj_locale)], ['icon'=>'user','label'=>JJ_I18N::t('stepper_personal_info', $__jj_locale)] ], 2); ?>
                    <h2 id="jj-profile-title" data-i18n="profile_title">تکمیل اطلاعات</h2>
                    <div id="jj-profile-fields"></div>
                    <div class="jj-msg" id="jj-msg-profile"></div>
                </div>

                <div class="jj-step" data-step="documents" style="display:none"><div style="margin:0 0 12px;padding:10px;background:rgba(0,0,0,.04);border-radius:6px;"><label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" id="jj-doc-outside-iran" /> <span data-i18n="doc_outside_iran_checkbox">فعالیت کارفرما/کاریابی من خارج از ایران است</span></label></div><div id="jj-doc-row-national_id" class="jj-doc-row" style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06);gap:10px;"><span data-i18n="doc_national_id">کارت ملی (ایرانیان) / کارت شناسایی معتبر (غیرایرانیان)</span><button class="jj-btn jj-btn-secondary" type="button" id="jj-doc-choose-national_id" data-i18n="choose_file_btn">انتخاب فایل</button></div><div id="jj-doc-row-birth_certificate" class="jj-doc-row" style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06);gap:10px;"><span data-i18n="doc_birth_certificate">شناسنامه</span><button class="jj-btn jj-btn-secondary" type="button" id="jj-doc-choose-birth_certificate" data-i18n="choose_file_btn">انتخاب فایل</button></div><div id="jj-doc-row-passport" class="jj-doc-row" style="display:none;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06);gap:10px;"><span data-i18n="doc_passport">پاسپورت</span><button class="jj-btn jj-btn-secondary" type="button" id="jj-doc-choose-passport" data-i18n="choose_file_btn">انتخاب فایل</button></div><div id="jj-doc-row-last_entry_page" class="jj-doc-row" style="display:none;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06);gap:10px;"><span data-i18n="doc_last_entry_page">صفحه آخرین ورود</span><button class="jj-btn jj-btn-secondary" type="button" id="jj-doc-choose-last_entry_page" data-i18n="choose_file_btn">انتخاب فایل</button></div><div id="jj-doc-row-visa" class="jj-doc-row" style="display:none;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06);gap:10px;"><span data-i18n="doc_visa">ویزا</span><button class="jj-btn jj-btn-secondary" type="button" id="jj-doc-choose-visa" data-i18n="choose_file_btn">انتخاب فایل</button></div><div id="jj-doc-row-work_contract" class="jj-doc-row" style="display:none;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06);gap:10px;"><span data-i18n="doc_work_contract">قرارداد کار پروژه (کارفرمای خارج از ایران)</span><button class="jj-btn jj-btn-secondary" type="button" id="jj-doc-choose-work_contract" data-i18n="choose_file_btn">انتخاب فایل</button></div><div id="jj-doc-row-agency_license" class="jj-doc-row" style="display:none;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06);gap:10px;"><span data-i18n="doc_agency_license">مجوز فعالیت کاریابی (کاریابی خارجی)</span><button class="jj-btn jj-btn-secondary" type="button" id="jj-doc-choose-agency_license" data-i18n="choose_file_btn">انتخاب فایل</button></div><div id="jj-doc-row-personal_photo" class="jj-doc-row" style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06);gap:10px;"><span data-i18n="doc_personal_photo">عکس پرسنلی</span><button class="jj-btn jj-btn-secondary" type="button" id="jj-doc-choose-personal_photo" data-i18n="choose_file_btn">انتخاب فایل</button></div><div id="jj-doc-row-company_logo" class="jj-doc-row" style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06);gap:10px;"><span data-i18n="doc_company_logo">لوگو شرکت / کاریابی</span><button class="jj-btn jj-btn-secondary" type="button" id="jj-doc-choose-company_logo" data-i18n="choose_file_btn">انتخاب فایل</button></div><input type="file" id="jj-doc-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" style="display:none" /><button class="jj-btn jj-btn-secondary" id="jj-btn-add-doc" data-i18n="add_file" style="display:none">افزودن مدرک</button><div class="jj-doc-list" id="jj-doc-list"></div><div class="jj-msg" id="jj-msg-docs"></div><button class="jj-btn" id="jj-btn-docs-continue" data-i18n="continue">ادامه</button></div>

                <div class="jj-step" data-step="payment" style="display:none">
                    <h2 data-i18n="payment_title">هزینه ثبت‌نام</h2>
                    <p class="jj-sub" data-i18n="payment_desc">برای تکمیل ثبت‌نام، لازم است هزینه‌ی مربوطه پرداخت شود (مبلغ دقیق در صفحه‌ی درگاه نمایش داده می‌شود).</p>
                    <button class="jj-btn" id="jj-btn-pay" data-i18n="pay_and_finish">پرداخت و تکمیل ثبت‌نام</button>
                    <div class="jj-msg" id="jj-msg-payment"></div>
                </div>

                <div class="jj-step" data-step="package-select" style="display:none">
                    <h2 data-i18n="package_select_title">انتخاب بسته‌ی اشتراک</h2>
                    <p class="jj-sub" data-i18n="package_select_desc">یکی از بسته‌های زیر را برای فعال‌سازی/تمدید حساب خود انتخاب کنید.</p>
                    <div id="jj-package-back-row" style="display:none;margin-bottom:8px;">
                        <button class="jj-btn-link" id="jj-btn-package-back"><span class="jj-back-arrow">→</span> <span data-i18n="back">بازگشت</span></button>
                    </div>
                    <div id="jj-packages-list"></div>
                    <div class="jj-msg" id="jj-msg-package"></div>
                </div>

                <div class="jj-step jj-wide" data-step="team" style="display:none">
<img src="https://javanjob.ir/wp-content/uploads/2026/08/avatar-team-crew.webp" alt="" class="jj-step-feature-img" style="display:block;width:140px;height:140px;object-fit:cover;border-radius:16px;margin:0 auto 14px;">
                    <h2 id="jj-team-title" data-i18n="my_team">اکیپ من</h2>
                    <p class="jj-sub" id="jj-team-code"></p>
                    <div class="jj-field-row" style="margin-bottom:10px;">
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-team-chat" data-i18n="team_chat_btn">💬 چت اکیپ</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-team-browse-jobs" data-i18n="team_browse_jobs">🔍 مرور و درخواست شغل برای اکیپ</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-my-contracts-team" data-i18n="team_contracts">📄 قراردادهای اکیپ</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-team-apps" data-i18n="team_applications">📋 درخواست‌های اکیپ</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-renew-team" data-i18n="renew_package">🔄 تمدید/خرید بسته</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-change-role-team" data-i18n="change_membership_type">🔀 تغییر نوع عضویت</button>
                    </div>
                    <ul class="jj-doc-list" id="jj-team-apps-list" style="display:none;"></ul>
                    <ul class="jj-doc-list" id="jj-team-members"></ul>
                    <fieldset class="jj-fieldset"><legend data-i18n="add_member_to_list">افزودن عضو جدید به فهرست</legend>
                        <input type="tel" id="jj-team-phone" class="jj-input" data-i18n="phone_placeholder" data-i18n-placeholder placeholder="09xxxxxxxxx" maxlength="11" />
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-team-check" data-i18n="check_number">بررسی شماره</button>
                        <div id="jj-team-check-result" style="margin-top:10px;"></div>
                    </fieldset>
                    <fieldset class="jj-fieldset" id="jj-team-roster-box" style="display:none;">
                        <legend data-i18n="pending_payment_list">فهرست افراد در انتظار پرداخت</legend>
                        <ul class="jj-doc-list" id="jj-team-roster-list"></ul>
                        <p style="font-weight:bold;margin:10px 0;"><span data-i18n="total_amount">جمع کل</span>: <span id="jj-team-roster-total">۰</span> <span data-i18n="toman">تومان</span></p>
                        <button class="jj-btn" id="jj-btn-team-pay-roster" data-i18n="pay_and_add_members">پرداخت و افزودن اعضا</button>
                    </fieldset>
                    <div class="jj-msg" id="jj-msg-team"></div>

                    <fieldset class="jj-fieldset" id="jj-team-profile-box" style="display:none;">
                        <legend data-i18n="team_profile_legend">پروفایل اکیپ</legend>
                        <div id="jj-team-profile-disabled-msg" style="display:none;font-size:13px;color:#9ca3af;" data-i18n="team_profile_disabled_msg">بسته‌ی فعلی شما شامل امکان «پروفایل اکیپ» نیست.</div>
                        <div id="jj-team-profile-form">
                            <input type="text" id="jj-team-specialty" class="jj-input" data-i18n="team_specialty_ph" data-i18n-placeholder placeholder="تخصص اکیپ (مثلاً: نصب و راه‌اندازی تأسیسات)" style="margin-bottom:8px;" />
                            <textarea id="jj-team-description" class="jj-input" rows="3" data-i18n="team_desc_ph" data-i18n-placeholder placeholder="توضیحات درباره‌ی اکیپ" style="margin-bottom:8px;"></textarea>
                            <input type="text" id="jj-team-skills" class="jj-input" data-i18n="team_skills_ph" data-i18n-placeholder placeholder="مهارت‌ها (با ویرگول جدا کنید)" style="margin-bottom:8px;" />
                            <button class="jj-btn jj-btn-secondary" id="jj-btn-team-profile-save" data-i18n="save_profile_btn2">ذخیره‌ی پروفایل</button>
                            <div class="jj-msg" id="jj-msg-team-profile"></div>
                        </div>
                    </fieldset>

                    <fieldset class="jj-fieldset" id="jj-team-track-box" style="display:none;">
                        <legend data-i18n="team_track_legend">سوابق اکیپ</legend>
                        <div id="jj-team-track-disabled-msg" style="display:none;font-size:13px;color:#9ca3af;" data-i18n="team_track_disabled_msg">بسته‌ی فعلی شما شامل امکان «ثبت سوابق اکیپ» نیست.</div>
                        <div id="jj-team-track-form">
                            <ul class="jj-doc-list" id="jj-team-track-list"></ul>
                            <input type="text" id="jj-team-track-title" class="jj-input" data-i18n="track_title_ph" data-i18n-placeholder placeholder="عنوان سابقه (مثلاً: پروژه‌ی نصب سوله)" style="margin-bottom:8px;" />
                            <input type="text" id="jj-team-track-employer" class="jj-input" data-i18n="track_employer_ph" data-i18n-placeholder placeholder="نام کارفرما (اختیاری)" style="margin-bottom:8px;" />
                            <textarea id="jj-team-track-desc" class="jj-input" rows="2" data-i18n="track_desc_ph" data-i18n-placeholder placeholder="توضیحات (اختیاری)" style="margin-bottom:8px;"></textarea>
                            <button class="jj-btn jj-btn-secondary" id="jj-btn-team-track-add" data-i18n="add_track_btn">افزودن سابقه</button>
                            <div class="jj-msg" id="jj-msg-team-track"></div>
                        </div>
                    </fieldset>

                    <fieldset class="jj-fieldset" id="jj-consultations-box">
                        <legend data-i18n="consultations_legend">جلسات مشاوره‌ی تلفنی</legend>
                        <ul class="jj-doc-list" id="jj-consultations-list"><li style="color:#9ca3af;" data-i18n="loading">در حال بارگذاری...</li></ul>
                    </fieldset>
                </div>
                <style>
                    #jj-team-roster-list li { display:flex; justify-content:space-between; align-items:center; }
                    #jj-team-roster-list button.jj-remove-entry { background:none; border:none; color:#dc2626; cursor:pointer; font-size:12px; }
                </style>

                <div class="jj-step jj-wide" data-step="jobs" style="display:none">
                    <h2 data-i18n="my_jobs">آگهی‌های شغلی من</h2>
                    <div class="jj-field-row" style="margin-bottom:10px;">
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-my-contracts-emp" data-i18n="my_contracts">📄 قراردادهای من</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-request-agency" data-i18n="request_agency_partnership">🤝 درخواست همکاری با کاریابی</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-agency-search" data-i18n="agency_search_btn" style="display:none;">🔍 جستجوی پیشرفته‌ی کارجویان</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-agency-videos" data-i18n="agency_videos_btn" style="display:none;">🎬 ویدئوهای معرفی</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-agency-report" data-i18n="agency_report_btn" style="display:none;">📊 گزارش عملکرد</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-employer-requests" data-i18n="employer_requests_btn">📥 درخواست‌های کارجویان</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-change-role-emp" data-i18n="change_membership_type">🔀 تغییر نوع عضویت</button>
                    </div>
                    <div id="jj-agency-list-wrap" style="display:none;margin-bottom:16px;">
                        <fieldset class="jj-fieldset"><legend data-i18n="choose_agency">انتخاب کاریابی</legend>
                            <ul class="jj-doc-list" id="jj-agency-list"></ul>
                        </fieldset>
                        <div class="jj-msg" id="jj-msg-agency"></div>
                    </div>
                    <ul class="jj-doc-list" id="jj-jobs-list"></ul>
                    <button class="jj-btn jj-btn-secondary" id="jj-btn-new-job" data-i18n="new_job">+ ثبت آگهی جدید</button>
                    <div id="jj-job-form-wrap" style="display:none;margin-top:16px;">
                        <div id="jj-job-form-fields"></div>
                    </div>
                    <div id="jj-job-media-wrap" style="display:none;margin-top:16px;">
                        <fieldset class="jj-fieldset"><legend data-i18n="workshop_media">عکس و فیلم کارگاه</legend>
                            <p class="jj-sub" data-i18n="media_limit_desc">حداکثر ۵ فایل (عکس تا ۱ مگابایت، فیلم تا ۲۵ مگابایت)</p>
                            <input type="file" id="jj-media-workshop-input" accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,.webm" style="display:none">
                            <button class="jj-btn jj-btn-secondary" id="jj-btn-add-workshop-media" data-i18n="add_workshop_file">+ افزودن فایل کارگاه</button>
                            <ul class="jj-doc-list" id="jj-media-workshop-list"></ul>
                        </fieldset>
                        <fieldset class="jj-fieldset"><legend data-i18n="dormitory_media">عکس و فیلم خوابگاه</legend>
                            <p class="jj-sub" data-i18n="media_limit_desc">حداکثر ۵ فایل (عکس تا ۱ مگابایت، فیلم تا ۲۵ مگابایت)</p>
                            <input type="file" id="jj-media-dormitory-input" accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,.webm" style="display:none">
                            <button class="jj-btn jj-btn-secondary" id="jj-btn-add-dormitory-media" data-i18n="add_dormitory_file">+ افزودن فایل خوابگاه</button>
                            <ul class="jj-doc-list" id="jj-media-dormitory-list"></ul>
                        </fieldset>
                        <button class="jj-btn" id="jj-btn-media-done" data-i18n="finish_return_to_list">پایان و بازگشت به لیست</button>
                        <div class="jj-msg" id="jj-msg-media"></div>
                    </div>
                    <div class="jj-msg" id="jj-msg-jobs"></div>
                </div>

                <div class="jj-step jj-wide" data-step="agency-search" style="display:none">
                    <div style="margin-bottom:8px;">
                        <button class="jj-btn-link" id="jj-btn-agency-search-back"><span class="jj-back-arrow">→</span> <span data-i18n="back">بازگشت</span></button>
                    </div>
                    <h2 data-i18n="agency_search_heading">جستجوی پیشرفته‌ی کارجویان</h2>
                    <div id="jj-agency-search-disabled-msg" style="display:none;color:#9ca3af;font-size:13px;" data-i18n="agency_search_disabled_msg">بسته‌ی فعلی شما شامل امکان «جستجوی پیشرفته‌ی کارجویان» نیست.</div>
                    <div id="jj-agency-search-form">
                        <div class="jj-field-row" style="flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                            <input type="text" id="jj-as-q" class="jj-input" data-i18n="skill_or_job_search_ph" data-i18n-placeholder placeholder="مهارت یا شغل مورد نظر..." style="flex:2;min-width:180px;" />
                            <select id="jj-as-nationality" class="jj-input" style="flex:1;min-width:120px;">
                                <option value="" data-i18n="all_nationalities">همه‌ی ملیت‌ها</option>
                                <option value="iranian" data-i18n="nationality_iranian">ایرانی</option>
                                <option value="other" data-i18n="other_nationalities">سایر ملیت‌ها</option>
                            </select>
                            <select id="jj-as-gender" class="jj-input" style="flex:1;min-width:100px;">
                                <option value="" data-i18n="gender_all">جنسیت (همه)</option>
                                <option value="male" data-i18n="gender_male">مرد</option>
                                <option value="female" data-i18n="gender_female">زن</option>
                            </select>
                            <select id="jj-as-work-location" class="jj-input" style="flex:1;min-width:120px;">
                                <option value="" data-i18n="work_location_all">محل کار (همه)</option>
                                <option value="inside" data-i18n="location_inside">داخل کشور</option>
                                <option value="outside" data-i18n="location_outside">خارج از کشور</option>
                                <option value="both" data-i18n="location_both">هردو</option>
                            </select>
                            <input type="text" id="jj-as-province" class="jj-input" data-i18n="province" data-i18n-placeholder placeholder="استان" style="flex:1;min-width:100px;" />
                            <button class="jj-btn" id="jj-btn-agency-search-run" data-i18n="search">جستجو</button>
                        </div>
                        <div class="jj-msg" id="jj-msg-agency-search"></div>
                        <div id="jj-agency-search-results" style="display:flex;flex-wrap:wrap;gap:14px;"></div>
                    </div>
                </div>

                <div class="jj-step jj-wide" data-step="agency-videos" style="display:none">
                    <div style="margin-bottom:8px;">
                        <button class="jj-btn-link" id="jj-btn-agency-videos-back"><span class="jj-back-arrow">→</span> <span data-i18n="back">بازگشت</span></button>
                    </div>
                    <h2 data-i18n="agency_videos_heading">ویدئوهای معرفی</h2>
                    <p class="jj-sub" style="font-size:12.5px;color:#6b7280;"><span data-i18n="agency_video_quota_used">تعداد استفاده‌شده از سقف بسته:</span> <strong id="jj-av-quota">—</strong></p>
                    <div id="jj-av-disabled-msg" style="display:none;color:#9ca3af;font-size:13px;" data-i18n="agency_videos_disabled_msg">بسته‌ی فعلی شما شامل امکان «ویدئوی معرفی کاریابی» نیست.</div>
                    <button class="jj-btn" id="jj-btn-agency-video-generate" data-i18n="agency_video_generate_btn" style="margin-bottom:14px;">+ ساخت ویدئوی جدید با هوش مصنوعی</button>
                    <div class="jj-msg" id="jj-msg-agency-videos"></div>
                    <div id="jj-agency-videos-list" style="display:flex;flex-wrap:wrap;gap:14px;"></div>
                </div>

                <div class="jj-step jj-wide" data-step="employer-requests" style="display:none">
                    <div style="margin-bottom:8px;">
                        <button class="jj-btn-link" id="jj-btn-employer-requests-back"><span class="jj-back-arrow">→</span> <span data-i18n="back">بازگشت</span></button>
                    </div>
                    <h2 data-i18n="employer_requests_heading">درخواست‌های فرصت شغلی از کارجویان</h2>
                    <p class="jj-sub" style="font-size:12.5px;color:#9ca3af;" data-i18n="employer_requests_privacy_note">اطلاعات هویتی کارجو تا وقتی درخواست را نپذیرید محرمانه است.</p>
                    <div class="jj-msg" id="jj-msg-employer-requests"></div>
                    <div id="jj-employer-requests-list" style="display:flex;flex-wrap:wrap;gap:14px;"></div>
                </div>

                <div class="jj-step jj-wide" data-step="agency-report" style="display:none">
                    <div style="margin-bottom:8px;">
                        <button class="jj-btn-link" id="jj-btn-agency-report-back"><span class="jj-back-arrow">→</span> <span data-i18n="back">بازگشت</span></button>
                    </div>
                    <h2 data-i18n="agency_report_heading">گزارش عملکرد کاریابی</h2>
                    <div id="jj-ar-disabled-msg" style="display:none;color:#9ca3af;font-size:13px;" data-i18n="agency_report_disabled_msg">بسته‌ی فعلی شما شامل امکان «گزارش عملکرد کاریابی» نیست.</div>
                    <div id="jj-ar-content" style="display:flex;flex-wrap:wrap;gap:14px;"></div>
                </div>

                <div class="jj-step jj-wide" data-step="browse-jobs" style="display:none">
                    <h2 data-i18n="jobs_available">فرصت‌های شغلی</h2>
                    <div id="jj-browse-back-row" style="display:none;margin-bottom:8px;">
                        <button class="jj-btn-link" id="jj-btn-browse-back"><span class="jj-back-arrow">→</span> <span data-i18n="back_to_team">بازگشت به اکیپ</span></button>
                    </div>
                    <div id="jj-browse-normal-actions" class="jj-field-row" style="margin-bottom:10px;">
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-my-apps" data-i18n="my_applications">📋 درخواست‌های من</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-my-profile" data-i18n="my_profile">👤 پروفایل من</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-my-contracts-js" data-i18n="my_contracts">📄 قراردادهای من</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-renew-js" data-i18n="renew_package">🔄 تمدید/خرید بسته</button>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-change-role-js" data-i18n="change_membership_type">🔀 تغییر نوع عضویت</button>
<button class="jj-btn jj-btn-secondary" id="jj-btn-team-chat-js" data-i18n="team_chat_btn" style="display:none;">💬 چت اکیپ</button>
                    </div>
                    <div id="jj-team-dues-box" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:14px;font-size:13px;"></div>
                    <div id="jj-jobseeker-profile-wrap" style="display:none;margin-bottom:16px;">
                    <?php echo self::jj_stepper([
                        ['icon'=>'user','label'=>JJ_I18N::t('stepper_basic_info', $__jj_locale)],
                        ['icon'=>'star','label'=>JJ_I18N::t('skills_label', $__jj_locale)],
                        ['icon'=>'briefcase','label'=>JJ_I18N::t('stepper_desired_jobs', $__jj_locale)],
                        ['icon'=>'doc','label'=>JJ_I18N::t('additional_info_heading', $__jj_locale)]
                    ], 0); ?>
                    <div class="jj-resume-layout">
                        <div class="jj-resume-main">
                    <fieldset class="jj-fieldset"><legend data-i18n="jobseeker_profile">پروفایل کارجو</legend>

                        <div class="jj-resume-step" data-resume-step="0">
                            <span class="jj-label" data-i18n="full_name">نام و نام خانوادگی</span>
                            <input class="jj-input" id="jj-js-fullname" data-i18n="full_name" data-i18n-placeholder placeholder="نام کامل" disabled style="background:#f3f4f6;color:#6b7280;">
                            <span class="jj-label" data-i18n="acct_mobile_label">شماره موبایل</span>
                            <input class="jj-input" id="jj-js-phone-display" disabled style="background:#f3f4f6;color:#6b7280;">
                            <span class="jj-label" data-i18n="work_type_pref_label">نوع همکاری مورد نظر</span>
                            <div class="jj-check-group" id="jj-js-work-type-group">
                                <label class="jj-check-chip"><input type="checkbox" value="full_time"> <span data-i18n="work_type_full_time">تمام‌وقت</span></label>
                                <label class="jj-check-chip"><input type="checkbox" value="part_time"> <span data-i18n="work_type_part_time">پاره‌وقت</span></label>
                                <label class="jj-check-chip"><input type="checkbox" value="remote"> <span data-i18n="work_type_remote">دورکاری</span></label>
                                <label class="jj-check-chip"><input type="checkbox" value="project"> <span data-i18n="work_type_project">پروژه‌ای</span></label>
                            </div>
                            <div class="jj-wizard-nav"><button type="button" class="jj-btn jj-btn-primary-wide" data-resume-next="1" data-i18n="continue">ادامه</button></div>
                        </div>

                        <div class="jj-resume-step jj-wizard-hidden" data-resume-step="1">
                            <span class="jj-label" data-i18n="skills_max3">مهارت‌ها (حداکثر ۳ مورد)</span>
                            <input class="jj-input" id="jj-js-skill-1" data-i18n="skill_1" data-i18n-placeholder placeholder="مهارت اول">
                            <input class="jj-input" id="jj-js-skill-2" data-i18n="skill_2" data-i18n-placeholder placeholder="مهارت دوم">
                            <input class="jj-input" id="jj-js-skill-3" data-i18n="skill_3" data-i18n-placeholder placeholder="مهارت سوم">
                            <div class="jj-wizard-nav">
                                <button type="button" class="jj-btn jj-btn-secondary" data-resume-prev="0" data-i18n="prev_step_btn">مرحله قبل</button>
                                <button type="button" class="jj-btn jj-btn-primary-wide" data-resume-next="2" data-i18n="continue">ادامه</button>
                            </div>
                        </div>

                        <div class="jj-resume-step jj-wizard-hidden" data-resume-step="2">
                            <span class="jj-label" data-i18n="desired_jobs_max3">شغل‌های درخواستی (حداکثر ۳ مورد)</span>
                            <div style="position:relative;">
                            <input class="jj-input" id="jj-js-desired-1" autocomplete="off" data-i18n="desired_job_1" data-i18n-placeholder placeholder="شغل درخواستی اول">
                            <div class="jj-prof-dropdown" id="jj-js-desired-dropdown-1" style="display:none;position:absolute;z-index:20;background:#fff;border:1px solid #e5e7eb;border-radius:8px;max-height:220px;overflow-y:auto;width:100%;box-shadow:0 4px 12px rgba(0,0,0,.08);"></div>
                            </div>
                            <div style="position:relative;">
                            <input class="jj-input" id="jj-js-desired-2" autocomplete="off" data-i18n="desired_job_2" data-i18n-placeholder placeholder="شغل درخواستی دوم">
                            <div class="jj-prof-dropdown" id="jj-js-desired-dropdown-2" style="display:none;position:absolute;z-index:20;background:#fff;border:1px solid #e5e7eb;border-radius:8px;max-height:220px;overflow-y:auto;width:100%;box-shadow:0 4px 12px rgba(0,0,0,.08);"></div>
                            </div>
                            <div style="position:relative;">
                            <input class="jj-input" id="jj-js-desired-3" autocomplete="off" data-i18n="desired_job_3" data-i18n-placeholder placeholder="شغل درخواستی سوم">
                            <div class="jj-prof-dropdown" id="jj-js-desired-dropdown-3" style="display:none;position:absolute;z-index:20;background:#fff;border:1px solid #e5e7eb;border-radius:8px;max-height:220px;overflow-y:auto;width:100%;box-shadow:0 4px 12px rgba(0,0,0,.08);"></div>
                            </div>
                            <div class="jj-wizard-nav">
                                <button type="button" class="jj-btn jj-btn-secondary" data-resume-prev="1" data-i18n="prev_step_btn">مرحله قبل</button>
                                <button type="button" class="jj-btn jj-btn-primary-wide" data-resume-next="3" data-i18n="continue">ادامه</button>
                            </div>
                        </div>

                        <div class="jj-resume-step jj-wizard-hidden" data-resume-step="3">
                            <span class="jj-label" data-i18n="gender">جنسیت</span>
                            <select class="jj-input" id="jj-js-gender">
                                <option value="" data-i18n="please_select">— انتخاب کنید —</option>
                                <option value="male" data-i18n="gender_male">مرد</option>
                                <option value="female" data-i18n="gender_female">زن</option>
                            </select>
                            <span class="jj-label" data-i18n="nationality">ملیت</span>
                            <select class="jj-input" id="jj-js-nationality">
                                <option value="" data-i18n="please_select">— انتخاب کنید —</option>
                                <option value="iranian" data-i18n="nationality_iranian">ایرانی</option>
                                <option value="other" data-i18n="nationality_other">سایر</option>
                            </select>
                            <div id="jj-js-nationality-country-wrap" style="display:none;">
                                <span class="jj-label" data-i18n="nationality_country_label">کشور تابعیت</span>
                                <input class="jj-input" id="jj-js-nationality-country">
                            </div>
                            <span class="jj-label" data-i18n="father_name">نام پدر</span>
                            <input class="jj-input" id="jj-js-father-name">
                            <span class="jj-label" data-i18n="national_id">کد ملی / شماره ملی</span>
                            <input class="jj-input" id="jj-js-national-id">
                            <span class="jj-label" data-i18n="preferred_work_location">محل کار مدنظر</span>
                            <select class="jj-input" id="jj-js-work-location">
                                <option value="" data-i18n="please_select">— انتخاب کنید —</option>
                                <option value="inside" data-i18n="location_inside">داخل کشور</option>
                                <option value="outside" data-i18n="location_outside">خارج از کشور</option>
                                <option value="both" data-i18n="location_both">هردو</option>
                            </select>
                            <span class="jj-label" data-i18n="address_note">آدرس (بعد از عقد قرارداد به کارفرما نمایش داده می‌شود)</span>
                            <textarea class="jj-input" id="jj-js-address" rows="2"></textarea>
                            <div style="text-align:center;margin:14px 0;">
                                <img id="jj-js-avatar-preview" src="" style="display:none;width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid #c9a227;margin-bottom:8px;">
                                <span class="jj-label" data-i18n="profile_photo_optional">عکس پروفایل (اختیاری)</span>
                                <p class="jj-sub" data-i18n="profile_photo_warning" style="font-size:11.5px;color:#b45309;">⚠ این عکس برخلاف مدارک هویتی، به‌صورت عمومی روی صفحه‌ی اصلی سایت نمایش داده می‌شود. فقط در صورت رضایت آپلود کنید.</p>
                                <input type="file" id="jj-js-avatar-input" accept="image/*" class="jj-input">
                                <span class="jj-hint" data-i18n="photo_size_hint" style="display:block;font-size:12px;color:#6b7280;margin:4px 0;">حداکثر حجم عکس: ۲ مگابایت (فرمت jpg, png)</span>
                                <button class="jj-btn jj-btn-secondary" id="jj-btn-js-avatar-upload" data-i18n="upload_photo" style="margin-top:6px;">آپلود عکس</button>
                                <div class="jj-msg" id="jj-msg-js-avatar"></div>
                            </div>
                            <div class="jj-wizard-nav" style="margin-bottom:10px;">
                                <button type="button" class="jj-btn jj-btn-secondary" data-resume-prev="2" data-i18n="prev_step_btn">مرحله قبل</button>
                            </div>
                            <button class="jj-btn" id="jj-btn-js-profile-save" data-i18n="save_profile">ذخیره پروفایل</button>
                            <div class="jj-msg" id="jj-msg-js-profile"></div>
                        </div>

                    </fieldset>

                    <fieldset class="jj-fieldset" id="jj-resume-ai-box">
                        <legend data-i18n="resume_ai_legend">بازبینی و بهبود رزومه با هوش مصنوعی</legend>
                        <p class="jj-sub" style="font-size:12.5px;color:#6b7280;"><span data-i18n="resume_quota_remaining">سهمیه‌ی باقیمانده:</span> <strong id="jj-resume-ai-quota">—</strong></p>
                        <textarea class="jj-input" id="jj-resume-ai-text" rows="8" data-i18n="resume_text_ph" data-i18n-placeholder placeholder="متن رزومه‌ی خود را اینجا بنویسید یا paste کنید..."></textarea>
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-resume-ai-save" data-i18n="save_resume_text_btn" style="margin-top:8px;">ذخیره‌ی متن رزومه</button>
                        <div class="jj-field-row" style="margin-top:10px;">
                            <button class="jj-btn jj-btn-secondary" id="jj-btn-resume-ai-review" data-i18n="resume_ai_review_btn">🔎 بازبینی با هوش مصنوعی</button>
                            <button class="jj-btn jj-btn-secondary" id="jj-btn-resume-ai-improve" data-i18n="resume_ai_improve_btn">✨ بهبود با هوش مصنوعی</button>
                        </div>
                        <div class="jj-msg" id="jj-msg-resume-ai"></div>
                        <div id="jj-resume-ai-result" style="display:none;margin-top:10px;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;white-space:pre-wrap;font-size:13px;line-height:1.9;"></div>
                        <button class="jj-btn" id="jj-btn-resume-ai-apply" data-i18n="resume_apply_improved_btn" style="display:none;margin-top:8px;">اعمال متن بهبودیافته روی رزومه</button>
                    </fieldset>

                    <fieldset class="jj-fieldset" id="jj-resume-builder-box">
                        <legend data-i18n="resume_builder_legend">رزومه‌ی حرفه‌ای (سوابق کامل)</legend>
                        <p class="jj-sub" style="font-size:12px;color:#6b7280;" data-i18n="resume_builder_hint">این بخش برای ساخت یک رزومه‌ی ساخت‌یافته و قابل‌دانلود/اشتراک‌گذاری است.</p>

                        <span class="jj-label" data-i18n="resume_summary_label">خلاصه‌ی حرفه‌ای</span>
                        <textarea class="jj-input" id="jj-rb-summary" rows="3" data-i18n="resume_summary_ph" data-i18n-placeholder placeholder="در چند جمله، خودتان و مهارت‌های کلیدی‌تان را معرفی کنید..."></textarea>

                        <div style="margin-top:16px;">
                            <span class="jj-label" data-i18n="resume_experience_label">سوابق شغلی</span>
                            <ul class="jj-doc-list" id="jj-rb-experience-list"></ul>
                            <div class="jj-field-row">
                                <input class="jj-input" id="jj-rb-exp-title" data-i18n="resume_exp_title_ph" data-i18n-placeholder placeholder="عنوان شغلی">
                                <input class="jj-input" id="jj-rb-exp-company" data-i18n="resume_exp_company_ph" data-i18n-placeholder placeholder="نام شرکت/کارفرما">
                            </div>
                            <div class="jj-field-row">
                                <input class="jj-input" id="jj-rb-exp-start" data-i18n="resume_date_start_ph" data-i18n-placeholder placeholder="شروع (مثلاً 1401 یا 1401-05)">
                                <input class="jj-input" id="jj-rb-exp-end" data-i18n="resume_date_end_ph" data-i18n-placeholder placeholder="پایان (اگر مشغولید، خالی بگذارید)">
                            </div>
                            <label class="jj-check-chip"><input type="checkbox" id="jj-rb-exp-current"> <span data-i18n="resume_currently_working">هم‌اکنون مشغولم</span></label>
                            <textarea class="jj-input" id="jj-rb-exp-desc" rows="2" data-i18n="resume_exp_desc_ph" data-i18n-placeholder placeholder="شرح وظایف و دستاوردها (اختیاری)"></textarea>
                            <button type="button" class="jj-btn jj-btn-secondary" id="jj-btn-rb-exp-add" data-i18n="resume_add_experience_btn" style="margin-top:6px;">+ افزودن سابقه‌ی شغلی</button>
                        </div>

                        <div style="margin-top:16px;">
                            <span class="jj-label" data-i18n="resume_education_label">تحصیلات</span>
                            <ul class="jj-doc-list" id="jj-rb-education-list"></ul>
                            <div class="jj-field-row">
                                <input class="jj-input" id="jj-rb-edu-degree" data-i18n="resume_edu_degree_ph" data-i18n-placeholder placeholder="مقطع (مثلاً کارشناسی)">
                                <input class="jj-input" id="jj-rb-edu-field" data-i18n="resume_edu_field_ph" data-i18n-placeholder placeholder="رشته‌ی تحصیلی">
                            </div>
                            <input class="jj-input" id="jj-rb-edu-institution" data-i18n="resume_edu_institution_ph" data-i18n-placeholder placeholder="نام مؤسسه/دانشگاه">
                            <div class="jj-field-row">
                                <input class="jj-input" id="jj-rb-edu-start" data-i18n="resume_date_start_ph" data-i18n-placeholder placeholder="شروع">
                                <input class="jj-input" id="jj-rb-edu-end" data-i18n="resume_date_end_ph" data-i18n-placeholder placeholder="پایان">
                            </div>
                            <button type="button" class="jj-btn jj-btn-secondary" id="jj-btn-rb-edu-add" data-i18n="resume_add_education_btn" style="margin-top:6px;">+ افزودن تحصیلات</button>
                        </div>

                        <div style="margin-top:16px;">
                            <span class="jj-label" data-i18n="resume_languages_label">زبان‌ها</span>
                            <ul class="jj-doc-list" id="jj-rb-languages-list"></ul>
                            <div class="jj-field-row">
                                <input class="jj-input" id="jj-rb-lang-name" data-i18n="resume_lang_name_ph" data-i18n-placeholder placeholder="نام زبان">
                                <select class="jj-input" id="jj-rb-lang-level">
                                    <option value="beginner" data-i18n="resume_level_beginner">مبتدی</option>
                                    <option value="intermediate" selected data-i18n="resume_level_intermediate">متوسط</option>
                                    <option value="advanced" data-i18n="resume_level_advanced">پیشرفته</option>
                                    <option value="native" data-i18n="resume_level_native">زبان مادری</option>
                                </select>
                            </div>
                            <button type="button" class="jj-btn jj-btn-secondary" id="jj-btn-rb-lang-add" data-i18n="resume_add_language_btn" style="margin-top:6px;">+ افزودن زبان</button>
                        </div>

                        <div style="margin-top:16px;">
                            <span class="jj-label" data-i18n="resume_certifications_label">گواهینامه‌ها و دوره‌ها</span>
                            <ul class="jj-doc-list" id="jj-rb-certifications-list"></ul>
                            <div class="jj-field-row">
                                <input class="jj-input" id="jj-rb-cert-title" data-i18n="resume_cert_title_ph" data-i18n-placeholder placeholder="عنوان گواهینامه">
                                <input class="jj-input" id="jj-rb-cert-issuer" data-i18n="resume_cert_issuer_ph" data-i18n-placeholder placeholder="صادرکننده">
                            </div>
                            <input class="jj-input" id="jj-rb-cert-date" data-i18n="resume_date_ph" data-i18n-placeholder placeholder="تاریخ (مثلاً 1402)">
                            <button type="button" class="jj-btn jj-btn-secondary" id="jj-btn-rb-cert-add" data-i18n="resume_add_cert_btn" style="margin-top:6px;">+ افزودن گواهینامه</button>
                        </div>

                        <div style="margin-top:16px;">
                            <span class="jj-label" data-i18n="resume_links_label">لینک‌ها (نمونه‌کار، شبکه‌های اجتماعی حرفه‌ای)</span>
                            <ul class="jj-doc-list" id="jj-rb-links-list"></ul>
                            <div class="jj-field-row">
                                <input class="jj-input" id="jj-rb-link-label" data-i18n="resume_link_label_ph" data-i18n-placeholder placeholder="عنوان لینک">
                                <input class="jj-input" id="jj-rb-link-url" data-i18n="resume_link_url_ph" data-i18n-placeholder placeholder="https://...">
                            </div>
                            <button type="button" class="jj-btn jj-btn-secondary" id="jj-btn-rb-link-add" data-i18n="resume_add_link_btn" style="margin-top:6px;">+ افزودن لینک</button>
                        </div>

                        <div style="margin-top:16px;">
                            <span class="jj-label" data-i18n="resume_template_label">قالب نمایش رزومه</span>
                            <select class="jj-input" id="jj-rb-template">
                                <option value="classic" data-i18n="resume_template_classic">کلاسیک</option>
                                <option value="modern" data-i18n="resume_template_modern">مدرن</option>
                            </select>
                        </div>

                        <button type="button" class="jj-btn" id="jj-btn-rb-save" data-i18n="resume_builder_save_btn" style="margin-top:14px;">ذخیره‌ی رزومه‌ی حرفه‌ای</button>
                        <div class="jj-msg" id="jj-msg-rb"></div>

                        <div style="margin-top:16px;padding-top:14px;border-top:1px dashed #e5e7eb;">
                            <label class="jj-check-chip"><input type="checkbox" id="jj-rb-public-toggle"> <span data-i18n="resume_public_toggle_label">لینک عمومیِ رزومه‌ام فعال باشد (قابل‌اشتراک‌گذاری با کارفرمایان)</span></label>
                            <div id="jj-rb-public-url-box" style="display:none;margin-top:8px;font-size:12.5px;">
                                <input class="jj-input" id="jj-rb-public-url" readonly style="direction:ltr;text-align:left;">
                                <button type="button" class="jj-btn jj-btn-secondary" id="jj-btn-rb-copy-link" data-i18n="copy_link_btn" style="margin-top:6px;">کپی لینک</button>
                            </div>
                            <div class="jj-msg" id="jj-msg-rb-public"></div>
                        </div>
                    </fieldset>
                        </div>
                        <div class="jj-resume-preview">
                            <h3 data-i18n="resume_preview_heading">پیش‌نمایش رزومه</h3>
                            <div class="jj-preview-avatar" id="jj-preview-avatar"><img id="jj-preview-avatar-img" style="display:none;width:100%;height:100%;object-fit:cover;"><span id="jj-preview-avatar-fallback">👤</span></div>
                            <div class="jj-preview-name" id="jj-preview-name">—</div>
                            <div class="jj-preview-title" id="jj-preview-title"></div>
                            <ul class="jj-preview-contact" id="jj-preview-contact"></ul>
                            <div class="jj-preview-skills-title" data-i18n="skills_label">مهارت‌ها</div>
                            <div id="jj-preview-skills"></div>
                        </div>
                    </div>
                </div>
                    <div class="jj-field-row" style="margin-bottom:16px;">
                        <input type="text" id="jj-jobs-search" class="jj-input" data-i18n="search_placeholder" data-i18n-placeholder placeholder="جستجو در آگهی‌ها...">
                        <button class="jj-btn jj-btn-secondary" id="jj-btn-jobs-search" data-i18n="search" style="width:auto;padding:12px 20px;">جستجو</button>
                    </div>
                    <ul class="jj-doc-list" id="jj-browse-jobs-list"></ul>
                    <ul class="jj-doc-list" id="jj-my-apps-list" style="display:none;"></ul>
                    <div class="jj-msg" id="jj-msg-browse"></div>
                </div>

                <div class="jj-step jj-wide" data-step="contracts" style="display:none">
                    <h2 data-i18n="my_contracts">قراردادهای من</h2>
                    <button class="jj-btn-link" id="jj-btn-contracts-back"><span class="jj-back-arrow">→</span> <span data-i18n="back">بازگشت</span></button>
                    <ul class="jj-doc-list" id="jj-contracts-list"></ul>
                    <div id="jj-contract-detail" style="display:none;margin-top:16px;">
                        <fieldset class="jj-fieldset"><legend data-i18n="contract_details">جزئیات قرارداد</legend>
                            <pre id="jj-contract-text" style="white-space:pre-wrap;font-family:inherit;font-size:13px;background:#f9fafb;padding:12px;border-radius:8px;max-height:300px;overflow-y:auto;"></pre>
                            <div id="jj-contract-identity" style="display:none;background:#f7f0dc;border:1px solid #99f6e4;border-radius:8px;padding:10px;margin-top:10px;font-size:13px;"></div>
                            <div id="jj-contract-team-payment" style="display:none;margin-top:10px;"></div>
                            <button class="jj-btn" id="jj-btn-contract-pay" data-i18n="pay_my_share" style="display:none;">پرداخت سهم من</button>
                            <div id="jj-contract-otp-box" style="display:none;margin-top:10px;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                                <p style="font-size:13px;margin:0 0 8px;" data-i18n="contract_settlement_otp_note">تسویه‌ی مالی این قرارداد کامل شده است. برای نهایی‌شدنِ حقوقیِ قرارداد، هویت خود را با کد پیامکی تأیید کنید.</p>
                                <button class="jj-btn jj-btn-secondary" id="jj-btn-contract-otp-request" type="button" data-i18n="send_verify_code_btn">ارسال کد تأیید</button>
                                <div id="jj-contract-otp-verify-row" style="display:none;margin-top:8px;">
                                    <input type="text" id="jj-contract-otp-code" class="jj-input" maxlength="5" data-i18n="five_digit_code_ph" data-i18n-placeholder placeholder="کد ۵ رقمی" style="width:100%;box-sizing:border-box;margin-bottom:8px;" />
                                    <button class="jj-btn" id="jj-btn-contract-otp-verify" type="button" data-i18n="verify_finalize_contract_btn">تأیید کد و نهایی‌کردن قرارداد</button>
                                </div>
                            </div>
                            <a href="#" id="jj-contract-certificate-link" target="_blank" class="jj-btn" data-i18n="view_print_certificate_btn" style="display:none;text-decoration:none;">📄 مشاهده / چاپ گواهی قرارداد</a>
                            <div class="jj-msg" id="jj-msg-contract"></div>
                        </fieldset>
                    </div>
                </div>

                <div class="jj-step" data-step="done" style="display:none">
                    <h2 id="jj-done-title" data-i18n="done_step_title">✔ با موفقیت انجام شد</h2>
                    <p class="jj-sub" id="jj-done-desc"></p>
                    <div id="jj-done-actions" style="display:none;margin-top:12px;"></div>
                </div>

            </div>
        </div>

        <style>
            .jj-wrap { max-width: 420px; margin: 32px auto; font-family: Tahoma, Vazir, sans-serif; }
            .jj-wrap.jj-wide-mode { max-width: 640px; }
            .jj-card { background:#fff; border-radius:14px; border-top:3px solid #c9a227; box-shadow:0 4px 24px rgba(0,0,0,.08); padding:28px 24px; text-align:center; }
            .jj-card h2 { font-size:19px; margin:0 0 8px; color:#14213d; }
            .jj-sub { color:#6b7280; font-size:14px; margin:0 0 20px; }
            .jj-input { width:100%; box-sizing:border-box; padding:12px 14px; font-size:15px; border:1px solid #d1d5db; border-radius:8px; margin-bottom:14px; }
            .jj-input-otp { font-size:22px; letter-spacing:6px; text-align:center; direction:ltr; }
            select.jj-input { background:#fff; }
            .jj-btn { width:100%; padding:12px; font-size:15px; background:#14213d; color:#fff; border:none; border-radius:8px; cursor:pointer; }
            .jj-btn:hover { opacity:.9; }
            .jj-btn:disabled { background:#9ca3af; cursor:not-allowed; }
            .jj-btn-secondary { background:#fff; color:#14213d; border:1.5px solid #14213d; margin-bottom:14px; }
            .jj-btn-link { background:none; border:none; color:#14213d; font-size:13px; margin-top:12px; cursor:pointer; }
            .jj-btn-link:disabled { color:#9ca3af; cursor:not-allowed; }
            .jj-msg { margin-top:12px; font-size:13px; min-height:18px; }
            .jj-msg.jj-error { color:#dc2626; }
            .jj-msg.jj-ok { color:#059669; }
            .jj-timer { font-size:13px; color:#6b7280; margin-top:10px; }
            .jj-role-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px; }
            .jj-role-card { border:1.5px solid #e5e7eb; background:#fafafa; border-radius:10px; padding:14px 10px; cursor:pointer; text-align:center; }
.jj-role-icon{width:56px;height:56px;border-radius:50%;object-fit:cover;margin:0 auto 8px;display:block;}
            .jj-role-card:hover, .jj-role-card.jj-selected { border-color:#14213d; background:#f7f0dc; }
            .jj-role-title { display:block; font-size:14px; font-weight:bold; color:#1f2937; margin-bottom:4px; }
            .jj-role-desc { display:block; font-size:11.5px; color:#6b7280; }
            .jj-role-extra { margin-top:16px; }
            .jj-wide .jj-card, .jj-wide { text-align:right; }
            .jj-fieldset { border:1px solid #e5e7eb; border-radius:10px; padding:14px; margin-bottom:16px; text-align:right; }
            .jj-fieldset legend { font-size:13px; font-weight:bold; color:#14213d; padding:0 6px; }
            .jj-field-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
            .jj-field-row .jj-input { margin-bottom:10px; }
            .jj-label { display:block; font-size:12px; color:#6b7280; margin-bottom:4px; }
            .jj-radio-row { display:flex; gap:16px; margin-bottom:16px; justify-content:center; }
            .jj-radio-row label { font-size:14px; display:flex; align-items:center; gap:6px; cursor:pointer; }
            .jj-doc-list { list-style:none; padding:0; margin:0 0 14px; text-align:right; }
            .jj-doc-list li { display:flex; justify-content:space-between; align-items:center; padding:8px 10px; background:#f9fafb; border-radius:6px; margin-bottom:6px; font-size:13px; flex-wrap:wrap; gap:6px; word-break:break-word; }
            #jj-country-code { min-width:0; }
            .jj-phone-row { display:flex; gap:8px; align-items:flex-start; }
            .jj-phone-row #jj-country-code { flex:0 0 110px; padding-left:4px; padding-right:4px; }
            .jj-phone-row #jj-phone { flex:1; min-width:0; }

            /* ---- ریسپانسیو: موبایل و صفحه‌ی باریک ---- */
            @media (max-width: 480px) {
                .jj-wrap { max-width:100%; margin:0; padding:14px 10px 90px; box-sizing:border-box; }
                .jj-wrap.jj-wide-mode { max-width:100%; }
                .jj-card { padding:20px 16px; border-radius:10px; }
                .jj-field-row { grid-template-columns:1fr; }
                .jj-role-grid { grid-template-columns:1fr; }
                .jj-input { font-size:16px; } /* جلوگیری از زوم خودکار سافاری روی فوکوس */
                #jj-help-fab, #jj-ai-fab, #jj-install-fab { width:42px; height:42px; font-size:17px; }
                #jj-help-fab-wrap, #jj-ai-fab-wrap, #jj-install-fab { left:10px; }
                #jj-help-fab-wrap { bottom:12px; }
                #jj-ai-fab-wrap { bottom:78px; }
                #jj-install-fab { bottom:144px; }
                #jj-help-panel, #jj-ai-panel { left:10px; width:calc(100vw - 20px); max-width:calc(100vw - 20px); }
                #jj-lang-switch { font-size:11.5px; padding:5px 6px; }
                table.widefat { display:block; overflow-x:auto; }
            }
        
            /* === Wizard stepper (registration / resume / job-post) === */
            .jj-stepper { display:flex; align-items:flex-start; margin-bottom:26px; }
            .jj-stepper-item { display:flex; flex-direction:column; align-items:center; gap:6px; min-width:60px; }
            .jj-stepper-circle { width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #d1d5db; background:#fff; color:#9ca3af; flex-shrink:0; box-sizing:border-box; }
            .jj-stepper-circle svg { width:17px; height:17px; }
            .jj-stepper-item.jj-step-active .jj-stepper-circle { background:#14213d; border-color:#14213d; color:#fff; }
            .jj-stepper-item.jj-step-done .jj-stepper-circle { border-color:#14213d; color:#14213d; }
            .jj-stepper-label { font-size:11px; color:#9ca3af; white-space:nowrap; }
            .jj-stepper-item.jj-step-active .jj-stepper-label, .jj-stepper-item.jj-step-done .jj-stepper-label { color:#14213d; font-weight:bold; }
            .jj-stepper-line { flex:1; height:2px; background:#e5e7eb; margin:19px 4px 0; }
            .jj-stepper-line.jj-step-done { background:#14213d; }

            /* Resume builder / job form layout with live preview */
            .jj-resume-layout { display:flex; gap:20px; align-items:flex-start; }
            .jj-resume-main { flex:1 1 0; min-width:0; }
            .jj-resume-preview { width:250px; flex-shrink:0; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px; text-align:right; }
            @media (max-width:820px){ .jj-resume-layout{flex-direction:column;} .jj-resume-preview{width:100%;box-sizing:border-box;} }
            .jj-resume-preview h3 { font-size:14px; margin:0 0 14px; text-align:center; color:#14213d; }
            .jj-preview-avatar { width:60px; height:60px; border-radius:50%; background:#eef0f4; margin:0 auto 10px; display:flex; align-items:center; justify-content:center; font-size:22px; color:#9ca3af; overflow:hidden; }
            .jj-preview-avatar img { width:100%; height:100%; object-fit:cover; }
            .jj-preview-name { text-align:center; font-size:15px; font-weight:bold; color:#1f2937; min-height:18px; }
            .jj-preview-title { text-align:center; font-size:12px; color:#6b7280; margin-bottom:14px; min-height:14px; }
            .jj-preview-contact { list-style:none; padding:0; margin:0 0 14px; font-size:11.5px; color:#374151; }
            .jj-preview-contact li { padding:5px 0; border-bottom:1px dashed #f0f0f0; overflow-wrap:anywhere; }
            .jj-preview-skills-title { font-size:12px; font-weight:bold; color:#14213d; margin-bottom:8px; }
            .jj-preview-skill-row { display:flex; align-items:center; gap:8px; margin-bottom:7px; font-size:11px; }
            .jj-preview-skill-name { width:64px; flex-shrink:0; color:#374151; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .jj-preview-skill-bar { flex:1; height:6px; background:#eef0f4; border-radius:4px; overflow:hidden; }
            .jj-preview-skill-bar span { display:block; height:100%; background:#c9a227; }

            /* wizard nav buttons */
            .jj-wizard-nav { display:flex; gap:10px; margin-top:6px; }
            .jj-wizard-nav .jj-btn { margin-bottom:0; }
            .jj-wizard-nav .jj-btn-secondary { flex:1; margin-bottom:0; }
            .jj-wizard-nav .jj-btn-primary-wide { flex:2; }

            /* checkbox chip group (work type) */
            .jj-check-group { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
            .jj-check-chip { display:flex; align-items:center; gap:6px; border:1.5px solid #e5e7eb; border-radius:8px; padding:8px 12px; font-size:12.5px; cursor:pointer; color:#374151; }
            .jj-check-chip input { margin:0; }
            .jj-check-chip.jj-checked { border-color:#14213d; background:#f7f0dc; }

            .jj-wizard-substep-title { font-size:14px; font-weight:bold; color:#1f2937; margin:0 0 14px; }
            .jj-wizard-hidden { display:none !important; }
</style>

        <script>
        (function () {
            var REST = <?php echo wp_json_encode($rest_url); ?>;
            var JJ_PHONE_RE = /^(09\d{9}|\d{8,15})$/;
            var jjLoginMethod = 'otp'; // 'otp' یا 'password' — طبق درخواست مدیر سایت، کاربر قبلی می‌تواند انتخاب کند
            var state = { phone: '', token: <?php echo wp_json_encode($initial_token); ?>, role: '', ttl: 120, timerHandle: null, locations: null }; try { if (!state.token) { var __jjSavedToken = localStorage.getItem('jj_auth_token'); if (__jjSavedToken) { state.token = __jjSavedToken; window.JJ_AUTH_TOKEN = __jjSavedToken; } } } catch (e) {}
            var I18N_DATA = <?php echo wp_json_encode($i18n_all, JSON_UNESCAPED_UNICODE); ?>;
            var RTL_LANGS = <?php echo wp_json_encode(JJ_I18N::RTL_LANGS); ?>;
            var jjServerLocale = "<?php echo esc_js($__jj_locale); ?>";
            var currentLang = (function () {
                try {
                    localStorage.setItem('jj_lang', jjServerLocale);
                    return jjServerLocale;
                } catch (e) { return jjServerLocale || 'fa'; }
            })();
            function T(key) { return (I18N_DATA[currentLang] && I18N_DATA[currentLang][key]) || (I18N_DATA.fa && I18N_DATA.fa[key]) || key; }
            function applyTranslations() {
                document.querySelectorAll('[data-i18n]').forEach(function (el) {
                    var key = el.getAttribute('data-i18n');
                    if (el.hasAttribute('data-i18n-placeholder')) el.setAttribute('placeholder', T(key));
                    else if (el.hasAttribute('data-i18n-title')) el.setAttribute('title', T(key));
                    else el.textContent = T(key);
                });
                document.documentElement.setAttribute('lang', currentLang);
                var isRtl = RTL_LANGS.indexOf(currentLang) !== -1;
                document.getElementById('jj-app').setAttribute('dir', isRtl ? 'rtl' : 'ltr');
                document.querySelectorAll('.jj-back-arrow').forEach(function (el) { el.textContent = isRtl ? '→' : '←'; });
            }
            function setLang(lang) {
                currentLang = lang;
                try { localStorage.setItem('jj_lang', lang); } catch (e) {}
                try {
                    // یکسان‌سازیِ تشخیصِ زبان: قبلاً فقط localStorage نوشته می‌شد که
                    // سمتِ سرور هیچ اطلاعی از آن نداشت، پس با هر بارگذاریِ بعدیِ صفحه
                    // (یا رفتن به گام/صفحه‌ی دیگر) این انتخاب گم می‌شد. حالا علاوه بر
                    // localStorage، در کوکیِ jj_lang هم ذخیره می‌شود تا
                    // JJ_I18N::current_lang() سمتِ سرور همین انتخاب را در هر صفحه‌ای
                    // از سایت تشخیص داده و همیشه اعمال کند.
                    var jjLangExpiry = new Date();
                    jjLangExpiry.setFullYear(jjLangExpiry.getFullYear() + 1);
                    document.cookie = 'jj_lang=' + encodeURIComponent(lang) + '; path=/; expires=' + jjLangExpiry.toUTCString() + '; SameSite=Lax';
                } catch (e) {}
                applyTranslations();
            }

            function $(sel) { return document.querySelector(sel); }
            function escHtml(s) {
                if (s === null || s === undefined) return '';
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }
            var currentStepName = 'phone';
            var previousStepName = 'phone';
            function showStep(name) {
                if (name !== currentStepName) previousStepName = currentStepName;
                currentStepName = name;
                var isWide = false;
                document.querySelectorAll('.jj-step').forEach(function (el) {
                    var match = el.getAttribute('data-step') === name;
                    el.style.display = match ? '' : 'none';
                    if (match && el.classList.contains('jj-wide')) isWide = true;
                });
                document.getElementById('jj-app').classList.toggle('jj-wide-mode', isWide);
                if ($('#jj-help-panel').style.display !== 'none') loadHelp();
            }
            function setMsg(id, text, isError) {
                var el = $(id);
                el.textContent = text || '';
                el.className = 'jj-msg' + (text ? (isError ? ' jj-error' : ' jj-ok') : '');
            }
            function jjIconSvg(name) {
                var icons = {
                    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
                    account: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M14 10h4M14 14h4"/></svg>',
                    user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>',
                    star: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>',
                    briefcase: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
                    doc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/></svg>',
                    building: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18"/><path d="M9 8h1M9 12h1M9 16h1M14 8h1M14 12h1M14 16h1"/></svg>',
                    money: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-1 1-1.5 3-1.5s3 .8 3 2-1 1.7-3 2-3 1-3 2 1.3 2 3 2 3-.5 3-1.5"/></svg>',
                    flag: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4"/><path d="M5 4h13l-3 4 3 4H5"/></svg>'
                };
                return icons[name] || '';
            }
            function jjStepperHtml(steps, current) {
                var html = '<div class="jj-stepper">';
                steps.forEach(function (step, i) {
                    var state = i < current ? 'jj-step-done' : (i === current ? 'jj-step-active' : '');
                    html += '<div class="jj-stepper-item ' + state + '" data-stepper-index="' + i + '"><div class="jj-stepper-circle">' + jjIconSvg(step.icon) + '</div><div class="jj-stepper-label">' + step.label + '</div></div>';
                    if (i < steps.length - 1) html += '<div class="jj-stepper-line ' + (i < current ? 'jj-step-done' : '') + '"></div>';
                });
                html += '</div>';
                return html;
            }
            function showJobFormStep(n) {
                var wrap = $('#jj-job-form-fields');
                if (!wrap) return;
                wrap.querySelectorAll('.jj-resume-step').forEach(function (el) {
                    el.classList.toggle('jj-wizard-hidden', el.getAttribute('data-resume-step') !== String(n));
                });
                var stepper = wrap.querySelector('.jj-stepper');
                if (stepper) {
                    stepper.querySelectorAll('.jj-stepper-item').forEach(function (el) {
                        var idx = parseInt(el.getAttribute('data-stepper-index'), 10);
                        el.classList.remove('jj-step-active', 'jj-step-done');
                        if (idx < n) el.classList.add('jj-step-done');
                        else if (idx === n) el.classList.add('jj-step-active');
                    });
                    stepper.querySelectorAll('.jj-stepper-line').forEach(function (el, idx) {
                        el.classList.toggle('jj-step-done', idx < n);
                    });
                }
                wrap.scrollIntoView({ block: 'nearest' });
            }
            function showResumeStep(n) {
                var wrap = $('#jj-jobseeker-profile-wrap');
                if (!wrap) return;
                wrap.querySelectorAll('.jj-resume-step').forEach(function (el) {
                    el.classList.toggle('jj-wizard-hidden', el.getAttribute('data-resume-step') !== String(n));
                });
                var stepper = wrap.querySelector('.jj-stepper');
                if (stepper) {
                    stepper.querySelectorAll('.jj-stepper-item').forEach(function (el) {
                        var idx = parseInt(el.getAttribute('data-stepper-index'), 10);
                        el.classList.remove('jj-step-active', 'jj-step-done');
                        if (idx < n) el.classList.add('jj-step-done');
                        else if (idx === n) el.classList.add('jj-step-active');
                    });
                    stepper.querySelectorAll('.jj-stepper-line').forEach(function (el, idx) {
                        el.classList.toggle('jj-step-done', idx < n);
                    });
                }
            }
            function loadResumeAiBox() {
                fetch(REST + 'jobseeker/resume', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) return;
                        $('#jj-resume-ai-text').value = res.resume_text || '';
                        $('#jj-resume-ai-quota').textContent = (res.quota_remaining === null || res.quota_remaining === undefined) ? T('no_active_plan_short') : res.quota_remaining;
                    }).catch(function () {});
            }
            $('#jj-btn-resume-ai-save').addEventListener('click', function () {
                setMsg('#jj-msg-resume-ai', '', false);
                $('#jj-btn-resume-ai-save').disabled = true;
                api('jobseeker/resume', { resume_text: $('#jj-resume-ai-text').value }, true).then(function (res) {
                    $('#jj-btn-resume-ai-save').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-resume-ai', (res.data && res.data.message) || T('error_generic'), true); return; }
                    setMsg('#jj-msg-resume-ai', T('resume_text_saved'), false);
                });
            });
            function runResumeAi(action, btnId) {
                setMsg('#jj-msg-resume-ai', '', false);
                $('#jj-resume-ai-result').style.display = 'none';
                $('#jj-btn-resume-ai-apply').style.display = 'none';
                $(btnId).disabled = true;
                api('jobseeker/resume/' + action, {}, true).then(function (res) {
                    $(btnId).disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-resume-ai', (res.data && res.data.message) || T('error_generic'), true); return; }
                    if (res.data.quota_remaining !== undefined) $('#jj-resume-ai-quota').textContent = res.data.quota_remaining;
                    var text = action === 'review' ? res.data.feedback : res.data.improved_text;
                    var box = $('#jj-resume-ai-result');
                    box.textContent = text; box.style.display = '';
                    if (action === 'improve') { box.setAttribute('data-improved-text', text); $('#jj-btn-resume-ai-apply').style.display = ''; }
                });
            }
            $('#jj-btn-resume-ai-review').addEventListener('click', function () { runResumeAi('review', '#jj-btn-resume-ai-review'); });
            $('#jj-btn-resume-ai-improve').addEventListener('click', function () { runResumeAi('improve', '#jj-btn-resume-ai-improve'); });
            $('#jj-btn-resume-ai-apply').addEventListener('click', function () {
                var improved = $('#jj-resume-ai-result').getAttribute('data-improved-text') || '';
                $('#jj-resume-ai-text').value = improved;
                $('#jj-btn-resume-ai-apply').style.display = 'none';
                setMsg('#jj-msg-resume-ai', T('resume_improved_applied_hint'), false);
            });

            // ---------- رزومه‌ی حرفه‌ای ساخت‌یافته (سوابق شغلی/تحصیلی/زبان/گواهینامه/لینک) ----------
            var jjRb = { summary: '', experience: [], education: [], languages: [], certifications: [], links: [], template: 'classic' };
            var jjRbLevelLabels = { beginner: 'resume_level_beginner', intermediate: 'resume_level_intermediate', advanced: 'resume_level_advanced', native: 'resume_level_native' };
            function rbRenderList(type) {
                var listMap = { experience: '#jj-rb-experience-list', education: '#jj-rb-education-list', languages: '#jj-rb-languages-list', certifications: '#jj-rb-certifications-list', links: '#jj-rb-links-list' };
                var ul = $(listMap[type]);
                if (!ul) return;
                ul.innerHTML = '';
                if (!jjRb[type].length) { ul.innerHTML = '<li style="color:#9ca3af;">' + T('resume_list_empty') + '</li>'; return; }
                jjRb[type].forEach(function (item, idx) {
                    var li = document.createElement('li');
                    var title = document.createElement('span');
                    if (type === 'experience') {
                        var period = [item.start_date, item.current ? T('resume_currently_working') : item.end_date].filter(Boolean).join(' – ');
                        title.textContent = [item.title, item.company].filter(Boolean).join(' — ') + (period ? ' (' + period + ')' : '');
                    } else if (type === 'education') {
                        var period2 = [item.start_date, item.end_date].filter(Boolean).join(' – ');
                        title.textContent = [item.degree, item.field].filter(Boolean).join(' ') + (item.institution ? ' — ' + item.institution : '') + (period2 ? ' (' + period2 + ')' : '');
                    } else if (type === 'languages') {
                        title.textContent = item.name + ' (' + T(jjRbLevelLabels[item.level] || 'resume_level_intermediate') + ')';
                    } else if (type === 'certifications') {
                        title.textContent = [item.title, item.issuer].filter(Boolean).join(' — ') + (item.date ? ' (' + item.date + ')' : '');
                    } else if (type === 'links') {
                        title.textContent = (item.label || item.url) + ' — ' + item.url;
                    }
                    var rm = document.createElement('button');
                    rm.type = 'button'; rm.className = 'jj-btn-link'; rm.textContent = '✕';
                    rm.style.cssText = 'float:left;color:#dc2626;';
                    rm.addEventListener('click', function () { jjRb[type].splice(idx, 1); rbRenderList(type); });
                    li.appendChild(rm); li.appendChild(title);
                    ul.appendChild(li);
                });
            }
            function loadResumeBuilder() {
                fetch(REST + 'jobseeker/resume/full', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) return;
                        jjRb.summary = res.summary || '';
                        jjRb.experience = res.experience || [];
                        jjRb.education = res.education || [];
                        jjRb.languages = res.languages || [];
                        jjRb.certifications = res.certifications || [];
                        jjRb.links = res.links || [];
                        jjRb.template = res.template || 'classic';
                        $('#jj-rb-summary').value = jjRb.summary;
                        $('#jj-rb-template').value = jjRb.template;
                        ['experience', 'education', 'languages', 'certifications', 'links'].forEach(rbRenderList);
                        $('#jj-rb-public-toggle').checked = !!res.public;
                        $('#jj-rb-public-url-box').style.display = res.public ? '' : 'none';
                        if (res.public_url) $('#jj-rb-public-url').value = res.public_url;
                    }).catch(function () {});
            }
            $('#jj-btn-rb-exp-add').addEventListener('click', function () {
                var title = $('#jj-rb-exp-title').value.trim();
                if (!title) { setMsg('#jj-msg-rb', T('resume_title_required'), true); return; }
                jjRb.experience.push({
                    title: title, company: $('#jj-rb-exp-company').value.trim(),
                    start_date: $('#jj-rb-exp-start').value.trim(), end_date: $('#jj-rb-exp-end').value.trim(),
                    current: $('#jj-rb-exp-current').checked, description: $('#jj-rb-exp-desc').value.trim()
                });
                $('#jj-rb-exp-title').value = ''; $('#jj-rb-exp-company').value = ''; $('#jj-rb-exp-start').value = ''; $('#jj-rb-exp-end').value = ''; $('#jj-rb-exp-current').checked = false; $('#jj-rb-exp-desc').value = '';
                rbRenderList('experience'); setMsg('#jj-msg-rb', '', false);
            });
            $('#jj-btn-rb-edu-add').addEventListener('click', function () {
                var degree = $('#jj-rb-edu-degree').value.trim();
                var institution = $('#jj-rb-edu-institution').value.trim();
                if (!degree && !institution) { setMsg('#jj-msg-rb', T('resume_title_required'), true); return; }
                jjRb.education.push({
                    degree: degree, field: $('#jj-rb-edu-field').value.trim(), institution: institution,
                    start_date: $('#jj-rb-edu-start').value.trim(), end_date: $('#jj-rb-edu-end').value.trim()
                });
                $('#jj-rb-edu-degree').value = ''; $('#jj-rb-edu-field').value = ''; $('#jj-rb-edu-institution').value = ''; $('#jj-rb-edu-start').value = ''; $('#jj-rb-edu-end').value = '';
                rbRenderList('education'); setMsg('#jj-msg-rb', '', false);
            });
            $('#jj-btn-rb-lang-add').addEventListener('click', function () {
                var name = $('#jj-rb-lang-name').value.trim();
                if (!name) { setMsg('#jj-msg-rb', T('resume_title_required'), true); return; }
                jjRb.languages.push({ name: name, level: $('#jj-rb-lang-level').value });
                $('#jj-rb-lang-name').value = '';
                rbRenderList('languages'); setMsg('#jj-msg-rb', '', false);
            });
            $('#jj-btn-rb-cert-add').addEventListener('click', function () {
                var title = $('#jj-rb-cert-title').value.trim();
                if (!title) { setMsg('#jj-msg-rb', T('resume_title_required'), true); return; }
                jjRb.certifications.push({ title: title, issuer: $('#jj-rb-cert-issuer').value.trim(), date: $('#jj-rb-cert-date').value.trim() });
                $('#jj-rb-cert-title').value = ''; $('#jj-rb-cert-issuer').value = ''; $('#jj-rb-cert-date').value = '';
                rbRenderList('certifications'); setMsg('#jj-msg-rb', '', false);
            });
            $('#jj-btn-rb-link-add').addEventListener('click', function () {
                var url = $('#jj-rb-link-url').value.trim();
                if (!url) { setMsg('#jj-msg-rb', T('resume_title_required'), true); return; }
                jjRb.links.push({ label: $('#jj-rb-link-label').value.trim(), url: url });
                $('#jj-rb-link-label').value = ''; $('#jj-rb-link-url').value = '';
                rbRenderList('links'); setMsg('#jj-msg-rb', '', false);
            });
            $('#jj-btn-rb-save').addEventListener('click', function () {
                setMsg('#jj-msg-rb', '', false);
                jjRb.summary = $('#jj-rb-summary').value.trim();
                jjRb.template = $('#jj-rb-template').value;
                $('#jj-btn-rb-save').disabled = true;
                api('jobseeker/resume/full', jjRb, true).then(function (res) {
                    $('#jj-btn-rb-save').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-rb', (res.data && res.data.message) || T('error_generic'), true); return; }
                    setMsg('#jj-msg-rb', T('resume_builder_saved'), false);
                    loadResumeAiBox();
                });
            });
            $('#jj-rb-public-toggle').addEventListener('change', function () {
                var checked = this.checked;
                setMsg('#jj-msg-rb-public', '', false);
                api('jobseeker/resume/public-toggle', { public: checked }, true).then(function (res) {
                    if (!res.ok) { $('#jj-rb-public-toggle').checked = !checked; setMsg('#jj-msg-rb-public', (res.data && res.data.message) || T('error_generic'), true); return; }
                    $('#jj-rb-public-url-box').style.display = res.data.public ? '' : 'none';
                    if (res.data.public_url) $('#jj-rb-public-url').value = res.data.public_url;
                });
            });
            $('#jj-btn-rb-copy-link').addEventListener('click', function () {
                var val = $('#jj-rb-public-url').value;
                if (!val) return;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(val).then(function () { setMsg('#jj-msg-rb-public', T('link_copied'), false); });
                } else {
                    $('#jj-rb-public-url').select();
                    document.execCommand('copy');
                    setMsg('#jj-msg-rb-public', T('link_copied'), false);
                }
            });

            document.addEventListener('click', function (e) {
                var nextBtn = e.target.closest && e.target.closest('[data-resume-next]');
                if (nextBtn) { showResumeStep(parseInt(nextBtn.getAttribute('data-resume-next'), 10)); return; }
                var prevBtn = e.target.closest && e.target.closest('[data-resume-prev]');
                if (prevBtn) { showResumeStep(parseInt(prevBtn.getAttribute('data-resume-prev'), 10)); return; }
                var jobNextBtn = e.target.closest && e.target.closest('[data-jobform-next]');
                if (jobNextBtn) { showJobFormStep(parseInt(jobNextBtn.getAttribute('data-jobform-next'), 10)); return; }
                var jobPrevBtn = e.target.closest && e.target.closest('[data-jobform-prev]');
                if (jobPrevBtn) { showJobFormStep(parseInt(jobPrevBtn.getAttribute('data-jobform-prev'), 10)); return; }
            });
            document.addEventListener('change', function (e) {
                if (e.target.matches && e.target.matches('.jj-check-chip input[type=checkbox]')) {
                    e.target.closest('.jj-check-chip').classList.toggle('jj-checked', e.target.checked);
                }
            });
            function updateResumePreview() {
                var nameEl = $('#jj-preview-name'), titleEl = $('#jj-preview-title'), contactEl = $('#jj-preview-contact'), skillsEl = $('#jj-preview-skills');
                if (!nameEl) return;
                var fullname = (($('#jj-js-fullname') || {}).value) || '';
                nameEl.textContent = fullname || '—';
                var titleSrc = (($('#jj-js-desired-1') || {}).value) || '';
                titleEl.textContent = titleSrc;
                var contactItems = [];
                var phone = (($('#jj-js-phone-display') || {}).value) || '';
                if (phone) contactItems.push(phone);
                var address = (($('#jj-js-address') || {}).value) || '';
                if (address) contactItems.push(address);
                contactEl.innerHTML = contactItems.map(function (t) { return '<li>' + escHtml(t) + '</li>'; }).join('');
                var skills = [1, 2, 3].map(function (i) { return ((($('#jj-js-skill-' + i) || {}).value) || '').trim(); }).filter(Boolean);
                skillsEl.innerHTML = skills.map(function (s) {
                    return '<div class="jj-preview-skill-row"><span class="jj-preview-skill-name">' + escHtml(s) + '</span><div class="jj-preview-skill-bar"><span style="width:80%;"></span></div></div>';
                }).join('');
                var avatarImgEl = $('#jj-js-avatar-preview');
                var showAvatar = avatarImgEl && avatarImgEl.src && avatarImgEl.style.display !== 'none';
                if (showAvatar && $('#jj-preview-avatar-img')) {
                    $('#jj-preview-avatar-img').src = avatarImgEl.src;
                    $('#jj-preview-avatar-img').style.display = '';
                    $('#jj-preview-avatar-fallback').style.display = 'none';
                } else if ($('#jj-preview-avatar-img')) {
                    $('#jj-preview-avatar-img').style.display = 'none';
                    $('#jj-preview-avatar-fallback').style.display = '';
                }
            }
            document.addEventListener('input', function (e) {
                if (e.target.closest && e.target.closest('#jj-jobseeker-profile-wrap')) updateResumePreview();
            });
            document.addEventListener('change', function (e) {
                if (e.target.closest && e.target.closest('#jj-jobseeker-profile-wrap')) updateResumePreview();
            });
            // اگر هر درخواستی ۴۰۱ برگرداند (توکن منقضی/باطل‌شده — مثلاً از
            // یک دستگاه دیگر خارج شده یا نشستش تمام شده)، به‌جای این‌که هر
            // فراخوان جداگانه سعی کند حدس بزند چرا پاسخ خالی/خطا برگشته
            // (که قبلاً باعث می‌شد مثلاً «هنوز آگهی‌ای ثبت نکرده‌اید» به یک
            // کارفرمای واقعاً دارای آگهی نشان داده شود)، یک‌جا کاربر را به
            // مرحله‌ی ورود برمی‌گردانیم.
            function handleUnauthorized() {
                if (state.token === null) return; // قبلاً یک‌بار مدیریت شده
                state.token = null;
                window.JJ_AUTH_TOKEN = null;
                showStep('phone');
                setMsg('#jj-msg-phone', T('session_expired'), true);
            }
            function api(path, body, useAuth) {
                var headers = { 'Content-Type': 'application/json' };
                if (useAuth && state.token) headers['X-JJ-Auth'] = state.token;
                return fetch(REST + path, {
                    method: 'POST', headers: headers, credentials: 'same-origin', body: JSON.stringify(body || {})
                }).then(function (r) {
                    if (r.status === 401 && useAuth) handleUnauthorized();
                    return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                }).catch(function (err) {
                    return { ok: false, data: { message: T('acct_network_error_retry') } };
                });
            }
            function apiUpload(path, formData) {
                var headers = {};
                if (state.token) headers['X-JJ-Auth'] = state.token;
                return fetch(REST + path, { method: 'POST', headers: headers, credentials: 'same-origin', body: formData })
                    .then(function (r) {
                        if (r.status === 401) handleUnauthorized();
                        return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                    }).catch(function (err) {
                        return { ok: false, data: { message: T('acct_network_error_retry') } };
                    });
            }

            // ---------- خلاصه‌ی پروفایل (بالای همه‌ی داشبوردها) ----------
            function loadProfileSummary() {
                var box = $('#jj-profile-summary');
                fetch(REST + 'profile/summary', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) {
                        if (r.status === 401) { handleUnauthorized(); return null; }
                        return r.json().then(function (data) { return r.ok ? data : null; });
                    }).then(function (res) {
                        if (!res || !res.summary) return;
                        var s = res.summary;
                        var html = '';
                        if (res.role === 'jobseeker') {
                            var subLabel = s.subscription_status === 'active' ? T('plan_status_active') : T('plan_status_inactive');
                            var daysHtml = (s.days_remaining !== null && s.days_remaining !== undefined)
                                ? ' — <strong>' + escHtml(s.days_remaining) + ' ' + T('day_label') + '</strong> ' + T('acct_remaining') : '';
                            html = '<strong>👤 ' + escHtml(s.phone) + '</strong> | ' + T('nationality_word') + ': ' + (s.nationality === 'iranian' ? T('nationality_iranian') : (s.nationality === 'other' ? T('nationality_other') : '—'))
                                + ' | ' + T('subscription_word') + ': ' + subLabel + daysHtml
                                + (s.skills && s.skills.length ? '<br>' + T('skills_label') + ': ' + escHtml(s.skills.join('، ')) : '');
                        } else if (res.role === 'employer') {
                            html = '<strong>🏢 ' + escHtml(s.company_name || s.phone) + '</strong> | ' + T('mobile_word') + ': ' + escHtml(s.phone);
                        } else if (res.role === 'agency') {
                            html = '<strong>🤝 ' + escHtml(s.agency_name || s.phone) + '</strong> | ' + T('mobile_word') + ': ' + escHtml(s.phone);
                        } else {
                            html = '<strong>👤 ' + escHtml(s.phone) + '</strong>';
                        }
                        box.innerHTML = html;
                        box.style.display = '';
                    }).catch(function () {});
            }

            // ---------- راهنمای همه‌جاحاضر ----------
            var helpCache = {};
            function loadHelp() {
                var ctx = currentStepName || 'default';
                if (helpCache[ctx]) { renderHelp(helpCache[ctx]); return; }
                $('#jj-help-title').textContent = T('help_title');
                $('#jj-help-body').textContent = T('loading');
                fetch(REST + 'help?context=' + encodeURIComponent(ctx), { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.help) return;
                        helpCache[ctx] = res;
                        renderHelp(res);
                    }).catch(function () { $('#jj-help-body').textContent = T('error_network'); });
            }
            function renderHelp(res) {
                $('#jj-help-title').textContent = res.help.title || T('help_title');
                $('#jj-help-body').textContent = res.help.body || '';
                var c = res.contact || {};
                var lines = [];
                if (c.phone) lines.push(T('contact_phone_label') + ' ' + c.phone);
                if (c.whatsapp) lines.push(T('contact_whatsapp_label') + ' ' + c.whatsapp);
                if (c.telegram) lines.push(T('contact_telegram_label') + ' ' + c.telegram);
                $('#jj-help-contact').innerHTML = lines.length ? lines.join('<br>') : '';
            }
            $('#jj-install-fab').addEventListener('click', function () {
                if (!window.jjDeferredInstallPrompt) return;
                window.jjDeferredInstallPrompt.prompt();
                window.jjDeferredInstallPrompt.userChoice.then(function () { window.jjDeferredInstallPrompt = null; $('#jj-install-fab').style.display = 'none'; });
            });
            $('#jj-lang-switch').value = currentLang;
            $('#jj-lang-switch').addEventListener('change', function () { setLang(this.value); });
            applyTranslations();

            $('#jj-help-fab').addEventListener('click', function () {
                var panel = $('#jj-help-panel');
                var opening = panel.style.display === 'none';
                panel.style.display = opening ? '' : 'none';
                if (opening) loadHelp();
            });
            $('#jj-help-close').addEventListener('click', function () { $('#jj-help-panel').style.display = 'none'; });

            // ---------- کارشناس مشاور (هوش مصنوعی) ----------
            var aiHistoryLoaded = false;
            function renderAiMessage(role, text) {
                var wrap = $('#jj-ai-messages');
                var row = document.createElement('div');
                row.style.cssText = 'margin-bottom:8px;display:flex;' + (role === 'user' ? 'justify-content:flex-start;' : 'justify-content:flex-end;');
                var bubble = document.createElement('div');
                bubble.style.cssText = 'max-width:80%;padding:8px 12px;border-radius:12px;line-height:1.7;white-space:pre-wrap;'
                    + (role === 'user' ? 'background:#e0e7ff;color:#1e1b4b;border-bottom-left-radius:2px;' : 'background:#fff;border:1px solid #e5e7eb;color:#111827;border-bottom-right-radius:2px;');
                bubble.textContent = text;
                row.appendChild(bubble);
                wrap.appendChild(row);
                wrap.scrollTop = wrap.scrollHeight;
            }
            function loadAiHistory() {
                var wrap = $('#jj-ai-messages');
                wrap.innerHTML = '<p style="color:#9ca3af;text-align:center;">' + T('loading') + '</p>';
                fetch(REST + 'ai-advisor/history', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); }).then(function (res) {
                        wrap.innerHTML = '';
                        if (!res.enabled) {
                            wrap.innerHTML = '<p style="color:#9ca3af;text-align:center;padding-top:30px;">' + T('ai_advisor_not_configured') + '</p>';
                            return;
                        }
                        if (!res.messages || !res.messages.length) {
                            wrap.innerHTML = '<p style="color:#9ca3af;text-align:center;padding-top:30px;">' + T('ai_advisor_welcome') + '</p>';
                            return;
                        }
                        res.messages.forEach(function (m) { renderAiMessage(m.role, m.message); });
                        aiHistoryLoaded = true;
                    });
            }
            function sendAiMessage() {
                var input = $('#jj-ai-input');
                var text = input.value.trim();
                if (!text) return;
                renderAiMessage('user', text);
                input.value = '';
                input.disabled = true; $('#jj-ai-send').disabled = true;
                var wrap = $('#jj-ai-messages');
                var typing = document.createElement('div');
                typing.id = 'jj-ai-typing';
                typing.style.cssText = 'color:#9ca3af;font-size:12px;text-align:right;';
                typing.textContent = T('ai_typing_reply');
                wrap.appendChild(typing); wrap.scrollTop = wrap.scrollHeight;
                api('ai-advisor/send', { message: text }, true).then(function (res) {
                    input.disabled = false; $('#jj-ai-send').disabled = false; input.focus();
                    var t = document.getElementById('jj-ai-typing'); if (t) t.remove();
                    if (!res.ok) { renderAiMessage('assistant', (res.data && res.data.message) || T('error_occurred')); return; }
                    renderAiMessage('assistant', res.data.reply);
                }).catch(function () {
                    input.disabled = false; $('#jj-ai-send').disabled = false;
                    var t = document.getElementById('jj-ai-typing'); if (t) t.remove();
                    renderAiMessage('assistant', T('error_network'));
                });
            }
            $('#jj-ai-fab').addEventListener('click', function () {
                var panel = $('#jj-ai-panel');
                var opening = panel.style.display === 'none';
                panel.style.display = opening ? 'flex' : 'none';
                if (!opening) return;
                if (!state.token) {
                    $('#jj-ai-messages').innerHTML = '<p style="color:#6b7280;text-align:center;padding-top:40px;">' + T('ai_advisor_login_required') + '</p>';
                    return;
                }
                if (!aiHistoryLoaded) loadAiHistory();
            });
            $('#jj-ai-close').addEventListener('click', function () { $('#jj-ai-panel').style.display = 'none'; });
            $('#jj-ai-send').addEventListener('click', sendAiMessage);
            $('#jj-ai-input').addEventListener('keydown', function (e) { if (e.key === 'Enter') sendAiMessage(); });
            $('#jj-ai-clear').addEventListener('click', function () {
                if (!confirm(T('confirm_clear_chat'))) return;
                api('ai-advisor/clear', {}, true).then(function () { $('#jj-ai-messages').innerHTML = ''; aiHistoryLoaded = false; loadAiHistory(); });
            });

            // ---------- زنگ اعلان‌ها ----------
            function jjLinkify(html) {
            return html.replace(/(https?:\/\/[^\s<]+)/g, function (url) {
                return '<a href="' + url + '" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline;">' + url + '</a>';
            });
        }
            if (state.token) loadProfileSummary();

            // ---------- بررسی بازگشت از درگاه پرداخت ----------
            (function checkPaymentReturn() {
                var params = new URLSearchParams(window.location.search);
                var pay = params.get('jj_payment');
                if (!pay) return;
                var type = params.get('type') || '';
                var titleMap = {
                    success: T('payment_success_title'),
                    failed: T('payment_failed_plain'),
                    canceled: T('payment_canceled_title'),
                    error: T('payment_error_title')
                };
                var descMap = {
                    employer_registration: T('desc_employer_registration'),
                    agency_registration: T('desc_agency_registration'),
                    jobseeker_registration: T('desc_jobseeker_registration'),
                    team_leader_registration: T('desc_team_leader_registration'),
                    team_roster_batch: T('desc_team_roster_batch'),
                    subscription_plan: T('desc_subscription_plan')
                };
                $('#jj-pr-title').textContent = titleMap[pay] || titleMap.error;
                if (pay === 'success') {
                    $('#jj-pr-desc').textContent = descMap[type] || T('operation_success_generic');
                    showStep('payment-result');
                    // بعد از نمایش پیام موفقیت، بلافاصله کاربر رو به پروفایل/داشبورد نقش خودش می‌بریم
                    // (قبلاً فقط همین پیام نشون داده می‌شد و کاربر همون‌جا می‌موند).
                    setTimeout(function () {
                        if (type === 'jobseeker_registration') {
                            window.location.href = '/account/?jj_complete=1';
                        } else if (type === 'team_leader_registration' || type === 'team_roster_batch') {
                            loadTeamDashboard();
                        } else if (type === 'employer_registration') {
                            resumeEmployerAgencyFlow('employer');
                        } else if (type === 'agency_registration') {
                            resumeEmployerAgencyFlow('agency');
                        } else if (type === 'subscription_plan') {
                            // رفع خلأ واقعی: خرید/تمدید بسته (که همه‌ی ۱۲ بسته‌ی واقعی از همین مسیر
                            // رد می‌شوند) قبلاً هیچ ریدایرکتی نداشت — کاربر روی همین پیام می‌ماند و
                            // خودش باید حدس می‌زد کجا برود. حالا مستقیم به پروفایل می‌رود، جایی که
                            // بخش «امکانات بسته‌ی فعلی من» (Task 12) بلافاصله سهمیه‌های تازه را نشان می‌دهد.
                            window.location.href = '/account/?jj_complete=1';
                        }
                    }, 1800);
                } else {
                    var failDesc = {
                        failed: T('payment_failed_desc'),
                        canceled: T('payment_canceled_desc'),
                        error: T('payment_error_desc')
                    };
                    $('#jj-pr-desc').textContent = failDesc[pay] || failDesc.error;
                    showStep('payment-result');
                }
            })();

            // ---------- مرحله ۱: تلفن و OTP ----------
            function startTimer(seconds) {
                clearInterval(state.timerHandle);
                var remaining = seconds;
                var resendBtn = $('#jj-btn-resend');
                resendBtn.disabled = true;
                function tick() {
                    if (remaining <= 0) {
                        clearInterval(state.timerHandle);
                        $('#jj-timer').textContent = T('otp_expired');
                        resendBtn.disabled = false;
                        return;
                    }
                    var m = Math.floor(remaining / 60), s = remaining % 60;
                    $('#jj-timer').textContent = T('otp_validity_prefix') + ' ' + m + ':' + (s < 10 ? '0' : '') + s;
                    remaining--;
                }
                tick();
                state.timerHandle = setInterval(tick, 1000);
            }

            function buildFullPhone() {
                var raw = $('#jj-phone').value.trim().replace(/\D/g, '');
                var cc = $('#jj-country-code').value;
                var isIran = $('#jj-country-code').selectedOptions[0].hasAttribute('data-iran');
                if (isIran) return raw; // فرمت قدیمی: 09xxxxxxxxx، بدون تغییر
                if (raw.charAt(0) === '0') raw = raw.substring(1); // در فرمت بین‌المللی صفر ابتدایی حذف می‌شود
                return cc + raw;
            }
            $('#jj-country-code').addEventListener('change', function () {
                var isIran = this.selectedOptions[0].hasAttribute('data-iran');
                $('#jj-phone').placeholder = isIran ? '09xxxxxxxxx' : '9xxxxxxxx';
                $('#jj-phone').maxLength = isIran ? 11 : 12;
            });
            function requestOtp() {
                var phone = buildFullPhone();
                setMsg('#jj-msg-phone', '', false);
                if (!JJ_PHONE_RE.test(phone)) {
                    setMsg('#jj-msg-phone', T('phone_invalid_msg'), true);
                    return;
                }
                $('#jj-btn-request').disabled = true;
                api('otp/request', { phone: phone }).then(function (res) {
                    $('#jj-btn-request').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-phone', (res.data && res.data.message) || T('error_generic'), true); return; }
                    state.phone = phone;
                    $('#jj-phone-display').textContent = phone;
                    $('#jj-code').value = '';
                    showStep('otp');
                    startTimer((res.data && res.data.ttl) || 120);
                }).catch(function () { $('#jj-btn-request').disabled = false; setMsg('#jj-msg-phone', T('error_network'), true); });
            }

            function proceedAfterAuth(data) {
                if (data.team_invite_confirmed) {
                        showStep('done');
                        $('#jj-done-title').textContent = T('team_invite_confirmed_title');
                        $('#jj-done-desc').textContent = T('team_invite_confirmed_desc');
                    } else if (data.needs_role_selection) {
                        showStep('role');
                    } else if ((data.roles || []).indexOf('jj_team_leader') !== -1) {
                        // کاربری که قبلاً ثبت‌نامش کامل شده و دوباره وارد شده — به صفحه‌ی
                        // حساب کاربری (که هم پروفایل و هم داشبورد نقش خودش را نشان می‌دهد)
                        // هدایت می‌شود (کوکی ورود وردپرس در otp/verify ست شده، پس منوی بالای
                        // سایت هم با نام/عکس کاربر بارگذاری می‌شود — نگاه کنید به inject_user_menu_label).
                        window.location.href = '/account/';
                    } else if ((data.roles || []).indexOf('jj_employer') !== -1 || (data.roles || []).indexOf('jj_agency') !== -1) {
                        window.location.href = '/account/';
                    } else if ((data.roles || []).indexOf('jj_employer_pending') !== -1) {
                        // کاربری که قبلاً نقش کارفرما رو انتخاب کرده ولی هنوز فرآیند ثبت‌نامش
                        // (پروفایل/مدارک/بررسی) تمام نشده — قبلاً این حالت هیچ گزینه‌ای نشون
                        // نمی‌داد (صفحه‌ی «خوش آمدید» خالی)، حالا دقیقاً از همون مرحله ادامه می‌ده
                        resumeEmployerAgencyFlow('employer', true);
                    } else if ((data.roles || []).indexOf('jj_agency_pending') !== -1) {
                        resumeEmployerAgencyFlow('agency', true);
                    } else if ((data.roles || []).indexOf('jj_jobseeker') !== -1 && (data.roles || []).indexOf('jj_team_leader') === -1) {
                        window.location.href = '/account/';
                    } else {
                        window.location.href = '/account/';
                    }
            }

            function verifyOtp() {
                var code = $('#jj-code').value.trim();
                setMsg('#jj-msg-otp', '', false);
                if (!/^\d{5}$/.test(code)) { setMsg('#jj-msg-otp', T('enter_5digit_code'), true); return; }
                $('#jj-btn-verify').disabled = true;
                api('otp/verify', { phone: state.phone, code: code }).then(function (res) {
                    $('#jj-btn-verify').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-otp', (res.data && res.data.message) || T('invalid_code'), true); return; }
                    clearInterval(state.timerHandle);
                    state.token = res.data.token; try { localStorage.setItem('jj_auth_token', res.data.token); document.cookie = 'jj_auth_token=' + res.data.token + ';path=/;max-age=2592000;SameSite=Lax;Secure'; } catch (e) {}
                    window.JJ_AUTH_TOKEN = state.token;
            checkReturnRedirect();
                    state.currentRoles = res.data.roles || [];
                    loadProfileSummary();
                    proceedAfterAuth(res.data);
                }).catch(function () { $('#jj-btn-verify').disabled = false; setMsg('#jj-msg-otp', T('error_network'), true); });
            }

            function passwordLogin() {
                var phone = buildFullPhone();
                var password = $('#jj-login-password') ? $('#jj-login-password').value : '';
                setMsg('#jj-msg-phone', '', false);
                if (!JJ_PHONE_RE.test(phone)) { setMsg('#jj-msg-phone', T('phone_invalid_msg'), true); return; }
                if (!password) { setMsg('#jj-msg-phone', T('enter_password'), true); return; }
                $('#jj-btn-request').disabled = true;
                api('auth/password-login', { phone: phone, password: password }).then(function (res) {
                    $('#jj-btn-request').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-phone', (res.data && res.data.message) || T('login_failed'), true); return; }
                    state.phone = phone;
                    state.token = res.data.token; try { localStorage.setItem('jj_auth_token', res.data.token); document.cookie = 'jj_auth_token=' + res.data.token + ';path=/;max-age=2592000;SameSite=Lax;Secure'; } catch (e) {}
                    window.JJ_AUTH_TOKEN = state.token;
            checkReturnRedirect();
                    state.currentRoles = res.data.roles || [];
                    loadProfileSummary();
                    proceedAfterAuth(res.data);
                }).catch(function () { $('#jj-btn-request').disabled = false; setMsg('#jj-msg-phone', T('error_network'), true); });
            }

            function setLoginMethod(method) {
                jjLoginMethod = method;
                var otpBtn = $('#jj-method-otp-btn'), pwBtn = $('#jj-method-password-btn');
                if (otpBtn) { otpBtn.classList.toggle('jj-method-active', method === 'otp'); otpBtn.style.background = (method === 'otp') ? '#14213d' : '#fff'; otpBtn.style.color = (method === 'otp') ? '#fff' : '#14213d'; }
                if (pwBtn) { pwBtn.classList.toggle('jj-method-active', method === 'password'); pwBtn.style.background = (method === 'password') ? '#14213d' : '#fff'; pwBtn.style.color = (method === 'password') ? '#fff' : '#14213d'; }
                var pwRow = $('#jj-password-row');
                if (pwRow) pwRow.style.display = (method === 'password') ? '' : 'none';
                var btn = $('#jj-btn-request');
                if (btn) btn.textContent = (method === 'password') ? T('login_with_password') : T('send_code');
                setMsg('#jj-msg-phone', '', false);
            }

            function resendOtp() {
                var btn = $('#jj-btn-resend');
                if (btn) { if (btn.disabled) return; btn.disabled = true; }
                api('otp/request', { phone: state.phone }).then(function (res) {
                    if (!res.ok) { if (btn) btn.disabled = false; setMsg('#jj-msg-otp', (res.data && res.data.message) || T('error_generic'), true); return; }
                    setMsg('#jj-msg-otp', T('new_code_sent'), false);
                    startTimer((res.data && res.data.ttl) || 120);
                }).catch(function () { if (btn) btn.disabled = false; setMsg('#jj-msg-otp', T('error_network'), true); });
            }

            // ---------- مرحله ۲: انتخاب نقش ----------
            var extraFieldMap = {
                team_leader: { label: T('acct_team_name'), param: 'team_name' }
            };
            var paymentTypeForRole = {
                jobseeker: 'jobseeker_registration',
                team_leader: 'team_leader_registration',
                employer: 'employer_registration',
                agency: 'agency_registration'
            };
            var doneMessages = {
                jobseeker: T('done_jobseeker_msg'),
                team_leader: T('done_team_leader_msg')
            };

            function selectRole(role) {
                document.querySelectorAll('.jj-role-card').forEach(function (el) {
                    el.classList.toggle('jj-selected', el.getAttribute('data-role') === role);
                });
                state.role = role;
                if (role === 'employer' || role === 'agency') {
                    // برای کارفرما/کاریابی، اول باید نقش pending ثبت شود (بدون نام)، سپس فرم کامل نمایش داده شود
                    submitRoleMinimal(role);
                    return;
                }
                var extra = $('#jj-role-extra');
                if (extraFieldMap[role]) {
                    extra.style.display = '';
                    $('#jj-role-extra-input').placeholder = extraFieldMap[role].label;
                    $('#jj-role-extra-input').value = '';
                } else {
                    extra.style.display = 'none';
                    submitRole({});
                }
            }

            function submitRoleMinimal(role) {
                setMsg('#jj-msg-role', '', false);
                var body = { role: role, first_name: ($('#jj-role-first-name') ? $('#jj-role-first-name').value.trim() : ''), last_name: ($('#jj-role-last-name') ? $('#jj-role-last-name').value.trim() : '') };
                if (role === 'employer') body.company_name = '—';
                if (role === 'agency') body.agency_name = '—';
                api('role/select', body, true).then(function (res) {
                    if (!res.ok) { setMsg('#jj-msg-role', (res.data && res.data.message) || T('error_generic'), true); return; }
                    loadLocationsThenShowProfile();
                }).catch(function () { setMsg('#jj-msg-role', T('error_network'), true); });
            }

            function submitRole(extra) {
                setMsg('#jj-msg-role', '', false);
                var body = Object.assign({ role: state.role, first_name: ($('#jj-role-first-name') ? $('#jj-role-first-name').value.trim() : ''), last_name: ($('#jj-role-last-name') ? $('#jj-role-last-name').value.trim() : '') }, extra || {});
                if (extraFieldMap[state.role]) {
                    var val = $('#jj-role-extra-input').value.trim();
                    if (!val) { setMsg('#jj-msg-role', extraFieldMap[state.role].label + ' ' + T('enter_value_suffix'), true); return; }
                    body[extraFieldMap[state.role].param] = val;
                }
                api('role/select', body, true).then(function (res) {
                    if (!res.ok) { setMsg('#jj-msg-role', (res.data && res.data.message) || T('error_generic'), true); return; }
                    // بسته‌های کارجو/سرپرست اکیپ دیگر از کاتالوگ قدیمی jj_packages
                    // خریده نمی‌شوند؛ کاربر به همان صفحه‌ی واقعی و فعال بسته‌های
                    // اشتراکی (subscription_plans) که تمدید/ارتقا هم از همان‌جا انجام
                    // می‌شود هدایت می‌شود (همان الگوی ریدایرکت کاریابی به /plans/agency/).
                    if (state.role === 'jobseeker') { window.location.href = '/plans/job-seeker/'; return; }
                    if (state.role === 'team_leader') { window.location.href = '/plans/team-leader/'; return; }
                    showPaymentStep(paymentTypeForRole[state.role]);
                }).catch(function () { setMsg('#jj-msg-role', T('error_network'), true); });
            }

            // ---------- بازگشت کارفرما/کاریابیِ «در انتظار» به مرحله‌ی درست ----------
            // قبل از این رفع‌باگ، ورود مجدد با شماره‌ای که نقش jj_employer_pending یا
            // jj_agency_pending داشت (یعنی مراحل پروفایل/مدارک/بررسی هنوز کامل نشده) به
            // هیچ‌کدوم از شرط‌های verifyOtp نمی‌خورد و کاربر یک صفحه‌ی «خوش آمدید» خالی و
            // بدون هیچ گزینه‌ای می‌دید. اینجا دقیقاً بر اساس documents_status واقعی، کاربر
            // رو به همون مرحله‌ای که رهاش کرده بود برمی‌گردونیم.
            function resumeEmployerAgencyFlow(role, allowPaymentRedirect) {
                state.role = role;
                var actionsBox = $('#jj-done-actions');
                actionsBox.style.display = 'none'; actionsBox.innerHTML = '';
                fetch(REST + 'profile/summary', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) {
                        if (r.status === 401) { handleUnauthorized(); return null; }
                        return r.json();
                    })
                    .then(function (res) {
                        if (!res) return;
                        var status = (res && res.summary && res.summary.documents_status) || 'pending';
                        if (status === 'pending_review') {
                            showStep('done');
                            $('#jj-done-title').textContent = T('request_pending_review_title');
                            $('#jj-done-desc').textContent = T('request_pending_review_desc');
                            actionsBox.innerHTML = '<button class="jj-btn jj-btn-secondary" id="jj-btn-done-recheck">' + T('recheck_status_btn') + '</button>';
                            actionsBox.style.display = '';
                            $('#jj-btn-done-recheck').addEventListener('click', function () { resumeEmployerAgencyFlow(role, true); });
                        } else if (status === 'rejected') {
                            showStep('done');
                            $('#jj-done-title').textContent = T('docs_rejected_title');
                            $('#jj-done-desc').textContent = T('docs_rejected_desc');
                            if (res.summary && res.summary.rejection_reason) { $('#jj-done-desc').textContent += ' ' + T('rejection_reason_prefix') + ' ' + res.summary.rejection_reason; }
                            actionsBox.innerHTML = '<button class="jj-btn jj-btn-secondary" id="jj-btn-done-help">' + T('help_and_support_btn') + '</button>';
                            actionsBox.style.display = '';
                            $('#jj-btn-done-help').addEventListener('click', function () { $('#jj-help-fab').click(); });
                        } else if (status === 'approved') {
                            // به‌روزرسانی طبق تصمیم مدیرکل: کارفرما بعد از تأیید مدارک بلافاصله و
                            // کاملاً رایگان فعال می‌شود؛ کاریابی باید مستقیماً به مرحله‌ی خرید
                            // بسته‌ی اشتراکی برود (نه یک «هزینه‌ی ثبت‌نام» جداگانه).
                            if (role === 'agency') {
                                showStep('done');
                                $('#jj-done-title').textContent = 'مدارک شما تأیید شد';
                                $('#jj-done-desc').textContent = 'حساب کاریابی شما فعال شد. اکنون به صفحه‌ی بسته‌های اشتراکی منتقل می‌شوید...';
                                setTimeout(function () { window.location.href = '/plans/agency/'; }, 1200);
                            } else {
                                showStep('done');
                                $('#jj-done-title').textContent = 'مدارک شما تأیید شد';
                                $('#jj-done-desc').textContent = 'حساب کارفرمایی شما کاملاً و رایگان فعال شد. اکنون می‌توانید آگهی ثبت کنید.';
                            }
                        } else if (status === 'needs_revision') {
                            // مدیرکل درخواست اصلاح کرده — کاربر را با نمایش توضیح اصلاح به فرم پروفایل برمی‌گردانیم.
                            var note = (res.summary && res.summary.revision_note) || T('revision_needed_default');
                            alert(T('docs_revision_needed_prefix') + '\n\n' + note);
                            loadLocationsThenShowProfile();
                        } else if (status === 'pending_documents') {
                            showStep('documents');
                            loadMyDocumentsIntoList();
                        } else {
                            // 'pending' یا وضعیت نامشخص: یعنی فرم پروفایل هنوز تکمیل نشده — از همون‌جا ادامه بده
                            loadLocationsThenShowProfile();
						}
                    })
                    .catch(function () {
                        // حتی اگه گرفتن وضعیت شکست خورد، کاربر رو توی صفحه‌ی خالی رها نکن — امن‌ترین نقطه‌ی شروع فرم پروفایله
                        loadLocationsThenShowProfile();
                    });
            }

            /** لیست مدارک قبلاً آپلودشده رو (برای ادامه‌ی آپلود بعد از خروج/ورود دوباره) توی مرحله‌ی مدارک نشون می‌ده. */
            function loadMyDocumentsIntoList() {
                fetch(REST + 'documents/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        var docs = (res && res.documents) || [];
                        var list = $('#jj-doc-list');
                        list.innerHTML = '';
                        uploadedCount = 0;
                        docs.forEach(function (d) {
                            if (d.status === 'rejected') return;
                            var li = document.createElement('li');
                            li.textContent = d.file_name;
                            list.appendChild(li);
                            uploadedCount++;
                        });
                        $('#jj-btn-add-doc').disabled = uploadedCount >= 5;
                        $('#jj-btn-docs-continue').disabled = uploadedCount === 0;
                    })
                    .catch(function () {});
            }

            // ---------- انتخاب بسته‌ی اشتراک (کارجو/سرپرست اکیپ) ----------
            var packageReturnStep = null;
            function showPackageStep(returnStep) {
                packageReturnStep = returnStep || null;
                $('#jj-package-back-row').style.display = returnStep ? '' : 'none';
                showStep('package-select');
                var list = $('#jj-packages-list');
                list.innerHTML = T('loading');
                fetch(REST + 'packages?context=' + encodeURIComponent(state.role || 'jobseeker')).then(function (r) { return r.json(); }).then(function (res) {
                    if (!res.packages || !res.packages.length) { list.innerHTML = '<p>' + T('no_packages_defined') + '</p>'; return; }
                    list.innerHTML = '';
                    res.packages.forEach(function (p) {
                        var card = document.createElement('div');
                        card.className = 'jj-role-card';
                        card.style.marginBottom = '10px';
                        card.innerHTML = '<span class="jj-role-title">' + p.name + '</span><span class="jj-role-desc">' + p.duration_days + ' ' + T('day_label') + ' — ' + Number(p.price_toman).toLocaleString('fa-IR') + ' ' + T('toman') + '</span>';
                        card.addEventListener('click', function () {
                            setMsg('#jj-msg-package', '', false);
                            document.querySelectorAll('#jj-packages-list .jj-role-card').forEach(function (c) { c.style.pointerEvents = 'none'; c.style.opacity = '.6'; });
                            api('payment/request', { payment_type: paymentTypeForRole[state.role], package_id: p.id }, true).then(function (res2) {
                                if (!res2.ok) {
                                    setMsg('#jj-msg-package', (res2.data && res2.data.message) || T('gateway_connection_error'), true);
                                    document.querySelectorAll('#jj-packages-list .jj-role-card').forEach(function (c) { c.style.pointerEvents = ''; c.style.opacity = ''; });
                                    return;
                                }
                                window.location.href = res2.data.payment_url;
                            });
                        });
                        list.appendChild(card);
                    });
                }).catch(function () { list.innerHTML = '<p>' + T('error_network') + '</p>'; });
            }
            $('#jj-btn-package-back').addEventListener('click', function () {
                if (packageReturnStep === 'team') showStep('team');
                else showStep('browse-jobs');
            });
            $('#jj-btn-renew-js').addEventListener('click', function () { window.location.href = '/plans/job-seeker/'; });
            $('#jj-btn-renew-team').addEventListener('click', function () { window.location.href = '/plans/team-leader/'; });

            // ---------- مرحله ۳: فرم پروفایل کامل ----------
            function loadLocationsThenShowProfile() {
                if (state.locations) { renderProfile(); return; }
                fetch(REST + 'locations').then(function (r) { return r.json(); }).then(function (data) {
                    state.locations = data; renderProfile();
                }).catch(function () { state.locations = { countries: ['ایران', 'سایر'], iran_provinces: {} }; renderProfile(); });
            }

            function countryOptions() {
                return state.locations.countries.map(function (c) { return '<option value="' + c + '">' + c + '</option>'; }).join('');
            }
            function provinceOptions() {
                return Object.keys(state.locations.iran_provinces).map(function (p) { return '<option value="' + p + '">' + p + '</option>'; }).join('');
            }
            // ---------- تاریخ تولد (تقویم هجری شمسی، به‌صورت انتخابی روز/ماه/سال) ----------
            var JJ_JALALI_MONTHS = [T('jalali_m1'), T('jalali_m2'), T('jalali_m3'), T('jalali_m4'), T('jalali_m5'), T('jalali_m6'), T('jalali_m7'), T('jalali_m8'), T('jalali_m9'), T('jalali_m10'), T('jalali_m11'), T('jalali_m12')];
            function jjGregorianToJalali(gy, gm, gd) {
                var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
                var jy = (gy <= 1600) ? 0 : 979;
                gy -= (gy <= 1600) ? 621 : 1600;
                var gy2 = (gm > 2) ? (gy + 1) : gy;
                var days = (365 * gy) + parseInt((gy2 + 3) / 4) - parseInt((gy2 + 99) / 100) + parseInt((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
                jy += 33 * parseInt(days / 12053);
                days %= 12053;
                jy += 4 * parseInt(days / 1461);
                days %= 1461;
                if (days > 365) { jy += parseInt((days - 1) / 365); days = (days - 1) % 365; }
                return jy;
            }
            function jjIsLeapJalaliYear(jy) {
                var r = ((jy % 33) + 33) % 33;
                return [1, 5, 9, 13, 17, 22, 26, 30].indexOf(r) !== -1;
            }
            function jjJalaliDaysInMonth(jy, jm) {
                if (jm <= 6) return 31;
                if (jm <= 11) return 30;
                return jjIsLeapJalaliYear(jy) ? 30 : 29;
            }
            function birthDayOptionsHTML(count) {
                var out = '';
                for (var d = 1; d <= count; d++) out += '<option value="' + d + '">' + d + '</option>';
                return out;
            }
            function birthMonthOptionsHTML() {
                return JJ_JALALI_MONTHS.map(function (m, i) { return '<option value="' + (i + 1) + '">' + m + '</option>'; }).join('');
            }
            function birthYearOptionsHTML() {
                var now = new Date();
                var curJy = jjGregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
                var out = '';
                for (var y = curJy - 14; y >= curJy - 90; y--) out += '<option value="' + y + '">' + y + '</option>';
                return out;
            }
            function wireBirthDateSelects(root) {
                root.querySelectorAll('.jj-birth-date-row').forEach(function (row) {
                    var daySel = row.querySelector('.jj-birth-day');
                    var monthSel = row.querySelector('.jj-birth-month');
                    var yearSel = row.querySelector('.jj-birth-year');
                    var hidden = row.parentElement.querySelector('input[type="hidden"][data-field]');
                    function sync() {
                        var y = parseInt(yearSel.value, 10), m = parseInt(monthSel.value, 10);
                        if (y && m) {
                            var maxDay = jjJalaliDaysInMonth(y, m);
                            var curDay = parseInt(daySel.value, 10) || 0;
                            if (daySel.dataset.max != maxDay) {
                                daySel.innerHTML = '<option value="">' + T('day_label') + '</option>' + birthDayOptionsHTML(maxDay);
                                daySel.dataset.max = maxDay;
                                if (curDay && curDay <= maxDay) daySel.value = curDay;
                            }
                        }
                        var dv = daySel.value, mv = monthSel.value, yv = yearSel.value;
                        hidden.value = (dv && mv && yv) ? (yv + '/' + (mv.length < 2 ? '0' + mv : mv) + '/' + (dv.length < 2 ? '0' + dv : dv)) : '';
                    }
                    daySel.addEventListener('change', sync);
                    monthSel.addEventListener('change', sync);
                    yearSel.addEventListener('change', sync);
                });
            }
            function jjFutureYearOptionsHTML() {
                var now = new Date();
                var curJy = jjGregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
                var out = '';
                for (var y = curJy; y <= curJy + 2; y++) out += '<option value="' + y + '">' + y + '</option>';
                return out;
            }
            function jjDateFieldRowHTML(id) {
                return '<div class="jj-field-row jj-jalali-date-row">' +
                    '<select class="jj-input jj-jalali-day" data-jalali-part="d"><option value="">' + T('day_label') + '</option>' + birthDayOptionsHTML(31) + '</select>' +
                    '<select class="jj-input jj-jalali-month" data-jalali-part="m"><option value="">' + T('month_label') + '</option>' + birthMonthOptionsHTML() + '</select>' +
                    '<select class="jj-input jj-jalali-year" data-jalali-part="y"><option value="">' + T('year_label') + '</option>' + jjFutureYearOptionsHTML() + '</select>' +
                    '</div>' +
                    '<input type="hidden" id="' + id + '">';
            }
            function wireJalaliDateSelects(root) {
                root.querySelectorAll('.jj-jalali-date-row').forEach(function (row) {
                    var daySel = row.querySelector('.jj-jalali-day');
                    var monthSel = row.querySelector('.jj-jalali-month');
                    var yearSel = row.querySelector('.jj-jalali-year');
                    var hidden = row.parentElement.querySelector('input[type="hidden"]');
                    function sync() {
                        var y = parseInt(yearSel.value, 10), m = parseInt(monthSel.value, 10);
                        if (y && m) {
                            var maxDay = jjJalaliDaysInMonth(y, m);
                            var curDay = parseInt(daySel.value, 10) || 0;
                            if (daySel.dataset.max != maxDay) {
                                daySel.innerHTML = '<option value="">' + T('day_label') + '</option>' + birthDayOptionsHTML(maxDay);
                                daySel.dataset.max = maxDay;
                                if (curDay > maxDay) curDay = 0;
                                daySel.value = curDay;
                            }
                        }
                        var dv = daySel.value, mv = monthSel.value, yv = yearSel.value;
                        hidden.value = (dv && mv && yv) ? (yv + '/' + (mv.length < 2 ? '0' + mv : mv) + '/' + (dv.length < 2 ? '0' + dv : dv)) : '';
                    }
                    daySel.addEventListener('change', sync);
                    monthSel.addEventListener('change', sync);
                    yearSel.addEventListener('change', sync);
                });
            }

            function personFieldsHTML(prefix, title) {
                return '' +
                    '<fieldset class="jj-fieldset"><legend>' + title + '</legend>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('first_name') + '</span><input class="jj-input" data-field="' + prefix + '.first_name" required></div>' +
                    '<div><span class="jj-label">' + T('last_name') + '</span><input class="jj-input" data-field="' + prefix + '.last_name" required></div>' +
                    '</div>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('father_name') + '</span><input class="jj-input" data-field="' + prefix + '.father_name"></div>' +
                    '<div><span class="jj-label">' + T('birth_date') + '</span>' +
                    '<div class="jj-field-row jj-birth-date-row" data-birth-prefix="' + prefix + '">' +
                    '<select class="jj-input jj-birth-day" data-birth-part="d"><option value="">' + T('day_placeholder') + '</option>' + birthDayOptionsHTML(31) + '</select>' +
                    '<select class="jj-input jj-birth-month" data-birth-part="m"><option value="">' + T('month_placeholder') + '</option>' + birthMonthOptionsHTML() + '</select>' +
                    '<select class="jj-input jj-birth-year" data-birth-part="y"><option value="">' + T('year_placeholder') + '</option>' + birthYearOptionsHTML() + '</select>' +
                    '</div>' +
                    '<input type="hidden" data-field="' + prefix + '.birth_date">' +
                    '</div>' +
                    '</div>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('nationality') + '</span><select class="jj-input" data-field="' + prefix + '.nationality">' +
                    '<option value="iranian">' + T('nationality_iranian') + '</option><option value="other">' + T('nationality_other') + '</option></select></div>' +
                    '<div><span class="jj-label">' + T('national_id') + '</span><input class="jj-input" data-field="' + prefix + '.national_id"></div>' +
                    '</div></fieldset>';
            }

            function addressFieldsHTML(prefix, title) {
                return '' +
                    '<fieldset class="jj-fieldset"><legend>' + title + '</legend>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('country') + '</span><select class="jj-input jj-country-select" data-address-prefix="' + prefix + '" data-field="' + prefix + '.country">' + countryOptions() + '</select></div>' +
                    '<div class="jj-province-wrap" data-for="' + prefix + '" style="display:none"><span class="jj-label">' + T('province') + '</span><select class="jj-input jj-province-select" data-address-prefix="' + prefix + '" data-field="' + prefix + '.province">' + provinceOptions() + '</select></div>' +
                    '</div>' +
                    '<div class="jj-field-row">' +
                    '<div class="jj-city-select-wrap" data-for="' + prefix + '" style="display:none"><span class="jj-label">' + T('city') + '</span><select class="jj-input jj-city-select" data-address-prefix="' + prefix + '" data-field="' + prefix + '.city"></select></div>' +
                    '<div class="jj-city-text-wrap" data-for="' + prefix + '"><span class="jj-label">' + T('city') + '</span><input class="jj-input jj-city-text" data-address-prefix="' + prefix + '" data-field="' + prefix + '.city"></div>' +
                    '</div>' +
                    '<span class="jj-label">' + T('address') + '</span><textarea class="jj-input" rows="2" data-field="' + prefix + '.address_text"></textarea>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('plaque') + '</span><input class="jj-input" data-field="' + prefix + '.plaque"></div>' +
                    '<div><span class="jj-label">' + T('unit') + '</span><input class="jj-input" data-field="' + prefix + '.unit"></div>' +
                    '</div>' +
                    '<span class="jj-label">' + T('postal_code') + '</span><input class="jj-input" data-field="' + prefix + '.postal_code">' +
                    '</fieldset>';
            }

            function messengerFieldsHTML(prefix) {
                return '' +
                    '<fieldset class="jj-fieldset"><legend>' + T('messenger_ids') + '</legend>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('whatsapp') + '</span><input class="jj-input" data-field="' + prefix + '.whatsapp"></div>' +
                    '<div><span class="jj-label">' + T('telegram') + '</span><input class="jj-input" data-field="' + prefix + '.telegram"></div>' +
                    '</div>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('instagram') + '</span><input class="jj-input" data-field="' + prefix + '.instagram"></div>' +
                    '<div><span class="jj-label">' + T('skype') + '</span><input class="jj-input" data-field="' + prefix + '.skype"></div>' +
                    '</div>' +
                    '<span class="jj-label">' + T('google_meet') + '</span><input class="jj-input" data-field="' + prefix + '.google_meet">' +
                    '</fieldset>';
            }

            function wireAddressCascades(root) {
                root.querySelectorAll('.jj-country-select').forEach(function (sel) {
                    sel.addEventListener('change', function () { onCountryChange(root, sel); });
                });
            }
            function onCountryChange(root, sel) {
                var prefix = sel.getAttribute('data-address-prefix');
                var isIran = (sel.value === 'ایران');
                var provinceWrap = root.querySelector('.jj-province-wrap[data-for="' + prefix + '"]');
                var citySelectWrap = root.querySelector('.jj-city-select-wrap[data-for="' + prefix + '"]');
                var cityTextWrap = root.querySelector('.jj-city-text-wrap[data-for="' + prefix + '"]');
                provinceWrap.style.display = isIran ? '' : 'none';
                citySelectWrap.style.display = isIran ? '' : 'none';
                cityTextWrap.style.display = isIran ? 'none' : '';
                if (isIran) {
                    var provinceSelect = provinceWrap.querySelector('select');
                    provinceSelect.onchange = function () { fillCities(root, prefix, provinceSelect.value); };
                    fillCities(root, prefix, provinceSelect.value);
                }
            }
            function fillCities(root, prefix, province) {
                var citySelect = root.querySelector('.jj-city-select-wrap[data-for="' + prefix + '"] select');
                var cities = state.locations.iran_provinces[province] || [];
                citySelect.innerHTML = cities.map(function (c) { return '<option value="' + c + '">' + c + '</option>'; }).join('') + '<option value="">' + T('other_city_option') + '</option>';
            }

            function employerProfileHTML() {
                return '' +
                    '<div class="jj-verify-warning" style="background:#fffbeb;border:1px solid #fde68a;color:#b45309;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;line-height:1.9;">' + T('profile_verify_warning') + '</div>' +
                    '<div class="jj-radio-row">' +
                    '<label><input type="radio" name="jj-applicant-type" value="company" checked> ' + T('company') + '</label>' +
                    '<label><input type="radio" name="jj-applicant-type" value="contractor"> ' + T('contractor') + '</label>' +
                    '</div>' +
                    '<div id="jj-emp-company">' +
                    '<fieldset class="jj-fieldset"><legend>' + T('company_details') + '</legend>' +
                    '<span class="jj-label">' + T('company_full_name') + '</span><input class="jj-input" data-field="company_name" required>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('company_reg_number') + '</span><input class="jj-input" data-field="company_reg_number"></div>' +
                    '<div><span class="jj-label">' + T('business_field') + '</span><input class="jj-input" data-field="business_field"></div>' +
                    '</div>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('reg_country') + '</span><input class="jj-input" data-field="company_reg_country"></div>' +
                    '<div><span class="jj-label">' + T('reg_province') + '</span><input class="jj-input" data-field="company_reg_province"></div>' +
                    '</div></fieldset>' +
                    personFieldsHTML('ceo', T('ceo_details')) +
                    '<span class="jj-label">' + T('ceo_mobile') + '</span><input class="jj-input" data-field="ceo_mobile">' +
                    personFieldsHTML('hr_officer', T('hr_officer_details')) +
                    '<span class="jj-label">' + T('office_phone') + '</span><input class="jj-input" data-field="hr_officer_phone">' +
                    addressFieldsHTML('office_address', T('office_address')) +
                    messengerFieldsHTML('messengers') +
                    '</div>' +
                    '<div id="jj-emp-contractor" style="display:none">' +
                    personFieldsHTML('contractor', T('contractor_details')) +
                    '<span class="jj-label">' + T('office_phone') + '</span><input class="jj-input" data-field="contractor_office_phone">' +
                    addressFieldsHTML('residence_address', T('residence_address')) +
                    '</div>' +
                    '<span class="jj-label">' + T('additional_notes') + '</span>' +
                    '<textarea class="jj-input" rows="3" maxlength="1000" data-field="notes"></textarea>' +
                    '<label class="jj-check-chip" style="width:100%;box-sizing:border-box;margin-bottom:14px;justify-content:flex-start;"><input type="checkbox" id="jj-profile-terms"> ' + T('agree_terms_checkbox') + '</label>' +
                    '<button class="jj-btn" id="jj-btn-profile-submit">' + T('submit_and_continue') + '</button>';
            }

            function agencyProfileHTML() {
                return '' +
                    '<div class="jj-verify-warning" style="background:#fffbeb;border:1px solid #fde68a;color:#b45309;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;line-height:1.9;">' + T('profile_verify_warning') + '</div>' +
                    '<span class="jj-label">' + T('agency_full_name') + '</span><input class="jj-input" data-field="agency_name" required>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('license_type') + '</span><select class="jj-input" data-field="license_type">' +
                    '<option value="domestic">' + T('license_domestic') + '</option><option value="foreign">' + T('license_foreign') + '</option><option value="none">' + T('license_none') + '</option></select></div>' +
                    '<div><span class="jj-label">' + T('cooperation_type') + '</span><select class="jj-input" data-field="cooperation_type">' +
                    '<option value="exchange">' + T('cooperation_exchange') + '</option><option value="post_only">' + T('cooperation_post_only') + '</option></select></div>' +
                    '</div>' +
                    personFieldsHTML('owner', T('agency_owner_details')) +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('business_field') + '</span><input class="jj-input" data-field="business_field"></div>' +
                    '<div><span class="jj-label">' + T('staff_count') + '</span><input class="jj-input" data-field="staff_count"></div>' +
                    '</div>' +
                    '<fieldset class="jj-fieldset"><legend>' + T('target_countries') + '</legend>' +
                    '<select class="jj-input" data-field="target_country_1">' + countryOptions() + '</select>' +
                    '<select class="jj-input" data-field="target_country_2">' + countryOptions() + '</select>' +
                    '<select class="jj-input" data-field="target_country_3">' + countryOptions() + '</select>' +
                    '</fieldset>' +
                    '<div class="jj-field-row">' +
                    '<div><span class="jj-label">' + T('owner_mobile') + '</span><input class="jj-input" data-field="owner_mobile"></div>' +
                    '<div><span class="jj-label">' + T('office_phone') + '</span><input class="jj-input" data-field="office_phone"></div>' +
                    '</div>' +
                    addressFieldsHTML('office_address', T('office_address')) +
                    messengerFieldsHTML('messengers') +
                    '<span class="jj-label">' + T('additional_notes') + '</span>' +
                    '<textarea class="jj-input" rows="3" maxlength="1000" data-field="notes"></textarea>' +
                    '<label class="jj-check-chip" style="width:100%;box-sizing:border-box;margin-bottom:14px;justify-content:flex-start;"><input type="checkbox" id="jj-profile-terms"> ' + T('agree_terms_checkbox') + '</label>' +
                    '<button class="jj-btn" id="jj-btn-profile-submit">' + T('submit_and_continue') + '</button>';
            }

            function fillProfileFields(root, data) {
        if (!data) return;
        var info = (data.info && typeof data.info === 'object') ? data.info : {};
        var lookup = {};
        for (var k in info) { lookup[k] = info[k]; }
        lookup.company_name = data.company_name || lookup.company_name;
				        lookup.agency_name = data.agency_name || lookup.agency_name;
        if (!lookup.ceo || typeof lookup.ceo !== 'object') { lookup.ceo = {}; }
        if (!lookup.ceo.first_name) { lookup.ceo.first_name = data.first_name || ''; }
        if (!lookup.ceo.last_name) { lookup.ceo.last_name = data.last_name || ''; }
        root.querySelectorAll('[data-field]').forEach(function (el) {
          var path = el.getAttribute('data-field').split('.');
          var cur = lookup;
          for (var i = 0; i < path.length; i++) {
            if (cur === null || cur === undefined) { cur = undefined; break; }
            cur = cur[path[i]];
          }
          if (cur !== undefined && cur !== null && cur !== '' && !el.value) { el.value = cur; }
        });
      }
      function renderProfile() {
                var container = $('#jj-profile-fields');
                if (state.role === 'employer') {
                    $('#jj-profile-title').textContent = T('employer_info_title');
                    container.innerHTML = employerProfileHTML();
                    var radios = document.getElementsByName('jj-applicant-type');
                    radios.forEach(function (r) {
                        r.addEventListener('change', function () {
                            $('#jj-emp-company').style.display = (r.value === 'company' && r.checked) ? '' : (document.querySelector('input[name=jj-applicant-type]:checked').value === 'company' ? '' : 'none');
                            $('#jj-emp-contractor').style.display = document.querySelector('input[name=jj-applicant-type]:checked').value === 'contractor' ? '' : 'none';
                            if (document.querySelector('input[name=jj-applicant-type]:checked').value === 'company') { $('#jj-emp-company').style.display=''; $('#jj-emp-contractor').style.display='none'; }
							                if (window.__jjSyncDocRows) { window.__jjSyncDocRows(); }
                        });
                    });
                } else {
                    $('#jj-profile-title').textContent = T('agency_info_title');
                    container.innerHTML = agencyProfileHTML();
					                var __jjLicSel = container.querySelector('[data-field="license_type"]');
										                  if (__jjLicSel) { __jjLicSel.addEventListener('change', function () { if (window.__jjSyncDocRows) { window.__jjSyncDocRows(); } }); }
                }
                wireAddressCascades(container);
                wireBirthDateSelects(container);
                $('#jj-btn-profile-submit').addEventListener('click', submitProfile);
                var jjTermsBox = $('#jj-profile-terms');
                var jjSubmitBtn = $('#jj-btn-profile-submit');
                if (jjTermsBox && jjSubmitBtn) {
                    jjSubmitBtn.disabled = true;
                    jjTermsBox.addEventListener('change', function () { jjSubmitBtn.disabled = !jjTermsBox.checked; });
                }
                fetch(REST + 'profile/summary').then(function (r) { return r.json(); }).then(function (d) {
                fillProfileFields(container, d && d.summary);
					                        if (state.role === 'employer' && d && d.summary && d.summary.info && d.summary.info.applicant_type) {
										                              var __at = d.summary.info.applicant_type;
										                              var __atRadio = container.querySelector('input[name="jj-applicant-type"][value="' + __at + '"]');
										                              if (__atRadio) {
										                                  __atRadio.checked = true;
										                                  __atRadio.dispatchEvent(new Event('change'));
										                              }
										                          }
              }).catch(function () {});
              if (window.__jjSyncDocRows) { window.__jjSyncDocRows(); }
										              showStep('profile');
            }

            function collectFields(root) {
                var obj = {};
                root.querySelectorAll('[data-field]').forEach(function (el) {
                    var path = el.getAttribute('data-field').split('.');
                    var val = el.value;
                    var cur = obj;
                    for (var i = 0; i < path.length - 1; i++) { cur[path[i]] = cur[path[i]] || {}; cur = cur[path[i]]; }
                    cur[path[path.length - 1]] = val;
                });
                return obj;
            }

            function checkRequiredFields(root) {
                var missing = false;
                root.querySelectorAll('[data-field][required]').forEach(function (el) {
                    if (el.offsetParent === null) return;
                    var empty = !el.value || !el.value.trim();
                    el.style.borderColor = empty ? '#dc2626' : '';
                    if (empty) missing = true;
                });
                return !missing;
            }
            function submitProfile() {
                setMsg('#jj-msg-profile', '', false);
                var root = $('#jj-profile-fields');
                if (!checkRequiredFields(root)) { setMsg('#jj-msg-profile', T('required_fields_marked_msg'), true); return; }
                var body = collectFields(root);
                var endpoint;
                if (state.role === 'employer') {
                    var checked = document.querySelector('input[name=jj-applicant-type]:checked');
                    body.applicant_type = checked ? checked.value : 'company';
                    endpoint = 'profile/employer';
                } else {
                    endpoint = 'profile/agency';
                }
                $('#jj-btn-profile-submit').disabled = true;
                api(endpoint, body, true).then(function (res) {
                    $('#jj-btn-profile-submit').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-profile', (res.data && res.data.message) || T('required_fields_marked_msg'), true); return; }
                    if (state.editProfileMode) { setMsg('#jj-msg-profile', T('info_saved_ok_you'), false); return; }
                showStep('documents');
                }).catch(function () { $('#jj-btn-profile-submit').disabled = false; setMsg('#jj-msg-profile', T('error_network'), true); });
            }

            // ---------- مرحله ۴: آپلود مدارک ----------
            var uploadedCount = 0;
            $('#jj-btn-add-doc').addEventListener('click', function () { $('#jj-doc-input').click(); });
            $('#jj-doc-input').addEventListener('change', function () {
                var file = this.files[0];
                if (!file) return;
                if (file.size > 2 * 1024 * 1024) { setMsg('#jj-msg-docs', T('file_size_2mb_limit'), true); return; }
                var fd = new FormData();
                fd.append('file', file);
                fd.append('doc_type', (window.__jjCurrentDocType || 'general'));
                fd.append('owner_type', state.role);
                setMsg('#jj-msg-docs', T('loading'), false);
                apiUpload('documents/upload', fd).then(function (res) {
                    if (!res.ok) { setMsg('#jj-msg-docs', (res.data && res.data.message) || T('upload_error_generic'), true); return; }
                    setMsg('#jj-msg-docs', '', false);
                    var li = document.createElement('li');
                    li.textContent = '📄 ' + res.data.file_name;
                    $('#jj-doc-list').appendChild(li);
                    uploadedCount++; if (window.__jjCurrentDocType) { window.__jjDocUploaded = window.__jjDocUploaded || {}; window.__jjDocUploaded[window.__jjCurrentDocType] = true; var __jjRow = document.getElementById('jj-doc-row-' + window.__jjCurrentDocType); if (__jjRow) { __jjRow.classList.add('jj-doc-done'); } }
                    $('#jj-btn-docs-continue').disabled = false;
                    if (uploadedCount >= 5) $('#jj-btn-add-doc').disabled = true;
                    $('#jj-doc-input').value = '';
                }).catch(function () { setMsg('#jj-msg-docs', T('error_network'), true); });
            });
                        var __jjDocTypes = ['national_id','birth_certificate','passport','last_entry_page','visa','work_contract','agency_license','personal_photo','company_logo'];
            __jjDocTypes.forEach(function (t) {
                var __b = document.getElementById('jj-doc-choose-' + t);
                if (__b) {
                    __b.addEventListener('click', function () {
                        window.__jjCurrentDocType = t;
                        document.getElementById('jj-doc-input').click();
                    });
                }
            });
            function __jjSyncDocRows() {
                var __outEl = document.getElementById('jj-doc-outside-iran');
                var __out = __outEl ? __outEl.checked : false;
                ['passport','last_entry_page','visa'].forEach(function (t) {
                    var __r = document.getElementById('jj-doc-row-' + t);
                    if (__r) { __r.style.display = __out ? 'flex' : 'none'; }
                });
                var __atEl = document.querySelector('input[name="jj-applicant-type"]:checked');
                var __at = __atEl ? __atEl.value : null;
                var __licEl = document.querySelector('[data-field="license_type"]');
                var __lic = __licEl ? __licEl.value : null;
                var __show = { work_contract: false, agency_license: false, company_logo: false };
                if (state.role === 'employer') {
                    if (__at === 'contractor') { __show.work_contract = true; } else { __show.company_logo = true; }
                } else if (state.role === 'agency') {
                    __show.company_logo = true;
                    if (__lic && __lic !== 'none') { __show.agency_license = true; }
                }
                Object.keys(__show).forEach(function (t) {
                    var __r2 = document.getElementById('jj-doc-row-' + t);
                    if (__r2) { __r2.style.display = __show[t] ? 'flex' : 'none'; }
                });
            }
            window.__jjSyncDocRows = __jjSyncDocRows;
            var __jjOutsideToggle = document.getElementById('jj-doc-outside-iran');
            if (__jjOutsideToggle) {
                __jjOutsideToggle.addEventListener('change', __jjSyncDocRows);
            }
$('#jj-btn-docs-continue').addEventListener('click', function () {
                                    var __jjOutside = document.getElementById('jj-doc-outside-iran') && document.getElementById('jj-doc-outside-iran').checked;
                    var __jjRequired = ['national_id','birth_certificate','personal_photo','company_logo'];
                    if (__jjOutside) { __jjRequired = __jjRequired.concat(['passport','last_entry_page','visa']); if (state.role === 'employer') { __jjRequired.push('work_contract'); } else { __jjRequired.push('agency_license'); } }
                    var __jjMissing = __jjRequired.filter(function (k) { return !(window.__jjDocUploaded && window.__jjDocUploaded[k]); });
                    if (__jjMissing.length > 0) {
                        var __jjOk = window.confirm(T('doc_missing_confirm'));
                        if (!__jjOk) { return; }
                    }
if (state.role === 'employer' || state.role === 'agency') {
                    $('#jj-dp-title').textContent = T('docs_received_title');
                    $('#jj-dp-desc').textContent = T('docs_under_review_desc');
                    showStep('docs-pending');
                    return;
                }
                showPaymentStep(paymentTypeForRole[state.role]);
            });

            // ---------- مرحله ۵: پرداخت ----------
            function showPaymentStep(paymentType) {
                state.currentPaymentType = paymentType;
                var sub = document.querySelector('[data-step="payment"] .jj-sub');
                sub.textContent = T('receiving_amount');
                fetch(REST + 'fees').then(function (r) { return r.json(); }).then(function (fees) {
                    var toman = fees[paymentType];
                    sub.textContent = toman ? (T('amount_due_prefix') + ' ' + Number(toman).toLocaleString('fa-IR') + ' ' + T('toman')) : T('registration_fee_notice');
                }).catch(function () { sub.textContent = T('registration_fee_notice'); });
                showStep('payment');
            }
            $('#jj-btn-pay').addEventListener('click', function () {
                setMsg('#jj-msg-payment', '', false);
                $('#jj-btn-pay').disabled = true;
                api('payment/request', { payment_type: state.currentPaymentType }, true).then(function (res) {
                    if (!res.ok) { $('#jj-btn-pay').disabled = false; setMsg('#jj-msg-payment', (res.data && res.data.message) || T('payment_gateway_connect_error'), true); return; }
                    window.location.href = res.data.payment_url;
                }).catch(function () { $('#jj-btn-pay').disabled = false; setMsg('#jj-msg-payment', T('error_network'), true); });
            });

            // ---------- داشبورد اکیپ ----------
            function statusLabel(s) {
                var map = { pending_otp: T('member_pending_otp'), confirmed: T('member_confirmed') };
                return map[s] || s;
            }
            function renderTeamMembers(members) {
                var list = $('#jj-team-members');
                if (!members.length) { list.innerHTML = '<li>' + T('no_members_added_yet') + '</li>'; return; }
                list.innerHTML = '';
                members.forEach(function (m) {
                    var li = document.createElement('li');
                    var span = document.createElement('span');
                    span.textContent = m.phone + ' — ' + statusLabel(m.status);
                    var btn = document.createElement('button');
                    btn.textContent = T('delete_btn');
                    btn.className = 'jj-btn-link';
                    btn.style.color = '#dc2626';
                    btn.addEventListener('click', function () {
                        if (!confirm(T('confirm_remove_member'))) return;
                        api('team/remove-member', { member_id: m.id }, true).then(function (res) {
                            if (res.ok) loadTeamDashboard();
                            else alert((res.data && res.data.message) || T('remove_member_error'));
                        });
                    });
                    li.appendChild(span); li.appendChild(btn);
                    list.appendChild(li);
                });
            }
            var jjMyTeamId = 0;
            function loadTeamDashboard() {
                showStep('team');
                $('#jj-team-members').innerHTML = '<li>' + T('loading') + '</li>';
                fetch(REST + 'team/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
                    .then(function (res) {
                        if (!res.ok) { $('#jj-team-members').innerHTML = '<li>' + T('team_info_fetch_error') + '</li>'; return; }
                        $('#jj-team-title').textContent = T('team_colon_prefix') + ' ' + res.data.team.team_name;
                        $('#jj-team-code').textContent = T('team_code_prefix') + ' ' + res.data.team.unique_code;
                        renderTeamMembers(res.data.members);
                        jjMyTeamId = res.data.team.team_id;
                        loadTeamProfile();
                    });
                loadTeamRoster();
            }
            function loadTeamProfile() {
                fetch(REST + 'team/profile/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) return;
                        var ent = res.entitlements || {};
                        $('#jj-team-profile-box').style.display = '';
                        $('#jj-team-profile-disabled-msg').style.display = ent.team_profile_enabled ? 'none' : '';
                        $('#jj-team-profile-form').style.display = ent.team_profile_enabled ? '' : 'none';
                        if (ent.team_profile_enabled && res.profile) {
                            $('#jj-team-specialty').value = res.profile.specialty || '';
                            $('#jj-team-description').value = res.profile.description || '';
                            $('#jj-team-skills').value = (res.profile.skills || []).join('، ');
                        }
                        $('#jj-team-track-box').style.display = '';
                        $('#jj-team-track-disabled-msg').style.display = ent.team_track_record_enabled ? 'none' : '';
                        $('#jj-team-track-form').style.display = ent.team_track_record_enabled ? '' : 'none';
                        if (ent.team_track_record_enabled) loadTeamTrackRecord();
                    });
                loadConsultations();
            }
            function loadConsultations() {
                var statusLabels = { scheduled: T('consult_status_scheduled'), notified: T('consult_status_notified'), completed: T('consult_status_completed'), canceled: T('consult_status_canceled') };
                fetch(REST + 'team/consultations', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        var list = $('#jj-consultations-list');
                        list.innerHTML = '';
                        var sessions = (res && res.sessions) || [];
                        if (!sessions.length) { list.innerHTML = '<li style="color:#9ca3af;">' + T('no_sessions_scheduled') + '</li>'; return; }
                        sessions.forEach(function (s) {
                            var li = document.createElement('li');
                            li.textContent = T('session_word_prefix') + ' ' + s.session_number + ' — ' + s.scheduled_at + ' — ' + (statusLabels[s.status] || s.status);
                            list.appendChild(li);
                        });
                    }).catch(function () {});
            }
            function loadTeamTrackRecord() {
                if (!jjMyTeamId) return;
                fetch(REST + 'team/' + jjMyTeamId + '/track-record', { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        var list = $('#jj-team-track-list');
                        list.innerHTML = '';
                        (res.records || []).forEach(function (rec) {
                            var li = document.createElement('li');
                            li.textContent = rec.title + (rec.employer_name ? ' — ' + rec.employer_name : '') + (rec.happened_at ? ' (' + rec.happened_at + ')' : '');
                            list.appendChild(li);
                        });
                        if (!(res.records || []).length) list.innerHTML = '<li style="color:#9ca3af;">' + T('no_track_records_yet') + '</li>';
                    });
            }
            $('#jj-btn-team-profile-save').addEventListener('click', function () {
                setMsg('#jj-msg-team-profile', '', false);
                var skills = $('#jj-team-skills').value.split(/[,،]/).map(function (s) { return s.trim(); }).filter(Boolean);
                $('#jj-btn-team-profile-save').disabled = true;
                api('team/profile', { specialty: $('#jj-team-specialty').value.trim(), description: $('#jj-team-description').value.trim(), skills: skills }, true).then(function (res) {
                    $('#jj-btn-team-profile-save').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-team-profile', (res.data && res.data.message) || T('error_generic'), true); return; }
                    setMsg('#jj-msg-team-profile', T('team_profile_saved'), false);
                });
            });
            $('#jj-btn-team-track-add').addEventListener('click', function () {
                setMsg('#jj-msg-team-track', '', false);
                var title = $('#jj-team-track-title').value.trim();
                if (!title) { setMsg('#jj-msg-team-track', T('enter_record_title'), true); return; }
                $('#jj-btn-team-track-add').disabled = true;
                api('team/track-record', { title: title, employer_name: $('#jj-team-track-employer').value.trim(), description: $('#jj-team-track-desc').value.trim() }, true).then(function (res) {
                    $('#jj-btn-team-track-add').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-team-track', (res.data && res.data.message) || T('error_generic'), true); return; }
                    $('#jj-team-track-title').value = ''; $('#jj-team-track-employer').value = ''; $('#jj-team-track-desc').value = '';
                    setMsg('#jj-msg-team-track', T('record_added'), false);
                    loadTeamTrackRecord();
                });
            });
            var jjTeamChatBtn = document.getElementById('jj-btn-team-chat');
            if (jjTeamChatBtn) {
                jjTeamChatBtn.addEventListener('click', function () { if (window.jjChatOpen) window.jjChatOpen('team'); });
            }
            var teamRoster = [];
            function renderRoster() {
                var box = $('#jj-team-roster-box');
                var list = $('#jj-team-roster-list');
                if (!teamRoster.length) { box.style.display = 'none'; return; }
                box.style.display = '';
                list.innerHTML = '';
                var total = 0;
                teamRoster.forEach(function (entry, idx) {
                    total += entry.amount_toman;
                    var li = document.createElement('li');
                    var label = entry.mode === 'team_entry' ? T('team_entry_active_member') : (T('new_membership_prefix') + ' ' + entry.package_name);
                    li.innerHTML = '<span>' + entry.phone + ' — ' + label + ' (' + Number(entry.amount_toman).toLocaleString('fa-IR') + ' ' + T('toman') + ')</span>';
                    var btn = document.createElement('button');
                    btn.className = 'jj-remove-entry'; btn.textContent = T('delete_btn');
                    btn.addEventListener('click', function () { teamRoster.splice(idx, 1); renderRoster(); });
                    li.appendChild(btn);
                    list.appendChild(li);
                });
                $('#jj-team-roster-total').textContent = Number(total).toLocaleString('fa-IR');
            }
            function loadTeamRoster() { renderRoster(); }
            $('#jj-btn-team-check').addEventListener('click', function () {
                setMsg('#jj-msg-team', '', false);
                var phone = $('#jj-team-phone').value.trim();
                var resultBox = $('#jj-team-check-result');
                resultBox.innerHTML = '';
                if (!/^09\d{9}$/.test(phone)) { setMsg('#jj-msg-team', T('enter_mobile_correctly'), true); return; }
                if (teamRoster.some(function (e) { return e.phone === phone; })) { setMsg('#jj-msg-team', T('number_already_in_list'), true); return; }
                $('#jj-btn-team-check').disabled = true;
                api('team/check-member', { phone: phone }, true).then(function (res) {
                    $('#jj-btn-team-check').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-team', (res.data && res.data.message) || T('error_generic'), true); return; }
                    if (res.data.status === 'invite_pending') {
                        resultBox.innerHTML = '<p style="font-size:13px;color:#d97706;">' + T('invite_already_sent_pending') + '</p>';
                    } else if (res.data.status === 'ready_for_payment') {
                        var readyLabel = res.data.mode === 'team_entry' ? T('team_entry_active_member') : (T('new_membership_prefix') + ' ' + res.data.package_name);
                        resultBox.innerHTML = '<p style="font-size:13px;color:#059669;">' + T('candidate_confirmed_invite_prefix') + ' ' + readyLabel + ' (' + Number(res.data.fee_toman).toLocaleString('fa-IR') + ' ' + T('toman') + ')</p>'
                            + '<button class="jj-btn" id="jj-btn-add-confirmed">' + T('add_to_payment_list_btn') + '</button>';
                        document.getElementById('jj-btn-add-confirmed').addEventListener('click', function () {
                            teamRoster.push({ phone: phone, mode: res.data.mode, amount_toman: res.data.fee_toman });
                            renderRoster(); resultBox.innerHTML = ''; $('#jj-team-phone').value = '';
                        });
                    } else if (res.data.status === 'active_member') {
                        resultBox.innerHTML = '<p style="font-size:13px;color:#059669;">' + T('already_active_member_entry_fee') + ' ' + Number(res.data.fee_toman).toLocaleString('fa-IR') + ' ' + T('toman') + '</p>'
                            + '<button class="jj-btn" id="jj-btn-send-invite-entry">' + T('send_invite_for_confirm_btn') + '</button>';
                        document.getElementById('jj-btn-send-invite-entry').addEventListener('click', function () {
                            api('team/send-invite', { phone: phone, mode: 'team_entry' }, true).then(function (r) {
                                if (!r.ok) { setMsg('#jj-msg-team', (r.data && r.data.message) || T('error_generic'), true); return; }
                                resultBox.innerHTML = '<p style="font-size:13px;color:#2563eb;">' + T('invite_sent_awaiting_confirm') + '</p>';
                                $('#jj-team-phone').value = '';
                            });
                        });
                    } else {
                        resultBox.innerHTML = '<p style="font-size:13px;color:#d97706;">' + T('not_active_member_who_pays') + '</p>'
                            + '<button class="jj-btn jj-btn-secondary" id="jj-btn-leader-pays" style="margin-bottom:6px;">' + T('leader_pays_btn') + '</button>'
                            + '<button class="jj-btn jj-btn-secondary" id="jj-btn-self-pays">' + T('self_pays_btn') + '</button>'
                            + '<div id="jj-team-pkg-picker" style="margin-top:8px;"></div>';
                        document.getElementById('jj-btn-self-pays').addEventListener('click', function () {
                            api('team/notify-self-payment', { phone: phone }, true).then(function (r) {
                                if (!r.ok) { setMsg('#jj-msg-team', (r.data && r.data.message) || T('error_generic'), true); return; }
                                resultBox.innerHTML = '<p style="font-size:13px;color:#059669;">' + T('self_payment_notified') + '</p>';
                                $('#jj-team-phone').value = '';
                            });
                        });
                        document.getElementById('jj-btn-leader-pays').addEventListener('click', function () {
                            var pkgBox = document.getElementById('jj-team-pkg-picker');
                            pkgBox.innerHTML = T('loading_packages');
                            fetch(REST + 'packages?context=jobseeker').then(function (r) { return r.json(); }).then(function (pres) {
                                if (!pres.packages || !pres.packages.length) { pkgBox.innerHTML = '<p>' + T('packages_not_defined_short') + '</p>'; return; }
                                pkgBox.innerHTML = '';
                                pres.packages.forEach(function (p) {
                                    var card = document.createElement('div');
                                    card.className = 'jj-role-card'; card.style.marginBottom = '6px';
                                    card.innerHTML = '<span class="jj-role-title">' + p.name + '</span><span class="jj-role-desc">' + p.duration_days + ' ' + T('day_label') + ' — ' + Number(p.price_toman).toLocaleString('fa-IR') + ' ' + T('toman') + '</span>';
                                    card.addEventListener('click', function () {
                                        api('team/send-invite', { phone: phone, mode: 'new_membership', package_id: p.id }, true).then(function (r) {
                                            if (!r.ok) { setMsg('#jj-msg-team', (r.data && r.data.message) || T('error_generic'), true); return; }
                                            resultBox.innerHTML = '<p style="font-size:13px;color:#2563eb;">' + T('invite_sent_awaiting_confirm') + '</p>';
                                            $('#jj-team-phone').value = '';
                                        });
                                    });
                                    pkgBox.appendChild(card);
                                });
                            });
                        });
                    }
                }).catch(function () { $('#jj-btn-team-check').disabled = false; setMsg('#jj-msg-team', T('error_network'), true); });
            });
            $('#jj-btn-team-pay-roster').addEventListener('click', function () {
                setMsg('#jj-msg-team', '', false);
                if (!teamRoster.length) return;
                $('#jj-btn-team-pay-roster').disabled = true;
                var entries = teamRoster.map(function (e) { return { phone: e.phone, mode: e.mode, package_id: e.package_id || 0 }; });
                api('team/roster-payment', { entries: entries }, true).then(function (res) {
                    $('#jj-btn-team-pay-roster').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-team', (res.data && res.data.message) || T('gateway_connection_error'), true); return; }
                    window.location.href = res.data.payment_url;
                }).catch(function () { $('#jj-btn-team-pay-roster').disabled = false; setMsg('#jj-msg-team', T('error_network'), true); });
            });

            // ---------- داشبورد آگهی‌های شغلی (کارفرما/کاریابی) ----------
            var jobStatusColor = { pending: '#d97706', publish: '#059669', trash: '#dc2626', draft: '#6b7280' };
            var jjIsAgency = false;
            function loadJobsDashboard() {
                showStep('jobs');
                refreshMyJobs();
                fetch(REST + 'profile/summary', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        jjIsAgency = !!(res && res.role === 'agency');
                        $('#jj-btn-agency-search').style.display = jjIsAgency ? '' : 'none';
                        $('#jj-btn-agency-videos').style.display = jjIsAgency ? '' : 'none';
                        $('#jj-btn-agency-report').style.display = jjIsAgency ? '' : 'none';
                    }).catch(function () {});
            }
            $('#jj-btn-agency-search').addEventListener('click', function () {
                showStep('agency-search');
                runAgencySearch();
            });
            $('#jj-btn-agency-search-back').addEventListener('click', function () { showStep('jobs'); });
            $('#jj-btn-agency-search-run').addEventListener('click', runAgencySearch);
            var jjAsTierLabels = {1: [T('tier_featured'), '#2563eb'], 2: [T('tier_special'), '#7c3aed'], 3: [T('tier_custom'), '#c9a227']};
            function runAgencySearch() {
                setMsg('#jj-msg-agency-search', '', false);
                $('#jj-agency-search-results').innerHTML = '<p style="color:#9ca3af;">' + T('searching_ellipsis') + '</p>';
                var params = [];
                var q = $('#jj-as-q').value.trim(); if (q) params.push('q=' + encodeURIComponent(q));
                var nat = $('#jj-as-nationality').value; if (nat) params.push('nationality=' + nat);
                var gen = $('#jj-as-gender').value; if (gen) params.push('gender=' + gen);
                var wl = $('#jj-as-work-location').value; if (wl) params.push('work_location=' + wl);
                var prov = $('#jj-as-province').value.trim(); if (prov) params.push('province=' + encodeURIComponent(prov));
                fetch(REST + 'agency/jobseekers/search' + (params.length ? '?' + params.join('&') : ''), { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            $('#jj-agency-search-results').innerHTML = '';
                            if (res.data && res.data.code === 'not_entitled') {
                                $('#jj-agency-search-disabled-msg').style.display = '';
                                $('#jj-agency-search-form').style.display = 'none';
                            } else {
                                setMsg('#jj-msg-agency-search', (res.data && res.data.message) || T('error_generic'), true);
                            }
                            return;
                        }
                        $('#jj-agency-search-disabled-msg').style.display = 'none';
                        $('#jj-agency-search-form').style.display = '';
                        var list = res.data.jobseekers || [];
                        var box = $('#jj-agency-search-results');
                        box.innerHTML = '';
                        if (!list.length) { box.innerHTML = '<p style="color:#9ca3af;">' + T('no_candidates_found') + '</p>'; return; }
                        list.forEach(function (js) {
                            var card = document.createElement('div');
                            card.style.cssText = 'position:relative;flex:1;min-width:220px;max-width:260px;background:#fff;border:1px solid #e5e7eb;border-top:3px solid #c9a227;border-radius:10px;padding:16px;text-align:center;';
                            var avatarHtml = js.avatar_url ? '<img src="' + js.avatar_url + '" style="width:60px;height:60px;border-radius:50%;object-fit:cover;">' : '<div style="font-size:26px;">👤</div>';
                            card.innerHTML = avatarHtml
                                + '<p style="font-size:12.5px;color:#374151;margin:8px 0 2px;">' + T('nationality_word') + ': ' + escHtml(js.nationality) + ' | ' + escHtml(js.gender) + '</p>'
                                + '<p style="font-size:12.5px;color:#374151;margin:0 0 2px;">' + T('work_location_colon') + ' ' + escHtml(js.work_location) + (js.province ? ' — ' + escHtml(js.province) : '') + '</p>'
                                + (js.skills && js.skills.length ? '<p style="font-size:12.5px;color:#374151;margin:0;">' + T('skills_label') + ': ' + escHtml(js.skills.join('، ')) + '</p>' : '');
                            var btn = document.createElement('button');
                            btn.className = 'jj-btn jj-btn-secondary'; btn.style.marginTop = '8px'; btn.textContent = T('message_this_candidate');
                            btn.addEventListener('click', function () { if (window.jjStartDirectChat) window.jjStartDirectChat(js.user_id, T('user_role_jobseeker')); });
                            card.appendChild(btn);
                            box.appendChild(card);
                        });
                    }).catch(function () { setMsg('#jj-msg-agency-search', T('error_network'), true); });
            }
            $('#jj-btn-agency-videos').addEventListener('click', function () { showStep('agency-videos'); loadAgencyVideos(); });
            $('#jj-btn-agency-videos-back').addEventListener('click', function () { showStep('jobs'); });
            var jjAvPollTimer = null;
            var jjAvStatusLabels = { pending: T('video_status_generating'), ready: T('video_status_ready'), failed: T('video_status_failed') };
            function loadAgencyVideos() {
                clearTimeout(jjAvPollTimer);
                fetch(REST + 'agency/videos', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            if (res.data && res.data.code === 'not_entitled') { $('#jj-av-disabled-msg').style.display = ''; $('#jj-btn-agency-video-generate').style.display = 'none'; }
                            return;
                        }
                        $('#jj-av-disabled-msg').style.display = 'none';
                        $('#jj-btn-agency-video-generate').style.display = '';
                        $('#jj-av-quota').textContent = res.data.used + ' ' + T('of_word') + ' ' + res.data.quota;
                        var list = $('#jj-agency-videos-list');
                        list.innerHTML = '';
                        var hasPending = false;
                        (res.data.videos || []).forEach(function (v) {
                            if (v.status === 'pending') hasPending = true;
                            var card = document.createElement('div');
                            card.style.cssText = 'width:200px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px;text-align:center;';
                            if (v.status === 'ready' && v.video_url) {
                                card.innerHTML = '<video src="' + v.video_url + '" controls style="width:100%;height:280px;border-radius:8px;background:#000;object-fit:cover;"></video>';
                            } else {
                                card.innerHTML = '<div style="width:100%;height:280px;border-radius:8px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#6b7280;font-size:13px;">' + (jjAvStatusLabels[v.status] || v.status) + '</div>';
                                if (v.status === 'failed' && v.error_message) {
                                    var errP = document.createElement('p');
                                    errP.style.cssText = 'font-size:11px;color:#dc2626;margin:6px 0 0;';
                                    errP.textContent = v.error_message;
                                    card.appendChild(errP);
                                }
                            }
                            var delBtn = document.createElement('button');
                            delBtn.className = 'jj-btn-link'; delBtn.style.marginTop = '8px'; delBtn.textContent = T('delete_btn');
                            delBtn.addEventListener('click', function () {
                                if (!confirm(T('confirm_delete_video'))) return;
                                fetch(REST + 'agency/videos/' + v.id, { method: 'DELETE', headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                                    .then(function () { loadAgencyVideos(); });
                            });
                            card.appendChild(delBtn);
                            list.appendChild(card);
                        });
                        if (hasPending) { jjAvPollTimer = setTimeout(loadAgencyVideos, 8000); }
                    }).catch(function () {});
            }
            $('#jj-btn-agency-video-generate').addEventListener('click', function () {
                setMsg('#jj-msg-agency-videos', '', false);
                $('#jj-btn-agency-video-generate').disabled = true;
                api('agency/videos/generate', {}, true).then(function (res) {
                    $('#jj-btn-agency-video-generate').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-agency-videos', (res.data && res.data.message) || T('error_generic'), true); return; }
                    setMsg('#jj-msg-agency-videos', T('video_generation_started'), false);
                    loadAgencyVideos();
                });
            });
            $('#jj-btn-employer-requests').addEventListener('click', function () { showStep('employer-requests'); loadEmployerRequests(); });
            $('#jj-btn-employer-requests-back').addEventListener('click', function () { showStep('jobs'); });
            function loadEmployerRequests() {
                setMsg('#jj-msg-employer-requests', '', false);
                var list = $('#jj-employer-requests-list');
                list.innerHTML = '<p style="color:#9ca3af;">' + T('loading') + '</p>';
                fetch(REST + 'employer/requests/received', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
                    .then(function (res) {
                        if (!res.ok) { list.innerHTML = ''; setMsg('#jj-msg-employer-requests', (res.data && res.data.message) || T('error_generic'), true); return; }
                        var rows = res.data.requests || [];
                        list.innerHTML = '';
                        if (!rows.length) { list.innerHTML = '<p style="color:#9ca3af;">' + T('no_requests_received') + '</p>'; return; }
                        var statusLabels = { pending: T('req_status_pending'), accepted: T('req_status_accepted'), declined: T('req_status_declined') };
                        rows.forEach(function (req) {
                            var card = document.createElement('div');
                            card.style.cssText = 'flex:1;min-width:220px;max-width:280px;background:#fff;border:1px solid #e5e7eb;border-top:3px solid #c9a227;border-radius:10px;padding:16px;';
                            var avatarHtml = req.avatar_url ? '<img src="' + req.avatar_url + '" style="width:50px;height:50px;border-radius:50%;object-fit:cover;">' : '<div style="font-size:24px;">👤</div>';
                            card.innerHTML = '<div style="text-align:center;">' + avatarHtml + '</div>'
                                + '<p style="font-size:12.5px;color:#374151;margin:8px 0 2px;">' + T('nationality_word') + ': ' + escHtml(req.nationality) + ' | ' + escHtml(req.gender) + '</p>'
                                + '<p style="font-size:12.5px;color:#374151;margin:0 0 2px;">' + T('work_location_colon') + ' ' + escHtml(req.work_location) + '</p>'
                                + (req.skills && req.skills.length ? '<p style="font-size:12.5px;color:#374151;margin:0 0 6px;">' + T('skills_label') + ': ' + escHtml(req.skills.join('، ')) + '</p>' : '')
                                + (req.message ? '<p style="font-size:12.5px;color:#6b7280;margin:0 0 6px;background:#f9fafb;padding:8px;border-radius:6px;">' + escHtml(req.message) + '</p>' : '')
                                + '<p style="font-size:11.5px;color:#9ca3af;margin:0 0 8px;">' + T('status_colon') + ' ' + (statusLabels[req.status] || req.status) + '</p>';
                            if (req.status === 'pending') {
                                var acceptBtn = document.createElement('button');
                                acceptBtn.className = 'jj-btn'; acceptBtn.style.cssText = 'width:auto;padding:6px 14px;margin-left:6px;'; acceptBtn.textContent = T('accept_word');
                                var declineBtn = document.createElement('button');
                                declineBtn.className = 'jj-btn-secondary jj-btn'; declineBtn.style.cssText = 'width:auto;padding:6px 14px;'; declineBtn.textContent = T('decline_word');
                                function respond(action) {
                                    acceptBtn.disabled = true; declineBtn.disabled = true;
                                    api('employer/requests/' + req.id + '/respond', { action: action }, true).then(function (res2) {
                                        if (!res2.ok) { setMsg('#jj-msg-employer-requests', (res2.data && res2.data.message) || T('error_generic'), true); acceptBtn.disabled = false; declineBtn.disabled = false; return; }
                                        loadEmployerRequests();
                                    });
                                }
                                acceptBtn.addEventListener('click', function () { respond('accept'); });
                                declineBtn.addEventListener('click', function () { respond('decline'); });
                                card.appendChild(acceptBtn); card.appendChild(declineBtn);
                            } else if (req.status === 'accepted') {
                                var chatBtn = document.createElement('button');
                                chatBtn.className = 'jj-btn jj-btn-secondary'; chatBtn.style.width = 'auto'; chatBtn.style.padding = '6px 14px'; chatBtn.textContent = T('chat_word');
                                chatBtn.addEventListener('click', function () { if (window.jjStartDirectChat) window.jjStartDirectChat(req.jobseeker_id, T('user_role_jobseeker')); });
                                card.appendChild(chatBtn);
                            }
                            list.appendChild(card);
                        });
                    }).catch(function () { list.innerHTML = ''; setMsg('#jj-msg-employer-requests', T('error_network'), true); });
            }
            $('#jj-btn-agency-report').addEventListener('click', function () { showStep('agency-report'); loadAgencyReport(); });
            $('#jj-btn-agency-report-back').addEventListener('click', function () { showStep('jobs'); });
            function loadAgencyReport() {
                var box = $('#jj-ar-content');
                box.innerHTML = '<p style="color:#9ca3af;">' + T('loading') + '</p>';
                $('#jj-ar-disabled-msg').style.display = 'none';
                fetch(REST + 'agency/report', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            box.innerHTML = '';
                            if (res.data && res.data.code === 'not_entitled') { $('#jj-ar-disabled-msg').style.display = ''; }
                            return;
                        }
                        var d = res.data;
                        var cards = [
                            [T('report_jobs_posted'), d.jobs_posted],
                            [T('report_interacted_jobseekers'), d.interacted_jobseekers],
                            [T('report_requested_individual'), d.requested_individual],
                            [T('report_requested_team'), d.requested_team],
                            [T('report_hired_individual'), d.hired_individual],
                            [T('report_hired_team'), d.hired_team]
                        ];
                        box.innerHTML = cards.map(function (c) {
                            return '<div style="flex:1;min-width:150px;background:#fff;border:1px solid #e5e7eb;border-top:3px solid #c9a227;border-radius:10px;padding:16px;text-align:center;">'
                                + '<div style="font-size:26px;font-weight:bold;color:#14213d;">' + c[1] + '</div>'
                                + '<div style="font-size:12.5px;color:#6b7280;margin-top:4px;">' + c[0] + '</div></div>';
                        }).join('');
                    }).catch(function () { box.innerHTML = ''; });
            }
            function renderProfessionDropdown(query) {
                var box = $('#jj-professions-dropdown');
                if (!box) return;
                if (!state.professionsList) { box.style.display = 'none'; return; }
                var q = (query || '').trim();
                var matches = (q ? state.professionsList.filter(function (p) { return p.name.indexOf(q) !== -1; }) : state.professionsList).slice(0, 50);
                if (!matches.length) { box.innerHTML = '<div style="padding:10px;font-size:13px;color:#6b7280;">' + T('no_matches_found') + '</div>'; box.style.display = ''; return; }
                box.innerHTML = matches.map(function (p) {
                    return '<div class="jj-prof-option" data-id="' + p.id + '" data-name="' + p.name.replace(/"/g, '&quot;') + '" style="padding:9px 12px;cursor:pointer;font-size:13.5px;border-bottom:1px solid #f3f4f6;">' + p.name + '</div>';
                }).join('');
                box.style.display = '';
                box.querySelectorAll('.jj-prof-option').forEach(function (opt) {
                    opt.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        $('#jj-job-profession-input').value = opt.getAttribute('data-name');
                        $('#jj-job-profession-input').setAttribute('data-selected-id', opt.getAttribute('data-id'));
                        box.style.display = 'none';
                    });
                });
            }
            /** فراخوانی می‌شود بعد از هربار رندر شدن فرم آگهی (چون عنصر ورودی هر بار از نو ساخته می‌شود). */
            function renderProfessionDropdownSetup() {
                var input = $('#jj-job-profession-input');
                if (!input) return;
                if (!state.professionsList) {
                    fetch(REST + 'job-professions').then(function (r) { return r.json(); }).then(function (list) {
                        state.professionsList = list;
                        input.placeholder = T('profession_search_ph');
                    });
                } else {
                    input.placeholder = T('profession_search_ph');
                }
                input.addEventListener('input', function () {
                    this.removeAttribute('data-selected-id');
                    renderProfessionDropdown(this.value);
                });
                input.addEventListener('focus', function () { renderProfessionDropdown(this.value); });
            }
            function renderJobTitleDropdown(query) {
        var box = document.querySelector('#jj-job-title-dropdown');
        if (!box) return;
        if (!state.professionsList) { box.style.display = 'none'; return; }
        var q = (query || '').trim();
        var matches = (q ? state.professionsList.filter(function (p) {
          return p.name.indexOf(q) !== -1;
        }) : state.professionsList).slice(0, 50);
        if (!matches.length) { box.innerHTML = '<div style="padding:10px;font-size:13px;color:#6b7280;">' + T('no_matches_found') + '</div>'; box.style.display = ''; return; }
        box.innerHTML = matches.map(function (p) {
          return '<div class="jj-jobtitle-option" data-id="' + p.id + '" data-name="' + p.name + '" style="padding:8px 12px;cursor:pointer;font-size:13.5px;border-bottom:1px solid #f3f4f6;">' + p.name + '</div>';
        }).join('');
        box.style.display = '';
        box.querySelectorAll('.jj-jobtitle-option').forEach(function (opt) {
          opt.addEventListener('mousedown', function (e) {
            e.preventDefault();
            var jt = document.querySelector('#jj-job-title');
            jt.value = opt.getAttribute('data-name');
            jt.setAttribute('data-selected-id', opt.getAttribute('data-id'));
            box.style.display = 'none';
          });
        });
      }
      function renderJobTitleDropdownSetup() {
        var input = document.querySelector('#jj-job-title');
        if (!input) return;
        if (!state.professionsList) {
          fetch(REST + 'job-professions').then(function (r) { return r.json(); }).then(function (list) {
            state.professionsList = list;
          }).catch(function () {});
        }
        input.addEventListener('input', function () {
          this.removeAttribute('data-selected-id');
          renderJobTitleDropdown(this.value);
        });
        input.addEventListener('focus', function () { renderJobTitleDropdown(this.value); });
        input.addEventListener('blur', function () {
          setTimeout(function () { var b = document.querySelector('#jj-job-title-dropdown'); if (b) b.style.display = 'none'; }, 200);
        });
      }
      function renderDesiredDropdown(idx, query) {
                var box = $('#jj-js-desired-dropdown-' + idx);
                if (!box) return;
                if (!state.professionsList) { box.style.display = 'none'; return; }
                var q = (query || '').trim();
                var matches = q ? state.professionsList.filter(function (p) { return p.name.indexOf(q) > -1; }).slice(0, 20) : state.professionsList.slice(0, 20);
                if (!matches.length) { box.style.display = 'none'; return; }
                box.innerHTML = matches.map(function (p) {
                    return '<div class="jj-prof-option" data-id="' + p.id + '" data-name="' + p.name.replace(/"/g, '&quot;') + '" style="padding:9px 12px;cursor:pointer;font-size:13.5px;border-bottom:1px solid #f0f0f0;">' + p.name + '</div>';
                }).join('');
                box.style.display = 'block';
                box.querySelectorAll('.jj-prof-option').forEach(function (opt) {
                    opt.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        var inp = $('#jj-js-desired-' + idx);
                        inp.value = opt.getAttribute('data-name');
                        inp.setAttribute('data-selected-id', opt.getAttribute('data-id'));
                        box.style.display = 'none';
                    });
                });
            }
            function setupDesiredJobsAutocomplete() {
                function ensureList(cb) {
                    if (state.professionsList) { cb(); return; }
                    fetch(REST + 'job-professions').then(function (r) { return r.json(); }).then(function (list) {
                        state.professionsList = list;
                        cb();
                    });
                }
                [1, 2, 3].forEach(function (idx) {
                    var input = $('#jj-js-desired-' + idx);
                    if (!input) return;
                    if (input.getAttribute('data-jj-bound')) return;
                    input.setAttribute('data-jj-bound', '1');
                    ensureList(function () {});
                    input.addEventListener('input', function () {
                        this.removeAttribute('data-selected-id');
                        renderDesiredDropdown(idx, this.value);
                    });
                    input.addEventListener('focus', function () {
                        ensureList(function () { renderDesiredDropdown(idx, input.value); });
                    });
                });
            }
            document.addEventListener('click', function (e) {
                [1, 2, 3].forEach(function (idx) {
                    var input = $('#jj-js-desired-' + idx);
                    var box = $('#jj-js-desired-dropdown-' + idx);
                    if (!input || !box) return;
                    if (e.target !== input && !box.contains(e.target)) { box.style.display = 'none'; }
                });
            });
            document.addEventListener('click', function (e) {
                var box = $('#jj-professions-dropdown');
                if (box && e.target.id !== 'jj-job-profession-input') box.style.display = 'none';
            });
            function refreshMyJobs() {
                fetch(REST + 'jobs/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) {
                        if (r.status === 401) { handleUnauthorized(); return null; }
                        return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                    })
                    .then(function (res) {
                        var list = $('#jj-jobs-list');
                        if (!res) return;
                        if (!res.ok) { list.innerHTML = '<li>' + T('info_fetch_error_retry') + '</li>'; return; }
                        if (!res.data.jobs || !res.data.jobs.length) { list.innerHTML = '<li>' + T('no_jobs_posted_yet_short') + '</li>'; return; }
                        list.innerHTML = '';
                        res.data.jobs.forEach(function (j) {
                            var li = document.createElement('li');
                            li.style.display = 'block';
                            var row = document.createElement('div');
                            row.style.display = 'flex'; row.style.justifyContent = 'space-between';
                            row.innerHTML = '<span>' + escHtml(j.title) + '</span><span style="color:' + (jobStatusColor[j.status] || '#333') + '">' + escHtml(j.status_label) + '</span>';
                            var appsBox = document.createElement('div');
                            appsBox.style.marginTop = '8px'; appsBox.style.display = 'none';
                            if (j.status === 'publish') {
                                var btn = document.createElement('button');
                                btn.className = 'jj-btn-link'; btn.textContent = T('view_applicants_btn');
                                btn.addEventListener('click', function () {
                                    appsBox.style.display = appsBox.style.display === 'none' ? '' : 'none';
                                    if (appsBox.style.display === '') loadApplicants(j.id, appsBox);
                                });
                                row.appendChild(btn);
                            }
                            if (j.needs_revision) {
                                var noteBox = document.createElement('div');
                                noteBox.style.marginTop = '6px'; noteBox.style.padding = '8px'; noteBox.style.background = '#fff7ed';
                                noteBox.style.border = '1px solid #fdba74'; noteBox.style.borderRadius = '6px'; noteBox.style.fontSize = '13px';
                                noteBox.innerHTML = '<strong>' + T('needs_revision_label') + '</strong> ' + escHtml(j.revision_note || T('no_note_recorded')) + '<br>';
                                var resubmitBtn = document.createElement('button');
                                resubmitBtn.type = 'button'; resubmitBtn.className = 'button'; resubmitBtn.style.marginTop = '6px';
                                resubmitBtn.textContent = 'ویرایش و ارسال دوباره برای بررسی';
                                resubmitBtn.addEventListener('click', function () {
                                    openJobEditForm(j.id);
                                });
                                noteBox.appendChild(resubmitBtn);
                                li.appendChild(noteBox);
                            }
                            li.appendChild(row); li.appendChild(appsBox);
                            list.appendChild(li);
                        });
                    }).catch(function () { $('#jj-jobs-list').innerHTML = '<li>' + T('error_network') + '</li>'; });
            }
            function loadApplicants(jobId, box) {
        (function(){
            var __chatData = null, __chatTries = 0;
            fetch(REST + 'chat/threads', {method:'GET', headers:{'X-Jj-Auth': state.token}, credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(d){
                    var chatted = {};
                    (d.threads||[]).forEach(function(t){
                        if (t.context_type === 'application' && t.last_message) chatted[t.context_id] = true;
                    });
                    __chatData = chatted;
                    __applyChatGate();
                })
                .catch(function(){ __chatData = {}; __applyChatGate(); });
            function __applyChatGate(){
                if (__chatData === null) return;
                var btns = box.querySelectorAll('[data-app-id]');
                if (!btns.length && __chatTries < 25) {
                    __chatTries++;
                    setTimeout(__applyChatGate, 200);
                    return;
                }
                btns.forEach(function(el){
                    if (!__chatData[el.dataset.appId]) {
                        el.disabled = true;
                        el.title = T('must_chat_first_title');
                    }
                });
            }
        })();
                box.innerHTML = T('loading');
                fetch(REST + 'jobs/' + jobId + '/applicants', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) {
                        if (r.status === 401) { handleUnauthorized(); return null; }
                        return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                    })
                    .then(function (res) {
                        if (!res) return;
                        if (!res.ok) { box.innerHTML = '<p style="font-size:13px;color:#dc2626;">' + T('info_fetch_error_retry') + '</p>'; return; }
                        if (!res.data.applicants.length) { box.innerHTML = '<p style="font-size:13px;color:#6b7280;">' + T('no_applicants_yet') + '</p>'; return; }
                        box.innerHTML = '';
                        res.data.applicants.forEach(function (a) {
                            var row = document.createElement('div');
                            row.style.cssText = 'padding:8px 0;border-top:1px solid #eee;font-size:13px;';
                            var top = document.createElement('div');
                            top.style.cssText = 'display:flex;justify-content:space-between;align-items:center;';
                            var info = document.createElement('span');
                            info.innerHTML = '<strong>' + escHtml(a.display_name || a.phone) + '</strong> — ' + escHtml(a.phone) + '<br>'
                                + '<span style="font-size:12px;color:#6b7280;">' + T('gender_colon') + ' ' + escHtml(a.gender_label) + ' | ' + T('nationality_word') + ': ' + escHtml(a.nationality_label) + ' | ' + T('preferred_work_location_colon') + ' ' + escHtml(a.work_location_label)
                                + (a.skills && a.skills.length ? ' | ' + T('skills_label') + ': ' + escHtml(a.skills.join('، ')) : '') + '</span>';
                            var statusSpan = document.createElement('span'); statusSpan.textContent = a.status_label;
                            var chatBtn = document.createElement('button');
                            chatBtn.type = 'button'; chatBtn.className = 'jj-btn-link'; chatBtn.style.marginRight = '8px';
                            chatBtn.style.fontSize = '15px';
                            chatBtn.style.fontWeight = '700';
                            chatBtn.style.padding = '8px 14px';
                            chatBtn.style.display = 'inline-block';
                            chatBtn.textContent = T('chat_with_candidate_btn');
                            chatBtn.addEventListener('click', function () { if (window.jjChatOpen) window.jjChatOpen('application', a.id); });
                            top.appendChild(info); top.appendChild(statusSpan); top.appendChild(chatBtn);
                            row.appendChild(top);
                            if (a.status === 'pending') {
                                var btnRow = document.createElement('div'); btnRow.style.marginTop = '4px';
                                var acc = document.createElement('button'); acc.className = 'jj-btn-link'; acc.textContent = T('accept_with_check');
                                acc.dataset.appId = a.id;
                                var rej = document.createElement('button'); rej.className = 'jj-btn-link'; rej.style.color = '#dc2626'; rej.textContent = T('decline_with_x');
                                rej.dataset.appId = a.id;
                                var decideBusy = false;
                                var decide = function (decision) {
                                    if (decideBusy) return;
                                    decideBusy = true; acc.disabled = true; rej.disabled = true;
                                    api('applications/' + a.id + '/decide', { decision: decision }, true).then(function (r) {
                                        if (!r.ok) { alert((r.data && r.data.message) || T('error_generic')); decideBusy = false; acc.disabled = false; rej.disabled = false; return; }
                                        loadApplicants(jobId, box);
                                    }).catch(function () { alert(T('error_network')); decideBusy = false; acc.disabled = false; rej.disabled = false; });
                                };
                                acc.addEventListener('click', function () { decide('accepted'); });
                                rej.addEventListener('click', function () { decide('rejected'); });
                                btnRow.appendChild(acc); btnRow.appendChild(rej);
                                row.appendChild(btnRow);
                            }
                            box.appendChild(row);
                        });
                    }).catch(function () { box.innerHTML = '<p style="font-size:13px;color:#dc2626;">' + T('error_network') + '</p>'; });
            }
            var jjMetaOptions = null;
            function radioGroup(name, options, defaultVal) {
                return options.map(function (o) {
                    return '<label style="display:inline-flex;align-items:center;gap:4px;margin-left:14px;font-size:13px;"><input type="radio" name="' + name + '" value="' + o.v + '" ' + (o.v === defaultVal ? 'checked' : '') + '> ' + o.l + '</label>';
                }).join('');
            }
            function jobCountryOptions() {
                return jjMetaOptions.countries.map(function (c) { return '<option value="' + c + '">' + c + '</option>'; }).join('');
            }
            function renderJobFormFields() {
                var html = '';
                html += jjStepperHtml([
                    { icon: 'account', label: T('jobform_step_ad_info') },
                    { icon: 'money', label: T('jobform_step_terms') },
                    { icon: 'doc', label: T('additional_info_heading') },
                    { icon: 'flag', label: T('jobform_step_publish') }
                ], 0);
                html += '<div class="jj-resume-step" data-resume-step="0">';
                html += '<span class="jj-label">' + T('job_title') + '</span><div style="position:relative;"><input class="jj-input" id="jj-job-title" required autocomplete="off"><div id="jj-job-title-dropdown" style="display:none;position:absolute;z-index:20;top:100%;right:0;left:0;max-height:220px;overflow-y:auto;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.12);"></div></div>';
                html += '<div class="jj-field-row"><div><span class="jj-label">' + T('country') + '</span><select class="jj-input" id="jj-job-country">' + jobCountryOptions() + '</select></div>'
                      + '<div><span class="jj-label">' + T('province') + '</span><select class="jj-input" id="jj-job-province"></select></div></div>';
                html += '<span class="jj-label">' + T('city') + '</span><input class="jj-input" id="jj-job-city">';
                html += '<span class="jj-label">' + T('location_name') + '</span><input class="jj-input" id="jj-job-location-name">';
                html += '<span class="jj-label">' + T('skill_level') + '</span><select class="jj-input" id="jj-job-skill">' + jjMetaOptions.skill_levels.map(function (s) { return '<option value="' + s + '">' + s + '</option>'; }).join('') + '</select>';
                html += '<span class="jj-label">' + T('related_profession') + '</span><div style="position:relative;"><input class="jj-input" id="jj-job-profession-input" placeholder="' + T('loading') + '" autocomplete="off"><div id="jj-professions-dropdown" style="display:none;position:absolute;z-index:20;top:100%;right:0;left:0;max-height:220px;overflow-y:auto;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.12);"></div></div>';
                html += '<span class="jj-label">' + T('gender_preference') + '</span><div>' + radioGroup('jj-job-gender', [{v:'male',l:T('gender_male')},{v:'female',l:T('gender_female')},{v:'any',l:T('gender_any')}], 'any') + '</div>';
                html += '<span class="jj-label">' + T('cooperation_type') + '</span><div id="jj-job-types" class="jj-check-group"></div>';
                html += '<div class="jj-wizard-nav"><button type="button" class="jj-btn jj-btn-primary-wide" data-jobform-next="1">' + T('continue') + '</button></div></div><div class="jj-resume-step jj-wizard-hidden" data-resume-step="1">';
                html += '<span class="jj-label">' + T('headcount_needed') + '</span><input class="jj-input" type="number" min="1" id="jj-job-headcount">';
                html += '<span class="jj-label">' + T('experience_required') + '</span><input class="jj-input" id="jj-job-experience" placeholder="' + T('experience_ph_example') + '">';
                html += '<span class="jj-label">' + T('expected_date') + '</span>' + jjDateFieldRowHTML('jj-job-expected-date') + '';
                html += '<span class="jj-label">' + T('duration_type_label') + '</span><div>' + radioGroup('jj-duration-type', [{v:'fixed',l:T('duration_fixed')},{v:'unlimited',l:T('duration_unlimited')}], 'unlimited') + '</div>';
                html += '<div id="jj-end-date-wrap" style="display:none;"><span class="jj-label">' + T('end_date_label') + '</span>' + jjDateFieldRowHTML('jj-job-end-date') + '</div>';
                html += '<div class="jj-field-row"><div><span class="jj-label">' + T('hours_per_day') + '</span><input class="jj-input" type="number" id="jj-job-hours"></div>'
                      + '<div><span class="jj-label">' + T('shift_start') + '</span><input class="jj-input" type="time" id="jj-job-shift-start"></div></div>';
                html += '<span class="jj-label">' + T('shift_end') + '</span><input class="jj-input" type="time" id="jj-job-shift-end">';
                html += '<span class="jj-label">' + T('weekly_off') + '</span><div>' + radioGroup('jj-weekly-off', [{v:'has',l:T('has')},{v:'none',l:T('none_opt')},{v:'optional',l:T('optional')}], 'has') + '</div>';
                html += '<span class="jj-label">' + T('work_days_label') + '</span><div id="jj-job-workdays" class="jj-check-group"></div>';
                html += '<span class="jj-label">' + T('has_leave_label') + '</span><div>' + radioGroup('jj-has-leave', [{v:'has',l:T('has')},{v:'none',l:T('none_opt')}], 'none') + '</div>';
                html += '<div id="jj-leave-detail" style="display:none;" class="jj-field-row"><div><span class="jj-label">' + T('leave_every_days_label') + '</span><input class="jj-input" type="number" min="1" id="jj-job-leave-work-days"></div>'
                      + '<div><span class="jj-label">' + T('leave_days_label') + '</span><input class="jj-input" type="number" min="1" id="jj-job-leave-days"></div></div>';
                html += '<div id="jj-work-type-domestic"><span class="jj-label">' + T('work_type') + '</span><select class="jj-input" id="jj-job-work-type-d">' + jjMetaOptions.work_types_domestic.map(function (t) { return '<option value="' + t + '">' + t + '</option>'; }).join('') + '</select></div>';
                html += '<div id="jj-work-type-foreign" style="display:none;"><span class="jj-label">' + T('work_type') + '</span><select class="jj-input" id="jj-job-work-type-f">' + jjMetaOptions.work_types_foreign.map(function (t) { return '<option value="' + t + '">' + t + '</option>'; }).join('') + '</select></div>';
                html += '<div class="jj-field-row"><div><span class="jj-label">' + T('work_amount') + '</span><input class="jj-input" id="jj-job-work-amount"></div>'
                      + '<div><span class="jj-label">' + T('approx_duration') + '</span><input class="jj-input" id="jj-job-work-duration"></div></div>';
                html += '<div id="jj-wage-domestic"><span class="jj-label">' + T('wage_type') + '</span><div>' + radioGroup('jj-wage-type-d', [{v:'روزانه',l:T('daily')},{v:'ماهانه',l:T('monthly')}], 'روزانه') + '</div></div>';
                html += '<div class="jj-field-row"><div><span class="jj-label">' + T('wage_amount') + '</span><input class="jj-input" type="number" id="jj-job-wage-amount"></div>'
                      + '<div><span class="jj-label">' + T('currency') + '</span><input class="jj-input" id="jj-job-wage-currency" readonly></div></div>';
                html += '<span class="jj-label">' + T('wage_calc_type_label') + '</span><select class="jj-input" id="jj-job-wage-calc-type">' + jjMetaOptions.wage_calc_types.map(function (t) { return '<option value="' + t + '">' + t + '</option>'; }).join('') + '</select>';
                html += '<div id="jj-wage-calc-other-wrap" style="display:none;"><span class="jj-label">' + T('wage_calc_other_note_label') + '</span><input class="jj-input" id="jj-job-wage-calc-other"></div>';
                html += '<span class="jj-label">' + T('settlement_method') + '</span><input class="jj-input" id="jj-job-settlement">';
                html += '<span class="jj-label">' + T('payment_timing_label') + '</span><input class="jj-input" id="jj-job-payment-timing">';
                html += '<span class="jj-label">' + T('advance_has_label') + '</span><div>' + radioGroup('jj-has-advance', [{v:'has',l:T('has')},{v:'none',l:T('none_opt')}], 'none') + '</div>';
                html += '<div id="jj-advance-detail" style="display:none;"><span class="jj-label">' + T('advance_note_label') + '</span><input class="jj-input" id="jj-job-advance"></div>';
                html += '<span class="jj-label">' + T('dormitory') + '</span><div>' + radioGroup('jj-dormitory', [{v:'has',l:T('has')},{v:'none',l:T('none_opt')}], 'none') + '</div>';
                html += '<span class="jj-label">' + T('food') + '</span><div>' + radioGroup('jj-food', [{v:'has',l:T('has')},{v:'none',l:T('none_opt')}], 'none') + '</div>';
                html += '<div id="jj-food-has" style="display:none;">' + ['breakfast','lunch','dinner','snack_morning','snack_afternoon'].map(function(k,i){var l=[T('breakfast'),T('lunch'),T('dinner'),T('food_snack_morning'),T('food_snack_afternoon')][i];return '<label style="margin-left:12px;font-size:13px;"><input type="checkbox" id="jj-food-'+k+'"> '+l+'</label>';}).join('') + '</div>';
                html += '<div id="jj-food-none" style="display:none;"><span class="jj-label">' + T('food_allowance') + '</span><div>' + radioGroup('jj-food-allowance', [{v:'has',l:T('has')},{v:'none',l:T('none_opt')}], 'none') + '</div></div>';
                html += '<span class="jj-label">' + T('snack') + '</span><div>' + radioGroup('jj-snack', [{v:'has',l:T('has')},{v:'none',l:T('none_opt')}], 'none') + '</div>';
                html += '<span class="jj-label">' + T('work_tools') + '</span><div>' + radioGroup('jj-tools', [{v:'has',l:T('has')},{v:'none',l:T('none_opt')}], 'none') + '</div>';
                html += '<span class="jj-label">' + T('transport') + '</span><div>' + radioGroup('jj-transport', [{v:'has',l:T('has')},{v:'none',l:T('none_opt')},{v:'same_location',l:T('same_location')}], 'none') + '</div>';
                var jjRespOptions = [{v:'employer',l:T('role_employer')},{v:'team',l:T('resp_team_label')},{v:'unagreed',l:T('resp_unagreed')}];
                html += '<span class="jj-label">' + T('housing_label') + '</span><div>' + radioGroup('jj-resp-housing', jjRespOptions, 'unagreed') + '</div>';
                html += '<span class="jj-label">' + T('insurance_label') + '</span><div>' + radioGroup('jj-resp-insurance', jjRespOptions, 'unagreed') + '</div>';
                html += '<span class="jj-label">' + T('food') + '</span><div>' + radioGroup('jj-resp-food', jjRespOptions, 'unagreed') + '</div>';
                html += '<span class="jj-label">' + T('commute_label') + '</span><div>' + radioGroup('jj-resp-commute', jjRespOptions, 'unagreed') + '</div>';
                html += '<span class="jj-label">' + T('team_also_accepted_label') + '</span><div>' + radioGroup('jj-team-accepted', [{v:'yes',l:T('yes_word')},{v:'no',l:T('no_word')}], 'no') + '</div>';
                html += '<div id="jj-visa-wrap" style="display:none;"><span class="jj-label">' + T('visa_responsibility_label') + '</span><div>' + radioGroup('jj-visa-resp', [{v:'employer',l:T('role_employer')},{v:'jobseeker',l:T('user_role_jobseeker')},{v:'agreement',l:T('visa_agreement_opt')}], 'employer') + '</div>'
                      + '<div id="jj-visa-note-wrap" style="display:none;"><textarea class="jj-input" id="jj-job-visa-note" rows="2" placeholder="' + T('visa_note_ph') + '"></textarea></div></div>';
                html += '<div class="jj-wizard-nav"><button type="button" class="jj-btn jj-btn-secondary" data-jobform-prev="0">' + T('prev_step_btn') + '</button><button type="button" class="jj-btn jj-btn-primary-wide" data-jobform-next="2">' + T('continue') + '</button></div></div><div class="jj-resume-step jj-wizard-hidden" data-resume-step="2">';
                html += '<span class="jj-label">' + T('additional_description') + '</span><textarea class="jj-input" id="jj-job-desc" rows="4" maxlength="2000"></textarea>';
                html += '<span class="jj-label">' + T('responsibilities') + '</span><textarea class="jj-input" id="jj-job-responsibilities" rows="4" maxlength="2000" placeholder="' + T('one_item_per_line_ph') + '"></textarea>';
                html += '<div class="jj-field-row"><div><span class="jj-label">' + T('application_deadline') + '</span>' + jjDateFieldRowHTML('jj-job-deadline') + '</div>'
                      + '<div><span class="jj-label">' + T('resume_email') + '</span><input class="jj-input" type="email" id="jj-job-email"></div></div>';
                html += '<span class="jj-label">' + T('exact_work_address') + '</span><input class="jj-input" id="jj-job-address">';
                html += '<div class="jj-wizard-nav"><button type="button" class="jj-btn jj-btn-secondary" data-jobform-prev="1">' + T('prev_step_btn') + '</button><button type="button" class="jj-btn jj-btn-primary-wide" data-jobform-next="3">' + T('continue') + '</button></div></div><div class="jj-resume-step jj-wizard-hidden" data-resume-step="3">';
                html += '<p class="jj-sub">' + T('review_before_publish_note') + '</p><div class="jj-wizard-nav" style="margin-bottom:14px;"><button type="button" class="jj-btn jj-btn-secondary" data-jobform-prev="2">' + T('prev_step_btn') + '</button></div>';
                html += '<button class="jj-btn" id="jj-btn-job-submit">' + T('submit_continue_to_media') + '</button>';
                html += '</div>';
                $('#jj-job-form-fields').innerHTML = html;

                wireJobFormLogic();
                wireJalaliDateSelects(document.getElementById('jj-job-form-fields'));
            }
            function wireJobFormLogic() {
                var countrySel = $('#jj-job-country');
                function onCountryChange() {
                    var isIran = (countrySel.value === 'ایران');
                    var provSel = $('#jj-job-province');
                    var provinces = isIran ? Object.keys(jjMetaOptions.iran_provinces) : (jjMetaOptions.country_provinces[countrySel.value] || []);
                    provSel.innerHTML = provinces.map(function (p) { return '<option value="' + p + '">' + p + '</option>'; }).join('');
                    $('#jj-work-type-domestic').style.display = isIran ? '' : 'none';
                    $('#jj-work-type-foreign').style.display = isIran ? 'none' : '';
                    $('#jj-wage-domestic').style.display = isIran ? '' : 'none';
                    $('#jj-job-wage-currency').value = (jjMetaOptions.country_currency && jjMetaOptions.country_currency[countrySel.value]) || '';
                    $('#jj-visa-wrap').style.display = isIran ? 'none' : '';
                }
                countrySel.addEventListener('change', onCountryChange);
                onCountryChange();

                document.getElementsByName('jj-food').forEach(function (r) {
                    r.addEventListener('change', function () {
                        var has = document.querySelector('input[name=jj-food]:checked').value === 'has';
                        $('#jj-food-has').style.display = has ? '' : 'none';
                        $('#jj-food-none').style.display = has ? 'none' : '';
                    });
                });

                document.getElementsByName('jj-duration-type').forEach(function (r) {
                    r.addEventListener('change', function () {
                        $('#jj-end-date-wrap').style.display = (document.querySelector('input[name=jj-duration-type]:checked').value === 'fixed') ? '' : 'none';
                    });
                });
                document.getElementsByName('jj-has-leave').forEach(function (r) {
                    r.addEventListener('change', function () {
                        $('#jj-leave-detail').style.display = (document.querySelector('input[name=jj-has-leave]:checked').value === 'has') ? '' : 'none';
                    });
                });
                document.getElementsByName('jj-has-advance').forEach(function (r) {
                    r.addEventListener('change', function () {
                        $('#jj-advance-detail').style.display = (document.querySelector('input[name=jj-has-advance]:checked').value === 'has') ? '' : 'none';
                    });
                });
                document.getElementsByName('jj-visa-resp').forEach(function (r) {
                    r.addEventListener('change', function () {
                        $('#jj-visa-note-wrap').style.display = (document.querySelector('input[name=jj-visa-resp]:checked').value === 'agreement') ? '' : 'none';
                    });
                });
                $('#jj-job-wage-calc-type').addEventListener('change', function () {
                    $('#jj-wage-calc-other-wrap').style.display = (this.value === 'سایر موارد') ? '' : 'none';
                });

                renderProfessionDropdownSetup();
      renderJobTitleDropdownSetup();
                var jjCooperationTypes = [
                    { v: 'full_time', l: T('work_type_full_time') },
                    { v: 'part_time', l: T('work_type_part_time') },
                    { v: 'remote', l: T('work_type_remote') },
                    { v: 'project', l: T('work_type_project') }
                ];
                $('#jj-job-types').innerHTML = jjCooperationTypes.map(function (t) {
                    return '<label class="jj-check-chip"><input type="checkbox" value="' + t.v + '" class="jj-job-type-cb"> ' + t.l + '</label>';
                }).join('');
                $('#jj-job-workdays').innerHTML = jjMetaOptions.week_days.map(function (d) {
                    return '<label class="jj-check-chip"><input type="checkbox" value="' + d + '" class="jj-job-workday-cb"> ' + d + '</label>';
                }).join('');

                $('#jj-btn-job-submit').addEventListener('click', submitJobForm);
            }

            var currentJobId = null;
            var editingJobId = null;

            /** بازکردن فرم آگهی (همان فرم ساخت آگهی) در حالت ویرایش، برای آگهیِ «نیاز به اصلاح». */
            function openJobEditForm(jobId) {
                function open() {
                    editingJobId = jobId;
                    $('#jj-job-media-wrap').style.display = 'none';
                    $('#jj-job-form-wrap').style.display = '';
                    renderJobFormFields();
                    fetch(REST + 'jobs/' + jobId, { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res && res.success && res.job) { populateJobFormForEdit(res.job); }
                        });
                    $('#jj-job-form-wrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                if (jjMetaOptions) { open(); return; }
                fetch(REST + 'job-meta-options').then(function (r) { return r.json(); }).then(function (opts) {
                    jjMetaOptions = opts;
                    open();
                });
            }

            /** پرکردن فیلدهای فرم آگهی با مقادیر فعلیِ آگهی، برای ویرایش. */
            function populateJobFormForEdit(job) {
                function setVal(id, v) { var el = document.getElementById(id); if (el && v !== undefined && v !== null) el.value = v; }
                function setChecked(name, val) {
                    var el = document.querySelector('input[name="' + name + '"][value="' + val + '"]');
                    if (el) el.checked = true;
                }
                setVal('jj-job-title', job.title);
                setVal('jj-job-desc', job.description);
                setVal('jj-job-responsibilities', job.responsibilities);
                setVal('jj-job-country', job.country);
                $('#jj-job-country').dispatchEvent(new Event('change'));
                setVal('jj-job-province', job.province);
                setVal('jj-job-city', job.city);
                setVal('jj-job-location-name', job.location_name);
                setVal('jj-job-skill', job.skill_level);
                if (job.profession) {
                    $('#jj-job-profession-input').value = job.profession;
                    if (job.profession_id) $('#jj-job-profession-input').setAttribute('data-selected-id', job.profession_id);
                }
                setChecked('jj-job-gender', job.gender_preference || 'any');
                (job.type_ids || []).forEach(function (v) {
                    var cb = document.querySelector('.jj-job-type-cb[value="' + v + '"]');
                    if (cb) cb.checked = true;
                });
                setVal('jj-job-headcount', job.headcount);
                setVal('jj-job-experience', job.experience_required);
                if (job.expected_date) $('#jj-job-expected-date').value = job.expected_date;
                setVal('jj-job-hours', job.hours_per_day);
                setVal('jj-job-shift-start', job.shift_start);
                setVal('jj-job-shift-end', job.shift_end);
                setChecked('jj-weekly-off', job.weekly_off || 'has');
                setChecked('jj-duration-type', job.duration_type || 'unlimited');
                document.querySelectorAll('input[name="jj-duration-type"]').forEach(function (r) { r.dispatchEvent(new Event('change')); });
                if (job.end_date) $('#jj-job-end-date').value = job.end_date;
                (job.work_days || []).forEach(function (v) {
                    var cb = document.querySelector('.jj-job-workday-cb[value="' + v + '"]');
                    if (cb) cb.checked = true;
                });
                setChecked('jj-has-leave', job.has_leave || 'none');
                document.querySelectorAll('input[name="jj-has-leave"]').forEach(function (r) { r.dispatchEvent(new Event('change')); });
                setVal('jj-job-leave-work-days', job.leave_work_days);
                setVal('jj-job-leave-days', job.leave_days);
                setVal('jj-job-work-type-d', job.work_type);
                setVal('jj-job-work-type-f', job.work_type);
                setVal('jj-job-work-amount', job.work_amount);
                setVal('jj-job-work-duration', job.work_duration);
                setChecked('jj-wage-type-d', job.wage_type || 'روزانه');
                setVal('jj-job-wage-amount', job.wage_amount);
                setVal('jj-job-wage-calc-type', job.wage_calc_type);
                $('#jj-job-wage-calc-type').dispatchEvent(new Event('change'));
                setVal('jj-job-wage-calc-other', job.wage_calc_other_note);
                setVal('jj-job-settlement', job.settlement_method);
                setVal('jj-job-payment-timing', job.payment_timing);
                setChecked('jj-has-advance', job.has_advance || 'none');
                document.querySelectorAll('input[name="jj-has-advance"]').forEach(function (r) { r.dispatchEvent(new Event('change')); });
                setVal('jj-job-advance', job.advance_payment_method);
                setChecked('jj-dormitory', job.has_dormitory || 'none');
                setChecked('jj-food', job.has_food || 'none');
                if ($('#jj-food-breakfast')) $('#jj-food-breakfast').checked = !!job.food_breakfast;
                if ($('#jj-food-lunch')) $('#jj-food-lunch').checked = !!job.food_lunch;
                if ($('#jj-food-dinner')) $('#jj-food-dinner').checked = !!job.food_dinner;
                if ($('#jj-food-snack_morning')) $('#jj-food-snack_morning').checked = !!job.food_snack_morning;
                if ($('#jj-food-snack_afternoon')) $('#jj-food-snack_afternoon').checked = !!job.food_snack_afternoon;
                document.querySelectorAll('input[name="jj-food"]').forEach(function (r) { r.dispatchEvent(new Event('change')); });
                setChecked('jj-food-allowance', job.food_allowance || 'none');
                setChecked('jj-snack', job.has_snack || 'none');
                setChecked('jj-tools', job.has_tools || 'none');
                setChecked('jj-transport', job.transport_option || 'none');
                setChecked('jj-resp-housing', job.housing_responsibility || 'unagreed');
                setChecked('jj-resp-insurance', job.insurance_responsibility || 'unagreed');
                setChecked('jj-resp-food', job.food_responsibility || 'unagreed');
                setChecked('jj-resp-commute', job.commute_responsibility || 'unagreed');
                setChecked('jj-team-accepted', job.team_accepted || 'no');
                setChecked('jj-visa-resp', job.visa_responsibility || 'employer');
                document.querySelectorAll('input[name="jj-visa-resp"]').forEach(function (r) { r.dispatchEvent(new Event('change')); });
                setVal('jj-job-visa-note', job.visa_responsibility_note);
                if (job.deadline) $('#jj-job-deadline').value = job.deadline;
                setVal('jj-job-email', job.application_email);
                setVal('jj-job-address', job.address);
                $('#jj-btn-job-submit').textContent = 'ذخیره و ارسال دوباره برای بررسی';
            }

            function submitJobForm() {
                setMsg('#jj-msg-jobs', '', false);
                var title = $('#jj-job-title').value.trim();
                var desc = $('#jj-job-desc').value.trim();
                var responsibilities = $('#jj-job-responsibilities').value.trim();
                if (!title) { setMsg('#jj-msg-jobs', T('job_name_required'), true); return; }
                var typeIds = Array.prototype.slice.call(document.querySelectorAll('.jj-job-type-cb:checked')).map(function (el) { return el.value; });
                var workDays = Array.prototype.slice.call(document.querySelectorAll('.jj-job-workday-cb:checked')).map(function (el) { return el.value; });
                var profId = parseInt($('#jj-job-profession-input').getAttribute('data-selected-id'), 10) || 0;
                var profName = $('#jj-job-profession-input').value.trim();
                if (profName && !profId) { setMsg('#jj-msg-jobs', T('profession_not_in_list'), true); return; }
                var isIran = $('#jj-job-country').value === 'ایران';
                var checkedVal = function (name) { var el = document.querySelector('input[name=' + name + ']:checked'); return el ? el.value : ''; };
                var body = {
                    title: title, description: desc,
                    country: $('#jj-job-country').value, province: $('#jj-job-province').value, city: $('#jj-job-city').value.trim(),
                    location_name: $('#jj-job-location-name').value.trim(),
                    gender_preference: checkedVal('jj-job-gender') || 'any',
                    skill_level: $('#jj-job-skill').value,
                    profession_id: profId, type_ids: typeIds,
                    headcount: $('#jj-job-headcount').value,
                    hours_per_day: $('#jj-job-hours').value, shift_start: $('#jj-job-shift-start').value, shift_end: $('#jj-job-shift-end').value,
                    weekly_off: checkedVal('jj-weekly-off'),
                    work_days: workDays,
                    duration_type: checkedVal('jj-duration-type') || 'unlimited',
                    end_date: $('#jj-job-end-date').value,
                    has_leave: checkedVal('jj-has-leave'),
                    leave_work_days: $('#jj-job-leave-work-days').value, leave_days: $('#jj-job-leave-days').value,
                    work_type: isIran ? $('#jj-job-work-type-d').value : $('#jj-job-work-type-f').value,
                    work_amount: $('#jj-job-work-amount').value.trim(), work_duration: $('#jj-job-work-duration').value.trim(),
                    wage_type: isIran ? checkedVal('jj-wage-type-d') : '',
                    wage_amount: $('#jj-job-wage-amount').value, wage_currency: $('#jj-job-wage-currency').value,
                    wage_calc_type: $('#jj-job-wage-calc-type').value, wage_calc_other_note: $('#jj-job-wage-calc-other').value.trim(),
                    settlement_method: $('#jj-job-settlement').value.trim(),
                    payment_timing: $('#jj-job-payment-timing').value.trim(),
                    has_advance: checkedVal('jj-has-advance'), advance_payment_method: $('#jj-job-advance').value.trim(),
                    has_dormitory: checkedVal('jj-dormitory'),
                    has_food: checkedVal('jj-food'),
                    food_breakfast: $('#jj-food-breakfast').checked, food_lunch: $('#jj-food-lunch').checked, food_dinner: $('#jj-food-dinner').checked,
                    food_snack_morning: $('#jj-food-snack_morning').checked, food_snack_afternoon: $('#jj-food-snack_afternoon').checked,
                    food_allowance: checkedVal('jj-food-allowance'),
                    has_snack: checkedVal('jj-snack'), has_tools: checkedVal('jj-tools'),
                    transport_option: checkedVal('jj-transport'),
                    housing_responsibility: checkedVal('jj-resp-housing'),
                    insurance_responsibility: checkedVal('jj-resp-insurance'),
                    food_responsibility: checkedVal('jj-resp-food'),
                    commute_responsibility: checkedVal('jj-resp-commute'),
                    team_accepted: checkedVal('jj-team-accepted'),
                    visa_responsibility: checkedVal('jj-visa-resp'), visa_responsibility_note: $('#jj-job-visa-note').value.trim(),
                    deadline: $('#jj-job-deadline').value, application_email: $('#jj-job-email').value.trim(),
                    address: $('#jj-job-address').value.trim(),
                    experience_required: $('#jj-job-experience').value.trim(),
                    expected_date: $('#jj-job-expected-date').value,
                    responsibilities: responsibilities,
                };
                $('#jj-btn-job-submit').disabled = true;
                if (editingJobId) {
                    api('jobs/' + editingJobId + '/update', body, true).then(function (res) {
                        $('#jj-btn-job-submit').disabled = false;
                        if (!res.ok) { setMsg('#jj-msg-jobs', (res.data && res.data.message) || T('job_create_error'), true); return; }
                        editingJobId = null;
                        $('#jj-job-form-wrap').style.display = 'none';
                        setMsg('#jj-msg-jobs', 'آگهی ویرایش و برای بررسی دوباره ارسال شد.', false);
                        refreshMyJobs();
                    }).catch(function () { $('#jj-btn-job-submit').disabled = false; setMsg('#jj-msg-jobs', T('error_network'), true); });
                    return;
                }
                api('jobs/create', body, true).then(function (res) {
                    $('#jj-btn-job-submit').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-jobs', (res.data && res.data.message) || T('job_create_error'), true); return; }
                    currentJobId = res.data.job_id;
                    $('#jj-job-form-wrap').style.display = 'none';
                    $('#jj-job-media-wrap').style.display = '';
                    $('#jj-media-workshop-list').innerHTML = ''; $('#jj-media-dormitory-list').innerHTML = '';
                    setMsg('#jj-msg-jobs', '', false);
                }).catch(function () { $('#jj-btn-job-submit').disabled = false; setMsg('#jj-msg-jobs', T('error_network'), true); });
            }

            $('#jj-btn-request-agency').addEventListener('click', function () {
                var wrap = $('#jj-agency-list-wrap');
                if (wrap.style.display !== 'none') { wrap.style.display = 'none'; return; }
                wrap.style.display = '';
                var list = $('#jj-agency-list');
                list.innerHTML = '<li>' + T('loading') + '</li>';
                fetch(REST + 'agencies/list').then(function (r) { return r.json(); }).then(function (res) {
                    if (!res.agencies || !res.agencies.length) { list.innerHTML = '<li>' + T('no_approved_agency_found') + '</li>'; return; }
                    list.innerHTML = '';
                    res.agencies.forEach(function (a) {
                        var li = document.createElement('li');
                        var span = document.createElement('span'); span.textContent = a.agency_name + ' — ' + a.phone;
                        var btn = document.createElement('button'); btn.className = 'jj-btn-link'; btn.textContent = T('request_cooperation_btn');
                        btn.addEventListener('click', function () {
                            btn.disabled = true;
                            api('agency-contracts/request', { agency_id: a.user_id }, true).then(function (res2) {
                                if (!res2.ok) { setMsg('#jj-msg-agency', (res2.data && res2.data.message) || T('error_generic'), true); btn.disabled = false; return; }
                                setMsg('#jj-msg-agency', T('request_registered_check_contracts'), false);
                                btn.textContent = T('sent_with_check');
                            });
                        });
                        li.appendChild(span); li.appendChild(btn);
                        list.appendChild(li);
                    });
                });
            });
            $('#jj-btn-new-job').addEventListener('click', function () {
                var wrap = $('#jj-job-form-wrap');
                editingJobId = null;
                if (wrap.style.display !== 'none') { wrap.style.display = 'none'; return; }
                $('#jj-job-media-wrap').style.display = 'none';
                wrap.style.display = '';
                if (jjMetaOptions) { renderJobFormFields(); return; }
                fetch(REST + 'job-meta-options').then(function (r) { return r.json(); }).then(function (opts) {
                    jjMetaOptions = opts;
                    renderJobFormFields();
                });
            });

            function addMediaListItem(listEl, name, type) {
                var li = document.createElement('li');
                li.textContent = (type === 'photo' ? '🖼️ ' : '🎬 ') + name;
                listEl.appendChild(li);
            }
            function wireMediaGroup(group) {
                var input = $('#jj-media-' + group + '-input');
                var listEl = $('#jj-media-' + group + '-list');
                $('#jj-btn-add-' + group + '-media').addEventListener('click', function () { input.click(); });
                input.addEventListener('change', function () {
                    var file = this.files[0];
                    if (!file || !currentJobId) return;
                    var ext = (file.name.split('.').pop() || '').toLowerCase();
                    var isVideo = ['mp4', 'mov', 'avi', 'mkv', 'webm'].indexOf(ext) !== -1;
                    var maxSize = isVideo ? (25 * 1024 * 1024) : (1 * 1024 * 1024);
                    if (file.size > maxSize) {
                        setMsg('#jj-msg-media', isVideo ? T('video_size_25mb_limit') : T('photo_size_1mb_limit'), true);
                        input.value = '';
                        return;
                    }
                    var fd = new FormData();
                    fd.append('file', file);
                    fd.append('group', group);
                    setMsg('#jj-msg-media', T('loading'), false);
                    apiUpload('jobs/' + currentJobId + '/media', fd).then(function (res) {
                        if (!res.ok) { setMsg('#jj-msg-media', (res.data && res.data.message) || T('upload_error_generic'), true); return; }
                        setMsg('#jj-msg-media', '', false);
                        addMediaListItem(listEl, file.name, res.data.media_type);
                        input.value = '';
                    }).catch(function () { setMsg('#jj-msg-media', T('error_network'), true); });
                });
            }
            wireMediaGroup('workshop');
            wireMediaGroup('dormitory');
            $('#jj-btn-media-done').addEventListener('click', function () {
                $('#jj-job-media-wrap').style.display = 'none';
                setMsg('#jj-msg-jobs', T('job_posted_for_review'), false);
                refreshMyJobs();
            });

            // ---------- مرور آگهی‌ها و درخواست شغلی (کارجو) ----------
            function jobDetailHTML(j) {
                var yn = function (v) { return v === 'has' ? T('has') : (v === 'none' ? T('none_opt') : (v === 'optional' ? T('optional') : (v === 'same_location' ? T('same_location') : '—'))); };
                var rows = [];
                rows.push([T('work_location_label'), escHtml([j.country, j.province, j.city].filter(Boolean).join('، '))]);
                var genderMap = { male: T('gender_male'), female: T('gender_female'), any: T('gender_any') };
                rows.push([T('gender_preference'), genderMap[j.gender_preference] || T('gender_any')]);
                if (j.skill_level) rows.push([T('skill_level'), j.skill_level]);
                if (j.headcount) rows.push([T('headcount_needed'), j.headcount]);
                if (j.hours_per_day) rows.push([T('hours_per_day_label'), j.hours_per_day + ' ' + T('hours_per_day_suffix') + (j.shift_start ? (' (' + j.shift_start + ' ' + T('acct_until') + ' ' + j.shift_end + ')') : '')]);
                rows.push([T('weekly_off'), yn(j.weekly_off)]);
                if (j.work_type) rows.push([T('work_type'), escHtml(j.work_type)]);
                if (j.work_amount || j.work_duration) rows.push([T('work_amount_duration_label'), escHtml([j.work_amount, j.work_duration].filter(Boolean).join(' / '))]);
                if (j.wage_amount) rows.push([T('wage_label'), (j.wage_type ? escHtml(j.wage_type) + ' ' : '') + Number(j.wage_amount).toLocaleString('fa-IR') + ' ' + escHtml(j.wage_currency || '')]);
                if (j.settlement_method) rows.push([T('settlement_method'), escHtml(j.settlement_method)]);
                if (j.advance_payment_method) rows.push([T('advance_payment'), escHtml(j.advance_payment_method)]);
                rows.push([T('dormitory'), yn(j.has_dormitory)]);
                var foodStr = yn(j.has_food);
                if (j.has_food === 'has') {
                    var meals = [j.food_breakfast ? T('breakfast') : '', j.food_lunch ? T('lunch') : '', j.food_dinner ? T('dinner') : ''].filter(Boolean);
                    if (meals.length) foodStr += ' (' + meals.join('، ') + ')';
                } else if (j.has_food === 'none') {
                    foodStr += ' ' + T('food_allowance_dash_prefix') + ' ' + yn(j.food_allowance);
                }
                rows.push([T('food'), foodStr]);
                rows.push([T('snack'), yn(j.has_snack)]);
                rows.push([T('work_tools'), yn(j.has_tools)]);
                rows.push([T('transport_short_label'), yn(j.transport_option)]);
                if (j.deadline) rows.push([T('application_deadline'), j.deadline]);
                if (j.commission && j.commission.enabled) {
                    rows.push([T('agency_commission_label'), T('commission_has_your_share_prefix') + ' ' + Number(j.commission.jobseeker_share_toman).toLocaleString('fa-IR') + ' ' + T('toman')]);
                } else {
                    rows.push([T('agency_commission_label'), T('none_opt')]);
                }

                var html = '<div style="font-size:12.5px;line-height:1.9;">';
                rows.forEach(function (r) { html += '<div><strong>' + r[0] + ':</strong> ' + r[1] + '</div>'; });
                html += '</div>';
                if (j.description) html += '<p style="font-size:12.5px;color:#444;margin-top:8px;white-space:pre-wrap;">' + escHtml(j.description) + '</p>';
                ['workshop_media', 'dormitory_media'].forEach(function (key) {
                    var items = j[key] || [];
                    if (!items.length) return;
                    html += '<div style="margin-top:8px;"><strong style="font-size:12.5px;">' + (key === 'workshop_media' ? T('workshop_media_label') : T('dormitory_media_label')) + ':</strong><div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px;">';
                    items.forEach(function (m) {
                        if (m.type === 'photo') html += '<img src="' + m.url + '" style="width:70px;height:70px;object-fit:cover;border-radius:6px;">';
                        else html += '<video src="' + m.url + '" controls style="width:120px;height:70px;border-radius:6px;"></video>';
                    });
                    html += '</div></div>';
                });
                return html;
            }
            var browseJobsAsTeam = false;
            function checkMyTeamDues() {
                var box = $('#jj-team-dues-box');
                box.style.display = 'none';
                fetch(REST + 'my-team-dues', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); }).then(function (res) {
                        if (!res.dues || !res.dues.length) return;
                        box.style.display = '';
                        box.innerHTML = res.dues.map(function (d) {
                            return '<div style="display:flex;justify-content:space-between;align-items:center;">'
                                + '<span>' + T('team_dues_share_prefix') + ' (' + d.contract_number + '): ' + Number(d.amount_toman).toLocaleString('fa-IR') + ' ' + T('toman') + '</span>'
                                + '<button class="jj-btn" style="width:auto;padding:6px 14px;" data-cid="' + d.contract_id + '">' + T('pay_now') + '</button></div>';
                        }).join('');
                        box.querySelectorAll('button[data-cid]').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var btnEl = this;
                                var cid = btnEl.getAttribute('data-cid');
                                btnEl.disabled = true;
                                api('contracts/' + cid + '/pay-member-share', {}, true).then(function (r) {
                                    if (!r.ok) { btnEl.disabled = false; alert((r.data && r.data.message) || T('error_generic')); return; }
                                    window.location.href = r.data.payment_url;
                                });
                            });
                        });
                    });
            }
            // ---------- تغییر نوع عضویت (از داشبورد فعلی) ----------
            // اعضای تیم (نه فقط سرپرست) هم باید بتوانند وارد چت اکیپ شوند؛
// این تابع دکمه‌ی چت اکیپ را در داشبورد کارجو نشان/پنهان می‌کند.
function checkMyTeamChatEntry() {
    var btn = $('#jj-btn-team-chat-js');
    if (!btn) return;
    btn.style.display = 'none';
    fetch(REST + 'chat/my-team', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); }).then(function (res) {
            if (res && res.team) { btn.style.display = ''; }
        }).catch(function () {});
}
if ($('#jj-btn-team-chat-js')) {
    $('#jj-btn-team-chat-js').addEventListener('click', function () { if (window.jjChatOpen) window.jjChatOpen('team'); });
}
var roleChangeReturnStep = null;
            var currentRoleLabels = { jobseeker: T('role_jobseeker'), team_leader: T('role_team_leader'), employer: T('role_employer'), agency: T('role_agency') };
            function showRoleChangeStep(returnStep, currentType) {
                roleChangeReturnStep = returnStep;
                showStep('role');
                $('#jj-role-back-row').style.display = '';
                if (currentType) {
                    $('#jj-role-current-note').style.display = '';
                    $('#jj-role-current-note').textContent = T('current_type_prefix') + ' ' + (currentRoleLabels[currentType] || currentType) + ' — ' + T('role_change_note_suffix');
                } else {
                    $('#jj-role-current-note').style.display = 'none';
                }
            }
            $('#jj-btn-role-back').addEventListener('click', function () {
                $('#jj-role-back-row').style.display = 'none';
                $('#jj-role-current-note').style.display = 'none';
                if (roleChangeReturnStep === 'team') showStep('team');
                else if (roleChangeReturnStep === 'jobs') { showStep('jobs'); refreshMyJobs(); }
                else showStep('browse-jobs');
            });
            $('#jj-btn-change-role-team').addEventListener('click', function () { showRoleChangeStep('team', 'team_leader'); });
            $('#jj-btn-change-role-emp').addEventListener('click', function () {
                var isAgency = (state.currentRoles || []).indexOf('jj_agency') !== -1;
                showRoleChangeStep('jobs', isAgency ? 'agency' : 'employer');
            });
            $('#jj-btn-change-role-js').addEventListener('click', function () { showRoleChangeStep('browse-jobs', 'jobseeker'); });

            var browseJobsReqId = 0;
            function loadBrowseJobs(q, asTeam) {
                browseJobsAsTeam = !!asTeam;
                showStep('browse-jobs');
                $('#jj-browse-back-row').style.display = browseJobsAsTeam ? '' : 'none';
                $('#jj-browse-normal-actions').style.display = browseJobsAsTeam ? 'none' : '';
                if (!browseJobsAsTeam) { checkMyTeamDues(); checkMyTeamChatEntry(); }
                var list = $('#jj-browse-jobs-list');
                list.innerHTML = '<li>' + T('loading') + '</li>';
                var url = REST + 'jobs/browse' + (q ? '?q=' + encodeURIComponent(q) : '');
                var reqId = ++browseJobsReqId;
                fetch(url).then(function (r) { return r.json(); }).then(function (res) {
                    if (reqId !== browseJobsReqId) return; // پاسخ قدیمی؛ جست‌وجوی جدیدتری در جریان است
                    if (!res.jobs || !res.jobs.length) { list.innerHTML = '<li>' + T('no_jobs_found') + '</li>'; return; }
                    list.innerHTML = '';
                    var __tierLabels = {1: [T('tier_featured'), '#2563eb'], 2: [T('tier_special'), '#7c3aed'], 3: [T('tier_custom'), '#c9a227']};
                    res.jobs.forEach(function (j) {
                        var li = document.createElement('li');
                        li.style.display = 'block';
                        var top = document.createElement('div');
                        top.style.display = 'flex'; top.style.justifyContent = 'space-between'; top.style.cursor = 'pointer';
                        var tierBadge = __tierLabels[j.listing_tier] ? '<span style="background:' + __tierLabels[j.listing_tier][1] + ';color:#fff;font-size:10px;padding:1px 7px;border-radius:9px;margin-inline-start:6px;">' + __tierLabels[j.listing_tier][0] + '</span>' : '';
                        top.innerHTML = '<strong>' + escHtml(j.title) + tierBadge + '</strong><span style="font-size:12px;color:#6b7280;">' + escHtml(j.profession || '') + '</span>';
                        var desc = document.createElement('p');
                        desc.style.cssText = 'font-size:12.5px;color:#555;margin:6px 0;';
                        desc.textContent = j.excerpt + (j.salary ? ' | ' + T('salary_colon') + ' ' + j.salary : '');
                        var detailBox = document.createElement('div');
                        detailBox.style.cssText = 'display:none;background:#f9fafb;border-radius:8px;padding:10px;margin:6px 0;';
                        var loaded = false;
                        top.addEventListener('click', function () {
                            detailBox.style.display = detailBox.style.display === 'none' ? '' : 'none';
                            if (detailBox.style.display !== 'none' && !loaded) {
                                detailBox.innerHTML = T('loading');
                                fetch(REST + 'jobs/' + j.id).then(function (r) { return r.json(); }).then(function (r2) {
                                    detailBox.innerHTML = jobDetailHTML(r2.job);
                                    loaded = true;
                                });
                            }
                        });
                        var btn = document.createElement('button');
                        btn.className = 'jj-btn'; btn.style.width = 'auto'; btn.style.padding = '8px 16px';
                        btn.textContent = browseJobsAsTeam ? T('apply_for_team_btn') : T('apply_for_job_btn');
                        btn.addEventListener('click', function () {
                            btn.disabled = true;
                            api('jobs/' + j.id + '/apply', { as_team: browseJobsAsTeam }, true).then(function (res2) {
                                if (!res2.ok) { setMsg('#jj-msg-browse', (res2.data && res2.data.message) || T('error_occurred'), true); btn.disabled = false; return; }
                                btn.textContent = T('application_registered'); setMsg('#jj-msg-browse', '', false);
                            });
                        });
                        li.appendChild(top); li.appendChild(desc); li.appendChild(detailBox); li.appendChild(btn);
                        list.appendChild(li);
                    });
                }).catch(function () { if (reqId === browseJobsReqId) list.innerHTML = '<li>' + T('error_network') + '</li>'; });
            }
            $('#jj-btn-jobs-search').addEventListener('click', function () { loadBrowseJobs($('#jj-jobs-search').value.trim(), browseJobsAsTeam); });
            $('#jj-btn-team-browse-jobs').addEventListener('click', function () { loadBrowseJobs('', true); });
            $('#jj-btn-browse-back').addEventListener('click', function () { showStep('team'); });
            var jsProfileLoaded = false;
            $('#jj-btn-my-profile').addEventListener('click', function () {
                var wrap = $('#jj-jobseeker-profile-wrap');
                if (wrap.style.display !== 'none') { wrap.style.display = 'none'; return; }
                wrap.style.display = '';
                showResumeStep(0);
                if ($('#jj-js-phone-display')) $('#jj-js-phone-display').value = state.phone || '';
                loadResumeAiBox();
                loadResumeBuilder();
                if (jsProfileLoaded) return;
                fetch(REST + 'profile/jobseeker/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); }).then(function (res) {
                        if (res.profile) {
                            if (res.profile.full_name) $('#jj-js-fullname').value = res.profile.full_name;
                            if (res.profile.gender) $('#jj-js-gender').value = res.profile.gender;
                            if (res.profile.father_name) $('#jj-js-father-name').value = res.profile.father_name;
                            if (res.profile.national_id) $('#jj-js-national-id').value = res.profile.national_id;
                            if (res.profile.nationality) $('#jj-js-nationality').value = res.profile.nationality;
                            if (res.profile.nationality_country) $('#jj-js-nationality-country').value = res.profile.nationality_country;
                            $('#jj-js-nationality').dispatchEvent(new Event('change'));
                            if (res.profile.work_location) $('#jj-js-work-location').value = res.profile.work_location;
                            if (res.profile.address) $('#jj-js-address').value = res.profile.address;
                            (res.profile.skills || []).forEach(function (s, i) { if ($('#jj-js-skill-' + (i + 1))) $('#jj-js-skill-' + (i + 1)).value = s; });
                            var jjWt = res.profile.work_type || [];
                            document.querySelectorAll('#jj-js-work-type-group input[type=checkbox]').forEach(function (cb) { cb.checked = jjWt.indexOf(cb.value) > -1; cb.closest('.jj-check-chip').classList.toggle('jj-checked', cb.checked); });
                            setupDesiredJobsAutocomplete();
                            var desiredIds = res.profile.desired_jobs || [];
                            if (desiredIds.length) {
                                (function () {
                                    function fillDesiredJobs() {
                                        desiredIds.forEach(function (id, i) {
                                            var inp = $('#jj-js-desired-' + (i + 1));
                                            if (!inp) return;
                                            var m = (state.professionsList || []).filter(function (p) { return String(p.id) === String(id); })[0];
                                            if (m) { inp.value = m.name; inp.setAttribute('data-selected-id', m.id); }
                                        });
                                    }
                                    if (state.professionsList) { fillDesiredJobs(); }
                                    else { fetch(REST + 'job-professions').then(function (r) { return r.json(); }).then(function (list) { state.professionsList = list; fillDesiredJobs(); }); }
                                })();
                            }
                            if (res.profile.avatar_url) { $('#jj-js-avatar-preview').src = res.profile.avatar_url; $('#jj-js-avatar-preview').style.display = ''; }
                        }
                        jsProfileLoaded = true;
                        updateResumePreview();
                    });
            });
            $('#jj-btn-js-avatar-upload').addEventListener('click', function () {
                setMsg('#jj-msg-js-avatar', '', false);
                var fileInput = $('#jj-js-avatar-input');
                if (!fileInput.files || !fileInput.files[0]) { setMsg('#jj-msg-js-avatar', T('choose_a_file'), true); return; }
                if (fileInput.files[0].size > 2 * 1024 * 1024) { setMsg('#jj-msg-js-avatar', T('acct_photo_size_limit'), true); return; }
                var fd = new FormData();
                fd.append('file', fileInput.files[0]);
                $('#jj-btn-js-avatar-upload').disabled = true;
                apiUpload('profile/jobseeker/avatar-photo', fd).then(function (res) {
                    $('#jj-btn-js-avatar-upload').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-js-avatar', (res.data && res.data.message) || T('acct_upload_error'), true); return; }
                    $('#jj-js-avatar-preview').src = res.url; $('#jj-js-avatar-preview').style.display = '';
                    setMsg('#jj-msg-js-avatar', T('acct_photo_saved'), false);
                }).catch(function () { $('#jj-btn-js-avatar-upload').disabled = false; setMsg('#jj-msg-js-avatar', T('error_network'), true); });
            });
            $('#jj-js-nationality').addEventListener('change', function () {
                $('#jj-js-nationality-country-wrap').style.display = (this.value === 'other') ? '' : 'none';
            });
            $('#jj-btn-js-profile-save').addEventListener('click', function () {
                setMsg('#jj-msg-js-profile', '', false);
                if (!$('#jj-js-gender').value) { setMsg('#jj-msg-js-profile', T('select_gender_prompt'), true); return; }
                if (!$('#jj-js-nationality').value) { setMsg('#jj-msg-js-profile', T('select_nationality_prompt'), true); return; }
                if (!$('#jj-js-work-location').value) { setMsg('#jj-msg-js-profile', T('select_work_location_prompt'), true); return; }
                var skills = [$('#jj-js-skill-1').value.trim(), $('#jj-js-skill-2').value.trim(), $('#jj-js-skill-3').value.trim()].filter(Boolean);
                var desired_jobs = [1, 2, 3].map(function (i) { var el = $('#jj-js-desired-' + i); return el ? el.getAttribute('data-selected-id') : null; }).filter(Boolean);
                var body = {
                    full_name: $('#jj-js-fullname').value.trim(), father_name: $('#jj-js-father-name').value.trim(),
                    national_id: $('#jj-js-national-id').value.trim(),
                    gender: $('#jj-js-gender').value,
                    nationality: $('#jj-js-nationality').value, nationality_country: $('#jj-js-nationality-country').value.trim(),
                    work_location: $('#jj-js-work-location').value,
                    address: $('#jj-js-address').value.trim(), skills: skills, desired_jobs: desired_jobs, work_type: Array.from(document.querySelectorAll('#jj-js-work-type-group input:checked')).map(function (cb) { return cb.value; })
                };
                $('#jj-btn-js-profile-save').disabled = true;
                api('profile/jobseeker', body, true).then(function (res) {
                    $('#jj-btn-js-profile-save').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-js-profile', (res.data && res.data.message) || T('error_occurred'), true); return; }
                    setMsg('#jj-msg-js-profile', T('acct_saved_generic'), false);
                }).catch(function () { $('#jj-btn-js-profile-save').disabled = false; setMsg('#jj-msg-js-profile', T('error_network'), true); });
            });
            var contractPathInfoCache = null;
            function renderApplicationsList(rows, box, filterType) {
                var filtered = filterType ? rows.filter(function (a) { return a.applicant_type === filterType; }) : rows;
                if (!filtered.length) { box.innerHTML = '<li>' + T('no_applications_yet') + '</li>'; return; }
                box.innerHTML = '';
                filtered.forEach(function (a) {
                    var li = document.createElement('li');
                    li.style.display = 'block';
                    var top = document.createElement('div');
                    top.style.cssText = 'display:flex;justify-content:space-between;';
                    top.innerHTML = '<span>' + escHtml(a.post_title) + '</span><span>' + escHtml(a.status_label) + '</span>';
                    li.appendChild(top);
                    var chatBtn = document.createElement('button');
                    chatBtn.type = 'button'; chatBtn.className = 'jj-btn-link'; chatBtn.style.marginTop = '4px';
                    chatBtn.style.fontSize = '15px';
                    chatBtn.style.fontWeight = '700';
                    chatBtn.style.padding = '8px 14px';
                    chatBtn.style.display = 'inline-block';
                    chatBtn.textContent = T('chat_with_employer_btn');
                    chatBtn.addEventListener('click', function () { if (window.jjChatOpen) window.jjChatOpen('application', a.id); });
                    li.appendChild(chatBtn);
                    if (a.needs_contract_path_choice) {
                        var choiceBox = document.createElement('div');
                        choiceBox.style.cssText = 'margin-top:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;font-size:13px;';
                        choiceBox.innerHTML = '<p style="margin:0 0 8px;">' + T('contract_path_question') + '</p>'
                            + '<button class="jj-btn jj-btn-secondary" style="margin-bottom:6px;" data-choice="in_app">' + T('arrange_here_btn') + '</button>'
                            + '<button class="jj-btn jj-btn-secondary" data-choice="self_arranged">' + T('self_arranged_btn') + '</button>'
                            + '<div class="jj-path-detail" style="display:none;margin-top:8px;"></div>';
                        li.appendChild(choiceBox);
                        choiceBox.querySelectorAll('button[data-choice]').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var choice = this.getAttribute('data-choice');
                                var detail = choiceBox.querySelector('.jj-path-detail');
                                var render = function (info) {
                                    var opt = info.options[choice];
                                    var prosHtml = opt.pros.map(function (p) { return '<li style="color:#059669;">✔ ' + p + '</li>'; }).join('');
                                    var consHtml = opt.cons.map(function (c) { return '<li style="color:#dc2626;">✘ ' + c + '</li>'; }).join('');
                                    detail.innerHTML = '<strong>' + opt.title + '</strong><ul style="margin:6px 0;padding-right:18px;">' + prosHtml + consHtml + '</ul>'
                                        + '<label style="display:block;margin-bottom:8px;"><input type="checkbox" class="jj-path-ack"> ' + T('path_ack_checkbox') + '</label>'
                                        + '<button class="jj-btn" style="width:auto;padding:6px 16px;" id="jj-path-confirm">' + T('final_confirm_btn') + '</button>'
                                        + '<div class="jj-msg" style="font-size:12px;margin-top:4px;"></div>';
                                    detail.style.display = '';
                                    detail.querySelector('#jj-path-confirm').addEventListener('click', function () {
                                        var msgEl = detail.querySelector('.jj-msg');
                                        if (!detail.querySelector('.jj-path-ack').checked) {
                                            msgEl.textContent = T('check_confirm_first'); msgEl.style.color = '#dc2626'; return;
                                        }
                                        var confirmBtn = this;
                                        confirmBtn.disabled = true;
                                        api('applications/' + a.id + '/contract-path', { choice: choice, acknowledged: true }, true).then(function (r) {
                                            if (!r.ok) { confirmBtn.disabled = false; msgEl.textContent = (r.data && r.data.message) || T('error_occurred'); msgEl.style.color = '#dc2626'; return; }
                                            choiceBox.innerHTML = '<p style="color:#059669;margin:0;">' + T('path_registered_check_contracts') + '</p>';
                                        });
                                    });
                                };
                                if (contractPathInfoCache) { render(contractPathInfoCache); return; }
                                fetch(REST + 'applications/contract-path-info').then(function (r) { return r.json(); }).then(function (res) {
                                    contractPathInfoCache = res;
                                    render(res);
                                });
                            });
                        });
                    }
                    box.appendChild(li);
                });
            }
            $('#jj-btn-my-apps').addEventListener('click', function () {
                var box = $('#jj-my-apps-list');
                if (box.style.display !== 'none') { box.style.display = 'none'; return; }
                box.style.display = ''; box.innerHTML = '<li>' + T('loading') + '</li>';
                fetch(REST + 'applications/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) {
                        if (r.status === 401) { handleUnauthorized(); return null; }
                        return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                    })
                    .then(function (res) {
                        if (!res) return;
                        if (!res.ok) { box.innerHTML = '<li>' + T('error_fetching_info') + '</li>'; return; }
                        renderApplicationsList(res.data.applications, box, 'individual');
                    }).catch(function () { box.innerHTML = '<li>' + T('error_network') + '</li>'; });
            });
            $('#jj-btn-team-apps').addEventListener('click', function () {
                var box = $('#jj-team-apps-list');
                if (box.style.display !== 'none') { box.style.display = 'none'; return; }
                box.style.display = ''; box.innerHTML = '<li>' + T('loading') + '</li>';
                fetch(REST + 'applications/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) {
                        if (r.status === 401) { handleUnauthorized(); return null; }
                        return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                    })
                    .then(function (res) {
                        if (!res) return;
                        if (!res.ok) { box.innerHTML = '<li>' + T('error_fetching_info') + '</li>'; return; }
                        renderApplicationsList(res.data.applications, box, 'team');
                    }).catch(function () { box.innerHTML = '<li>' + T('error_network') + '</li>'; });
            });

            // ---------- قراردادهای من (فلوچارت ۷) ----------
            var contractsReturnStep = 'browse-jobs';
            function loadContracts(returnStep) {
                contractsReturnStep = returnStep;
                showStep('contracts');
                $('#jj-contract-detail').style.display = 'none';
                var list = $('#jj-contracts-list');
                list.innerHTML = '<li>' + T('loading') + '</li>';
                fetch(REST + 'contracts/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) {
                        if (r.status === 401) { handleUnauthorized(); return null; }
                        return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                    })
                    .then(function (res) {
                        if (!res) return;
                        if (!res.ok) { list.innerHTML = '<li>' + T('error_fetching_info_retry') + '</li>'; return; }
                        if (!res.data.contracts.length) { list.innerHTML = '<li>' + T('no_contracts_yet') + '</li>'; return; }
                        list.innerHTML = '';
                        res.data.contracts.forEach(function (c) {
                            var li = document.createElement('li');
                            li.style.cursor = 'pointer';
                            li.innerHTML = '<span>' + escHtml(c.contract_number) + ' — ' + escHtml(c.post_title) + '</span><span>' + escHtml(c.status_label) + '</span>';
                            li.addEventListener('click', function () { showContractDetail(c.id, c.my_role, c.type); });
                            list.appendChild(li);
                        });
                    }).catch(function () { list.innerHTML = '<li>' + T('error_network') + '</li>'; });
            }
            function showContractDetail(contractId, myRole, contractType) {
                setMsg('#jj-msg-contract', '', false);
                $('#jj-contract-detail').style.display = '';
                $('#jj-contract-text').textContent = T('loading');
                $('#jj-contract-identity').style.display = 'none';
                $('#jj-contract-team-payment').style.display = 'none';
                $('#jj-contract-team-payment').innerHTML = '';
                $('#jj-btn-contract-pay').style.display = 'none';
                $('#jj-contract-otp-box').style.display = 'none';
                $('#jj-contract-otp-verify-row').style.display = 'none';
                $('#jj-contract-otp-code').value = '';
                $('#jj-contract-certificate-link').style.display = 'none';
                fetch(REST + 'contracts/' + contractId, { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) {
                        if (r.status === 401) { handleUnauthorized(); return null; }
                        return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                    })
                    .then(function (res) {
                        if (!res) return;
                        if (!res.ok) { $('#jj-contract-text').textContent = ''; setMsg('#jj-msg-contract', (res.data && res.data.message) || T('error_occurred'), true); return; }
                        var c = res.data.contract;
                        $('#jj-contract-text').textContent = c.draft_text || T('draft_not_ready');
                        if (c.identity) {
                            $('#jj-contract-identity').style.display = '';
                            $('#jj-contract-identity').innerHTML = '<strong>' + T('candidate_identity_after_settlement') + '</strong><br>' + T('name_colon') + ' ' + escHtml(c.identity.name) + '<br>' + T('mobile_colon') + ' ' + escHtml(c.identity.phone) + '<br>' + T('national_id_colon') + ' ' + escHtml(c.identity.national_id) + '<br>' + T('nationality_colon') + ' ' + escHtml(c.identity.nationality) + '<br>' + T('address_colon') + ' ' + escHtml(c.identity.address);
                        } else if (c.identity_list) {
                            $('#jj-contract-identity').style.display = '';
                            var rows = c.identity_list.map(function (m) { return '<div style="margin-top:6px;">— ' + escHtml(m.name) + ' | ' + escHtml(m.phone) + ' | ' + T('national_id_colon') + ' ' + escHtml(m.national_id) + ' | ' + T('nationality_colon') + ' ' + escHtml(m.nationality) + '<br>&nbsp;&nbsp;' + T('address_colon') + ' ' + escHtml(m.address) + '</div>'; }).join('');
                            $('#jj-contract-identity').innerHTML = '<strong>' + T('team_members_identity_after_settlement') + '</strong>' + rows;
                        } else if (c.employer_contact) {
                            $('#jj-contract-identity').style.display = '';
                            var msgHtml = '';
                            if (c.employer_contact.messengers) {
                                var mgr = c.employer_contact.messengers;
                                var parts = [];
                                if (mgr.whatsapp) parts.push(T('whatsapp') + ': ' + escHtml(mgr.whatsapp));
                                if (mgr.telegram) parts.push(T('telegram') + ': ' + escHtml(mgr.telegram));
                                if (parts.length) msgHtml = '<br>' + parts.join(' | ');
                            }
                            $('#jj-contract-identity').innerHTML = '<strong>' + T('employer_contact_after_settlement') + '</strong><br>' + T('company_name_colon') + ' ' + escHtml(c.employer_contact.company_name) + '<br>' + T('mobile_colon') + ' ' + escHtml(c.employer_contact.phone) + '<br>' + T('work_address_colon') + ' ' + escHtml(c.employer_contact.address) + msgHtml + '<p style="margin-top:6px;color:#14213d;">' + T('coordinate_directly_note') + '</p>';
                        }

                        // --- سرپرست اکیپ: انتخاب حالت پرداخت کمیسیون سمت تیم ---
                        if (myRole === 'team_leader' && c.status === 'pending_payment' && !c.team_side_paid) {
                            var box = $('#jj-contract-team-payment');
                            box.style.display = '';
                            if (!c.team_payment_mode) {
                                box.innerHTML = '<p style="font-size:13px;margin-bottom:8px;">' + T('choose_team_commission_payment') + '</p>'
                                    + '<button class="jj-btn jj-btn-secondary" id="jj-btn-mode-lump" style="margin-bottom:6px;">' + T('lump_sum_by_me_btn') + '</button>'
                                    + '<button class="jj-btn jj-btn-secondary" id="jj-btn-mode-member">' + T('per_member_pays_btn') + '</button>';
                                $('#jj-btn-mode-lump').addEventListener('click', function () {
                                    var btnEl = this;
                                    btnEl.disabled = true;
                                    api('contracts/' + contractId + '/team-payment-mode', { mode: 'lump_sum' }, true).then(function (r) {
                                        if (!r.ok) { btnEl.disabled = false; setMsg('#jj-msg-contract', (r.data && r.data.message) || T('error_occurred'), true); return; }
                                        window.location.href = r.data.payment_url;
                                    });
                                });
                                $('#jj-btn-mode-member').addEventListener('click', function () {
                                    var btnEl = this;
                                    btnEl.disabled = true;
                                    api('contracts/' + contractId + '/team-payment-mode', { mode: 'per_member' }, true).then(function (r) {
                                        if (!r.ok) { btnEl.disabled = false; setMsg('#jj-msg-contract', (r.data && r.data.message) || T('error_occurred'), true); return; }
                                        showContractDetail(contractId, myRole, contractType);
                                    });
                                });
                            } else if (c.team_payment_mode === 'per_member' && c.member_payment_status) {
                                var rows2 = c.member_payment_status.map(function (m) {
                                    return '<div style="display:flex;justify-content:space-between;padding:4px 0;border-top:1px solid #eee;">' + m.phone + '<span style="color:' + (m.paid ? '#059669' : '#dc2626') + ';">' + (m.paid ? T('member_paid') : T('member_unpaid')) + '</span></div>';
                                }).join('');
                                box.innerHTML = '<p style="font-size:13px;">' + T('member_payment_status_note') + '</p>' + rows2;
                            }
                        }

                        if (c.status === 'pending_payment' && !c.my_share_paid && myRole === 'employer') {
                            var payType = (contractType === 'team' || contractType === 'agency_team') ? 'contract_team' : (contractType === 'agency' ? 'contract_agency' : 'contract_employer');
                            var btn = $('#jj-btn-contract-pay');
                            btn.style.display = '';
                            btn.textContent = (contractType === 'team' || contractType === 'agency_team' || contractType === 'agency') ? T('pay_full_contract_amount_btn') : T('pay_my_share');
                            btn.onclick = function () {
                                btn.disabled = true;
                                api('payment/request', { payment_type: payType, meta: { contract_id: contractId } }, true).then(function (r) {
                                    if (!r.ok) { btn.disabled = false; setMsg('#jj-msg-contract', (r.data && r.data.message) || T('error_gateway_connection'), true); return; }
                                    if (r.data.free) { showContractDetail(contractId, myRole, contractType); return; }
                                    window.location.href = r.data.payment_url;
                                });
                            };
                        } else if (c.status === 'pending_payment' && !c.my_share_paid && myRole === 'jobseeker') {
                            var payType2 = 'contract_jobseeker';
                            var btn2 = $('#jj-btn-contract-pay');
                            btn2.style.display = '';
                            btn2.textContent = T('pay_my_share');
                            btn2.onclick = function () {
                                btn2.disabled = true;
                                api('payment/request', { payment_type: payType2, meta: { contract_id: contractId } }, true).then(function (r) {
                                    if (!r.ok) { btn2.disabled = false; setMsg('#jj-msg-contract', (r.data && r.data.message) || T('error_gateway_connection'), true); return; }
                                    if (r.data.free) { showContractDetail(contractId, myRole, contractType); return; }
                                    window.location.href = r.data.payment_url;
                                });
                            };
                        } else if (c.status === 'draft_pending') {
                            setMsg('#jj-msg-contract', T('contract_under_agency_review'), false);
                        } else if (c.status === 'pending_otp') {
                            if (c.my_otp_role && !c.my_otp_verified) {
                                var otpBox = $('#jj-contract-otp-box');
                                otpBox.style.display = '';
                                var reqBtn = $('#jj-btn-contract-otp-request');
                                var verifyRow = $('#jj-contract-otp-verify-row');
                                reqBtn.disabled = false;
                                reqBtn.onclick = function () {
                                    reqBtn.disabled = true;
                                    api('contracts/' + contractId + '/otp/request', {}, true).then(function (r) {
                                        reqBtn.disabled = false;
                                        if (!r.ok) { setMsg('#jj-msg-contract', (r.data && r.data.message) || T('error_occurred'), true); return; }
                                        verifyRow.style.display = '';
                                        setMsg('#jj-msg-contract', T('otp_code_texted'), false);
                                    });
                                };
                                $('#jj-btn-contract-otp-verify').onclick = function () {
                                    var code = $('#jj-contract-otp-code').value.trim();
                                    if (!code) { setMsg('#jj-msg-contract', T('enter_the_code'), true); return; }
                                    api('contracts/' + contractId + '/otp/verify', { code: code }, true).then(function (r) {
                                        if (!r.ok) { setMsg('#jj-msg-contract', (r.data && r.data.message) || T('invalid_code'), true); return; }
                                        setMsg('#jj-msg-contract', r.data.fully_verified ? T('contract_finalized_active') : T('confirm_registered_awaiting_other'), false);
                                        showContractDetail(contractId, myRole, contractType);
                                    });
                                };
                            } else if (c.my_otp_role) {
                                setMsg('#jj-msg-contract', T('confirm_recorded_awaiting_other'), false);
                            } else {
                                setMsg('#jj-msg-contract', T('awaiting_final_sms_confirm_both'), false);
                            }
                        } else if (c.status === 'active') {
                            setMsg('#jj-msg-contract', T('contract_is_active'), false);
                            if (c.certificate_url) {
                                var certLink = $('#jj-contract-certificate-link');
                                certLink.href = c.certificate_url;
                                certLink.style.display = '';
                            }
                        }
                    })
                    .catch(function () {
                        $('#jj-contract-text').textContent = '';
                        setMsg('#jj-msg-contract', T('error_occurred'), true);
                    });
            }
            $('#jj-btn-my-contracts-js').addEventListener('click', function () { loadContracts('browse-jobs'); });
            $('#jj-btn-my-contracts-emp').addEventListener('click', function () { loadContracts('jobs'); });
            $('#jj-btn-my-contracts-team').addEventListener('click', function () { loadContracts('team'); });
            $('#jj-btn-contracts-back').addEventListener('click', function () {
                if (contractsReturnStep === 'jobs') { showStep('jobs'); refreshMyJobs(); }
                else if (contractsReturnStep === 'team') { showStep('team'); }
                else { showStep('browse-jobs'); }
            });
            $('#jj-btn-request').addEventListener('click', function () { if (jjLoginMethod === 'password') { passwordLogin(); } else { requestOtp(); } });
            if ($('#jj-method-otp-btn')) $('#jj-method-otp-btn').addEventListener('click', function () { setLoginMethod('otp'); });
            if ($('#jj-method-password-btn')) $('#jj-method-password-btn').addEventListener('click', function () { setLoginMethod('password'); });
            $('#jj-btn-verify').addEventListener('click', verifyOtp);
            $('#jj-btn-resend').addEventListener('click', resendOtp);
            $('#jj-btn-role-submit').addEventListener('click', function () { submitRole({}); });
            document.querySelectorAll('.jj-role-card').forEach(function (el) {
                el.addEventListener('click', function () {
                    var __fn = $('#jj-role-first-name') ? $('#jj-role-first-name').value.trim() : '';
                    var __ln = $('#jj-role-last-name') ? $('#jj-role-last-name').value.trim() : '';
                    if (!__fn || !__ln) {
                        setMsg('#jj-msg-role', T('enter_name_before_role_select'), true);
                        if ($('#jj-role-first-name')) $('#jj-role-first-name').focus();
                        return;
                    }
                    selectRole(el.getAttribute('data-role'));
                });
            });
        
            function checkReturnRedirect(){
                try{
                    var __p = new URLSearchParams(window.location.search);
                    var __ret = __p.get('jj_return');
                    if (__ret) { sessionStorage.setItem('jj_pending_return', __ret); }
                    else { __ret = sessionStorage.getItem('jj_pending_return'); }
                    if (!__ret || !state.token) return;
                    fetch(REST + 'jobseeker/package-status', {method:'GET', headers:{'X-Jj-Auth': state.token}, credentials:'same-origin'})
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if (d && d.active) {
                                sessionStorage.removeItem('jj_pending_return');
                                window.location.href = decodeURIComponent(__ret);
                            } else {
                                window.location.href = '/plans/job-seeker/';
                            }
                        })
                        .catch(function(){});
                } catch(e){}
            }
            checkReturnRedirect();
function checkPendingApprovalOnLoad(){
    try{
        if (!state.token) return;
        fetch(REST + 'profile/summary', {method:'GET', headers:{'X-Jj-Auth': state.token}, credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res && (res.role === 'employer' || res.role === 'agency') && typeof resumeEmployerAgencyFlow === 'function') {
                    resumeEmployerAgencyFlow(res.role);
                }
            })
            .catch(function(){});
    } catch(e){}
}
// وقتی از دکمه‌ی «+ فرصت شغلی جدید» در صفحه‌ی پروفایل (/account/) با
// jj_open=jobs به این‌جا هدایت شده باشیم، مستقیم داشبورد آگهی‌ها را نشان
// بده — بدون عبور از بررسی async مراحل ثبت‌نام (که برای کارفرما/کاریابیِ
// کاملاً فعال، به‌جای این‌جا، کاربر را به فرم پروفایل برمی‌گرداند).
var __jjOpenParam = new URLSearchParams(window.location.search).get('jj_open');
if (__jjOpenParam === 'jobs' && state.token) {
    loadJobsDashboard();
} else if (__jjOpenParam === 'team' && state.token) {
    loadTeamDashboard();
} else {
    checkPendingApprovalOnLoad();
}

})();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * اگر کاربرِ واردنشده به آدرس /account/ (صفحه‌ی اختصاصی پروفایل و داشبورد) برود،
     * به صفحه‌ی ورود/ثبت‌نام هدایت می‌شود.
     */
    public static function maybe_redirect_account_page() {
        if (!is_page('account')) return;
        if (!is_user_logged_in()) {
            $cookie_user_id = JJ_Session::resolve_user_id_from_cookie();
            if ($cookie_user_id) {
                wp_set_current_user($cookie_user_id);
                return;
            }
            wp_safe_redirect(home_url('/jj-login/'));
            exit;
        }
    }

    /**
     * صفحه‌ی اختصاصی «پروفایل و داشبورد من» — کاملاً جدا از صفحه‌ی ورود/ثبت‌نام (jj-login).
     * برای همه‌ی نقش‌های کاربری وجود دارد. استفاده: شورت‌کد [jj_account] در یک برگه‌ی
     * مستقل (آدرس پیش‌فرض: /account/). بالای صفحه هدر بزرگی با عکس، نام و نوع کاربری
     * کاربر نشان داده می‌شود و زیر آن دو بخش «داشبورد» و «پروفایل» کنار هم قرار دارند.
     */
    public static function render_account() {
        $lang = JJ_I18N::current_lang();
        if (!is_user_logged_in()) {
            return '<p style="text-align:center;padding:60px 10px;">' . sprintf(esc_html(JJ_I18N::t('acct_login_required', $lang)), '<a href="' . esc_url(home_url('/jj-login/')) . '">' . esc_html(JJ_I18N::t('acct_login_link_text', $lang)) . '</a>') . '</p>';
        }

        $rest_url = esc_url(rest_url('javanjob/v1/'));
        $token = JJ_Session::issue_token(get_current_user_id());
        $user = wp_get_current_user();
        $display = $user->display_name ? $user->display_name : $user->user_login;
        $avatar_url = self::resolve_menu_avatar_url($user);
        $roles = (array) $user->roles;

        $role_label = JJ_I18N::t('user_role_generic', $lang);
        if (in_array('jj_team_leader', $roles, true)) {
            $role_label = JJ_I18N::t('role_team_leader', $lang);
        } elseif (user_can($user, 'jj_apply_jobs')) {
            $role_label = JJ_I18N::t('user_role_jobseeker', $lang);
        } elseif (array_intersect(['jj_employer', 'jj_employer_pending'], $roles)) {
            $role_label = JJ_I18N::t('role_employer', $lang);
        } elseif (array_intersect(['jj_agency', 'jj_agency_pending'], $roles)) {
            $role_label = JJ_I18N::t('user_role_agency', $lang);
        }
        $is_employer_or_agency = (bool) array_intersect(['jj_employer', 'jj_employer_pending', 'jj_agency', 'jj_agency_pending'], $roles);
        $is_team_leader = in_array('jj_team_leader', $roles, true);

        $hero_bg = esc_url(content_url('themes/noo-jobmonster/assets/images/heading-bg.png'));

        ob_start();
        ?>
        <div id="jj-account-page" dir="<?php echo esc_attr(JJ_I18N::dir($lang)); ?>">
        <style>
            #jj-account-page{max-width:940px;margin:0 auto;padding:0 14px 50px;}
            #jj-account-page .jj-acct-hero{background:linear-gradient(180deg,rgba(20,33,61,.74),rgba(20,33,61,.74)),url('<?php echo $hero_bg; ?>') center/cover no-repeat;border-radius:14px;padding:38px 24px;display:flex;align-items:center;justify-content:center;gap:22px;color:#fff;margin:22px 0 26px;flex-wrap:wrap;text-align:center;}
            #jj-account-page .jj-acct-hero-photo{width:112px;height:112px;border-radius:50%;overflow:hidden;border:4px solid #fff;background:#eef0f4;flex-shrink:0;}
            #jj-account-page .jj-acct-hero-photo img{width:100%;height:100%;object-fit:cover;display:block;}
            #jj-account-page .jj-acct-hero-name{font-size:25px;font-weight:bold;margin:0 0 8px;}
            #jj-account-page .jj-acct-hero-role{font-size:14px;opacity:.92;}
            #jj-account-page .jj-acct-sections{display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;}
            #jj-account-page .jj-acct-box{flex:1 1 340px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;box-sizing:border-box;}
            #jj-account-page .jj-acct-box h2{font-size:16px;color:#14213d;margin:0 0 16px;}
            #jj-account-page .jj-acct-box h3{font-size:13.5px;color:#14213d;margin:0 0 8px;}
            #jj-account-page .jj-btn{display:block;width:100%;padding:9px;border:none;border-radius:8px;background:#14213d;color:#fff;font-size:13px;cursor:pointer;box-sizing:border-box;}
            #jj-account-page .jj-btn:disabled{opacity:.6;cursor:default;}
            #jj-account-page .jj-btn-link{background:none;border:none;color:#2563eb;font-size:12.5px;cursor:pointer;padding:0;}
            #jj-account-page .jj-input{border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;}
            #jj-account-page .jj-msg{font-size:12px;margin-top:6px;min-height:14px;}
            #jj-account-page .jj-msg.jj-error{color:#dc2626;}
            #jj-account-page .jj-msg.jj-ok{color:#16a34a;}
            @media (max-width:640px){ #jj-account-page .jj-acct-hero{flex-direction:column;} }
        </style>

        <div class="jj-acct-hero">
            <div class="jj-acct-hero-photo"><img id="jj-acct-hero-avatar" src="<?php echo esc_url($avatar_url); ?>" alt="" /></div>
            <div>
                <div class="jj-acct-hero-name" id="jj-acct-hero-name"><?php echo esc_html($display); ?></div>
                <div class="jj-acct-hero-role" id="jj-acct-hero-role"><?php echo esc_html($role_label); ?></div>
            </div>
        </div>

        <div class="jj-acct-sections">
            <div class="jj-acct-box">
                <h2><?php echo esc_html(JJ_I18N::t('acct_dashboard', $lang)); ?></h2>
                <h3><?php echo esc_html(JJ_I18N::t('acct_overview', $lang)); ?></h3>
                <div id="jj-acct-overview" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;"></div>
                <h3><?php echo esc_html(JJ_I18N::t('acct_perf_stats', $lang)); ?></h3>
                <div id="jj-acct-stats" style="display:flex;flex-wrap:wrap;gap:10px;"></div>
            </div>
            <div class="jj-acct-box">
                <h2><?php echo esc_html(JJ_I18N::t('acct_profile', $lang)); ?></h2>
                <div style="text-align:center;margin-bottom:16px;">
                    <div style="width:84px;height:84px;border-radius:50%;background:#eef0f4;margin:0 auto 8px;overflow:hidden;">
                        <img id="jj-acct-avatar-preview" src="<?php echo esc_url($avatar_url); ?>" style="width:100%;height:100%;object-fit:cover;display:<?php echo $avatar_url ? '' : 'none'; ?>;" alt="" />
                    </div>
                    <input type="file" id="jj-acct-avatar-input" accept="image/png,image/jpeg,image/webp" style="display:none;" />
                    <button type="button" class="jj-btn-link" id="jj-acct-avatar-pick" style="margin:0;"><?php echo esc_html(JJ_I18N::t('acct_change_photo', $lang)); ?></button>
                    <div class="jj-msg" id="jj-msg-acct-avatar"></div>
                </div>
                <h3><?php echo esc_html(JJ_I18N::t('acct_identity_info', $lang)); ?></h3>
                <div style="margin-bottom:6px;font-size:13px;color:#374151;"><?php echo esc_html(JJ_I18N::t('full_name', $lang)); ?>: <strong id="jj-acct-name"><?php echo esc_html($display); ?></strong></div>
                <div style="margin-bottom:6px;font-size:13px;color:#374151;"><?php echo esc_html(JJ_I18N::t('acct_username_label', $lang)); ?>: <strong id="jj-acct-username"><?php echo esc_html($user->user_login); ?></strong></div>
                <div style="margin-bottom:16px;font-size:13px;color:#374151;"><?php echo esc_html(JJ_I18N::t('acct_role_label', $lang)); ?>: <strong id="jj-acct-role-label"><?php echo esc_html($role_label); ?></strong></div>
                <h3><?php echo esc_html(JJ_I18N::t('acct_contact_auth_info', $lang)); ?></h3>
                <div style="margin-bottom:8px;font-size:13px;color:#374151;"><?php echo esc_html(JJ_I18N::t('acct_mobile_label', $lang)); ?>: <strong id="jj-acct-phone">—</strong></div>
                <div style="margin-bottom:8px;font-size:13px;color:#374151;" id="jj-acct-email-status"><?php echo esc_html(JJ_I18N::t('acct_email_label_prefix', $lang)); ?>: —</div>
                <div id="jj-acct-email-form" style="display:none;margin-bottom:10px;">
                    <input type="email" id="jj-acct-email-input" class="jj-input" style="width:100%;margin-bottom:8px;" placeholder="<?php echo esc_attr(JJ_I18N::t('gmail_address_placeholder', $lang)); ?>" />
                    <button class="jj-btn" id="jj-acct-btn-send-email" type="button"><?php echo esc_html(JJ_I18N::t('acct_send_verify_link', $lang)); ?></button>
                    <div class="jj-msg" id="jj-msg-acct-email"></div>
                </div>
                <button type="button" class="jj-btn-link" id="jj-acct-btn-edit-email" style="margin:0 0 14px;"><?php echo esc_html(JJ_I18N::t('acct_add_change_email', $lang)); ?></button>
                <br>
                <label style="display:block;font-size:12.5px;color:#6b7280;margin-bottom:4px;"><?php echo esc_html(JJ_I18N::t('acct_social_label', $lang)); ?></label>
                <input type="text" id="jj-acct-social" class="jj-input" style="width:100%;margin-bottom:8px;" placeholder="<?php echo esc_attr(JJ_I18N::t('acct_social_placeholder', $lang)); ?>" />
                <button class="jj-btn" id="jj-acct-btn-save-social" type="button"><?php echo esc_html(JJ_I18N::t('save', $lang)); ?></button>
                <div class="jj-msg" id="jj-msg-acct-social"></div>
                <button type="button" class="jj-btn-link" id="jj-acct-btn-toggle-password" style="margin:16px 0 0;"><?php echo esc_html(JJ_I18N::t('acct_change_password', $lang)); ?></button>
                <div id="jj-acct-password-panel" style="display:none;margin-top:8px;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;">
                    <input type="password" id="jj-acct-old-password" class="jj-input" style="width:100%;margin-bottom:8px;" placeholder="<?php echo esc_attr(JJ_I18N::t('acct_old_password', $lang)); ?>" autocomplete="current-password" />
                    <input type="password" id="jj-acct-new-password" class="jj-input" style="width:100%;margin-bottom:8px;" placeholder="<?php echo esc_attr(JJ_I18N::t('new_password_ph', $lang)); ?>" autocomplete="new-password" />
                    <input type="password" id="jj-acct-new-password-confirm" class="jj-input" style="width:100%;margin-bottom:8px;" placeholder="<?php echo esc_attr(JJ_I18N::t('acct_new_password_confirm', $lang)); ?>" autocomplete="new-password" />
                    <button class="jj-btn" id="jj-acct-btn-save-password" type="button"><?php echo esc_html(JJ_I18N::t('save_password_btn', $lang)); ?></button>
                    <div class="jj-msg" id="jj-msg-acct-password"></div>
                </div>
            </div>
            <?php if ($is_employer_or_agency): ?>
            <div class="jj-acct-box" style="flex-basis:100%;">
                <h2><?php echo esc_html(JJ_I18N::t('my_jobs', $lang)); ?></h2>
                <a href="<?php echo esc_url(home_url('/jj-login/?jj_open=jobs')); ?>" class="jj-btn" style="display:inline-block;width:auto;padding:9px 22px;text-decoration:none;margin-bottom:16px;"><?php echo esc_html(JJ_I18N::t('acct_new_job_cta', $lang)); ?></a>
                <ul id="jj-acct-jobs-list" style="list-style:none;margin:0;padding:0;">
                    <li style="color:#9ca3af;font-size:13px;"><?php echo esc_html(JJ_I18N::t('loading', $lang)); ?></li>
                </ul>
            </div>
            <?php endif; ?>
            <?php if ($is_team_leader): ?>
            <div class="jj-acct-box" style="flex-basis:100%;">
                <h2><?php echo esc_html(JJ_I18N::t('acct_team_mgmt', $lang)); ?></h2>
                <p style="font-size:13px;color:#6b7280;margin:0 0 14px;"><?php echo esc_html(JJ_I18N::t('acct_team_mgmt_desc', $lang)); ?></p>
                <a href="<?php echo esc_url(home_url('/jj-login/?jj_open=team')); ?>" class="jj-btn" style="display:inline-block;width:auto;padding:9px 22px;text-decoration:none;"><?php echo esc_html(JJ_I18N::t('acct_team_mgmt', $lang)); ?></a>
            </div>
            <?php endif; ?>
            <div class="jj-acct-box" style="flex-basis:100%;">
                <h2><?php echo esc_html(JJ_I18N::t('acct_current_entitlements', $lang)); ?></h2>
                <div id="jj-ent-plan-info" style="font-size:13px;color:#6b7280;margin-bottom:14px;"></div>
                <div id="jj-ent-items" style="display:flex;flex-wrap:wrap;gap:12px;"><p style="color:#9ca3af;font-size:13px;"><?php echo esc_html(JJ_I18N::t('loading', $lang)); ?></p></div>
            </div>
        </div>
        </div>
        <script>
        (function () {
            var REST = <?php echo wp_json_encode($rest_url); ?>;
            var state = { token: <?php echo wp_json_encode($token); ?> };
            try { if (!state.token) { var __t = localStorage.getItem('jj_auth_token'); if (__t) state.token = __t; } } catch (e) {}
            var T_DICT = <?php echo wp_json_encode(JJ_I18N::dictionary($lang), JSON_UNESCAPED_UNICODE); ?>;
            function T(key) { return T_DICT[key] || key; }

            function $(sel) { return document.querySelector(sel); }
            function escHtml(s) {
                if (s === null || s === undefined) return '';
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }
            function setMsg(id, text, isError) {
                var el = $(id);
                if (!el) return;
                el.textContent = text || '';
                el.className = 'jj-msg' + (text ? (isError ? ' jj-error' : ' jj-ok') : '');
            }
            function handleUnauthorized() {
                if (state.token === null) return;
                state.token = null;
                window.location.href = <?php echo wp_json_encode(home_url('/jj-login/')); ?>;
            }
            function api(path, body, useAuth) {
                var headers = { 'Content-Type': 'application/json' };
                if (useAuth && state.token) headers['X-JJ-Auth'] = state.token;
                return fetch(REST + path, {
                    method: 'POST', headers: headers, credentials: 'same-origin', body: JSON.stringify(body || {})
                }).then(function (r) {
                    if (r.status === 401 && useAuth) handleUnauthorized();
                    return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                }).catch(function () {
                    return { ok: false, data: { message: T('acct_network_error_retry') } };
                });
            }
            function apiUpload(path, formData) {
                var headers = {};
                if (state.token) headers['X-JJ-Auth'] = state.token;
                return fetch(REST + path, { method: 'POST', headers: headers, credentials: 'same-origin', body: formData })
                    .then(function (r) {
                        if (r.status === 401) handleUnauthorized();
                        return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                    }).catch(function () {
                        return { ok: false, data: { message: T('acct_network_error_retry') } };
                    });
            }

            function acctTile(label, value) {
                return '<div style="flex:1;min-width:120px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;text-align:center;">'
                    + '<div style="font-size:18px;font-weight:bold;color:#14213d;">' + escHtml(value) + '</div>'
                    + '<div style="font-size:11.5px;color:#6b7280;margin-top:4px;">' + escHtml(label) + '</div></div>';
            }
            var jjRoleLabels = { jobseeker: T('user_role_jobseeker'), team_leader: T('role_team_leader'), employer: T('role_employer'), agency: T('user_role_agency'), other: T('user_role_generic') };
            function renderAccountOverview(role, s) {
                var html = acctTile(T('acct_role_label'), jjRoleLabels[role] || '—');
                if (role === 'jobseeker') {
                    html += acctTile(T('acct_subscription_status'), s.subscription_status === 'active' ? T('plan_status_active') : T('plan_status_inactive'));
                    if (s.days_remaining !== null && s.days_remaining !== undefined) html += acctTile(T('acct_days_remaining'), s.days_remaining);
                } else if (role === 'employer' || role === 'agency') {
                    // دو برچسب این‌جا جابه‌جا نوشته شده بودند: pending_documents یعنی
                    // «پروفایل ثبت شد، نوبت بارگذاری مدارک است» ولی برچسب «در انتظار
                    // بررسی» می‌گرفت، و pending_review — تنها وضعیتی که واقعاً در صف
                    // بررسی مدیر می‌نشیند — به شاخه‌ی else می‌افتاد و «در انتظار مدارک»
                    // نشان داده می‌شد. نتیجه این بود که کاربرِ بدون مدرک فکر می‌کرد
                    // منتظر تأیید مدیر است و کاری نمی‌کرد، در حالی که صف مدیر خالی بود.
                    // ترتیب درست همانی است که resumeEmployerAgencyFlow استفاده می‌کند.
                    var docLabel;
                    if (s.documents_status === 'approved') { docLabel = T('acct_docs_approved'); }
                    else if (s.documents_status === 'pending_review') { docLabel = T('acct_docs_pending_review'); }
                    else { docLabel = T('acct_docs_pending_upload'); }
                    html += acctTile(T('acct_docs_status'), docLabel);
                } else if (role === 'team_leader') {
                    html += acctTile(T('acct_team_name'), s.team_name || '—');
                }
                $('#jj-acct-overview').innerHTML = html;
            }
            function renderAccountStats(role, stats) {
                var html = '';
                if (role === 'jobseeker' || role === 'team_leader') {
                    html += acctTile(T('acct_applications_sent_count'), stats.applications_count || 0);
                } else if (role === 'employer' || role === 'agency') {
                    html += acctTile(T('acct_jobs_count'), stats.jobs_count || 0);
                    html += acctTile(T('acct_applicants_count'), stats.applicants_count || 0);
                }
                $('#jj-acct-stats').innerHTML = html || '<p style="color:#9ca3af;">' + T('acct_no_stats') + '</p>';
            }
            function loadAccountData() {
                if (!state.token) { handleUnauthorized(); return; }
                fetch(REST + 'profile/summary', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) { if (r.status === 401) { handleUnauthorized(); return null; } return r.json(); })
                    .then(function (res) {
                        if (!res || !res.summary) return;
                        var s = res.summary, role = res.role;
                        var fullName = ((s.first_name || '') + ' ' + (s.last_name || '')).trim() || s.company_name || s.agency_name || s.team_name || s.phone || <?php echo wp_json_encode($display); ?>;
                        $('#jj-acct-name').textContent = fullName;
                        $('#jj-acct-hero-name').textContent = fullName;
                        $('#jj-acct-username').textContent = s.username || s.phone || '—';
                        $('#jj-acct-role-label').textContent = jjRoleLabels[role] || '—';
                        $('#jj-acct-hero-role').textContent = jjRoleLabels[role] || '—';
                        $('#jj-acct-phone').textContent = s.phone || '—';
                        if (s.avatar_url) {
                            $('#jj-acct-avatar-preview').src = s.avatar_url; $('#jj-acct-avatar-preview').style.display = '';
                            $('#jj-acct-hero-avatar').src = s.avatar_url;
                        }
                        $('#jj-acct-email-status').textContent = T('acct_email_label_prefix') + ': ' + (s.email ? (s.email + (s.email_verified ? ' ✅ ' + T('acct_docs_approved') : ' (' + T('acct_docs_pending_review') + ')')) : T('select_option'));
                        $('#jj-acct-social').value = s.social_handle || '';
                        renderAccountOverview(role, s);
                        renderAccountStats(role, s.stats || {});
                    }).catch(function () {});
            }

            var jjAcctJobStatusColor = { pending: '#d97706', publish: '#059669', trash: '#dc2626', draft: '#6b7280' };
            function loadAccountJobs() {
                var list = $('#jj-acct-jobs-list');
                if (!list || !state.token) return;
                fetch(REST + 'jobs/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { if (r.status === 401) { handleUnauthorized(); return null; } return r.json(); })
                    .then(function (res) {
                        if (!res) return;
                        if (!res.success || !res.jobs || !res.jobs.length) {
                            list.innerHTML = '<li style="color:#9ca3af;font-size:13px;">' + T('acct_no_jobs_yet') + '</li>';
                            return;
                        }
                        list.innerHTML = '';
                        res.jobs.forEach(function (j) {
                            var li = document.createElement('li');
                            li.style.cssText = 'display:flex;justify-content:space-between;padding:9px 4px;border-bottom:1px solid #f1f5f9;font-size:13px;';
                            li.innerHTML = '<span>' + escHtml(j.title) + '</span><span style="color:' + (jjAcctJobStatusColor[j.status] || '#374151') + '">' + escHtml(j.status_label || j.status) + '</span>';
                            list.appendChild(li);
                        });
                    }).catch(function () {
                        list.innerHTML = '<li style="color:#dc2626;font-size:13px;">' + T('acct_jobs_fetch_error') + '</li>';
                    });
            }

            function jjEntBadge(text, color) {
                return '<span style="display:inline-block;background:' + color + ';color:#fff;font-size:11px;padding:2px 9px;border-radius:10px;">' + text + '</span>';
            }
            function loadEntitlementsSummary() {
                var infoBox = $('#jj-ent-plan-info');
                var itemsBox = $('#jj-ent-items');
                if (!itemsBox || !state.token) return;
                fetch(REST + 'my-entitlements', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { if (r.status === 401) { handleUnauthorized(); return null; } return r.json(); })
                    .then(function (res) {
                        if (!res || !res.success) { itemsBox.innerHTML = '<p style="color:#dc2626;font-size:13px;">' + T('acct_info_fetch_error') + '</p>'; return; }
                        if (res.role === 'employer') {
                            infoBox.textContent = '';
                            itemsBox.innerHTML = '<p style="color:#9ca3af;font-size:13px;">' + escHtml(res.note || '') + '</p>';
                            return;
                        }
                        var plan = res.plan;
                        if (plan && plan.plan_id) {
                            infoBox.textContent = T('acct_active_plan_prefix') + ' ' + plan.plan_name + (plan.active ? (' — ' + T('acct_until') + ' ' + plan.expires_at + ' (' + plan.days_remaining + ' ' + T('acct_days_left_suffix') + ')') : ' — ' + T('acct_expired'));
                        } else {
                            infoBox.textContent = T('acct_no_active_plan');
                        }
                        var items = res.items || [];
                        if (!items.length) { itemsBox.innerHTML = '<p style="color:#9ca3af;font-size:13px;">' + T('acct_no_items_to_show') + '</p>'; return; }
                        itemsBox.innerHTML = '';
                        items.forEach(function (it) {
                            var card = document.createElement('div');
                            card.style.cssText = 'flex:1;min-width:190px;max-width:230px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;';
                            var titleEl = document.createElement('div');
                            titleEl.style.cssText = 'font-size:12.5px;font-weight:600;color:#14213d;margin-bottom:6px;';
                            titleEl.textContent = it.label;
                            card.appendChild(titleEl);
                            var body = document.createElement('div');
                            if (it.kind === 'quota') {
                                body.style.cssText = 'font-size:12px;color:#6b7280;';
                                if (it.unlimited) { body.innerHTML = jjEntBadge(T('acct_unlimited'), '#059669'); }
                                else { body.innerHTML = '<strong style="color:#14213d;">' + it.used + '</strong> ' + T('acct_used_of') + ' ' + it.total + ' — <strong style="color:#c9a227;">' + it.remaining + '</strong> ' + T('acct_remaining'); }
                            } else if (it.kind === 'level') {
                                body.style.cssText = 'font-size:12px;color:#6b7280;';
                                body.textContent = it.value;
                            } else {
                                body.innerHTML = it.enabled ? jjEntBadge(T('plan_status_active'), '#059669') : jjEntBadge(T('plan_status_inactive'), '#9ca3af');
                            }
                            card.appendChild(body);
                            itemsBox.appendChild(card);
                        });
                    }).catch(function () {
                        itemsBox.innerHTML = '<p style="color:#dc2626;font-size:13px;">' + T('error_network') + '</p>';
                    });
            }

            if ($('#jj-acct-avatar-pick')) $('#jj-acct-avatar-pick').addEventListener('click', function () { $('#jj-acct-avatar-input').click(); });
            if ($('#jj-acct-avatar-input')) $('#jj-acct-avatar-input').addEventListener('change', function () {
                setMsg('#jj-msg-acct-avatar', '', false);
                var fileInput = $('#jj-acct-avatar-input');
                if (!fileInput.files || !fileInput.files[0]) return;
                if (fileInput.files[0].size > 2 * 1024 * 1024) { setMsg('#jj-msg-acct-avatar', T('acct_photo_size_limit'), true); return; }
                var fd = new FormData();
                fd.append('file', fileInput.files[0]);
                apiUpload('profile/avatar-photo', fd).then(function (res) {
                    if (!res.ok) { setMsg('#jj-msg-acct-avatar', (res.data && res.data.message) || T('acct_upload_error'), true); return; }
                    $('#jj-acct-avatar-preview').src = res.data.url; $('#jj-acct-avatar-preview').style.display = '';
                    $('#jj-acct-hero-avatar').src = res.data.url;
                    setMsg('#jj-msg-acct-avatar', T('acct_photo_saved'), false);
                }).catch(function () { setMsg('#jj-msg-acct-avatar', T('error_network'), true); });
            });
            if ($('#jj-acct-btn-edit-email')) $('#jj-acct-btn-edit-email').addEventListener('click', function () {
                var form = $('#jj-acct-email-form');
                form.style.display = (form.style.display === 'none' || !form.style.display) ? '' : 'none';
            });
            if ($('#jj-acct-btn-send-email')) $('#jj-acct-btn-send-email').addEventListener('click', function () {
                var email = $('#jj-acct-email-input').value.trim();
                setMsg('#jj-msg-acct-email', '', false);
                if (!email) { setMsg('#jj-msg-acct-email', T('acct_enter_email'), true); return; }
                $('#jj-acct-btn-send-email').disabled = true;
                api('profile/email/request-verify', { email: email }, true).then(function (res) {
                    $('#jj-acct-btn-send-email').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-acct-email', (res.data && res.data.message) || T('error_generic'), true); return; }
                    setMsg('#jj-msg-acct-email', T('acct_email_verify_sent'), false);
                }).catch(function () { $('#jj-acct-btn-send-email').disabled = false; setMsg('#jj-msg-acct-email', T('error_network'), true); });
            });
            if ($('#jj-acct-btn-save-social')) $('#jj-acct-btn-save-social').addEventListener('click', function () {
                setMsg('#jj-msg-acct-social', '', false);
                $('#jj-acct-btn-save-social').disabled = true;
                api('profile/social', { social_handle: $('#jj-acct-social').value.trim() }, true).then(function (res) {
                    $('#jj-acct-btn-save-social').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-acct-social', (res.data && res.data.message) || T('error_generic'), true); return; }
                    setMsg('#jj-msg-acct-social', T('acct_saved_generic'), false);
                }).catch(function () { $('#jj-acct-btn-save-social').disabled = false; setMsg('#jj-msg-acct-social', T('error_network'), true); });
            });
            if ($('#jj-acct-btn-toggle-password')) $('#jj-acct-btn-toggle-password').addEventListener('click', function () {
                var panel = $('#jj-acct-password-panel');
                panel.style.display = (panel.style.display === 'none' || !panel.style.display) ? '' : 'none';
            });
            if ($('#jj-acct-btn-save-password')) $('#jj-acct-btn-save-password').addEventListener('click', function () {
                var oldPw = $('#jj-acct-old-password').value;
                var newPw = $('#jj-acct-new-password').value;
                var confirmPw = $('#jj-acct-new-password-confirm').value;
                setMsg('#jj-msg-acct-password', '', false);
                if (newPw !== confirmPw) { setMsg('#jj-msg-acct-password', T('acct_password_mismatch'), true); return; }
                if (!newPw || newPw.length < 6) { setMsg('#jj-msg-acct-password', T('acct_password_too_short'), true); return; }
                $('#jj-acct-btn-save-password').disabled = true;
                api('auth/set-password', { password: newPw, old_password: oldPw }, true).then(function (res) {
                    $('#jj-acct-btn-save-password').disabled = false;
                    if (!res.ok) { setMsg('#jj-msg-acct-password', (res.data && res.data.message) || T('error_generic'), true); return; }
                    setMsg('#jj-msg-acct-password', T('acct_password_changed'), false);
                    $('#jj-acct-old-password').value = ''; $('#jj-acct-new-password').value = ''; $('#jj-acct-new-password-confirm').value = '';
                }).catch(function () { $('#jj-acct-btn-save-password').disabled = false; setMsg('#jj-msg-acct-password', T('error_network'), true); });
            });

                                                function extEsc(s) { return escHtml(s == null ? '' : String(s)); }
            function extVal(id) { var el = $(id); return el ? el.value : ''; }
            /* این دو تابع پیش‌تر در بخش تکراریِ «کارهای شغلی» (dash-ext، حذف‌شده در
             * یکسان‌سازیِ ۱۴۰۵/۰۶/۱۴) هم دوباره تعریف شده بودند؛ چون extEnsureProfessions
             * و extCollectJobseeker پایین همین فایل هنوز به آن‌ها نیاز دارند، همین‌جا
             * (کنار بقیه‌ی توابع کمکیِ ext) نگه داشته شدند. */
            function dashGet(path) {
                return fetch(REST + path, { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
                    .catch(function () { return { ok: false, data: { message: T('error_network') } }; });
            }
            function dashProfessionIdByName(name, list) {
                var found = (list || []).filter(function (p) { return p.name === name; })[0];
                return found ? found.id : '';
            }
            function extInput(id, label, val, type) {
                type = type || 'text';
                return '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' + extEsc(label) + '</label><input type="' + type + '" id="' + id + '" class="jj-input" value="' + extEsc(val) + '" disabled style="width:100%;box-sizing:border-box;background:#f3f4f6;color:#6b7280;padding:8px;border:1px solid #d1d5db;border-radius:6px;"></div>';
            }
            function extSelect(id, label, val, options) {
                var h = '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' + extEsc(label) + '</label><select id="' + id + '" class="jj-input" disabled style="width:100%;box-sizing:border-box;background:#f3f4f6;color:#6b7280;padding:8px;border:1px solid #d1d5db;border-radius:6px;">';
                options.forEach(function (o) {
                    h += '<option value="' + extEsc(o.v) + '"' + (o.v === val ? ' selected' : '') + '>' + extEsc(o.t) + '</option>';
                });
                h += '</select></div>';
                return h;
            }
            function extTextarea(id, label, val) {
                return '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' + extEsc(label) + '</label><textarea id="' + id + '" class="jj-input" disabled style="width:100%;box-sizing:border-box;background:#f3f4f6;color:#6b7280;min-height:60px;padding:8px;border:1px solid #d1d5db;border-radius:6px;">' + extEsc(val) + '</textarea></div>';
            }
            function extArrToText(a) { return (a && a.length) ? a.join('، ') : ''; }
            function extTextToArr(s) { return s.split(/[,،]/).map(function (x) { return x.trim(); }).filter(Boolean); }

            function extCheckboxGroup(prefix, label, selected, options) {
                selected = selected || [];
                var h = '<div style="margin-bottom:10px;" class="jj-ext-checkgroup" data-prefix="' + prefix + '"><label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' + extEsc(label) + '</label><div style="display:flex;flex-wrap:wrap;gap:8px;">';
                options.forEach(function (o) {
                    var checked = selected.indexOf(o.v) !== -1;
                    h += '<label style="display:inline-flex;align-items:center;gap:4px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:14px;padding:4px 10px;font-size:12px;"><input type="checkbox" class="jj-ext-check" id="jj-ext-' + prefix + '-' + o.v + '" value="' + extEsc(o.v) + '" disabled' + (checked ? ' checked' : '') + '>' + extEsc(o.t) + '</label>';
                });
                h += '</div></div>';
                return h;
            }
            function extCollectCheckboxGroup(prefix, options) {
                var out = [];
                options.forEach(function (o) {
                    var el = $('#jj-ext-' + prefix + '-' + o.v);
                    if (el && el.checked) out.push(o.v);
                });
                return out;
            }

            var JJ_EXT_MONTHS = [T('jalali_m1'), T('jalali_m2'), T('jalali_m3'), T('jalali_m4'), T('jalali_m5'), T('jalali_m6'), T('jalali_m7'), T('jalali_m8'), T('jalali_m9'), T('jalali_m10'), T('jalali_m11'), T('jalali_m12')];
            function extDateFields(prefix, label) {
                var selStyle = 'flex:1;box-sizing:border-box;background:#f3f4f6;color:#6b7280;padding:8px;border:1px solid #d1d5db;border-radius:6px;';
                var h = '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' + extEsc(label) + '</label><div style="display:flex;gap:6px;">';
                h += '<select id="jj-ext-' + prefix + '-day" class="jj-input" disabled style="' + selStyle + '"><option value="">' + T('day_label') + '</option>';
                for (var d = 1; d <= 31; d++) h += '<option value="' + d + '">' + d + '</option>';
                h += '</select>';
                h += '<select id="jj-ext-' + prefix + '-month" class="jj-input" disabled style="' + selStyle + '"><option value="">' + T('month_label') + '</option>';
                JJ_EXT_MONTHS.forEach(function (mn, i) { h += '<option value="' + (i + 1) + '">' + mn + '</option>'; });
                h += '</select>';
                h += '<select id="jj-ext-' + prefix + '-year" class="jj-input" disabled style="' + selStyle + '"><option value="">' + T('year_label') + '</option>';
                for (var y = 1412; y >= 1300; y--) h += '<option value="' + y + '">' + y + '</option>';
                h += '</select>';
                h += '</div></div>';
                return h;
            }
            function extFillDate(prefix, val) {
                if (!val) return;
                var parts = String(val).split('/');
                if (parts.length !== 3) return;
                var dY = $('#jj-ext-' + prefix + '-year'), dM = $('#jj-ext-' + prefix + '-month'), dD = $('#jj-ext-' + prefix + '-day');
                if (dY) dY.value = parts[0];
                if (dM) dM.value = parts[1];
                if (dD) dD.value = parts[2];
            }
            function extCollectDate(prefix) {
                var y = extVal('#jj-ext-' + prefix + '-year'), mo = extVal('#jj-ext-' + prefix + '-month'), d = extVal('#jj-ext-' + prefix + '-day');
                if (!y && !mo && !d) return '';
                return y + '/' + mo + '/' + d;
            }

            function extPersonFields(prefix, title, d) {
                d = d || {};
                var h = '<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:14px;"><legend style="padding:0 6px;font-size:13px;font-weight:bold;">' + extEsc(title) + '</legend>';
                h += extInput('jj-ext-' + prefix + '-first_name', T('first_name'), d.first_name);
                h += extInput('jj-ext-' + prefix + '-last_name', T('last_name'), d.last_name);
                h += extInput('jj-ext-' + prefix + '-father_name', T('father_name'), d.father_name);
                h += extDateFields(prefix + '-birth_date', T('birth_date'));
                h += extSelect('jj-ext-' + prefix + '-nationality', T('nationality'), d.nationality, [{ v: '', t: T('select_option') }, { v: 'iranian', t: T('nationality_iranian') }, { v: 'other', t: T('nationality_other') }]);
                h += extInput('jj-ext-' + prefix + '-national_id', T('national_id'), d.national_id);
                h += '</fieldset>';
                return h;
            }
            function extPersonFieldsAfterInsert(prefix, d) {
                extFillDate(prefix + '-birth_date', d && d.birth_date);
            }
            function extCollectPerson(prefix) {
                return {
                    first_name: extVal('#jj-ext-' + prefix + '-first_name'),
                    last_name: extVal('#jj-ext-' + prefix + '-last_name'),
                    father_name: extVal('#jj-ext-' + prefix + '-father_name'),
                    birth_date: extCollectDate(prefix + '-birth_date'),
                    nationality: extVal('#jj-ext-' + prefix + '-nationality'),
                    national_id: extVal('#jj-ext-' + prefix + '-national_id')
                };
            }

            function extProvinceOptions(country, province, meta) {
                meta = meta || {};
                var list;
                if (country === 'ایران') list = Object.keys(meta.iran_provinces || {});
                else list = (meta.country_provinces && meta.country_provinces[country]) || [];
                var h = '<option value="">' + T('select_option') + '</option>';
                list.forEach(function (p) { h += '<option value="' + extEsc(p) + '"' + (p === province ? ' selected' : '') + '>' + extEsc(p) + '</option>'; });
                return h;
            }
            function extProvinceFieldHTML(prefix, country, province, meta) {
                return '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' + T('province') + '</label><select id="jj-ext-' + prefix + '-province" class="jj-input jj-ext-addr-province" disabled style="width:100%;box-sizing:border-box;background:#f3f4f6;color:#6b7280;padding:8px;border:1px solid #d1d5db;border-radius:6px;">' + extProvinceOptions(country, province, meta) + '</select></div>';
            }
            function extCityFieldHTML(prefix, country, province, city, meta) {
                meta = meta || {};
                if (country === 'ایران') {
                    var cities = (meta.iran_provinces && meta.iran_provinces[province]) || [];
                    var h = '<option value="">' + T('select_option') + '</option>';
                    cities.forEach(function (c) { h += '<option value="' + extEsc(c) + '"' + (c === city ? ' selected' : '') + '>' + extEsc(c) + '</option>'; });
                    return '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' + T('city') + '</label><select id="jj-ext-' + prefix + '-city" class="jj-input jj-ext-addr-city" disabled style="width:100%;box-sizing:border-box;background:#f3f4f6;color:#6b7280;padding:8px;border:1px solid #d1d5db;border-radius:6px;">' + h + '</select></div>';
                }
                return extInput('jj-ext-' + prefix + '-city', T('city'), city);
            }
            function extProvCityHTML(prefix, country, province, city, meta) {
                return extProvinceFieldHTML(prefix, country, province, meta) + extCityFieldHTML(prefix, country, province, city, meta);
            }
            function extAddressFields(prefix, title, d, meta) {
                d = d || {};
                var countries = (meta && meta.countries) || ['ایران'];
                var h = '<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:14px;"><legend style="padding:0 6px;font-size:13px;font-weight:bold;">' + extEsc(title) + '</legend>';
                h += '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' + T('country') + '</label><select id="jj-ext-' + prefix + '-country" class="jj-input jj-ext-addr-country" data-prefix="' + prefix + '" disabled style="width:100%;box-sizing:border-box;background:#f3f4f6;color:#6b7280;padding:8px;border:1px solid #d1d5db;border-radius:6px;"><option value="">' + T('select_option') + '</option>';
                countries.forEach(function (c) { h += '<option value="' + extEsc(c) + '"' + (c === d.country ? ' selected' : '') + '>' + extEsc(c) + '</option>'; });
                h += '</select></div>';
                h += '<div id="jj-ext-' + prefix + '-provcity">' + extProvCityHTML(prefix, d.country || '', d.province, d.city, meta) + '</div>';
                h += extInput('jj-ext-' + prefix + '-address_text', T('address'), d.address_text);
                h += extInput('jj-ext-' + prefix + '-plaque', T('plaque'), d.plaque);
                h += extInput('jj-ext-' + prefix + '-unit', T('unit'), d.unit);
                h += extInput('jj-ext-' + prefix + '-postal_code', T('postal_code'), d.postal_code);
                h += '</fieldset>';
                return h;
            }
            function extCollectAddress(prefix) {
                return {
                    country: extVal('#jj-ext-' + prefix + '-country'),
                    province: extVal('#jj-ext-' + prefix + '-province'),
                    city: extVal('#jj-ext-' + prefix + '-city'),
                    address_text: extVal('#jj-ext-' + prefix + '-address_text'),
                    plaque: extVal('#jj-ext-' + prefix + '-plaque'),
                    unit: extVal('#jj-ext-' + prefix + '-unit'),
                    postal_code: extVal('#jj-ext-' + prefix + '-postal_code')
                };
            }
            function extWireAddressCascade(prefix, meta) {
                var countrySel = $('#jj-ext-' + prefix + '-country');
                if (!countrySel) return;
                countrySel.addEventListener('change', function () {
                    var div = $('#jj-ext-' + prefix + '-provcity');
                    if (div) div.innerHTML = extProvCityHTML(prefix, countrySel.value, '', '', meta);
                var newProvSel = $('#jj-ext-' + prefix + '-province');
                if (newProvSel) {
                    newProvSel.disabled = false;
                    newProvSel.style.backgroundColor = '#fff';
                    newProvSel.style.color = '#111827';
                }
                    extWireProvince(prefix, countrySel, meta);
                });
                extWireProvince(prefix, countrySel, meta);
            }
            function extWireProvince(prefix, countrySel, meta) {
                var provSel = $('#jj-ext-' + prefix + '-province');
                if (!provSel) return;
                provSel.addEventListener('change', function () {
                    var cityEl = $('#jj-ext-' + prefix + '-city');
                    if (cityEl) {
                        cityEl.outerHTML = extCityFieldHTML(prefix, countrySel.value, provSel.value, '', meta);
                        var newCityEl = $('#jj-ext-' + prefix + '-city');
                        if (newCityEl) {
                            newCityEl.disabled = false;
                            newCityEl.style.backgroundColor = '#fff';
                            newCityEl.style.color = '#111827';
                        }
                    }
                });
            }
            function extWireAllAddressCascades(meta) {
                var box = $('#jj-ext-fields');
                if (!box) return;
                box.querySelectorAll('.jj-ext-addr-country').forEach(function (sel) {
                    extWireAddressCascade(sel.getAttribute('data-prefix'), meta);
                });
            }

            function extMessengerFields(d) {
                d = d || {};
                var h = '<fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:14px;"><legend style="padding:0 6px;font-size:13px;font-weight:bold;">' + T('messengers_legend') + '</legend>';
                h += extInput('jj-ext-msg-whatsapp', T('whatsapp'), d.whatsapp);
                h += extInput('jj-ext-msg-telegram', T('telegram'), d.telegram);
                h += extInput('jj-ext-msg-instagram', T('instagram'), d.instagram);
                h += extInput('jj-ext-msg-skype', T('skype'), d.skype);
                h += extInput('jj-ext-msg-google_meet', T('google_meet'), d.google_meet);
                h += '</fieldset>';
                return h;
            }
            function extCollectMessengers() {
                return {
                    whatsapp: extVal('#jj-ext-msg-whatsapp'),
                    telegram: extVal('#jj-ext-msg-telegram'),
                    instagram: extVal('#jj-ext-msg-instagram'),
                    skype: extVal('#jj-ext-msg-skype'),
                    google_meet: extVal('#jj-ext-msg-google_meet')
                };
            }

            var extRole = '';
            var extMeta = null;
            var extProfessions = null;
            var JJ_EXT_WORK_TYPES = [{ v: 'full_time', t: T('work_type_full_time') }, { v: 'part_time', t: T('work_type_part_time') }, { v: 'remote', t: T('work_type_remote') }, { v: 'project', t: T('work_type_project') }];

            function extBuildJobseekerFields(p, meta) {
                p = p || {};
                meta = meta || {};
                var countryOpts = (meta.countries || []).map(function (c) { return { v: c, t: c }; });
                var desiredNames = (p.desired_jobs || []).map(function (id) {
                    var f = (extProfessions || []).filter(function (x) { return String(x.id) === String(id); })[0];
                    return f ? f.name : '';
                }).filter(function (x) { return x; }).join('، ');
                var h = '';
                h += extSelect('jj-ext-nationality', T('nationality'), p.nationality, [{ v: '', t: T('select_option') }, { v: 'iranian', t: T('nationality_iranian') }, { v: 'other', t: T('nationality_other') }]);
                h += '<div id="jj-ext-national_id-wrap">' + extInput('jj-ext-national_id', T('national_id'), p.national_id) + '</div>';
                h += extSelect('jj-ext-gender', T('gender'), p.gender, [{ v: '', t: T('select_option') }, { v: 'male', t: T('gender_male') }, { v: 'female', t: T('gender_female') }]);
                h += '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' + extEsc(T('skills_label')) + '</label><input type="text" id="jj-ext-skills" class="jj-input" list="jj-ext-profession-list" value="' + extEsc(extArrToText(p.skills)) + '" placeholder="' + extEsc(T('type_to_search_hint')) + '" disabled style="width:100%;box-sizing:border-box;background:#f3f4f6;color:#6b7280;padding:8px;border:1px solid #d1d5db;border-radius:6px;"></div>';
                h += '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' + extEsc(T('desired_jobs_label')) + '</label><input type="text" id="jj-ext-desired_jobs" class="jj-input" list="jj-ext-profession-list" value="' + extEsc(desiredNames) + '" placeholder="' + extEsc(T('type_to_search_hint')) + '" disabled style="width:100%;box-sizing:border-box;background:#f3f4f6;color:#6b7280;padding:8px;border:1px solid #d1d5db;border-radius:6px;"></div>';
                h += '<datalist id="jj-ext-profession-list">' + (extProfessions || []).map(function (pr) { return '<option value="' + extEsc(pr.name) + '">'; }).join('') + '</datalist>';
                h += extSelect('jj-ext-work_location', T('work_request_label'), p.work_location, [{ v: '', t: T('select_option') }, { v: 'inside', t: T('location_inside') }, { v: 'outside', t: T('work_loc_outside_short') }, { v: 'both', t: T('location_both') }]);
                h += '<div id="jj-ext-target-country-wrap">';
                h += extSelect('jj-ext-target_country_1', T('target_country_1_label'), p.target_country_1, [{ v: '', t: T('select_option') }].concat(countryOpts));
                h += extSelect('jj-ext-target_country_2', T('target_country_2_label'), p.target_country_2, [{ v: '', t: T('select_option') }].concat(countryOpts));
                h += extSelect('jj-ext-target_country_3', T('target_country_3_label'), p.target_country_3, [{ v: '', t: T('select_option') }].concat(countryOpts));
                h += '</div>';
                h += extCheckboxGroup('worktype', T('cooperation_pref_label'), p.worktype, JJ_EXT_WORK_TYPES);
                h += '<div style="font-size:13px;font-weight:bold;margin:14px 0 8px;">' + T('address') + ':</div>';
                h += extProvCityHTML('address', 'ایران', p.province, p.city, meta);
                h += extInput('jj-ext-address_rest', T('address_rest_label'), p.address);
                return h;
            }
            function extJobseekerFieldsAfterInsert(data, meta) {
                data = data || {};
                function syncNationalId() {
                    var wrap = $('#jj-ext-national_id-wrap');
                    var nat = $('#jj-ext-nationality');
                    if (wrap && nat) wrap.style.display = (nat.value === 'iranian') ? '' : 'none';
                }
                var natSel = $('#jj-ext-nationality');
                if (natSel) { natSel.addEventListener('change', syncNationalId); syncNationalId(); }
                function syncTargetCountry() {
                    var wrap = $('#jj-ext-target-country-wrap');
                    var wl = $('#jj-ext-work_location');
                    if (wrap && wl) wrap.style.display = (wl.value === 'outside' || wl.value === 'both') ? '' : 'none';
                }
                var wlSel = $('#jj-ext-work_location');
                if (wlSel) { wlSel.addEventListener('change', syncTargetCountry); syncTargetCountry(); }
                extWireProvince('address', { value: 'ایران' }, meta || {});
            }
            function extEnsureProfessions() {
                if (extProfessions) return Promise.resolve(extProfessions);
                return dashGet('job-professions').then(function (res) {
                    extProfessions = (res && res.data) || [];
                    return extProfessions;
                });
            }
            function extCollectJobseeker() {
                var desiredNames = extTextToArr(extVal('#jj-ext-desired_jobs'));
                var desiredIds = desiredNames.map(function (n) { return dashProfessionIdByName(n, extProfessions || []); }).filter(function (id) { return id; });
                return {
                    nationality: extVal('#jj-ext-nationality'),
                    national_id: extVal('#jj-ext-national_id'),
                    gender: extVal('#jj-ext-gender'),
                    skills: extTextToArr(extVal('#jj-ext-skills')),
                    desired_jobs: desiredIds,
                    work_location: extVal('#jj-ext-work_location'),
                    target_country_1: extVal('#jj-ext-target_country_1'),
                    target_country_2: extVal('#jj-ext-target_country_2'),
                    target_country_3: extVal('#jj-ext-target_country_3'),
                    work_type: extCollectCheckboxGroup('worktype', JJ_EXT_WORK_TYPES),
                    province: extVal('#jj-ext-address-province'),
                    city: extVal('#jj-ext-address-city'),
                    address: extVal('#jj-ext-address_rest')
                };
            }

function extBuildEmployerFields(info, meta) {
                info = info || {};
                var h = '';
                h += extInput('jj-ext-applicant_type', T('applicant_type_label'), info.applicant_type);
                h += extInput('jj-ext-company_name', T('company_name_label'), info.company_name);
                h += extInput('jj-ext-company_reg_number', T('company_reg_number'), info.company_reg_number);
                h += extInput('jj-ext-company_reg_country', T('company_reg_country_label'), info.company_reg_country);
                h += extInput('jj-ext-company_reg_province', T('company_reg_province_label'), info.company_reg_province);
                h += extInput('jj-ext-business_field', T('business_field'), info.business_field);
                h += extPersonFields('ceo', T('ceo_label'), info.ceo);
                h += extInput('jj-ext-ceo_mobile', T('ceo_mobile'), info.ceo_mobile);
                h += extPersonFields('hr_officer', T('hr_officer_label'), info.hr_officer);
                h += extInput('jj-ext-hr_officer_phone', T('hr_officer_phone_label'), info.hr_officer_phone);
                h += extPersonFields('contractor', T('contractor_rep_label'), info.contractor);
                h += extInput('jj-ext-contractor_office_phone', T('contractor_office_phone_label'), info.contractor_office_phone);
                h += extAddressFields('office_address', T('office_addr_short'), info.office_address, meta);
                h += extAddressFields('residence_address', T('residence_address'), info.residence_address, meta);
                h += extMessengerFields(info.messengers);
                h += extTextarea('jj-ext-notes', T('notes_label_short'), info.notes);
                return h;
            }
            function extEmployerFieldsAfterInsert(info) {
                info = info || {};
                extPersonFieldsAfterInsert('ceo', info.ceo);
                extPersonFieldsAfterInsert('hr_officer', info.hr_officer);
                extPersonFieldsAfterInsert('contractor', info.contractor);
            }
            function extCollectEmployer() {
                return {
                    applicant_type: extVal('#jj-ext-applicant_type'),
                    company_name: extVal('#jj-ext-company_name'),
                    company_reg_number: extVal('#jj-ext-company_reg_number'),
                    company_reg_country: extVal('#jj-ext-company_reg_country'),
                    company_reg_province: extVal('#jj-ext-company_reg_province'),
                    business_field: extVal('#jj-ext-business_field'),
                    ceo: extCollectPerson('ceo'),
                    ceo_mobile: extVal('#jj-ext-ceo_mobile'),
                    hr_officer: extCollectPerson('hr_officer'),
                    hr_officer_phone: extVal('#jj-ext-hr_officer_phone'),
                    contractor: extCollectPerson('contractor'),
                    contractor_office_phone: extVal('#jj-ext-contractor_office_phone'),
                    office_address: extCollectAddress('office_address'),
                    residence_address: extCollectAddress('residence_address'),
                    messengers: extCollectMessengers(),
                    notes: extVal('#jj-ext-notes')
                };
            }

            function extBuildAgencyFields(info, meta) {
                info = info || {};
                var countryOpts = [{ v: '', t: T('select_option') }].concat(((meta && meta.countries) || []).map(function (c) { return { v: c, t: c }; }));
                var h = '';
                h += extInput('jj-ext-agency_name', T('agency_name_label'), info.agency_name);
                h += extSelect('jj-ext-license_type', T('license_type'), info.license_type, [{ v: '', t: T('select_option') }, { v: 'domestic', t: T('license_domestic') }, { v: 'foreign', t: T('license_foreign') }, { v: 'none', t: T('license_none') }]);
                h += extSelect('jj-ext-cooperation_type', T('cooperation_type'), info.cooperation_type, [{ v: '', t: T('select_option') }, { v: 'exchange', t: T('coop_exchange_short') }, { v: 'post_only', t: T('coop_post_only_short') }]);
                h += extInput('jj-ext-business_field', T('business_field'), info.business_field);
                h += extInput('jj-ext-staff_count', T('staff_count'), info.staff_count);
                h += extPersonFields('owner', T('owner_label'), info.owner);
                h += extInput('jj-ext-owner_mobile', T('owner_mobile'), info.owner_mobile);
                h += extInput('jj-ext-office_phone', T('office_phone'), info.office_phone);
                h += extSelect('jj-ext-target_country_1', T('target_country_1_label'), info.target_country_1, countryOpts);
                h += extSelect('jj-ext-target_country_2', T('target_country_2_label'), info.target_country_2, countryOpts);
                h += extSelect('jj-ext-target_country_3', T('target_country_3_label'), info.target_country_3, countryOpts);
                h += extAddressFields('office_address', T('full_office_addr_label'), info.office_address, meta);
                h += extMessengerFields(info.messengers);
                h += extTextarea('jj-ext-notes', T('notes_label_short'), info.notes);
                return h;
            }
            function extAgencyFieldsAfterInsert(info) {
                info = info || {};
                extPersonFieldsAfterInsert('owner', info.owner);
            }
            function extCollectAgency() {
                var officeAddr = extCollectAddress('office_address');
                return {
                    agency_name: extVal('#jj-ext-agency_name'),
                    license_type: extVal('#jj-ext-license_type'),
                    cooperation_type: extVal('#jj-ext-cooperation_type'),
                    business_field: extVal('#jj-ext-business_field'),
                    staff_count: extVal('#jj-ext-staff_count'),
                    owner: extCollectPerson('owner'),
                    owner_mobile: extVal('#jj-ext-owner_mobile'),
                    office_country: officeAddr.country,
                    office_province: officeAddr.province,
                    office_city: officeAddr.city,
                    office_phone: extVal('#jj-ext-office_phone'),
                    target_country_1: extVal('#jj-ext-target_country_1'),
                    target_country_2: extVal('#jj-ext-target_country_2'),
                    target_country_3: extVal('#jj-ext-target_country_3'),
                    office_address: officeAddr,
                    messengers: extCollectMessengers(),
                    notes: extVal('#jj-ext-notes')
                };
            }

            function extSetEditable(enabled) {
                var box = $('#jj-ext-fields');
                if (!box) return;
                var els = box.querySelectorAll('input, select, textarea');
                els.forEach(function (el) {
                    el.disabled = !enabled;
                    el.style.background = enabled ? '#fff' : '#f3f4f6';
                    el.style.color = enabled ? '#111827' : '#6b7280';
                });
                var editBtn = $('#jj-ext-btn-edit'), saveBtn = $('#jj-ext-btn-save'), cancelBtn = $('#jj-ext-btn-cancel');
                if (editBtn) editBtn.style.display = enabled ? 'none' : 'inline-block';
                if (saveBtn) saveBtn.style.display = enabled ? 'inline-block' : 'none';
                if (cancelBtn) cancelBtn.style.display = enabled ? 'inline-block' : 'none';
            }

            function extRenderProfile(role, data, meta) {
                var box = $('#jj-ext-fields');
                if (!box) return;
                if (role === 'jobseeker' || role === 'team_leader') {
                    box.innerHTML = extBuildJobseekerFields(data || {}, meta); extJobseekerFieldsAfterInsert(data || {}, meta); extWireAllAddressCascades(meta);
                } else if (role === 'employer') {
                    box.innerHTML = extBuildEmployerFields(data || {}, meta);
                    extEmployerFieldsAfterInsert(data || {});
                    extWireAllAddressCascades(meta);
                } else if (role === 'agency') {
                    box.innerHTML = extBuildAgencyFields(data || {}, meta);
                    extAgencyFieldsAfterInsert(data || {});
                    extWireAllAddressCascades(meta);
                } else {
                    box.innerHTML = '<p style="color:#6b7280;font-size:13px;">' + T('no_extra_info_for_role') + '</p>';
                    var eb = $('#jj-ext-btn-edit'); if (eb) eb.style.display = 'none';
                }
            }

            function extSaveProfile() {
                var saveBtn = $('#jj-ext-btn-save');
                if (saveBtn) saveBtn.disabled = true;
                var path = null, body = null;
                if (extRole === 'jobseeker' || extRole === 'team_leader') { path = 'profile/jobseeker'; body = extCollectJobseeker(); }
                else if (extRole === 'employer') { path = 'profile/employer'; body = extCollectEmployer(); }
                else if (extRole === 'agency') { path = 'profile/agency'; body = extCollectAgency(); }
                if (!path) { if (saveBtn) saveBtn.disabled = false; return; }
                api(path, body, true).then(function (res) {
                    if (saveBtn) saveBtn.disabled = false;
                    if (res.ok && res.data && res.data.success !== false) {
                        setMsg('#jj-ext-msg', T('info_saved_ok'), false);
                        extSetEditable(false);
                    } else {
                        setMsg('#jj-ext-msg', (res.data && res.data.message) ? res.data.message : T('save_failed'), true);
                    }
                }).catch(function () {
                    if (saveBtn) saveBtn.disabled = false;
                    setMsg('#jj-ext-msg', T('error_network'), true);
                });
            }

            function extLoadProfile() {
                if (!state.token) return;
                fetch(REST + 'profile/summary', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.success) return;
                        extRole = data.role || '';
                        function afterMeta() {
                            if (extRole === 'jobseeker' || extRole === 'team_leader') {
                                fetch(REST + 'profile/jobseeker/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                                    .then(function (r2) { return r2.json(); })
                                    .then(function (d2) { var __p2 = (d2 && d2.profile) ? d2.profile : {}; extEnsureProfessions().then(function () { extRenderProfile(extRole, __p2, extMeta); }); })
                                    .catch(function () { extRenderProfile(extRole, {}, extMeta); });
                            } else {
                                extRenderProfile(extRole, (data.summary && data.summary.info) ? data.summary.info : {}, extMeta);
                            }
                        }
                        if (extMeta) { afterMeta(); return; }
                        fetch(REST + 'job-meta-options', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                            .then(function (r3) { return r3.json(); })
                            .then(function (d3) { extMeta = (d3 && d3.data) ? d3.data : (d3 || {}); afterMeta(); })
                            .catch(function () { extMeta = {}; afterMeta(); });
                    })
                    .catch(function () { });
            }

            (function () {
                var sections = document.querySelector('.jj-acct-sections');
                if (sections && !$('#jj-ext-profile')) {
                    sections.insertAdjacentHTML('beforeend', '<div id="jj-ext-profile" class="jj-acct-box" style="margin-top:16px;"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;"><h3 style="margin:0;font-size:16px;">' + T('additional_info_heading') + '</h3><div><button type="button" id="jj-ext-btn-edit" class="jj-btn-link">' + T('edit_info_btn') + '</button><button type="button" id="jj-ext-btn-save" class="jj-btn-link" style="display:none;margin-right:10px;">' + T('save_changes_btn') + '</button><button type="button" id="jj-ext-btn-cancel" class="jj-btn-link" style="display:none;margin-right:10px;">' + T('cancel') + '</button></div></div><div id="jj-ext-msg" class="jj-msg"></div><div id="jj-ext-fields">' + T('loading') + '</div></div>');
                }
            })();

            (function () {
                var editBtn = $('#jj-ext-btn-edit'), saveBtn = $('#jj-ext-btn-save'), cancelBtn = $('#jj-ext-btn-cancel');
                if (editBtn) editBtn.addEventListener('click', function () { extSetEditable(true); });
                if (cancelBtn) cancelBtn.addEventListener('click', function () { extLoadProfile(); extSetEditable(false); setMsg('#jj-ext-msg', '', false); });
                if (saveBtn) saveBtn.addEventListener('click', extSaveProfile);
            })();
            extLoadProfile();
            /*
             * یکسان‌سازی (۲۰۲۶/۰۹/۰۵): این بخش («کارهای شغلی» / jj-dash-ext) که این‌جا
             * بود، یک پیاده‌سازیِ کاملاً موازی و تکراریِ همان قابلیت‌هایی بود که در
             * مراحل «آگهی‌های شغلی من» (data-step=jobs) و «فرصت‌های شغلی» (data-step=
             * browse-jobs) از قبل، کامل‌تر و با جزئیات بیشتر (فیلتر ملیت/جنسیت/مهارت،
             * قفل چت قبل از پذیرش، تیک تأیید مسیر قرارداد) پیاده‌سازی شده بود. چون
             * این باکس همیشه (فارغ از این‌که کاربر کدام مرحله را باز کرده) زیر پروفایل
             * تکمیلی اضافه می‌شد، باعث دیده‌شدن دو تجربه‌ی متفاوت برای «آگهی‌های من» و
             * «درخواست‌های من» می‌شد. کد آن (به همراه توابع کمکیِ Jalali-date که این‌جا
             * دوباره [duplicate] تعریف شده بودند و نسخه‌ی اصلی‌شان در بالای همین فایل
             * از قبل وجود دارد) حذف شد؛ تنها پیاده‌سازیِ فعال، همان مراحل قبلی هستند.
             */
            loadAccountData();
            loadAccountJobs();
            loadEntitlementsSummary();

            var jjSupportName = <?php echo wp_json_encode($display); ?>;
            var jjSupportPhone = <?php echo wp_json_encode($user->user_login); ?>;
            (function () {
                var sections = document.querySelector('.jj-acct-sections');
                if (sections && !document.getElementById('jj-support-box')) {
                    sections.insertAdjacentHTML('beforeend', '<div id="jj-support-box" class="jj-acct-box" style="margin-top:16px;"><h3 style="margin:0 0 12px;font-size:16px;">پیام برای پشتیبانی</h3><div id="jj-support-body">در حال بارگذاری...</div></div>');
                }
            })();

            function escHtmlSupport(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

            function jjSupportStatusLabel(status) {
                return status === 'open' ? 'در حال بررسی' : 'پاسخ داده شده';
            }

            function jjOpenSupportDetail(li, ticketId) {
                var detail = li.querySelector('.jj-support-detail');
                if (detail.style.display !== 'none') { detail.style.display = 'none'; return; }
                detail.style.display = '';
                detail.innerHTML = 'در حال بارگذاری...';
                fetch(REST + 'support/tickets/' + ticketId, { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.success) { detail.innerHTML = 'خطا در دریافت پیام.'; return; }
                        var html = '';
                        (res.messages || []).forEach(function (m) {
                            html += '<div style="margin-bottom:8px;"><strong>' + (m.is_admin ? 'پشتیبانی' : 'شما') + ':</strong><br>' + escHtmlSupport(m.body) + '</div>';
                        });
                        if (!res.messages || !res.messages.length) { html = 'هنوز پاسخی ثبت نشده است.'; }
                        detail.innerHTML = html;
                    })
                    .catch(function () { detail.innerHTML = 'خطای شبکه.'; });
            }

            function jjLoadSupport() {
                if (!state.token) return;
                fetch(REST + 'support/tickets/mine', { headers: { 'X-JJ-Auth': state.token }, credentials: 'same-origin' })
                    .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
                    .then(function (res) {
                        var body = document.getElementById('jj-support-body');
                        if (!body) return;

                        if (!res.ok || !res.data || !res.data.may_send) {
                            body.innerHTML = '<p style="color:#dc2626;font-size:13px;">برای ارسال پیام به پشتیبانی باید وارد سایت شوید، ثبت‌نام کنید یا یکی از بسته‌های اشتراکی را تهیه کنید.</p>';
                            return;
                        }

                        var html = '';
                        html += '<div style="margin-bottom:16px;">';
                        html += '<input class="jj-input" id="jj-support-name" placeholder="نام و نام‌خانوادگی" style="width:100%;margin-bottom:8px;" value="' + escHtmlSupport(jjSupportName || '') + '" readonly>';
                        html += '<input class="jj-input" id="jj-support-phone" placeholder="شماره تلفن" style="width:100%;margin-bottom:8px;" value="' + escHtmlSupport(jjSupportPhone || '') + '" readonly>';
                        html += '<input class="jj-input" id="jj-support-subject" placeholder="موضوع پیام" style="width:100%;margin-bottom:8px;">';
                        html += '<textarea class="jj-input" id="jj-support-message" maxlength="500" placeholder="متن پیام (حداکثر ۵۰۰ کاراکتر)" style="width:100%;min-height:80px;margin-bottom:8px;"></textarea>';
                        html += '<button type="button" class="jj-btn" id="jj-support-send-btn">ارسال پیام</button>';
                        html += '<div id="jj-support-msg" class="jj-msg"></div>';
                        html += '</div>';

                        html += '<h4 style="font-size:14px;margin:16px 0 8px;">پیام‌های پشتیبانی</h4>';
                        var tickets = (res.data && res.data.tickets) || [];
                        if (!tickets.length) {
                            html += '<p style="font-size:13px;color:#6b7280;">هنوز پیامی ثبت نکرده‌اید.</p>';
                        } else {
                            html += '<ul style="list-style:none;padding:0;margin:0;">';
                            tickets.forEach(function (t) {
                                var d = new Date(String(t.updated_at).replace(' ', 'T'));
                                var dateStr = isNaN(d.getTime()) ? '' : d.toLocaleDateString('fa-IR');
                                var timeStr = isNaN(d.getTime()) ? '' : d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
                                var isAnswered = t.status !== 'open';
                                html += '<li class="jj-support-item" data-id="' + t.id + '" style="padding:10px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;cursor:pointer;">'
                                    + '<div style="display:flex;justify-content:space-between;font-size:13px;gap:8px;"><span>' + escHtmlSupport(t.subject) + '</span><span style="color:' + (isAnswered ? '#16a34a' : '#a16207') + ';white-space:nowrap;">' + jjSupportStatusLabel(t.status) + '</span></div>'
                                    + '<div style="font-size:11px;color:#9ca3af;margin-top:4px;">' + dateStr + ' - ' + timeStr + '</div>'
                                    + '<div class="jj-support-detail" style="display:none;margin-top:8px;font-size:12.5px;background:#f6f7f7;padding:8px;border-radius:6px;"></div>'
                                    + '</li>';
                            });
                            html += '</ul>';
                        }
                        body.innerHTML = html;

                        var sendBtn = document.getElementById('jj-support-send-btn');
                        if (sendBtn) sendBtn.addEventListener('click', function () {
                            var subject = document.getElementById('jj-support-subject').value.trim();
                            var message = document.getElementById('jj-support-message').value.trim();
                            if (!subject || !message) { setMsg('#jj-support-msg', 'موضوع و متن پیام الزامی است.', true); return; }
                            sendBtn.disabled = true;
                            api('support/tickets', { subject: subject, message: message }, true).then(function (res2) {
                                sendBtn.disabled = false;
                                if (!res2.ok) { setMsg('#jj-support-msg', (res2.data && res2.data.message) || 'خطا رخ داد.', true); return; }
                                setMsg('#jj-support-msg', 'پیام شما ثبت و برای پشتیبانی ارسال شد.', false);
                                document.getElementById('jj-support-subject').value = '';
                                document.getElementById('jj-support-message').value = '';
                                jjLoadSupport();
                            }).catch(function () { sendBtn.disabled = false; setMsg('#jj-support-msg', 'خطای شبکه.', true); });
                        });

                        document.querySelectorAll('.jj-support-item').forEach(function (li) {
                            li.addEventListener('click', function () { jjOpenSupportDetail(li, li.getAttribute('data-id')); });
                        });
                    })
                    .catch(function () {});
            }
            jjLoadSupport();
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
