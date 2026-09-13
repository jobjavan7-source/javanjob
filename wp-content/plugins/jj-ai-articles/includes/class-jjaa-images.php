<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * تصاویر مقاله: اول تلاش برای پیدا کردن عکس مرتبط و رایگان از Pexels؛
 * فقط اگر Pexels چیزی برای آن عبارت پیدا نکرد، به‌عنوان راه‌حل آخر یک
 * تصویر با Gemini ساخته می‌شود. اتصال مستقیم از سرور سایت (ایران) به
 * api.pexels.com با خطای شبکه (Connection reset) مواجه می‌شد؛ به همین
 * دلیل این درخواست هم مثل Gemini از همان Cloudflare Worker واسط عبور
 * می‌کند (مسیر /pexels/... روی همان Worker).
 */
class JJAA_Images {

	const SEARCH_PATH = '/pexels/v1/search';

	public static function api_key() {
		return trim( (string) get_option( 'jjaa_pexels_api_key', '' ) );
	}

	/**
	 * برای هر عبارت جست‌وجو، یک عکس (از Pexels یا در نبود نتیجه از GapGPT)
	 * دانلود و به کتابخانه‌ی رسانه اضافه می‌کند.
	 * @param string[] $queries
	 * @param int $post_id برای اتصال رسانه به همان پست (post_parent)
	 * @return int[] شناسه‌ی پیوست‌های ساخته‌شده (attachment IDs)
	 */
	public static function fetch_and_attach( $queries, $post_id ) {
		$attachment_ids = array();
		$used_photo_ids = array();
		$diagnostics    = array();

		foreach ( $queries as $query ) {
			$query = trim( (string) $query );
			if ( $query === '' ) {
				continue;
			}

			// کل پردازش این عبارت در try/catch است: اگر افزونه‌ی دیگری
			// از سایت (مثلاً بهینه‌ساز عکس) روی یکی از هوک‌های پردازش
			// تصویر خطای مرگبار بدهد، کل تولید مقاله متوقف نشود و لااقل
			// عبارت‌های بعدی/تصویر شاخصِ همین یکی از دست نرود.
			try {
				list( $photo, $pexels_note ) = self::search_pexels( $query, $used_photo_ids );
				$attachment_id = 0;

				if ( $photo ) {
					$used_photo_ids[] = $photo['id'];
					$image_src = $photo['src']['large2x'] ?? $photo['src']['large'] ?? $photo['src']['original'];
					list( $attachment_id, $download_note ) = self::download_and_insert( $image_src, $post_id, $query, $photo );
					if ( ! $attachment_id ) {
						$pexels_note = $download_note;
					}
				}

				// Pexels چیزی برای این عبارت نداشت (یا دانلودش خطا داد) → fallback به تولید تصویر.
				$fallback_note = '';
				if ( ! $attachment_id ) {
					list( $attachment_id, $fallback_note ) = self::generate_and_insert( $query, $post_id );
				}

				if ( $attachment_id ) {
					// تصویر شاخص را همین‌جا و بلافاصله (نه در انتهای تابع
					// caller) تنظیم می‌کنیم: هاست سایت اجراهای طولانی را
					// قطع می‌کند و اگر یکی از عبارت‌های بعدی کند/ناموفق
					// باشد، کل درخواست ممکن است قبل از رسیدن به آن مرحله
					// متوقف شود — در آن صورت این تصویرِ همین حالا موفق،
					// بدون تصویر شاخص می‌ماند.
					// مستقیم روی post meta می‌نویسیم (نه set_post_thumbnail)
					// چون تابع اصلی وردپرس برای تأیید، wp_get_attachment_image
					// را صدا می‌زند که از هوک‌های افزونه‌های دیگر سایت (مثلاً
					// بهینه‌ساز/CDN عکس) عبور می‌کند و در تست‌های واقعی دقیقاً
					// همین‌جا اجرا متوقف می‌شد؛ چون خودمان همین الان attachment
					// را با موفقیت ساختیم، نیازی به آن تأیید نیست.
					if ( empty( $attachment_ids ) ) {
						update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
					}
					$attachment_ids[] = $attachment_id;
					$diagnostics[] = $query . ': OK (' . ( $fallback_note ? 'gemini-fallback' : 'pexels' ) . ')';
				} else {
					$diagnostics[] = $query . ': FAILED — pexels: ' . $pexels_note . ' | fallback: ' . $fallback_note;
				}
			} catch ( \Throwable $e ) {
				$diagnostics[] = $query . ': CRASHED — ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
			}

			// همین‌جا هم ذخیره می‌شود (نه فقط در انتها) تا اگر عبارت بعدی
			// اجرا را متوقف کرد، دست‌کم نتیجه‌ی همین‌جا از دست نرود.
			update_option( 'jjaa_last_image_diagnostics', implode( "\n", $diagnostics ), false );
		}

		return $attachment_ids;
	}

