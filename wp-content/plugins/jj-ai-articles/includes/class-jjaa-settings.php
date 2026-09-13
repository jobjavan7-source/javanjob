<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * صفحه‌ی تنظیمات + فرم «مقاله‌ی جدید» (تولید دستی با موضوع/تعداد
 * کلمات/تعداد عکس/زمان انتشار دلخواه). کلید Gemini در گزینه‌ی اختصاصی
 * jjaa_gemini_api_key ذخیره می‌شود (کاملاً مستقل از کلید GapGPT سایر
 * بخش‌های سایت).
 */
class JJAA_Settings {

	const OPTION_GROUP = 'jjaa_settings_group';

	/**
	 * زمان‌بندی گروهِ تنظیماتِ جداگانه‌ای دارد و این عمدی است:
	 * wp-admin/options.php هنگام ذخیره‌ی یک فرم، روی *همه‌ی* گزینه‌های آن
	 * گروه update_option می‌زند و هر گزینه‌ای که در POST نباشد را null
	 * می‌کند. اگر هر دو فرم در یک گروه بودند، ذخیره‌ی فرم زمان‌بندی آدرس
	 * Worker و سکرت را پاک می‌کرد (و برعکس).
	 */
	const SCHEDULE_GROUP = 'jjaa_schedule_group';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_jjaa_create_article', array( __CLASS__, 'handle_create_article' ) );
		add_action( 'wp_ajax_jjaa_list_models', array( __CLASS__, 'ajax_list_models' ) );
	}

	public static function add_menu() {
		add_menu_page(
			'مقالات هوش مصنوعی',
			'مقالات AI',
			'manage_options',
			'jjaa-settings',
			array( __CLASS__, 'render' ),
			'dashicons-edit-page',
			59
		);
	}

	public static function register_settings() {
		register_setting( self::OPTION_GROUP, 'jjaa_relay_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( self::OPTION_GROUP, 'jjaa_relay_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::OPTION_GROUP, 'jjaa_gemini_model', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::OPTION_GROUP, 'jjaa_image_gen_model', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::OPTION_GROUP, 'jjaa_pexels_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		// --- زمان‌بندی تولید خودکار ---
		register_setting( self::SCHEDULE_GROUP, 'jjaa_schedule_mode', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_schedule_mode' ) ) );
		register_setting( self::SCHEDULE_GROUP, 'jjaa_schedule_time', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_schedule_time' ) ) );
		register_setting( self::SCHEDULE_GROUP, 'jjaa_schedule_days', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_schedule_days' ) ) );
		register_setting( self::SCHEDULE_GROUP, 'jjaa_auto_word_count', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_word_count' ) ) );
		register_setting( self::SCHEDULE_GROUP, 'jjaa_auto_image_count', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_image_count' ) ) );

		// تغییر هرکدام از این‌ها باید نوبت بعدی را همان لحظه جابه‌جا کند،
		// وگرنه ساعت/روزِ تازه تا بعد از اجرای بعدیِ قدیمی اثر نمی‌کرد.
		foreach ( array( 'jjaa_schedule_mode', 'jjaa_schedule_time', 'jjaa_schedule_days' ) as $opt ) {
			add_action( "update_option_{$opt}", array( 'JJAA_Cron', 'reschedule' ), 10, 0 );
			add_action( "add_option_{$opt}", array( 'JJAA_Cron', 'reschedule' ), 10, 0 );
		}
	}

	public static function sanitize_schedule_mode( $v ) {
		return in_array( $v, array( 'daily', 'weekly', 'off' ), true ) ? $v : JJAA_Cron::DEFAULT_MODE;
	}

	public static function sanitize_schedule_time( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $v ) ? $v : JJAA_Cron::DEFAULT_TIME;
	}

	public static function sanitize_schedule_days( $v ) {
		if ( ! is_array( $v ) ) {
			return JJAA_Cron::DEFAULT_DAYS;
		}
		$days = array_values( array_unique( array_filter( array_map( 'intval', $v ), function ( $d ) {
			return $d >= 0 && $d <= 6;
		} ) ) );
		sort( $days );
		// خالی‌گذاشتن همه‌ی روزها یعنی هیچ‌وقت اجرا نشود — که کار «خاموش»
		// است، نه زمان‌بندی هفتگی؛ پس به پیش‌فرض برمی‌گردیم تا زنجیره نمیرد.
		return empty( $days ) ? JJAA_Cron::DEFAULT_DAYS : $days;
	}

	public static function sanitize_word_count( $v ) {
		$n = (int) $v;
		return ( $n >= 300 && $n <= 6000 ) ? $n : JJAA_Generator::DEFAULT_WORD_COUNT;
	}

	public static function sanitize_image_count( $v ) {
		$n = (int) $v;
		return ( $n >= 0 && $n <= 6 ) ? $n : JJAA_Generator::DEFAULT_IMAGE_COUNT;
	}

	public static function ajax_list_models() {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'دسترسی مجاز نیست.', 403 );
		check_ajax_referer( 'jjaa_list_models' );

		$models = JJAA_Gemini::list_models();
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( $models->get_error_message() );
		}
		wp_send_json_success( $models );
	}

	/** پردازش فرم «مقاله‌ی جدید» — همیشه در پس‌زمینه (async) اجرا می‌شود. */
	public static function handle_create_article() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی مجاز نیست.' );
		check_admin_referer( 'jjaa_create_article' );

		$topic        = sanitize_text_field( wp_unslash( $_POST['jjaa_topic'] ?? '' ) );
		$word_count   = max( 0, (int) ( $_POST['jjaa_word_count'] ?? 0 ) );
		$image_count  = max( 0, min( 6, (int) ( $_POST['jjaa_image_count'] ?? JJAA_Generator::DEFAULT_IMAGE_COUNT ) ) );
		$publish_mode = ( ( $_POST['jjaa_publish_mode'] ?? 'now' ) === 'schedule' ) ? 'schedule' : 'now';

		$publish_ts = 0;
		if ( $publish_mode === 'schedule' && ! empty( $_POST['jjaa_publish_datetime'] ) ) {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
			$dt = DateTime::createFromFormat( 'Y-m-d\TH:i', sanitize_text_field( wp_unslash( $_POST['jjaa_publish_datetime'] ) ), $tz );
			if ( $dt ) {
				$publish_ts = $dt->getTimestamp();
			}
		}

		JJAA_Cron::schedule_manual( array(
			'topic'             => $topic,
			'word_count'        => $word_count,
			'image_count'       => $image_count,
			'publish_timestamp' => $publish_ts,
		) );

		wp_redirect( admin_url( 'admin.php?page=jjaa-settings&run=queued' ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { echo '<div class="wrap"><p>دسترسی مجاز نیست.</p></div>'; return; }

		$relay_url       = get_option( 'jjaa_relay_url', '' );
		$relay_secret    = get_option( 'jjaa_relay_secret', '' );
		$model           = get_option( 'jjaa_gemini_model', 'gemini-flash-latest' );
		$image_gen_model = get_option( 'jjaa_image_gen_model', 'gemini-2.5-flash-image' );
		$pexels_key      = get_option( 'jjaa_pexels_api_key', '' );
		$last_log        = get_option( 'jjaa_last_run_log', array() );
		$image_diag      = get_option( 'jjaa_last_image_diagnostics', '' );
		$next_run        = wp_next_scheduled( JJAA_Cron::HOOK_AUTO );
		$sched_mode      = JJAA_Cron::mode();
		$sched_time      = get_option( 'jjaa_schedule_time', JJAA_Cron::DEFAULT_TIME );
		$sched_days      = JJAA_Cron::days();
		$auto_words      = (int) get_option( 'jjaa_auto_word_count', JJAA_Generator::DEFAULT_WORD_COUNT );
		$auto_images     = (int) get_option( 'jjaa_auto_image_count', JJAA_Generator::DEFAULT_IMAGE_COUNT );
		$day_names       = array( 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه' );
		?>
		<div class="wrap" dir="rtl">
			<h1>مقالات هوش مصنوعی (کار و اقتصاد)</h1>

			<?php if ( ( $_GET['run'] ?? '' ) === 'queued' ) : ?>
				<div class="notice notice-info"><p>درخواست ثبت شد و تولید مقاله در پس‌زمینه شروع شد. چون نوشتن یک مقاله‌ی کامل ممکن است ۱ تا ۳ دقیقه طول بکشد، این صفحه را کمی بعد دوباره بار بزنید و بخش «آخرین رویداد» را بررسی کنید.</p></div>
			<?php endif; ?>

			<h2>مقاله‌ی جدید</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'jjaa_create_article' ); ?>
				<input type="hidden" name="action" value="jjaa_create_article">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="jjaa_topic">موضوع نوشته</label></th>
						<td>
							<input type="text" name="jjaa_topic" id="jjaa_topic" class="regular-text" placeholder="مثلاً: راهنمای مذاکره حقوق در مصاحبه‌ی شغلی">
							<p class="description">خالی بگذارید تا موضوع (در حوزه‌ی کار و اقتصاد) خودکار انتخاب شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jjaa_word_count">تعداد کلمات نوشته</label></th>
						<td><input type="number" name="jjaa_word_count" id="jjaa_word_count" min="300" max="6000" step="50" value="<?php echo (int) JJAA_Generator::DEFAULT_WORD_COUNT; ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="jjaa_image_count">تعداد عکس‌های نوشته</label></th>
						<td><input type="number" name="jjaa_image_count" id="jjaa_image_count" min="0" max="6" value="<?php echo (int) JJAA_Generator::DEFAULT_IMAGE_COUNT; ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row">انتشار</th>
						<td>
							<label><input type="radio" name="jjaa_publish_mode" value="now" checked> همین الان (بعد از تولید)</label><br>
							<label><input type="radio" name="jjaa_publish_mode" value="schedule" id="jjaa_publish_schedule_radio"> زمان‌بندی برای:</label>
							<input type="datetime-local" name="jjaa_publish_datetime" id="jjaa_publish_datetime" disabled>
						</td>
					</tr>
				</table>
				<?php submit_button( 'دستور تولید مقاله', 'primary', 'submit', false ); ?>
			</form>

			<script>
			(function () {
				var radios = document.querySelectorAll('input[name="jjaa_publish_mode"]');
				var dt = document.getElementById('jjaa_publish_datetime');
				radios.forEach(function (r) {
					r.addEventListener('change', function () {
						dt.disabled = (document.getElementById('jjaa_publish_schedule_radio').checked === false);
					});
				});
			})();
			</script>

			<?php if ( ! empty( $last_log ) ) : ?>
				<h2>آخرین رویداد</h2>
				<p><strong><?php echo esc_html( $last_log['time'] ?? '' ); ?></strong> — وضعیت: <code><?php echo esc_html( $last_log['stage'] ?? '' ); ?></code></p>
				<pre style="background:#f6f7f7;padding:10px;max-width:800px;overflow:auto;direction:ltr;text-align:left;"><?php echo esc_html( $last_log['message'] ?? '' ); ?></pre>
			<?php endif; ?>

			<?php if ( '' !== $image_diag ) : ?>
				<h2>نتیجه‌ی آخرین تلاش برای عکس‌ها</h2>
				<pre style="background:#f6f7f7;padding:10px;max-width:800px;overflow:auto;direction:ltr;text-align:left;"><?php echo esc_html( $image_diag ); ?></pre>
			<?php endif; ?>

			<hr>
			<h2>زمان‌بندی تولید خودکار</h2>
			<p>
				<?php if ( $sched_mode === 'off' ) : ?>
					تولید خودکار <strong>خاموش</strong> است.
				<?php elseif ( $next_run ) : ?>
					اجرای بعدی: <strong><?php echo esc_html( wp_date( 'Y/m/d H:i', $next_run ) ); ?></strong>
					(به‌وقت <?php echo esc_html( wp_timezone_string() ); ?>)
				<?php else : ?>
					<em>هنوز نوبتی زمان‌بندی نشده — تنظیمات زیر را ذخیره کنید.</em>
				<?php endif; ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::SCHEDULE_GROUP ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">هر چند وقت؟</th>
						<td>
							<label><input type="radio" name="jjaa_schedule_mode" value="daily" <?php checked( $sched_mode, 'daily' ); ?>> روزانه</label><br>
							<label><input type="radio" name="jjaa_schedule_mode" value="weekly" <?php checked( $sched_mode, 'weekly' ); ?>> فقط روزهای انتخابی هفته</label><br>
							<label><input type="radio" name="jjaa_schedule_mode" value="off" <?php checked( $sched_mode, 'off' ); ?>> خاموش (فقط تولید دستی)</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jjaa_schedule_time">ساعت انتشار</label></th>
						<td>
							<input type="time" name="jjaa_schedule_time" id="jjaa_schedule_time" value="<?php echo esc_attr( $sched_time ); ?>">
							<p class="description">به‌وقت سایت (<?php echo esc_html( wp_timezone_string() ); ?>).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">روزهای هفته</th>
						<td>
							<?php foreach ( $day_names as $idx => $label ) : ?>
								<label style="display:inline-block;margin-left:14px;">
									<input type="checkbox" name="jjaa_schedule_days[]" value="<?php echo (int) $idx; ?>" <?php checked( in_array( $idx, $sched_days, true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">فقط وقتی حالت «روزهای انتخابی هفته» باشد اثر دارد. در حالت روزانه نادیده گرفته می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jjaa_auto_word_count">تعداد کلمه (اجرای خودکار)</label></th>
						<td>
							<input type="number" name="jjaa_auto_word_count" id="jjaa_auto_word_count" value="<?php echo (int) $auto_words; ?>" min="300" max="6000" step="100" class="small-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jjaa_auto_image_count">تعداد عکس (اجرای خودکار)</label></th>
						<td>
							<input type="number" name="jjaa_auto_image_count" id="jjaa_auto_image_count" value="<?php echo (int) $auto_images; ?>" min="0" max="6" class="small-text">
							<p class="description">اولین عکس تصویر شاخص می‌شود و بقیه بین بخش‌های مقاله پخش می‌شوند. صفر یعنی بدون عکس.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'ذخیره‌ی زمان‌بندی' ); ?>
			</form>

			<h2>تنظیمات API</h2>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="jjaa_relay_url">آدرس واسط Gemini (Cloudflare Worker)</label></th>
						<td>
							<input type="text" name="jjaa_relay_url" id="jjaa_relay_url" value="<?php echo esc_attr( $relay_url ); ?>" class="regular-text" dir="ltr" placeholder="https://your-worker.your-account.workers.dev">
							<p class="description">چون سرور سایت روی هاست ایرانی است و گوگل مستقیم به آن پاسخ نمی‌دهد، درخواست‌ها از طریق یک Worker رایگان روی Cloudflare به Gemini می‌رسند. کلید واقعی Gemini فقط داخل همان Worker نگه‌داری می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jjaa_relay_secret">کلید محرمانه‌ی واسط</label></th>
						<td>
							<input type="text" name="jjaa_relay_secret" id="jjaa_relay_secret" value="<?php echo esc_attr( $relay_secret ); ?>" class="regular-text" dir="ltr">
							<p class="description">همان مقدار RELAY_SECRET که در تنظیمات Worker وارد شده.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jjaa_gemini_model">مدل نوشتن مقاله</label></th>
						<td>
							<input type="text" name="jjaa_gemini_model" id="jjaa_gemini_model" value="<?php echo esc_attr( $model ); ?>" class="regular-text" dir="ltr">
							<button type="button" id="jjaa-fetch-models" class="button">دریافت لیست مدل‌های موجود</button>
							<span id="jjaa-models-result" style="display:block;margin-top:8px;"></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jjaa_image_gen_model">مدل تولید تصویر (fallback)</label></th>
						<td>
							<input type="text" name="jjaa_image_gen_model" id="jjaa_image_gen_model" value="<?php echo esc_attr( $image_gen_model ); ?>" class="regular-text" dir="ltr">
							<p class="description">فقط وقتی در Pexels عکس مرتبطی پیدا نشود استفاده می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jjaa_pexels_api_key">کلید API بانک عکس Pexels</label></th>
						<td><input type="text" name="jjaa_pexels_api_key" id="jjaa_pexels_api_key" value="<?php echo esc_attr( $pexels_key ); ?>" class="regular-text" dir="ltr"></td>
					</tr>
				</table>
				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>

			<h2>راهنمای اسلایدر</h2>
			<p>برای نمایش اسلایدر آخرین مقالات، شورت‌کد زیر را در هر صفحه‌ای قرار دهید: <code>[jj_ai_articles_slider]</code></p>
		</div>
		<script>
		(function () {
			var btn = document.getElementById('jjaa-fetch-models');
			if (!btn) return;
			btn.addEventListener('click', function () {
				var out = document.getElementById('jjaa-models-result');
				out.textContent = 'در حال دریافت...';
				var data = new FormData();
				data.append('action', 'jjaa_list_models');
				data.append('_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'jjaa_list_models' ) ); ?>);
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res.success) {
							out.textContent = 'مدل‌های موجود: ' + res.data.join('، ');
						} else {
							out.textContent = 'خطا: ' + res.data;
						}
					})
					.catch(function (e) { out.textContent = 'خطا: ' + e; });
			});
		})();
		</script>
		<?php
	}
}
