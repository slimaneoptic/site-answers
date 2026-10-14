<?php
/**
 * REST API: POST site-answers/v1/ask, /search (demo mode) and /test (admins).
 *
 * @package Site_Answers
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes and validation.
 */
class Site_Answers_Rest {

	const NS               = 'site-answers/v1';
	const MAX_QUESTION     = 500;
	const MAX_HISTORY      = 6; // Three turns: a question and its answer each.
	const MAX_HISTORY_TEXT = 1000;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public static function routes() {
		$question = array(
			'description'       => __( 'The question, 1 to 500 characters.', 'site-answers' ),
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( __CLASS__, 'validate_question' ),
			'sanitize_callback' => array( __CLASS__, 'clean_text' ),
		);
		$history  = array(
			'description'       => __( 'Up to three earlier turns as { role: user|assistant, content } items of at most 1,000 characters.', 'site-answers' ),
			'type'              => 'array',
			'default'           => array(),
			'validate_callback' => array( __CLASS__, 'validate_history' ),
			'sanitize_callback' => array( __CLASS__, 'clean_history' ),
		);

		// Public on purpose: visitors ask questions without an account. Abuse is bounded by the per-IP rate
		// limit and the site-wide daily cap (Settings > Site Answers); answers only use published content.
		register_rest_route(
			self::NS,
			'/ask',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'ask' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'question' => $question,
					'history'  => $history,
				),
			)
		);

		// Public on purpose, for the online demo only (it answers 404 unless demo mode is on): it returns
		// passages of published content, with the same limits as /ask. The browser sends them to the demo AI.
		register_rest_route(
			self::NS,
			'/search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'question' => $question,
					'history'  => $history,
					'query'    => array(
						'description'       => __( 'The question translated into the site language (optional second try).', 'site-answers' ),
						'type'              => 'string',
						'default'           => '',
						'validate_callback' => array( __CLASS__, 'validate_query' ),
						'sanitize_callback' => array( __CLASS__, 'clean_text' ),
					),
				),
			)
		);

		// The settings page's "Test a question" box.
		register_rest_route(
			self::NS,
			'/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'test' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array( 'question' => $question ),
			)
		);
	}

	/**
	 * POST /ask.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function ask( $request ) {
		$question = (string) $request->get_param( 'question' );
		if ( '' === $question ) {
			return self::empty_question();
		}
		$allowed = Site_Answers_Limits::take_ip();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		return self::no_store( Site_Answers_Answer::answer( $question, (array) $request->get_param( 'history' ) ) );
	}

	/**
	 * POST /search (demo mode only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function search( $request ) {
		if ( empty( Site_Answers_Plugin::settings()['demo_mode'] ) ) {
			return new WP_Error( 'site_answers_demo_off', __( 'Demo mode is off on this site.', 'site-answers' ), array( 'status' => 404 ) );
		}
		$question = (string) $request->get_param( 'question' );
		if ( '' === $question ) {
			return self::empty_question();
		}
		$allowed = Site_Answers_Limits::take_ip();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$out = Site_Answers_Answer::passages( $question, (array) $request->get_param( 'history' ), (string) $request->get_param( 'query' ) );
		if ( $out['found'] && ! Site_Answers_Limits::take_daily() ) {
			return new WP_Error( 'site_answers_daily_cap', __( 'This site has used all of today\'s AI answers. Please come back tomorrow.', 'site-answers' ), array( 'status' => 429 ) );
		}
		return self::no_store( $out );
	}

	/**
	 * POST /test (admins): the answer with the passages, scores and any AI error. Not counted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test( $request ) {
		$question = (string) $request->get_param( 'question' );
		if ( '' === $question ) {
			return self::empty_question();
		}
		return self::no_store(
			Site_Answers_Answer::answer(
				$question,
				array(),
				array(
					'count' => false,
					'debug' => true,
				)
			)
		);
	}

	/**
	 * JSON response that proxies and browsers must not cache.
	 *
	 * @param array $data Data.
	 * @return WP_REST_Response
	 */
	private static function no_store( $data ) {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Error for a question that is empty once cleaned.
	 *
	 * @return WP_Error
	 */
	private static function empty_question() {
		return new WP_Error( 'rest_invalid_param', __( 'Please type a question.', 'site-answers' ), array( 'status' => 400 ) );
	}

	/**
	 * Question: a string of 1 to 500 characters.
	 *
	 * @param mixed $value Value.
	 * @return true|WP_Error
	 */
	public static function validate_question( $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The question must be text.', 'site-answers' ), array( 'status' => 400 ) );
		}
		$length = mb_strlen( trim( $value ) );
		if ( $length < 1 || $length > self::MAX_QUESTION ) {
			/* translators: %d: maximum number of characters. */
			return new WP_Error( 'rest_invalid_param', sprintf( __( 'The question must be 1 to %d characters long.', 'site-answers' ), self::MAX_QUESTION ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Translated query: empty, or a string of at most 500 characters.
	 *
	 * @param mixed $value Value.
	 * @return true|WP_Error
	 */
	public static function validate_query( $value ) {
		if ( ! is_string( $value ) || mb_strlen( $value ) > self::MAX_QUESTION ) {
			return new WP_Error( 'rest_invalid_param', __( 'The query must be text of at most 500 characters.', 'site-answers' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * History: at most three turns of { role: user|assistant, content: text up to 1,000 characters }.
	 *
	 * @param mixed $value Value.
	 * @return true|WP_Error
	 */
	public static function validate_history( $value ) {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_HISTORY ) {
			return new WP_Error( 'rest_invalid_param', __( 'The history can hold at most three turns.', 'site-answers' ), array( 'status' => 400 ) );
		}
		foreach ( $value as $turn ) {
			if ( ! is_array( $turn ) || ! isset( $turn['role'], $turn['content'] ) || ! in_array( $turn['role'], array( 'user', 'assistant' ), true )
				|| ! is_string( $turn['content'] ) || mb_strlen( $turn['content'] ) > self::MAX_HISTORY_TEXT ) {
				return new WP_Error( 'rest_invalid_param', __( 'Each history item needs a role (user or assistant) and a text of at most 1,000 characters.', 'site-answers' ), array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/**
	 * Plain text: valid UTF-8, no tags, single spaces.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function clean_text( $value ) {
		$text = wp_strip_all_tags( wp_check_invalid_utf8( (string) $value, true ) );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Clean history items.
	 *
	 * @param array $value Validated history.
	 * @return array
	 */
	public static function clean_history( $value ) {
		$out = array();
		foreach ( (array) $value as $turn ) {
			$content = self::clean_text( $turn['content'] );
			if ( '' !== $content ) {
				$out[] = array(
					'role'    => $turn['role'],
					'content' => $content,
				);
			}
		}
		return $out;
	}
}