	/** @return array [photo|null, note-string برای دیباگ] */
	private static function search_pexels( $query, $used_photo_ids ) {
		$api_key = self::api_key();
		if ( empty( $api_key ) ) {
			return array( null, 'no pexels api key configured' );
		}
		$relay_url    = JJAA_GapGPT::relay_url();
		$relay_secret = JJAA_GapGPT::relay_secret();
		if ( '' === $relay_url || '' === $relay_secret ) {
			return array( null, 'relay (Cloudflare Worker) not configured' );
		}

		$url = add_query_arg( array(
			'query'       => rawurlencode( $query ),
			'per_page'    => 5,
			'orientation' => 'landscape',
		), $relay_url . self::SEARCH_PATH );

		$response = wp_remote_get( $url, array(
			'timeout' => 30,
			'headers' => array(
				'X-Relay-Secret' => $relay_secret,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( null, 'pexels request error: ' . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			return array( null, 'pexels HTTP ' . $code . ' - ' . mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 200 ) );
		}
		if ( empty( $data['photos'] ) ) {
			return array( null, 'pexels: no results' );
		}

		foreach ( $data['photos'] as $candidate ) {
			if ( ! in_array( $candidate['id'], $used_photo_ids, true ) ) {
				return array( $candidate, '' );
			}
		}
		return array( null, 'pexels: all results already used' );
	}

	/** @return array [attachment_id (0 در خطا), note] */
	private static function download_and_insert( $image_url, $post_id, $alt_text, $photo ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// دانلود مستقیم فایل عکس از دامنه‌ی CDN عکس‌های Pexels (images.pexels.com)
		// هم مثل خودِ API جست‌وجو از سرور ایران قطع می‌شد؛ باید از همان Worker
		// واسط عبور کند، نه دانلود مستقیم.
		$relay_url    = JJAA_GapGPT::relay_url();
		$relay_secret = JJAA_GapGPT::relay_secret();
		if ( '' === $relay_url || '' === $relay_secret ) {
			return array( 0, 'relay not configured for image download' );
		}
		$proxied_url = $relay_url . '/proxy?' . http_build_query( array( 'url' => $image_url, 'secret' => $relay_secret ) );

		$tmp_file = download_url( $proxied_url, 60 );
		if ( is_wp_error( $tmp_file ) ) {
			return array( 0, 'pexels image download failed: ' . $tmp_file->get_error_message() );
		}

		$filename = 'jj-article-' . sanitize_title( $alt_text ) . '-' . wp_generate_password( 6, false ) . '.jpg';

		// این سایت ده‌ها اندازه‌ی سفارشیِ عکس دارد (لوگوی کاریابی، پورتفولیو
		// و غیره) که هیچ‌کدام به تصویر یک مقاله‌ی وبلاگ ربطی ندارند؛ ساختن
		// همه‌ی آن‌ها برای هر عکس (پردازش GD روی هاست ضعیف) آن‌قدر طول
		// می‌کشید که درخواست وسط کار قطع می‌شد و کد بعدی (تنظیم تصویر
		// شاخص و...) هرگز اجرا نمی‌شد. این فیلتر فقط برای همین یک آپلود،
		// اندازه‌های لازم برای نمایش مقاله را نگه می‌دارد.
		$limit_sizes = function ( $sizes ) {
			$keep = array( 'thumbnail', 'medium', 'medium_large', 'large' );
			return array_intersect_key( $sizes, array_flip( $keep ) );
		};
		// افزونه‌ی reSmush.it روی فیلتر wp_generate_attachment_metadata می‌نشیند
		// و هر عکس را به http://api.resmush.it می‌فرستد. در کد آن فقط
		// CURLOPT_CONNECTTIMEOUT (۱۰ ثانیه) تنظیم شده و CURLOPT_TIMEOUT —
		// یعنی سقف کل زمان انتقال — اصلاً تنظیم نشده است؛ پس اگر اتصال
		// برقرار شود ولی انتقال از سرور ایران متوقف بماند، curl بدون سقف
		// منتظر می‌ماند تا هاست خودِ پروسه‌ی PHP را بکشد. این kill با
		// try/catch گرفته نمی‌شود و دقیقاً به همین دلیل اجرا بعد از ساخته
		// شدن عکس و پیش از تنظیم تصویر شاخص قطع می‌شد.
		// این فیلتر هنگام اجرای WP-Cron همیشه ثبت می‌شود (شرط $doing_cron در
		// ProcessController) و مرحله‌ی عکس ما داخل cron اجرا می‌شود، پس
		// خاموش‌کردن گزینه‌ی «بهینه‌سازی هنگام آپلود» مشکل را حل نمی‌کند.
		// بنابراین فقط و فقط برای همین یک آپلود برداشته و بلافاصله برگردانده
		// می‌شود؛ رفتار reSmush.it در بقیه‌ی سایت دست‌نخورده می‌ماند.
		$resmush_cb = null;
		if ( class_exists( '\\Resmush\\Controller\\ProcessController' ) ) {
			$resmush_pc = \Resmush\Controller\ProcessController::getInstance();
			$candidate  = array( $resmush_pc, 'process_images' );
			if ( remove_filter( 'wp_generate_attachment_metadata', $candidate, 10 ) ) {
				$resmush_cb = $candidate;
			}
		}

		add_filter( 'intermediate_image_sizes_advanced', $limit_sizes );
		$attachment_id = media_handle_sideload( array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		), $post_id, $alt_text );
		remove_filter( 'intermediate_image_sizes_advanced', $limit_sizes );

		if ( $resmush_cb ) {
			add_filter( 'wp_generate_attachment_metadata', $resmush_cb, 10, 2 );
		}

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );
			return array( 0, 'sideload failed: ' . $attachment_id->get_error_message() );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );

