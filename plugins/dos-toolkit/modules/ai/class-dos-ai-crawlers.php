<?php
/**
 * Per-bot crawler policy.
 *
 * The useful distinction is not "AI bots yes/no". It is what a given bot does
 * with the page:
 *
 *   training  - takes content to train a model. Nothing comes back, ever.
 *   search    - indexes pages so they can be cited in answers. A citation is
 *               a referral, so blocking these costs traffic.
 *   user      - fetches a page because someone asked the assistant about that
 *               specific URL, right now. Blocking these breaks a request the
 *               reader deliberately made.
 *
 * Blanket-blocking "AI" kills the second and third along with the first,
 * which is why this is a table rather than a switch.
 *
 * Categories reflect each vendor's stated purpose at the time of writing.
 * Vendors change them, and some tokens do more than one job, so the notes
 * matter as much as the category.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_AI_Crawlers {

	public static function init() {
		add_filter( 'robots_txt', array( __CLASS__, 'append_rules' ), 20, 2 );
	}

	/**
	 * @return array token => [ label, vendor, category, note ]
	 */
	public static function registry() {
		return array(
			'GPTBot' => array(
				'label'    => 'GPTBot',
				'vendor'   => 'OpenAI',
				'category' => 'training',
				'note'     => __( 'Collects content to train OpenAI models. Blocking it does not affect whether ChatGPT can cite you.', 'dos-toolkit' ),
			),
			'OAI-SearchBot' => array(
				'label'    => 'OAI-SearchBot',
				'vendor'   => 'OpenAI',
				'category' => 'search',
				'note'     => __( 'Builds the index ChatGPT search cites from. Blocking it removes you from those answers.', 'dos-toolkit' ),
			),
			'ChatGPT-User' => array(
				'label'    => 'ChatGPT-User',
				'vendor'   => 'OpenAI',
				'category' => 'user',
				'note'     => __( 'Fetches a page because a user asked ChatGPT about that URL. Blocking it breaks a request your reader made deliberately.', 'dos-toolkit' ),
			),
			'ClaudeBot' => array(
				'label'    => 'ClaudeBot',
				'vendor'   => 'Anthropic',
				'category' => 'training',
				'note'     => __( 'Collects content to train Anthropic models.', 'dos-toolkit' ),
			),
			'Claude-SearchBot' => array(
				'label'    => 'Claude-SearchBot',
				'vendor'   => 'Anthropic',
				'category' => 'search',
				'note'     => __( 'Indexes pages so Claude can cite them in answers.', 'dos-toolkit' ),
			),
			'Claude-User' => array(
				'label'    => 'Claude-User',
				'vendor'   => 'Anthropic',
				'category' => 'user',
				'note'     => __( 'Fetches a page on behalf of a user asking about that URL.', 'dos-toolkit' ),
			),
			'PerplexityBot' => array(
				'label'    => 'PerplexityBot',
				'vendor'   => 'Perplexity',
				'category' => 'search',
				'note'     => __( 'Builds the index Perplexity answers cite. Perplexity links sources prominently, so this one tends to be worth allowing.', 'dos-toolkit' ),
			),
			'Perplexity-User' => array(
				'label'    => 'Perplexity-User',
				'vendor'   => 'Perplexity',
				'category' => 'user',
				'note'     => __( 'Fetches a page a user asked about directly.', 'dos-toolkit' ),
			),
			'Google-Extended' => array(
				'label'    => 'Google-Extended',
				'vendor'   => 'Google',
				'category' => 'training',
				'note'     => __( 'Not a crawler — a token controlling whether Google may use already-crawled pages for Gemini. Blocking it does NOT affect Google Search ranking.', 'dos-toolkit' ),
			),
			'Applebot-Extended' => array(
				'label'    => 'Applebot-Extended',
				'vendor'   => 'Apple',
				'category' => 'training',
				'note'     => __( 'Controls Apple Intelligence training only. Applebot itself, which powers Siri and Spotlight, is separate and unaffected.', 'dos-toolkit' ),
			),
			'CCBot' => array(
				'label'    => 'CCBot',
				'vendor'   => 'Common Crawl',
				'category' => 'training',
				'note'     => __( 'Builds the public Common Crawl dataset, which many model builders train on. Blocking it is the broadest single training opt-out available.', 'dos-toolkit' ),
			),
			'Bytespider' => array(
				'label'    => 'Bytespider',
				'vendor'   => 'ByteDance',
				'category' => 'training',
				'note'     => __( 'ByteDance training crawler. Widely reported to crawl aggressively.', 'dos-toolkit' ),
			),
			'Meta-ExternalAgent' => array(
				'label'    => 'Meta-ExternalAgent',
				'vendor'   => 'Meta',
				'category' => 'training',
				'note'     => __( 'Collects content to train Meta models.', 'dos-toolkit' ),
			),
			'DuckAssistBot' => array(
				'label'    => 'DuckAssistBot',
				'vendor'   => 'DuckDuckGo',
				'category' => 'search',
				'note'     => __( 'Fetches pages for DuckAssist answers.', 'dos-toolkit' ),
			),
		);
	}

	public static function categories() {
		return array(
			'training' => __( 'Training', 'dos-toolkit' ),
			'search'   => __( 'Search & citation', 'dos-toolkit' ),
			'user'     => __( 'User-requested', 'dos-toolkit' ),
		);
	}

	/**
	 * Block training, allow anything that can send a reader back. Applied to
	 * any bot the operator has not decided on, including ones added to the
	 * registry in a later release.
	 */
	public static function default_policy( $category ) {
		return 'training' === $category ? 'block' : 'allow';
	}

	public static function policy( $token ) {
		$saved = DOS_Settings::get( 'ai_crawler_policy', array() );

		if ( is_array( $saved ) && isset( $saved[ $token ] ) && in_array( $saved[ $token ], array( 'allow', 'block' ), true ) ) {
			return $saved[ $token ];
		}

		$registry = self::registry();

		return isset( $registry[ $token ] ) ? self::default_policy( $registry[ $token ]['category'] ) : 'allow';
	}

	public static function is_enabled() {
		return (bool) DOS_Settings::get( 'ai_crawlers_enabled', 0 );
	}

	/**
	 * WordPress only filters robots.txt when it is generating it. A real file
	 * in the web root is served by the server and never reaches PHP, so the
	 * settings screen has to say when that is the case rather than showing
	 * rules that are not live.
	 */
	public static function static_file_path() {
		$path = ABSPATH . 'robots.txt';

		return file_exists( $path ) ? $path : '';
	}

	public static function rules() {
		$lines   = array();
		$blocked = array();

		foreach ( self::registry() as $token => $bot ) {
			if ( 'block' === self::policy( $token ) ) {
				$blocked[] = $token;
			}
		}

		if ( ! $blocked ) {
			return '';
		}

		$lines[] = '';
		$lines[] = '# AI crawler policy — DoS Toolkit';

		foreach ( $blocked as $token ) {
			$lines[] = 'User-agent: ' . $token;
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	public static function append_rules( $output, $public ) {
		if ( ! self::is_enabled() ) {
			return $output;
		}

		// A site set to discourage search engines already disallows
		// everything; adding bot-specific rules would only muddy it.
		if ( ! $public ) {
			return $output;
		}

		return $output . self::rules();
	}

	/**
	 * Counts by decision, for the settings screen summary.
	 */
	public static function summary() {
		$out = array( 'allow' => 0, 'block' => 0 );

		foreach ( array_keys( self::registry() ) as $token ) {
			$out[ self::policy( $token ) ]++;
		}

		return $out;
	}
}
