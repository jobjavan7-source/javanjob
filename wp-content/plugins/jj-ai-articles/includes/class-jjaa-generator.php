<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * موتور اصلی تولید مقاله: ساخت پرامپت، فراخوانی Gemini، تولید پست
 * وردپرس، افزودن تصاویر (شاخص + درون‌متنی)، و ذخیره‌ی فیلدهای سئو/GEO
 * اختصاصی (پست‌متا، مستقل از هر افزونه‌ی دیگر).
 *
 * دو مسیر استفاده می‌کنند:
 *  - زمان‌بندی خودکار هفتگی (JJAA_Cron): بدون override، موضوع تصادفی و
 *    طول/تعداد عکس پیش‌فرض.
 *  - فرم «مقاله‌ی جدید» در پیشخوان: با override دستیِ موضوع/تعداد
 *    کلمات/تعداد عکس/زمان انتشار.
 */
class JJAA_Generator {

	const META_FLAG        = '_jjaa_generated';
	const META_TITLE        = '_jjaa_seo_title';
	const META_DESCRIPTION  = '_jjaa_seo_description';
	const META_FOCUS        = '_jjaa_focus_keyword';
	const META_FAQ          = '_jjaa_faq';
	const META_WORDCOUNT    = '_jjaa_word_count';
	const CATEGORY_NAME     = 'مقالات اقتصاد و کار';
	const CATEGORY_SLUG     = 'articles-work-economy';

	const DEFAULT_WORD_COUNT  = 2800;
	const DEFAULT_IMAGE_COUNT = 3;

	/**
	 * @param array $overrides {
	 *   @type string $topic             موضوع دستیِ مقاله (خالی = موضوع تصادفیِ کار/اقتصاد)
	 *   @type int    $word_count        تعداد کلمه‌ی هدف (خالی/۰ = پیش‌فرض ۲۵۰۰-۳۰۰۰)
	 *   @type int    $image_count       تعداد عکس (خالی = پیش‌فرض ۲ تا ۳)
	 *   @type int    $publish_timestamp زمان انتشار به‌صورت timestamp UTC (خالی/گذشته = انتشار فوری)
	 * }
	 * @return int|WP_Error post_id در موفقیت
	 */
	public static function run( $overrides = array() ) {
		// روی این هاست max_execution_time پیش‌فرض ۳۰ ثانیه است؛ تولید یک
		// مقاله‌ی کامل (فراخوانی Gemini + دانلود چند عکس) معمولاً بیشتر طول
		// می‌کشد، پس این محدودیت را فقط برای همین اجرای Cron برمی‌داریم.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		$topic       = trim( (string) ( $overrides['topic'] ?? '' ) );
		$word_count  = (int) ( $overrides['word_count'] ?? 0 );
		$image_count = isset( $overrides['image_count'] ) ? max( 0, (int) $overrides['image_count'] ) : self::DEFAULT_IMAGE_COUNT;
		$publish_ts  = isset( $overrides['publish_timestamp'] ) ? (int) $overrides['publish_timestamp'] : 0;

		$avoid_titles = $topic === '' ? self::recent_titles( 20 ) : array();
		$prompt       = self::build_prompt( $avoid_titles, $topic, $word_count );

		$raw = JJAA_Gemini::chat( array(
			array( 'role' => 'user', 'content' => $prompt ),
		) );

		if ( is_wp_error( $raw ) ) {
			self::log( 'error', $raw->get_error_message() );
			return $raw;
		}

		$parsed = self::parse_ai_json( $raw );
		if ( is_wp_error( $parsed ) ) {
			self::log( 'parse_error', $parsed->get_error_message() . ' | RAW: ' . mb_substr( $raw, 0, 1500 ) );
			return $parsed;
		}

		$post_id = self::create_post( $parsed, $image_count, $publish_ts );
		if ( is_wp_error( $post_id ) ) {
			self::log( 'post_error', $post_id->get_error_message() );
			return $post_id;
		}

		self::log( 'success', 'post_id=' . $post_id . ' title=' . $parsed['title'] );
		return $post_id;
	}

