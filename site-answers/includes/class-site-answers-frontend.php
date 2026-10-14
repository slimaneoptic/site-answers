<?php
/**
 * Front end: the floating chat button and the [site_answers] shortcode.
 *
 * @package Site_Answers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Widget assets and configuration.
 */
class Site_Answers_Frontend {

	/**
	 * Whether the inline configuration was printed.
	 *
	 * @var bool
	 */
	private static $configured = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_shortcode( 'site_answers', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Register the script and style once.
	 */
	private static function register() {
		if ( wp_script_is( 'site-answers', 'registered' ) ) {
			return;
		}
		wp_register_script(
			'site-answers',
			SITE_ANSWERS_URL . 'assets/site-answers.js',
			array(),
			SITE_ANSWERS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_style( 'site-answers', SITE_ANSWERS_URL . 'assets/site-answers.css', array(), SITE_ANSWERS_VERSION );
	}

	/**
	 * Load the floating widget on every front-end page when it is enabled.
	 */
	public static function enqueue() {
		if ( ! empty( Site_Answers_Plugin::settings()['enabled'] ) ) {
			self::load();
		}
	}

	/**
	 * [site_answers]: the same chat, inline in the content.
	 *
	 * @return string
	 */
	public static function shortcode() {
		self::load();
		return '<div class="sa-root sa-inline-host" data-site-answers-inline></div>';
	}

	/**
	 * Enqueue the assets and print the configuration (no key, no secrets: everything here is public).
	 */
	private static function load() {
		self::register();
		wp_enqueue_script( 'site-answers' );
		wp_enqueue_style( 'site-answers' );
		if ( self::$configured ) {
			return;
		}
		self::$configured = true;

		$s     = Site_Answers_Plugin::settings();
		$color = sanitize_hex_color( $s['color'] );
		$color = $color ? $color : '#1f6f5c';
		wp_add_inline_style( 'site-answers', '.sa-root{--sa-brand:' . $color . ';--sa-on-brand:' . self::text_on( $color ) . '}' );

		$demo   = ! empty( $s['demo_mode'] );
		$config = array(
			'rest'         => esc_url_raw( rest_url( Site_Answers_Rest::NS . '/' ) ),
			'floating'     => ! empty( $s['enabled'] ),
			'demo'         => $demo,
			/** This filter is documented in readme.txt (Developers). */
			'demoEndpoint' => $demo ? esc_url_raw( (string) apply_filters( 'site_answers_demo_endpoint', SITE_ANSWERS_DEMO_ENDPOINT ) ) : '',
			/**
			 * Filters the origins where the demo AI answers (the online demo). Elsewhere a failed call to it means
			 * "this site is not the online demo"; there it means "the demo AI is down right now".
			 *
			 * @param string[] $origins Origins such as "https://playground.wordpress.net".
			 */
			'demoOrigins'  => $demo ? array_values( array_map( 'strval', (array) apply_filters( 'site_answers_demo_origins', array( 'https://playground.wordpress.net' ) ) ) ) : array(),
			'title'        => Site_Answers_Text::plain( get_bloginfo( 'name' ) ),
			'greeting'     => Site_Answers_Plugin::greeting(),
			'starters'     => array_values( array_filter( array_map( 'trim', $s['starters'] ) ) ),
			'contact'      => Site_Answers_Plugin::contact(),
			'i18n'         => array(
				'open'        => __( 'Ask a question about this site', 'site-answers' ),
				'ask'         => __( 'Ask', 'site-answers' ),
				'close'       => __( 'Close the chat', 'site-answers' ),
				'label'       => __( 'Your question', 'site-answers' ),
				'placeholder' => __( 'Type your question…', 'site-answers' ),
				'send'        => __( 'Send', 'site-answers' ),
				'sources'     => __( 'Sources', 'site-answers' ),
				'thinking'    => __( 'Looking through the site…', 'site-answers' ),
				'error'       => __( 'Something went wrong. Please try again.', 'site-answers' ),
				'contact'     => __( 'Contact us', 'site-answers' ),
				'demoOnly'    => __( 'The free demo AI only works in the online demo. Add your AI key in Settings > Site Answers.', 'site-answers' ),
				'demoDown'    => __( 'The demo AI did not answer just now. Here are the matching passages.', 'site-answers' ),
				'footer'      => __( 'Answers come from this site’s pages.', 'site-answers' ),
			),
		);
		wp_add_inline_script( 'site-answers', 'window.siteAnswers=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . ';', 'before' );
	}

	/**
	 * Readable text colour on the brand colour (WCAG relative luminance).
	 *
	 * @param string $hex #rgb or #rrggbb.
	 * @return string
	 */
	public static function text_on( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$lum = 0.0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
			$c    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$c    = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			$lum += $weight * $c;
		}
		// Contrast with white vs. with near-black (#111, luminance about 0.0056).
		return ( 1.05 / ( $lum + 0.05 ) ) >= ( ( $lum + 0.05 ) / 0.0556 ) ? '#ffffff' : '#111111';
	}
}
