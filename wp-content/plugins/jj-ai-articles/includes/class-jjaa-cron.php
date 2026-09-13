<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * زمان‌بندی هفتگی: یکشنبه‌ها و پنج‌شنبه‌ها ساعت ۱۸:۰۰ به‌وقت سایت
 * (Asia/Tehran). چون WP-Cron فقط بازه‌ی ثابت (هر N ثانیه) را بلد است، نه
 * «روزهای هفته»، به‌جای recurring event یک single event زمان‌بندی
 * می‌شود که بعد از هر اجرا، نوبت بعدی را دوباره زمان‌بندی می‌کند
 * (زنجیره‌ی خودتکرار). دستور دستیِ «مقاله‌ی جدید» از هوک جدا استفاده
 * می‌کند تا هیچ اثری روی این زنجیره‌ی خودکار نگذارد.
 */
class JJAA_Cron {

	const HOOK_AUTO          = 'jjaa_scheduled_generate';
	const HOOK_MANUAL        = 'jjaa_manual_generate';
	const HOOK_ATTACH_IMAGES = 'jjaa_attach_images';
	const OLD_DAILY_HOOK     = 'jjaa_daily_generate_article'; // نسخه‌ی قبلی (روزانه) — باید پاک شود.

	public static function init() {
		add_action( self::HOOK_AUTO, array( __CLASS__, 'run_auto' ) );
		add_action( self::HOOK_MANUAL, array( __CLASS__, 'run_manual' ) );
		add_action( self::HOOK_ATTACH_IMAGES, array( __CLASS__, 'run_attach_images' ), 10, 2 );
		self::maybe_migrate();
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

	/** یک‌بار (وقتی از نسخه‌ی قبلیِ «روزانه» ارتقا پیدا می‌کند): زمان‌بندی قدیمی را پاک و زمان‌بندی هفتگی را می‌سازد. */
	private static function maybe_migrate() {
		if ( get_option( 'jjaa_schedule_migrated_v2' ) ) {
			return;
		}
		wp_clear_scheduled_hook( self::OLD_DAILY_HOOK );
		self::ensure_scheduled();
		update_option( 'jjaa_schedule_migrated_v2', 1, false );
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK_AUTO ) ) {
			wp_schedule_single_event( self::next_occurrence(), self::HOOK_AUTO );
		}
	}

	/** اجرای خودکار هفتگی: تولید با تنظیمات پیش‌فرض، سپس زمان‌بندی نوبت بعدی. */
	public static function run_auto() {
		JJAA_Generator::run();
		wp_schedule_single_event( self::next_occurrence(), self::HOOK_AUTO );
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

		for ( $i = 0; $i <= 8; $i++ ) {
			$candidate = clone $now;
			$candidate->modify( "+{$i} days" );
			$dow = (int) $candidate->format( 'w' ); // 0 = یکشنبه, 4 = پنج‌شنبه
			if ( $dow === 0 || $dow === 4 ) {
				$candidate->setTime( 18, 0, 0 );
				if ( $candidate->getTimestamp() > $now->getTimestamp() ) {
					return $candidate->getTimestamp();
				}
			}
		}
		return $now->getTimestamp() + WEEK_IN_SECONDS; // نباید هرگز به اینجا برسد
	}
}
