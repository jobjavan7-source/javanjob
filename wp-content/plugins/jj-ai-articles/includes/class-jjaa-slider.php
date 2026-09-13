<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * شورت‌کد [jj_ai_articles_slider] — اسلایدر ۲۰ مقاله‌ی آخر سایت (هر وردپرس
 * post منتشرشده، نه فقط مقالات این افزونه)، به‌صورت گروه‌های ۴تایی، با
 * تصویر شاخص هر مقاله. سبک بصری هم‌راستا با بقیه‌ی صفحه‌ی اصلی
 * (رنگ‌بندی و اندازه‌ی مشابه [jj_recent_jobs]).
 */
class JJAA_Slider {

	const TOTAL   = 20;
	const PER_VIEW = 4;

	public static function init() {
		add_shortcode( 'jj_ai_articles_slider', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'numberposts'    => self::TOTAL,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		if ( empty( $posts ) ) {
			return '';
		}

		$uid = 'jjaa-slider-' . mt_rand( 1000, 999999 );
		$total_slides = (int) ceil( count( $posts ) / self::PER_VIEW );

		ob_start();
		?>
		<div style="margin:30px 0;">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;max-width:1040px;margin-left:auto;margin-right:auto;padding:0 4px;">
				<h2 style="color:#14213d;font-size:24px;margin:0;">📰 آخرین مقالات</h2>
				<a href="<?php echo esc_url( get_permalink( get_option( 'page_for_posts' ) ) ); ?>" style="color:#c9a227;font-weight:bold;text-decoration:none;font-size:14px;">مشاهده همه ←</a>
			</div>

			<div style="position:relative;max-width:1040px;margin:0 auto;">
				<?php if ( $total_slides > 1 ) : ?>
				<button type="button" class="jjaa-prev" data-target="<?php echo esc_attr( $uid ); ?>" aria-label="قبلی" style="position:absolute;top:50%;right:-6px;transform:translateY(-50%);z-index:2;width:36px;height:36px;border-radius:50%;border:1px solid #e5e7eb;background:#fff;color:#14213d;font-size:16px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.12);">›</button>
				<button type="button" class="jjaa-next" data-target="<?php echo esc_attr( $uid ); ?>" aria-label="بعدی" style="position:absolute;top:50%;left:-6px;transform:translateY(-50%);z-index:2;width:36px;height:36px;border-radius:50%;border:1px solid #e5e7eb;background:#fff;color:#14213d;font-size:16px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.12);">‹</button>
				<?php endif; ?>

				<div style="overflow:hidden;">
					<div id="<?php echo esc_attr( $uid ); ?>" style="display:flex;transition:transform .35s ease;" dir="rtl">
						<?php foreach ( $posts as $p ) :
							$thumb = get_the_post_thumbnail_url( $p->ID, 'medium' );
							$excerpt = wp_trim_words( wp_strip_all_tags( do_shortcode( $p->post_content ) ), 14 );
						?>
						<div style="flex:0 0 25%;max-width:25%;box-sizing:border-box;padding:0 8px;">
							<div style="background:#fff;border:1px solid #e5e7eb;border-top:3px solid #c9a227;border-radius:10px;overflow:hidden;height:100%;">
								<a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" style="text-decoration:none;color:inherit;display:block;">
									<?php if ( $thumb ) : ?>
										<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( get_the_title( $p->ID ) ); ?>" loading="lazy" style="width:100%;aspect-ratio:16/10;height:auto;object-fit:cover;display:block;">
									<?php else : ?>
										<div style="width:100%;aspect-ratio:16/10;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:28px;color:#d1d5db;">📰</div>
									<?php endif; ?>
									<div style="padding:12px 14px;">
										<strong style="color:#14213d;font-size:14px;display:block;line-height:1.6;min-height:44px;"><?php echo esc_html( wp_trim_words( get_the_title( $p->ID ), 10 ) ); ?></strong>
										<p style="font-size:12px;color:#6b7280;margin:8px 0 0;line-height:1.7;"><?php echo esc_html( $excerpt ); ?></p>
									</div>
								</a>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<?php if ( $total_slides > 1 ) : ?>
				<div style="display:flex;justify-content:center;gap:6px;margin-top:14px;">
					<?php for ( $i = 0; $i < $total_slides; $i++ ) : ?>
						<span class="jjaa-dot" data-target="<?php echo esc_attr( $uid ); ?>" data-index="<?php echo (int) $i; ?>" style="width:8px;height:8px;border-radius:50%;background:<?php echo $i === 0 ? '#c9a227' : '#e5e7eb'; ?>;cursor:pointer;display:inline-block;"></span>
					<?php endfor; ?>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<style>
			@media (max-width: 900px) {
				#<?php echo esc_js( $uid ); ?> > div { flex: 0 0 50% !important; max-width: 50% !important; }
			}
			@media (max-width: 560px) {
				#<?php echo esc_js( $uid ); ?> > div { flex: 0 0 100% !important; max-width: 100% !important; }
			}
		</style>

		<?php if ( $total_slides > 1 ) : ?>
		<script>
		(function () {
			var track = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
			if (!track) return;
			var idx = 0;

			function perView() {
				if (window.innerWidth <= 560) return 1;
				if (window.innerWidth <= 900) return 2;
				return 4;
			}

			function go(newIdx) {
				var pv = perView();
				var maxIdx = Math.max(0, Math.ceil(track.children.length / pv) - 1);
				idx = Math.max(0, Math.min(maxIdx, newIdx));
				var offsetPercent = idx * pv * (100 / track.children.length);
				track.style.transform = 'translateX(' + offsetPercent + '%)';
				document.querySelectorAll('.jjaa-dot[data-target="' + <?php echo wp_json_encode( $uid ); ?> + '"]').forEach(function (dot, i) {
					dot.style.background = (i === idx) ? '#c9a227' : '#e5e7eb';
				});
			}

			document.querySelectorAll('.jjaa-prev[data-target="' + <?php echo wp_json_encode( $uid ); ?> + '"]').forEach(function (btn) {
				btn.addEventListener('click', function () { go(idx - 1); });
			});
			document.querySelectorAll('.jjaa-next[data-target="' + <?php echo wp_json_encode( $uid ); ?> + '"]').forEach(function (btn) {
				btn.addEventListener('click', function () { go(idx + 1); });
			});
			document.querySelectorAll('.jjaa-dot[data-target="' + <?php echo wp_json_encode( $uid ); ?> + '"]').forEach(function (dot) {
				dot.addEventListener('click', function () { go(parseInt(dot.getAttribute('data-index'), 10)); });
			});
			window.addEventListener('resize', function () { go(0); });
		})();
		</script>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}
}
