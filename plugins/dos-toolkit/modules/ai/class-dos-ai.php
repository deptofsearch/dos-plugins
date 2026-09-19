<?php
/**
 * AI Search module.
 *
 * Three things, in descending order of how sure anyone can be that they
 * matter: who is allowed to crawl and for what, explicit FAQ markup, and an
 * llms.txt file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-dos-ai-crawlers.php';
require_once __DIR__ . '/class-dos-ai-schema.php';
require_once __DIR__ . '/class-dos-ai-llms.php';

final class DOS_Module_AI extends DOS_Module {

	const KEY = 'ai';

	public static function init() {
		DOS_AI_Crawlers::init();
		DOS_AI_Schema::init();
		DOS_AI_Llms::init();

		add_action( 'admin_init', array( __CLASS__, 'handle_post' ), 20 );
	}

	public static function pages() {
		return array(
			array(
				'slug'     => 'dos-ai',
				'title'    => __( 'AI Search', 'dos-toolkit' ),
				'callback' => array( __CLASS__, 'render_page' ),
			),
		);
	}

	public static function handle_post() {
		if ( empty( $_POST['dos_action'] ) || 'save_ai' !== sanitize_key( wp_unslash( $_POST['dos_action'] ) ) ) {
			return;
		}

		if ( ! current_user_can( DOS_Settings::capability() ) ) {
			return;
		}

		check_admin_referer( 'dos_save_ai' );

		$submitted = isset( $_POST['policy'] ) ? (array) wp_unslash( $_POST['policy'] ) : array();
		$policy    = array();

		foreach ( array_keys( DOS_AI_Crawlers::registry() ) as $token ) {
			$choice = isset( $submitted[ $token ] ) ? sanitize_key( $submitted[ $token ] ) : '';

			if ( in_array( $choice, array( 'allow', 'block' ), true ) ) {
				$policy[ $token ] = $choice;
			}
		}

		$llms_was = DOS_AI_Llms::is_enabled();

		DOS_Settings::update(
			array(
				'ai_crawler_policy'   => $policy,
				'ai_crawlers_enabled' => empty( $_POST['crawlers_enabled'] ) ? 0 : 1,
				'ai_llms_enabled'     => empty( $_POST['llms_enabled'] ) ? 0 : 1,
			)
		);

		// The llms.txt rewrite rule only exists once it has been registered
		// and the rules rebuilt; without this the URL 404s until someone
		// happens to save permalinks.
		if ( $llms_was !== DOS_AI_Llms::is_enabled() ) {
			DOS_AI_Llms::add_rewrite();
			flush_rewrite_rules( false );
		}

		self::log( 'settings_saved', 'AI search settings updated.' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'dos-ai', 'dos_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		$registry = DOS_AI_Crawlers::registry();
		$labels   = DOS_AI_Crawlers::categories();
		$static   = DOS_AI_Crawlers::static_file_path();
		$summary  = DOS_AI_Crawlers::summary();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Search', 'dos-toolkit' ); ?></h1>

			<?php DOS_Admin::notice(); ?>

			<form method="post">
				<?php wp_nonce_field( 'dos_save_ai' ); ?>
				<input type="hidden" name="dos_action" value="save_ai" />

				<h2><?php esc_html_e( 'Crawler policy', 'dos-toolkit' ); ?></h2>

				<p class="description">
					<?php esc_html_e( 'Not every AI crawler wants the same thing. Training crawlers take content and send nothing back. Search crawlers index pages so assistants can cite them, and a citation is a referral. User-requested fetches happen because a reader asked an assistant about your page specifically.', 'dos-toolkit' ); ?>
				</p>

				<p class="description">
					<?php esc_html_e( 'The default blocks training and allows the other two. Blocking everything is a choice you can make here, but it costs the citations as well.', 'dos-toolkit' ); ?>
				</p>

				<?php if ( ! get_option( 'blog_public' ) ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'This site is set to discourage search engines, so robots.txt already disallows everything and these rules are not added.', 'dos-toolkit' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $static ) : ?>
					<div class="notice notice-error inline">
						<p>
							<?php esc_html_e( 'A real robots.txt file exists in the site root. Your server serves that file directly, so WordPress never generates one and nothing set here reaches a crawler. Delete or rename that file, or copy the rules below into it by hand.', 'dos-toolkit' ); ?>
							<br><code><?php echo esc_html( $static ); ?></code>
						</p>
					</div>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Apply policy', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="crawlers_enabled" value="1" <?php checked( DOS_AI_Crawlers::is_enabled() ); ?> />
								<?php esc_html_e( 'Add these rules to robots.txt', 'dos-toolkit' ); ?>
							</label>
							<p class="description">
								<?php
								printf(
									/* translators: 1: number allowed, 2: number blocked */
									esc_html__( 'Currently %1$d allowed, %2$d blocked.', 'dos-toolkit' ),
									(int) $summary['allow'],
									(int) $summary['block']
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<table class="widefat striped" style="max-width:70em">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Crawler', 'dos-toolkit' ); ?></th>
							<th><?php esc_html_e( 'What it does', 'dos-toolkit' ); ?></th>
							<th style="width:11em"><?php esc_html_e( 'Policy', 'dos-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $labels as $category => $category_label ) : ?>
						<tr>
							<th colspan="3" style="background:#f6f7f7">
								<?php echo esc_html( $category_label ); ?>
							</th>
						</tr>
						<?php foreach ( $registry as $token => $bot ) : ?>
							<?php if ( $bot['category'] !== $category ) { continue; } ?>
							<?php $current = DOS_AI_Crawlers::policy( $token ); ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $bot['label'] ); ?></strong><br>
									<span class="description"><?php echo esc_html( $bot['vendor'] ); ?></span>
								</td>
								<td class="description"><?php echo esc_html( $bot['note'] ); ?></td>
								<td>
									<label style="margin-right:.75em">
										<input type="radio" name="policy[<?php echo esc_attr( $token ); ?>]" value="allow" <?php checked( 'allow', $current ); ?> />
										<?php esc_html_e( 'Allow', 'dos-toolkit' ); ?>
									</label>
									<label>
										<input type="radio" name="policy[<?php echo esc_attr( $token ); ?>]" value="block" <?php checked( 'block', $current ); ?> />
										<?php esc_html_e( 'Block', 'dos-toolkit' ); ?>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'What gets added to robots.txt', 'dos-toolkit' ); ?></h3>

				<?php $rules = DOS_AI_Crawlers::rules(); ?>

				<?php if ( trim( $rules ) ) : ?>
					<textarea readonly rows="10" class="large-text code" style="max-width:50em"><?php echo esc_textarea( trim( $rules ) ); ?></textarea>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Nothing — every crawler above is allowed.', 'dos-toolkit' ); ?></p>
				<?php endif; ?>

				<hr>

				<h2><?php esc_html_e( 'FAQ schema', 'dos-toolkit' ); ?></h2>

				<p class="description">
					<?php esc_html_e( 'Question-and-answer pairs are close to the unit an assistant builds an answer from, so marking them explicitly takes the guesswork out of it. This is per page: tick the FAQ schema box on the editor screen, and any heading ending in a question mark becomes a question with the text beneath it as the answer. The editor shows exactly what was extracted before you publish it.', 'dos-toolkit' ); ?>
				</p>

				<p class="description">
					<?php esc_html_e( 'If Yoast or Rank Math is also producing FAQ markup for a page, turn one of them off for it — two FAQPage blocks on one page is worse than none.', 'dos-toolkit' ); ?>
				</p>

				<hr>

				<h2><?php esc_html_e( 'llms.txt', 'dos-toolkit' ); ?></h2>

				<div class="notice notice-info inline">
					<p>
						<strong><?php esc_html_e( 'Speculative.', 'dos-toolkit' ); ?></strong>
						<?php esc_html_e( 'llms.txt is a proposed convention, not an established one. No major AI vendor has publicly committed to reading it. It costs one generated file to participate and nothing is lost if it never catches on — that is the whole case for turning it on.', 'dos-toolkit' ); ?>
					</p>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Serve llms.txt', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="llms_enabled" value="1" <?php checked( DOS_AI_Llms::is_enabled() ); ?> />
								<?php esc_html_e( 'Generate it from published pages and posts', 'dos-toolkit' ); ?>
							</label>
							<?php if ( DOS_AI_Llms::is_enabled() ) : ?>
								<p class="description">
									<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save AI settings', 'dos-toolkit' ) ); ?>
			</form>
		</div>
		<?php
	}
}
