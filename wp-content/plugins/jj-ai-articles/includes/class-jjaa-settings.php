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
			<h2>زمان‌بندی خودکار</h2>
			<p>
				تولید خودکار: هر یکشنبه و پنج‌شنبه، ساعت ۱۸:۰۰ به‌وقت تهران.
				<?php if ( $next_run ) : ?>
					اجرای بعدی: <strong><?php echo esc_html( wp_date( 'Y/m/d H:i', $next_run ) ); ?></strong>
				<?php endif; ?>
			</p>

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
