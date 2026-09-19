<?php
/**
 * FAQ schema.
 *
 * Question-and-answer pairs are close to the unit an assistant assembles an
 * answer from, so marking them explicitly removes the guesswork from
 * extraction.
 *
 * Extraction is deliberately dumb and visible: a heading whose text ends in a
 * question mark becomes a question, and the content up to the next heading of
 * the same or higher level becomes its answer. The editor screen shows exactly
 * what was extracted, so an operator can see what will be published rather
 * than trusting it. Nothing is emitted unless the post is opted in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_AI_Schema {

	const META_ENABLED = '_dos_ai_faq';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'render' ), 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
	}

	public static function is_enabled_for( $post_id ) {
		return (bool) get_post_meta( $post_id, self::META_ENABLED, true );
	}

	/**
	 * Pull question/answer pairs out of rendered post content.
	 *
	 * @return array List of [ question, answer ].
	 */
	public static function extract( $content ) {
		$pairs = array();

		if ( ! $content || false === stripos( $content, '<h' ) ) {
			return $pairs;
		}

		// Split on headings, keeping the level and the heading text.
		$parts = preg_split(
			'/<h([2-4])\b[^>]*>(.*?)<\/h\1>/is',
			$content,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		// parts[0] is whatever preceded the first heading; then repeating
		// triples of level, heading text, following content.
		for ( $i = 1; $i + 2 <= count( $parts ); $i += 3 ) {
			$heading = trim( wp_strip_all_tags( $parts[ $i + 1 ] ) );
			$body    = isset( $parts[ $i + 2 ] ) ? $parts[ $i + 2 ] : '';

			$heading = html_entity_decode( $heading, ENT_QUOTES, 'UTF-8' );

			if ( '' === $heading || '?' !== substr( $heading, -1 ) ) {
				continue;
			}

			$answer = trim( wp_strip_all_tags( $body ) );
			$answer = html_entity_decode( $answer, ENT_QUOTES, 'UTF-8' );
			$answer = trim( preg_replace( '/\s+/u', ' ', $answer ) );

			// A question with no answer under it is a heading, not an FAQ
			// entry, and Google rejects FAQPage entries with empty answers.
			if ( '' === $answer ) {
				continue;
			}

			$pairs[] = array(
				'question' => $heading,
				'answer'   => $answer,
			);
		}

		return $pairs;
	}

	public static function pairs_for_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		// Run the content through the same filters the theme would, so
		// blocks and shortcodes are resolved before headings are looked for.
		$content = apply_filters( 'the_content', $post->post_content );

		return self::extract( $content );
	}

	public static function render() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id || ! self::is_enabled_for( $post_id ) ) {
			return;
		}

		$pairs = self::pairs_for_post( $post_id );

		if ( ! $pairs ) {
			return;
		}

		$entities = array();

		foreach ( $pairs as $pair ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $pair['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $pair['answer'],
				),
			);
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode(
				array(
					'@context'   => 'https://schema.org',
					'@type'      => 'FAQPage',
					'@id'        => get_permalink( $post_id ) . '#faq',
					'mainEntity' => $entities,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Editor
	 * ------------------------------------------------------------------- */

	public static function add_meta_box() {
		foreach ( get_post_types( array( 'public' => true ) ) as $screen ) {
			if ( 'attachment' === $screen ) {
				continue;
			}

			add_meta_box(
				'dos-ai-faq',
				__( 'FAQ schema', 'dos-toolkit' ),
				array( __CLASS__, 'render_meta_box' ),
				$screen,
				'side'
			);
		}
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'dos_ai_faq_save', 'dos_ai_faq_nonce' );

		$enabled = self::is_enabled_for( $post->ID );
		$pairs   = self::pairs_for_post( $post->ID );
		?>
		<p>
			<label>
				<input type="checkbox" name="dos_ai_faq" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Publish FAQ schema for this page', 'dos-toolkit' ); ?>
			</label>
		</p>

		<p class="description">
			<?php esc_html_e( 'Any heading ending in a question mark becomes a question, and the text under it becomes the answer.', 'dos-toolkit' ); ?>
		</p>

		<?php if ( $pairs ) : ?>
			<p><strong>
				<?php
				printf(
					/* translators: %d: number of question and answer pairs found */
					esc_html( _n( '%d pair found:', '%d pairs found:', count( $pairs ), 'dos-toolkit' ) ),
					count( $pairs )
				);
				?>
			</strong></p>
			<ol class="dos-faq-preview">
				<?php foreach ( $pairs as $pair ) : ?>
					<li>
						<strong><?php echo esc_html( $pair['question'] ); ?></strong><br>
						<span><?php echo esc_html( wp_trim_words( $pair['answer'], 20 ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php else : ?>
			<p class="description">
				<em><?php esc_html_e( 'No question headings found yet. Save the page after adding some to see what would be published.', 'dos-toolkit' ); ?></em>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['dos_ai_faq_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dos_ai_faq_nonce'] ) ), 'dos_ai_faq_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['dos_ai_faq'] ) ) {
			delete_post_meta( $post_id, self::META_ENABLED );

			return;
		}

		update_post_meta( $post_id, self::META_ENABLED, 1 );
	}
}
