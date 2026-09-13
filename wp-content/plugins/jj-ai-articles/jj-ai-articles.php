<?php
/**
 * Plugin Name: جوان‌جاب - مقالات هوش مصنوعی (SEO/GEO)
 * Plugin URI: https://javanjob.ir
 * Description: تولید خودکار روزانه‌ی مقاله (کار و اقتصاد) با Google Gemini (رایگان) + تصاویر Pexels، سئو/GEO مستقل، و اسلایدر آخرین مقالات. کاملاً مستقل از سایر افزونه‌های اتوماسیون سایت.
 * Version: 1.0.0
 * Author: Javan Team
 * Text Domain: jj-ai-articles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JJAA_PATH', plugin_dir_path( __FILE__ ) );
define( 'JJAA_URL', plugin_dir_url( __FILE__ ) );
define( 'JJAA_VERSION', '1.0.0' );

require_once JJAA_PATH . 'includes/class-jjaa-gapgpt.php';
require_once JJAA_PATH . 'includes/class-jjaa-images.php';
require_once JJAA_PATH . 'includes/class-jjaa-generator.php';
require_once JJAA_PATH . 'includes/class-jjaa-seo.php';
require_once JJAA_PATH . 'includes/class-jjaa-slider.php';
require_once JJAA_PATH . 'includes/class-jjaa-settings.php';
require_once JJAA_PATH . 'includes/class-jjaa-cron.php';

register_activation_hook( __FILE__, array( 'JJAA_Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JJAA_Cron', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	JJAA_Cron::init();
	JJAA_SEO::init();
	JJAA_Slider::init();
	JJAA_Settings::init();
} );