	private static function recent_titles( $n ) {
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'numberposts'    => $n,
			'meta_key'       => self::META_FLAG,
			'meta_value'     => '1',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );
		return array_map( 'get_the_title', $posts );
	}

	private static function build_prompt( $avoid_titles, $topic = '', $word_count = 0 ) {
		$topic_text = $topic !== ''
			? "موضوع دقیق مقاله (حتماً همین را بنویس، تغییرش نده): {$topic}"
			: 'موضوع: یکی از زیرشاخه‌های «کار و اقتصاد» (مثلاً: بازار کار، مهارت‌آموزی، رزومه و مصاحبه، حقوق و دستمزد، کارآفرینی، اقتصاد خانواده، حقوق کارگر، دورکاری، فریلنسری، تحولات اقتصادی مؤثر بر اشتغال و مواردی از این دست) را خودت انتخاب کن.';

		$target_words = $word_count > 0 ? $word_count : self::DEFAULT_WORD_COUNT;
		$length_text  = sprintf( 'طول: تقریباً %d کلمه‌ی فارسی (حداکثر ۱۰ درصد کمتر یا بیشتر مجاز است).', $target_words );

		$avoid_text = empty( $avoid_titles )
			? ''
			: "قبلاً درباره‌ی این عنوان‌ها نوشته‌ای، دقیقاً همان موضوع را تکرار نکن و زاویه‌ی تازه‌ای انتخاب کن:\n- " . implode( "\n- ", $avoid_titles );

		return <<<PROMPT
تو یک نویسنده‌ی محتوای فارسی، متخصص حوزه‌ی «کار، استخدام و اقتصاد ایران» برای وبلاگ سایت کاریابی جوان (javanjob.ir) هستی.

یک مقاله‌ی تازه با این ویژگی‌ها بنویس:
- {$topic_text}
- {$length_text}
- لحن: کاملاً انسانی، ساده و روان، محاوره‌ای‌نه‌رسمیِ خشک؛ جمله‌های با طول متفاوت؛ استفاده از مثال واقعی و ملموس؛ بدون تکرار الگوی رباتیک، بدون اشاره به هوش مصنوعی/ChatGPT/Gemini به هیچ شکل.
- ساختار: مقدمه‌ی کوتاه جذاب، چند بخش با تیتر H2 (و در صورت نیاز H3)، در ابتدای هر بخش پاسخ مستقیم و خلاصه به سؤال آن بخش بیاور (برای سازگاری با موتورهای پاسخ‌گوی هوش مصنوعی/GEO)، پاراگراف‌های کوتاه (۲ تا ۴ جمله)، حداقل یک لیست (ul/ol) در متن، و یک بخش «سؤالات متداول» در پایان با ۴ تا ۶ سؤال و پاسخ کوتاه.
- سئو: از کلمه‌ی کلیدی اصلی به‌طور طبیعی (نه تکرار زورکی) در تیتر، مقدمه، و حداقل یکی از H2ها استفاده کن.
- {$avoid_text}

خروجی را دقیقاً و فقط به‌صورت یک JSON معتبر (بدون هیچ متن قبل/بعد، بدون code fence) با این ساختار برگردان:
{
  "title": "عنوان جذاب مقاله (حداکثر ۶۵ کاراکتر)",
  "meta_title": "تیتر سئو (حداکثر ۶۰ کاراکتر)",
  "meta_description": "توضیحات متا (حداکثر ۱۵۵ کاراکتر)",
  "focus_keyword": "کلمه‌ی کلیدی اصلی",
  "body_html": "<h2>...</h2><p>...</p> ... (کل بدنه‌ی مقاله با تگ‌های HTML: h2,h3,p,ul,ol,li — بدون تگ img، بدون بخش سؤالات متداول این‌جا)",
  "faq": [ { "q": "سؤال ۱", "a": "پاسخ ۱" }, { "q": "سؤال ۲", "a": "پاسخ ۲" } ],
  "image_queries": [ "english short phrase 1", "english short phrase 2", "english short phrase 3" ],
  "categories": [ "یک زیردسته‌ی مشخص و کوتاه فارسی (مثلاً «بازار کار» یا «رزومه و مصاحبه»)", "زیردسته‌ی دوم اختیاری" ],
  "tags": [ "برچسب کوتاه ۱", "برچسب کوتاه ۲", "... (بین ۵ تا ۸ برچسب فارسی مرتبط با موضوع، برای سئو)" ]
}

نکته‌ی مهم درباره‌ی image_queries: چون بانک تصویر انگلیسی‌زبان است، سه عبارت کوتاه انگلیسی (۲ تا ۴ کلمه) بده که به‌خوبی موضوع مقاله را در آن بانک تصویری نشان دهند (مثلاً "office job interview" یا "iranian workers factory").
PROMPT;
	}

	private static function parse_ai_json( $raw ) {
		$clean = trim( (string) $raw );
		$clean = preg_replace( '/^```(json)?/i', '', $clean );
		$clean = preg_replace( '/```$/', '', $clean );
		$clean = trim( $clean );

		// اگر مدل چیزی قبل/بعد از JSON اضافه کرد، فقط بخش { ... } را بردار.
		$start = strpos( $clean, '{' );
		$end   = strrpos( $clean, '}' );
		if ( $start !== false && $end !== false && $end > $start ) {
			$clean = substr( $clean, $start, $end - $start + 1 );
		}

		$data = json_decode( $clean, true );
		if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['body_html'] ) ) {
			return new WP_Error( 'jjaa_bad_json', 'پاسخ Gemini به‌صورت JSON معتبر قابل‌پارس نبود.' );
		}

		$data['title']             = sanitize_text_field( $data['title'] );
		$data['meta_title']        = sanitize_text_field( $data['meta_title'] ?? $data['title'] );
		$data['meta_description']  = sanitize_text_field( $data['meta_description'] ?? '' );
		$data['focus_keyword']     = sanitize_text_field( $data['focus_keyword'] ?? '' );
		$data['faq']               = is_array( $data['faq'] ?? null ) ? $data['faq'] : array();
		$data['image_queries']     = is_array( $data['image_queries'] ?? null ) ? array_map( 'sanitize_text_field', $data['image_queries'] ) : array();
		$data['categories']        = is_array( $data['categories'] ?? null ) ? array_map( 'sanitize_text_field', array_filter( $data['categories'] ) ) : array();
		$data['tags']              = is_array( $data['tags'] ?? null ) ? array_map( 'sanitize_text_field', array_filter( $data['tags'] ) ) : array();

		return $data;
	}

	private static function create_post( $parsed, $image_count, $publish_ts ) {
		$category_id  = self::ensure_category();
		$category_ids = self::ensure_subcategories( $parsed['categories'], $category_id );

		$content = wp_kses_post( $parsed['body_html'] );
		$content .= self::internal_link_cta();

		$post_args = array(
			'post_type'     => 'post',
			'post_title'    => $parsed['title'],
			'post_content'  => $content,
			'post_category' => $category_ids,
		);

		if ( $publish_ts > 0 && $publish_ts > time() + 59 ) {
			$post_args['post_status']   = 'future';
			$post_args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $publish_ts );
			$post_args['post_date']     = get_date_from_gmt( $post_args['post_date_gmt'] );
		} else {
			$post_args['post_status'] = 'publish';
		}

		$post_id = wp_insert_post( $post_args, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_FLAG, 1 );
		update_post_meta( $post_id, self::META_TITLE, $parsed['meta_title'] );
		update_post_meta( $post_id, self::META_DESCRIPTION, $parsed['meta_description'] );
		update_post_meta( $post_id, self::META_FOCUS, $parsed['focus_keyword'] );
		update_post_meta( $post_id, self::META_FAQ, $parsed['faq'] );

		$tags = ! empty( $parsed['tags'] ) ? $parsed['tags'] : array( $parsed['focus_keyword'] ?: $parsed['title'] );
		wp_set_post_tags( $post_id, $tags, false );

		$word_count = count( preg_split( '/\s+/u', wp_strip_all_tags( $parsed['body_html'] ), -1, PREG_SPLIT_NO_EMPTY ) );
		update_post_meta( $post_id, self::META_WORDCOUNT, $word_count );

		self::schedule_image_attachment( $post_id, $parsed, $image_count );

		return $post_id;
	}

	/**
	 * پیوست‌کردن عکس‌ها یک مرحله‌ی کاملاً جدا و بعدی است (نه همین درخواست):
	 * روی این هاست، فقط نوشتنِ متن مقاله با Gemini معمولاً نزدیک به کل
	 * سقف زمانیِ مجاز هاست (حدود ۴۰ ثانیه) را مصرف می‌کند، پس اگر دانلود
	 * و تغییر اندازه‌ی عکس‌ها در همان درخواست انجام شود، هاست وسط کار
	 * درخواست را قطع می‌کند (نه خطای PHP قابل مدیریت — قطع کامل پردازش)
	 * و نه تصویر شاخص تنظیم می‌شود و نه چیز دیگری بعد از آن. به همین
	 * دلیل این‌جا فقط یک رویداد Cron جدا زمان‌بندی می‌شود تا در یک
	 * درخواستِ مستقل و با سقف زمانیِ تازه اجرا شود.
	 */
	private static function schedule_image_attachment( $post_id, $parsed, $image_count ) {
		if ( $image_count <= 0 ) {
			return;
		}

		$queries = ! empty( $parsed['image_queries'] ) ? $parsed['image_queries'] : array( $parsed['focus_keyword'] ?: $parsed['title'] );
		$queries = array_slice( $queries, 0, $image_count );
		// اگر تعداد عبارت‌های جست‌وجو کمتر از تعداد عکس درخواستی بود، آخرین عبارت تکرار می‌شود.
		while ( count( $queries ) < $image_count ) {
			$queries[] = end( $queries ) ?: ( $parsed['focus_keyword'] ?: $parsed['title'] );
		}

		JJAA_Cron::schedule_image_attachment( $post_id, $queries );
	}

	/**
	 * اجرای واقعیِ پیوست‌کردن عکس‌ها — در یک درخواست Cron کاملاً جدا از
	 * تولید متن مقاله (به همین دلیل از schedule_image_attachment صدا
	 * زده می‌شود، نه مستقیم از run()).
	 */
	public static function run_attach_images( $post_id, $queries ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		// تصویر شاخص (اولین تصویر موفق) همین داخل fetch_and_attach تنظیم
		// می‌شود، نه این‌جا — چون باید بلافاصله بعد از اولین موفقیت ثبت
		// شود، نه بعد از پردازش کامل همه‌ی عبارت‌ها.
		$attachment_ids = JJAA_Images::fetch_and_attach( $queries, $post_id );

		if ( empty( $attachment_ids ) ) {
			return;
		}

		// تصویر(های) بعدی = درون‌متن، پخش‌شده بین بخش‌های H2.
		if ( count( $attachment_ids ) > 1 ) {
			$post = get_post( $post_id );
			$content = $post->post_content;
			$headings = preg_split( '/(?=<h2)/i', $content );
			$extra_ids = array_slice( $attachment_ids, 1 );

			if ( count( $headings ) >= 3 && ! empty( $extra_ids ) ) {
				$slots = count( $extra_ids );
				$insert_positions = array();
				for ( $s = 0; $s < $slots; $s++ ) {
					$pos = (int) round( ( $s + 1 ) * count( $headings ) / ( $slots + 1 ) );
					$pos = max( 1, min( count( $headings ) - 1, $pos ) );
					$insert_positions[ $pos ] = $extra_ids[ $s ];
				}

				$new_content = '';
				foreach ( $headings as $i => $chunk ) {
					if ( isset( $insert_positions[ $i ] ) ) {
						$img_id = $insert_positions[ $i ];
						$img_url = wp_get_attachment_image_url( $img_id, 'large' );
						$alt = esc_attr( get_post_meta( $img_id, '_wp_attachment_image_alt', true ) );
						if ( $img_url ) {
							$new_content .= sprintf(
								'<figure class="wp-block-image size-large"><img src="%s" alt="%s" loading="lazy" style="max-width:100%%;border-radius:10px;" /></figure>' . "\n",
								esc_url( $img_url ), $alt
							);
						}
					}
					$new_content .= $chunk;
				}

				wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );
			}
		}
	}

	public static function ensure_category() {
		$cached = get_option( 'jjaa_category_id' );
		if ( $cached && term_exists( (int) $cached, 'category' ) ) {
			return (int) $cached;
		}

		$term = get_term_by( 'slug', self::CATEGORY_SLUG, 'category' );
		if ( $term ) {
			update_option( 'jjaa_category_id', $term->term_id );
			return (int) $term->term_id;
		}

		$new = wp_insert_term( self::CATEGORY_NAME, 'category', array( 'slug' => self::CATEGORY_SLUG ) );
		if ( is_wp_error( $new ) ) {
			return 1; // دسته‌بندی‌نشده، به‌عنوان آخرین راه‌حل
		}
		update_option( 'jjaa_category_id', $new['term_id'] );
		return (int) $new['term_id'];
	}

	/** زیردسته‌های پیشنهادی هوش مصنوعی را (زیر دسته‌ی اصلی) می‌سازد/پیدا می‌کند. همیشه دسته‌ی اصلی هم در نتیجه هست. */
	private static function ensure_subcategories( $names, $parent_id ) {
		$ids = array( $parent_id );
		$names = array_slice( array_unique( array_filter( $names ) ), 0, 2 );

		foreach ( $names as $name ) {
			$slug = sanitize_title( $name );
			if ( $slug === '' ) continue;

			$term = get_term_by( 'slug', $slug, 'category' );
			if ( ! $term ) {
				$inserted = wp_insert_term( $name, 'category', array( 'slug' => $slug, 'parent' => $parent_id ) );
				if ( is_wp_error( $inserted ) ) continue;
				$term_id = $inserted['term_id'];
			} else {
				$term_id = $term->term_id;
			}
			$ids[] = (int) $term_id;
		}

		return array_unique( $ids );
	}

	/** لینک داخلی به صفحه‌ی فرصت‌های شغلی در پایان مقاله — برای سئو (لینک‌سازی داخلی) و کاهش نرخ پرش. */
	private static function internal_link_cta() {
		return "\n" . '<p style="margin-top:28px;padding-top:18px;border-top:1px solid #e5e7eb;">'
			. 'به دنبال فرصت شغلی مناسب خودتان هستید؟ '
			. '<a href="/jobs-list/" style="color:#14213d;font-weight:bold;">آخرین فرصت‌های شغلی کاریابی جوان را ببینید ←</a>'
			. '</p>';
	}

	public static function log( $stage, $message ) {
		update_option( 'jjaa_last_run_log', array(
			'stage'   => $stage,
			'message' => mb_substr( (string) $message, 0, 2000 ),
			'time'    => current_time( 'mysql' ),
		), false );
	}
}
