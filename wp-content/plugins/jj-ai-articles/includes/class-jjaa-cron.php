<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * زمان‌بندیِ تولید خودکار — کاملاً از پنل مدیریت قابل تنظیم:
 *
 *   jjaa_schedule_mode  : daily | weekly | off   (پیش‌فرض daily)
 *   jjaa_schedule_time  : ساعت به شکل HH:MM      (پیش‌فرض 18:00)
 *   jjaa_schedule_days  : آرایه‌ی روزهای هفته ۰..۶ — فقط در حالت weekly
 *
 * چون WP-Cron فقط بازه‌ی ثابت (هر N ثانیه) را بلد است، نه «ساعت مشخص در
 * روزهای مشخص»، به‌جای recurring event یک single event زمان‌بندی می‌شود
 * که بعد از هر اجرا نوبت بعدی را دوباره زمان‌بندی می‌کند (زنجیره‌ی
 * خودتکرار). هر بار هم next_occurrence() تنظیمات روزِ ذخیره‌شده را
 * می‌خواند، پس تغییر تنظیمات از همان نوبت بعد اثر می‌کند.
 *
 * دستور دستیِ «مقاله‌ی جدید» از هوک جدا استفاده می‌کند تا هیچ اثری روی
 * این زنجیره‌ی خودکار نگذارد.
 */
class JJAA_Cron {

	const HOOK_AUTO          = 'jjaa_scheduled_generate';
	const HOOK_MANUAL        = 'jjaa_manual_generate';
	const HOOK_ATTACH_IMAGES = 'jjaa_attach_images';
	const OLD_DAILY_HOOK     = 'jjaa_daily_generate_article'; // نسخه‌ی قبلی (روزانه) — باید پاک شود.

	const DEFAULT_MODE = 'daily';
	const DEFAULT_TIME = '18:00';
	const DEFAULT_DAYS = array( 0, 4 ); // یکشنبه و پنج‌شنبه — فقط وقتی حالت weekly باشد.

	public static function init() {
		add_action( self::HOOK_AUTO, array( __CLASS__, 'run_auto' ) );
		add_action( self::HOOK_MANUAL, array( __CLASS__, 'run_manual' ) );
		add_action( self::HOOK_ATTACH_IMAGES, array( __CLASS__, 'run_attach_images' ), 10, 2 );
		self::maybe_migrate();

		// تور ایمنی: اگر به هر دلیلی نوبت بعدی از صف افتاده باشد (اجرای
		// نیمه‌کاره، پاک‌شدن رویدادهای cron، افزونه‌ی دیگر)، اولین بازدید
		// بعدی دوباره آن را می‌سازد. ensure_scheduled() اگر نوبتی در صف
		// باشد کاری نمی‌کند، پس هزینه‌اش فقط یک خواندن از آپشن cron است.
		self::ensure_scheduled();
	}

	public static function activate() {
		self::ensure_scheduled();
	}

	public static function deactivate() {
		$ts = wp_next_scheduled( self::HOOK_AUTO );
		if ( $ts ) wp_unschedule_event( $ts, self::HOOK_AUTO );
		$ts2 = wp_next_scheduled( self::HOOK_MANUAL );
		if ( $ts2 ) wp_unschedule_event( $ts2, self::HOOK_MANUAL );
		wp_clear_scheduled_hook( self::OLD_DAILY_HOOK );
	}

	/** یک‌بار: زمان‌بندی روزانه‌ی خیلی قدیمی (هوک منسوخ) را پاک می‌کند. */
	private static function maybe_migrate() {
		if ( ! get_option( 'jjaa_schedule_migrated_v2' ) ) {
			wp_clear_scheduled_hook( self::OLD_DAILY_HOOK );
			self::ensure_scheduled();
			update_option( 'jjaa_schedule_migrated_v2', 1, false );
		}

		// v3: زمان‌بندی از «هفتگیِ ثابت در کد» به «قابل تنظیم از پنل» تغییر
		// کرد. بدون این مهاجرت، نوبتی که از قاعده‌ی هفتگیِ قبلی در صف مانده
		// سر جایش می‌ماند و تنظیم تازه تا بعد از اجرای آن نوبت اثر نمی‌کرد.
		if ( ! get_option( 'jjaa_schedule_migrated_v3' ) ) {
			add_option( 'jjaa_schedule_mode', self::DEFAULT_MODE );
			add_option( 'jjaa_schedule_time', self::DEFAULT_TIME );
			add_option( 'jjaa_schedule_days', self::DEFAULT_DAYS );
			self::reschedule();
			update_option( 'jjaa_schedule_migrated_v3', 1, false );
		}
	}

