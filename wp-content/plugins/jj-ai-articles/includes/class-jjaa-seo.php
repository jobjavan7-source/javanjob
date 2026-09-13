<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * سئو/GEO مستقل، فقط برای پست‌هایی که این افزونه تولید کرده (پرچم
 * _jjaa_generated). عمداً هیچ فیلتری روی سایر محتوای سایت اثر نمی‌گذارد،
 * تا با هیچ افزونه‌ی دیگری (از جمله javan-job-core) تداخل نکند.
 */
class JJAA_SEO {

	public static function init() {
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_title' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_meta_tags' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'print_schema' ), 6 );
	}

	private static function is_our_post() {
		return is_singular( 'post' ) && get_post_meta( get_queried_object_id(), JJAA_Generator::META_FLAG, true );
	}

	public static function filter_title( $parts ) {
		if ( ! self::is_our_post() ) return $parts;
		$title = get_post_meta( get_queried_object_id(), JJAA_Generator::META_TITLE, true );
		if ( $title ) $parts['title'] = $title;
		return $parts;
	}

	public static function print_meta_tags() {
		if ( ! self::is_our_post() ) return;
		$post_id = get_queried_object_id();
		$desc  = get_post_meta( $post_id, JJAA_Generator::META_DESCRIPTION, true );
		$title = get_post_meta( $post_id, JJAA_Generator::META_TITLE, true );

		if ( $desc ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $desc ) );
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $desc ) );
			printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
		if ( $title ) {
			printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
			printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );
		}
		printf( '<meta property="og:type" content="article" />' . "\n" );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( get_permalink( $post_id ) ) );
		printf( '<meta name="twitter:card" content="summary_large_image" />' . "\n" );

		$thumb = get_the_post_thumbnail_url( $post_id, 'large' );
		if ( $thumb ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $thumb ) );
			printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $thumb ) );
		}

		$tags = get_the_tags( $post_id );
		if ( $tags && ! is_wp_error( $tags ) ) {
			$keywords = wp_list_pluck( $tags, 'name' );
			printf( '<meta name="keywords" content="%s" />' . "\n", esc_attr( implode( '، ', $keywords ) ) );
			foreach ( array_slice( $keywords, 0, 6 ) as $kw ) {
				printf( '<meta property="article:tag" content="%s" />' . "\n", esc_attr( $kw ) );
			}
		}
	}

	/** Schema.org: Article + FAQPage — برای بهبود نمایش در نتایج جست‌وجو و موتورهای پاسخ‌گوی هوش مصنوعی (GEO). */
	public static function print_schema() {
		if ( ! self::is_our_post() ) return;
		$post_id = get_queried_object_id();
		$post    = get_post( $post_id );

		$article = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'headline'         => get_post_meta( $post_id, JJAA_Generator::META_TITLE, true ) ?: get_the_title( $post_id ),
			'description'      => get_post_meta( $post_id, JJAA_Generator::META_DESCRIPTION, true ),
			'datePublished'    => get_the_date( 'c', $post_id ),
			'dateModified'     => get_the_modified_date( 'c', $post_id ),
			'mainEntityOfPage' => get_permalink( $post_id ),
			'author'           => array( '@type' => 'Organization', 'name' => 'کاریابی جوان' ),
			'publisher'        => array( '@type' => 'Organization', 'name' => 'کاریابی جوان' ),
		);
		$thumb = get_the_post_thumbnail_url( $post_id, 'large' );
		if ( $thumb ) $article['image'] = array( $thumb );

		$word_count = (int) get_post_meta( $post_id, JJAA_Generator::META_WORDCOUNT, true );
		if ( $word_count > 0 ) $article['wordCount'] = $word_count;

		$categories = get_the_category( $post_id );
		if ( $categories && ! is_wp_error( $categories ) ) {
			$article['articleSection'] = wp_list_pluck( $categories, 'name' );
		}

		$tags = get_the_tags( $post_id );
		if ( $tags && ! is_wp_error( $tags ) ) {
			$article['keywords'] = implode( '، ', wp_list_pluck( $tags, 'name' ) );
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $article, JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";

		self::print_breadcrumb_schema( $post_id, $categories );

		$faq = get_post_meta( $post_id, JJAA_Generator::META_FAQ, true );
		if ( is_array( $faq ) && ! empty( $faq ) ) {
			$entities = array();
			foreach ( $faq as $item ) {
				if ( empty( $item['q'] ) || empty( $item['a'] ) ) continue;
				$entities[] = array(
					'@type' => 'Question',
					'name'  => wp_strip_all_tags( $item['q'] ),
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => wp_strip_all_tags( $item['a'] ),
					),
				);
			}
			if ( $entities ) {
				$faq_schema = array(
					'@context'   => 'https://schema.org',
					'@type'      => 'FAQPage',
					'mainEntity' => $entities,
				);
				echo '<script type="application/ld+json">' . wp_json_encode( $faq_schema, JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
			}

			// نمایش بخش سؤالات متداول در پایان محتوا (فیلتر the_content، فقط برای پست‌های ما).
		}
	}

	/** Schema.org BreadcrumbList: خانه > دسته > عنوان مقاله. */
	private static function print_breadcrumb_schema( $post_id, $categories ) {
		$items = array(
			array( '@type' => 'ListItem', 'position' => 1, 'name' => 'خانه', 'item' => home_url( '/' ) ),
		);

		$position = 2;
		if ( $categories && ! is_wp_error( $categories ) ) {
			$cat = $categories[0];
			$items[] = array( '@type' => 'ListItem', 'position' => $position++, 'name' => $cat->name, 'item' => get_category_link( $cat->term_id ) );
		}

		$items[] = array( '@type' => 'ListItem', 'position' => $position, 'name' => get_the_title( $post_id ), 'item' => get_permalink( $post_id ) );

		$breadcrumb = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $breadcrumb, JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}

add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) return $content;
	$post_id = get_the_ID();
	if ( ! get_post_meta( $post_id, JJAA_Generator::META_FLAG, true ) ) return $content;

	$faq = get_post_meta( $post_id, JJAA_Generator::META_FAQ, true );
	if ( ! is_array( $faq ) || empty( $faq ) ) return $content;

	$html = '<div class="jjaa-faq" style="margin-top:36px;border-top:1px solid #e5e7eb;padding-top:20px;">';
	$html .= '<h2 style="color:#14213d;">سؤالات متداول</h2>';
	foreach ( $faq as $item ) {
		if ( empty( $item['q'] ) || empty( $item['a'] ) ) continue;
		$html .= '<div style="margin-bottom:14px;">';
		$html .= '<h3 style="color:#14213d;font-size:16px;margin:0 0 6px;">' . esc_html( $item['q'] ) . '</h3>';
		$html .= '<p style="color:#374151;margin:0;">' . esc_html( $item['a'] ) . '</p>';
		$html .= '</div>';
	}
	$html .= '</div>';

	return $content . $html;
} );
