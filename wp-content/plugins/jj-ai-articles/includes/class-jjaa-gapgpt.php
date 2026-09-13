<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کلاینت هوش مصنوعیِ تولید مقاله — Google Gemini (رایگان)، از طریق یک
 * Cloudflare Worker واسط. سرور خودِ سایت روی هاست ایرانی است و گوگل
 * درخواست‌های مستقیم از IP ایران به Gemini API را مسدود می‌کند («User
 * location is not supported»)؛ به همین دلیل درخواست‌ها اول به یک Worker
 * رایگان روی Cloudflare (خارج از ایران) می‌روند و از آن‌جا به Gemini
 * فوروارد می‌شوند. کلید واقعی Gemini فقط داخل همان Worker (به‌صورت secret)
 * نگه‌داری می‌شود، نه در دیتابیس وردپرس.
 *
 * این فایل قبلاً از GapGPT استفاده می‌کرد و کلاسش هم همان نام را داشت. طبق
 * تصمیم صریح مدیر سایت، تولید مقاله دیگر هیچ ارتباطی با GapGPT ندارد، پس
 * کلاس به JJAA_Gemini تغییر نام داد تا نامش با کاری که می‌کند بخواند.
 *
 * نام خودِ فایل هنوز class-jjaa-gapgpt.php است، چون ویرایشگر افزونه‌ی
 * وردپرس فقط فایل‌های موجود را ویرایش می‌کند و نمی‌تواند فایل تازه بسازد؛
 * تغییر نام فایل نیاز به دسترسی FTP/File Manager دارد. اگر روزی آن دسترسی
 * فراهم شد، فایل را به class-jjaa-gemini.php تغییر نام دهید و require
 * مربوط به آن را در jj-ai-articles.php به‌روز کنید.
 */
class JJAA_Gemini {

	public static function relay_url() {
		return untrailingslashit( trim( (string) get_option( 'jjaa_relay_url', '' ) ) );
	}

	public static function relay_secret() {
		return trim( (string) get_option( 'jjaa_relay_secret', '' ) );
	}

	public static function model() {
		$model = trim( (string) get_option( 'jjaa_gemini_model', '' ) );
		// gemini-flash-latest یک نام مستعار است که همیشه به جدیدترین مدل
		// «flash» توصیه‌شده‌ی گوگل اشاره می‌کند — برخلاف نام مدل‌های ثابت،
		// با هر تغییر رده‌بندی گوگل نیاز به آپدیت دستی ندارد.
		return $model !== '' ? $model : 'gemini-flash-latest';
	}

	/** مدل تولید تصویر — پیش‌فرض رایگان، فقط وقتی Pexels چیزی پیدا نکند استفاده می‌شود. */
	public static function image_model() {
		$model = trim( (string) get_option( 'jjaa_image_gen_model', '' ) );
		return $model !== '' ? $model : 'gemini-2.5-flash-image';
	}

	private static function relay_configured() {
		return '' !== self::relay_url() && '' !== self::relay_secret();
	}