		$photographer = $photo['photographer'] ?? '';
		if ( $photographer ) {
			wp_update_post( array(
				'ID'           => $attachment_id,
				'post_excerpt' => sprintf( 'عکس از %s در Pexels', $photographer ),
			) );
		}

		return array( (int) $attachment_id, 'ok' );
	}

	/**
	 * راه‌حل آخر (غیرفعال): تولید تصویر با Gemini وقتی Pexels چیز مرتبطی
	 * نداشت. مدل‌های تصویرساز Gemini برای حساب‌های رایگان سهمیه‌ی صفر
	 * دارند (خطای تأییدشده‌ی گوگل: «quota... limit: 0» برای
	 * generate_content_free_tier روی مدل‌های image) — یعنی این تماس
	 * همیشه شکست می‌خورد، و چون timeout آن طولانی است (تا ۱۲۰ ثانیه)،
	 * منتظرِ یک شکستِ قطعی ماندن باعث می‌شد هاست وسط اجرا درخواست را قطع
	 * کند و حتی تصویر شاخصِ از قبل موفق هم ذخیره نشود. طبق تصمیم صریح
	 * مدیر سایت (فقط هوش مصنوعی رایگان)، این مسیر بدون تماس شبکه رد
	 * می‌شود؛ اگر روزی صورتحساب Google Cloud فعال شد می‌توان دوباره
	 * تماس واقعی را برگرداند.
	 * @return array [attachment_id (0 در خطا), note-string برای دیباگ]
	 */
	private static function generate_and_insert( $query, $post_id ) {
		return array( 0, 'gemini image-gen skipped: تولید تصویر Gemini روی سطح رایگان سهمیه ندارد (نیاز به صورتحساب Google Cloud)' );
	}
}