	public static function ensure_scheduled() {
		if ( self::mode() === 'off' ) {
			self::clear_auto();
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_AUTO ) ) {
			wp_schedule_single_event( self::next_occurrence(), self::HOOK_AUTO );
		}
	}

	/** نوبت بعدی را پاک می‌کند (بدون دست‌زدن به هوک‌های دستی/عکس). */
	public static function clear_auto() {
		$ts = wp_next_scheduled( self::HOOK_AUTO );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK_AUTO );
			$ts = wp_next_scheduled( self::HOOK_AUTO );
		}
	}

	/**
	 * بعد از ذخیره‌ی تنظیمات زمان‌بندی صدا زده می‌شود: نوبت قدیمی پاک و
	 * نوبت تازه بر اساس تنظیمات جدید ساخته می‌شود، تا تغییر ساعت یا روزها
	 * لازم نباشد تا اجرای بعدی منتظر بماند.
	 */
	public static function reschedule() {
		self::clear_auto();
		self::ensure_scheduled();
	}

	/**
	 * اجرای خودکار: تولید مقاله با تعداد کلمه و تعداد عکسی که مدیر در
	 * تنظیمات گذاشته (نه ثابت‌های داخل کد)، سپس زمان‌بندی نوبت بعدی.
	 */
	public static function run_auto() {
		// نوبت بعدی *قبل* از تولید زمان‌بندی می‌شود، نه بعد از آن.
		// قبلاً ترتیب برعکس بود و این زنجیره را شکننده می‌کرد: اگر تولید
		// مقاله شکست می‌خورد — یا مثل همیشه روی این هاست، پروسه وسط کار
		// کشته می‌شد — خط زمان‌بندی هرگز اجرا نمی‌شد و هیچ نوبت بعدی‌ای
		// ساخته نمی‌شد. یعنی یک شکست، تولید خودکار را برای همیشه متوقف
		// می‌کرد (دقیقاً همین اتفاق در اولین اجرای روزانه افتاد).
		if ( self::mode() !== 'off' ) {
			wp_schedule_single_event( self::next_occurrence(), self::HOOK_AUTO );
		}

		JJAA_Generator::run( array(
			'word_count'  => (int) get_option( 'jjaa_auto_word_count', JJAA_Generator::DEFAULT_WORD_COUNT ),
			'image_count' => (int) get_option( 'jjaa_auto_image_count', JJAA_Generator::DEFAULT_IMAGE_COUNT ),
		) );
	}

	/** حالت زمان‌بندی، با اعتبارسنجی. */
	public static function mode() {
		$mode = (string) get_option( 'jjaa_schedule_mode', self::DEFAULT_MODE );
		return in_array( $mode, array( 'daily', 'weekly', 'off' ), true ) ? $mode : self::DEFAULT_MODE;
	}

	/** ساعت اجرا به شکل [ساعت, دقیقه]، با اعتبارسنجی. */
	public static function time_parts() {
		$raw = (string) get_option( 'jjaa_schedule_time', self::DEFAULT_TIME );
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $raw ), $m ) ) {
			list( $h, $i ) = explode( ':', self::DEFAULT_TIME );
			return array( (int) $h, (int) $i );
		}
		return array( (int) $m[1], (int) $m[2] );
	}

	/** روزهای هفته‌ی انتخابی (فقط حالت weekly)؛ اگر خالی بود به پیش‌فرض برمی‌گردد. */
	public static function days() {
		$days = get_option( 'jjaa_schedule_days', self::DEFAULT_DAYS );
		$days = is_array( $days ) ? array_values( array_unique( array_filter( array_map( 'intval', $days ), function ( $d ) {
			return $d >= 0 && $d <= 6;
		} ) ) ) : array();
		return empty( $days ) ? self::DEFAULT_DAYS : $days;
	}

	/** اجرای دستی از فرم «مقاله‌ی جدید» — بدون اثر روی زنجیره‌ی خودکار. */
	public static function run_manual( $args = array() ) {
		JJAA_Generator::run( is_array( $args ) ? $args : array() );
	}

	/** زمان‌بندی فوری یک اجرای دستی (async — نتیجه را منتظر نمی‌مانیم). */
	public static function schedule_manual( $args ) {
		wp_schedule_single_event( time(), self::HOOK_MANUAL, array( $args ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/** اجرای مرحله‌ی پیوست‌کردن عکس‌ها — در یک درخواست Cron کاملاً جدا از تولید متن مقاله. */
	public static function run_attach_images( $post_id, $queries ) {
		JJAA_Generator::run_attach_images( (int) $post_id, is_array( $queries ) ? $queries : array() );
	}

	/** زمان‌بندی فوری مرحله‌ی پیوست‌کردن عکس‌ها (async — با سقف زمانیِ تازه‌ی هاست). */
	public static function schedule_image_attachment( $post_id, $queries ) {
		wp_schedule_single_event( time(), self::HOOK_ATTACH_IMAGES, array( $post_id, $queries ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/** نزدیک‌ترین یکشنبه یا پنج‌شنبه‌ی ساعت ۱۸:۰۰ به‌وقت سایت، بعد از $from_ts (پیش‌فرض: الان). */
	public static function next_occurrence( $from_ts = null ) {
		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
		$now = new DateTime( '@' . ( $from_ts ?: time() ) );
		$now->setTimezone( $tz );

		$weekly = self::mode() === 'weekly';
		$days   = self::days();
		list( $hour, $minute ) = self::time_parts();

		for ( $i = 0; $i <= 8; $i++ ) {
			$candidate = clone $now;
			$candidate->modify( "+{$i} days" );
			$dow = (int) $candidate->format( 'w' ); // 0 = یکشنبه ... 6 = شنبه
			if ( $weekly && ! in_array( $dow, $days, true ) ) {
				continue;
			}
			$candidate->setTime( $hour, $minute, 0 );
			if ( $candidate->getTimestamp() > $now->getTimestamp() ) {
				return $candidate->getTimestamp();
			}
		}
		return $now->getTimestamp() + WEEK_IN_SECONDS; // نباید هرگز به اینجا برسد
	}
}