	/**
	 * @param array $messages [['role'=>'user'|'assistant'|'system','content'=>'...'], ...]
	 * @return string|WP_Error
	 */
	public static function chat( $messages, $args = array() ) {
		if ( ! self::relay_configured() ) {
			return new WP_Error( 'jjaa_no_key', 'آدرس/کلید واسط Gemini (Cloudflare Worker) تنظیم نشده است.' );
		}

		// Gemini برخلاف OpenAI، پیام «system» را در contents نمی‌پذیرد؛ باید
		// جدا در systemInstruction برود، و role دستیار «model» نامیده می‌شود.
		$system_text = '';
		$contents    = array();
		foreach ( $messages as $m ) {
			$role = $m['role'] ?? 'user';
			if ( 'system' === $role ) {
				$system_text .= ( '' === $system_text ? '' : "\n" ) . $m['content'];
				continue;
			}
			$contents[] = array(
				'role'  => ( 'assistant' === $role ) ? 'model' : 'user',
				'parts' => array( array( 'text' => (string) $m['content'] ) ),
			);
		}

		$body = array(
			'contents'         => $contents,
			// مقاله‌ی ۲۵۰۰-۳۰۰۰ کلمه‌ای فارسی به توکن‌های خروجی زیادی نیاز دارد؛
			// بدون این مقدار بعضی مدل‌ها با مقدار پیش‌فرض کم قطع می‌کنند.
			'generationConfig' => array(
				'maxOutputTokens' => $args['max_tokens'] ?? 8192,
			),
		);
		if ( '' !== $system_text ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system_text ) ) );
		}
		if ( ! empty( $args['temperature'] ) ) {
			$body['generationConfig']['temperature'] = $args['temperature'];
		}

		$model = $args['model'] ?? self::model();
		$url   = self::relay_url() . '/v1beta/models/' . rawurlencode( $model ) . ':generateContent';

		$response = wp_remote_post( $url, array(
			'timeout' => 180,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'X-Relay-Secret' => self::relay_secret(),
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = self::extract_text( $data );

		if ( $code < 200 || $code >= 300 || '' === $text ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code . ' - ' . wp_remote_retrieve_body( $response ) );
			return new WP_Error( 'jjaa_api_error', mb_substr( (string) $msg, 0, 500 ) );
		}

		return trim( $text );
	}

	private static function extract_text( $data ) {
		$parts = $data['candidates'][0]['content']['parts'] ?? array();
		$out   = '';
		foreach ( (array) $parts as $p ) {
			if ( isset( $p['text'] ) ) {
				$out .= $p['text'];
			}
		}
		return $out;
	}

	/**
	 * لیست مدل‌های موجود روی حساب Gemini — برای دکمه‌ی «دریافت لیست مدل‌ها»
	 * در صفحه‌ی تنظیمات (اجرا سمت سرور خود سایت، از طریق همان Worker).
	 * @return array|WP_Error
	 */
	public static function list_models() {
		if ( ! self::relay_configured() ) {
			return new WP_Error( 'jjaa_no_key', 'آدرس/کلید واسط Gemini (Cloudflare Worker) تنظیم نشده است.' );
		}

		$response = wp_remote_get( self::relay_url() . '/v1beta/models', array(
			'timeout' => 30,
			'headers' => array( 'X-Relay-Secret' => self::relay_secret() ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['models'] ) ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code . ' - ' . wp_remote_retrieve_body( $response ) );
			return new WP_Error( 'jjaa_api_error', (string) $msg );
		}

		$ids = array();
		foreach ( $data['models'] as $m ) {
			if ( empty( $m['name'] ) ) continue;
			$methods = $m['supportedGenerationMethods'] ?? array();
			if ( ! empty( $methods ) && ! in_array( 'generateContent', $methods, true ) ) continue;
			$ids[] = str_replace( 'models/', '', $m['name'] );
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * تولید یک تصویر با Gemini (fallback فقط وقتی Pexels عکس مرتبطی نداشت).
	 * @return array|WP_Error {b64?:string}
	 */
	public static function generate_image( $prompt ) {
		if ( ! self::relay_configured() ) {
			return new WP_Error( 'jjaa_no_key', 'آدرس/کلید واسط Gemini (Cloudflare Worker) تنظیم نشده است.' );
		}

		$model = self::image_model();
		$url   = self::relay_url() . '/v1beta/models/' . rawurlencode( $model ) . ':generateContent';

		$response = wp_remote_post( $url, array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'X-Relay-Secret' => self::relay_secret(),
			),
			'body'    => wp_json_encode( array(
				'contents'         => array(
					array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ),
				),
				// مدل‌های تصویرسازِ Gemini هر دو حالت TEXT و IMAGE را با هم
				// می‌خواهند؛ فقط IMAGE را قبول نمی‌کنند و خطا برمی‌گردانند.
				'generationConfig' => array( 'responseModalities' => array( 'TEXT', 'IMAGE' ) ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code  = wp_remote_retrieve_response_code( $response );
		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$parts = $data['candidates'][0]['content']['parts'] ?? array();

		foreach ( (array) $parts as $p ) {
			if ( ! empty( $p['inlineData']['data'] ) ) {
				return array( 'b64' => $p['inlineData']['data'] );
			}
			if ( ! empty( $p['inline_data']['data'] ) ) {
				return array( 'b64' => $p['inline_data']['data'] );
			}
		}

		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code . ' - ' . wp_remote_retrieve_body( $response ) );
			return new WP_Error( 'jjaa_image_api_error', mb_substr( (string) $msg, 0, 500 ) );
		}

		return new WP_Error( 'jjaa_image_api_error', 'پاسخ تولید تصویر بدون داده‌ی تصویر بود.' );
	}
}

/**
 * نام قدیمی، فقط برای سازگاری به‌عقب: اگر جایی بیرون از این افزونه هنوز
 * JJAA_GapGPT را صدا بزند، به همین کلاس می‌رسد. کد خودِ افزونه دیگر از این
 * نام استفاده نمی‌کند.
 */
class_alias( 'JJAA_Gemini', 'JJAA_GapGPT' );
