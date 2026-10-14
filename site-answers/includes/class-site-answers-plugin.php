<?php
/**
 * Bootstrap: settings, activation, cron hooks.
 *
 * @package Site_Answers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide settings and lifecycle.
 */
class Site_Answers_Plugin {

	const OPTION   = 'site_answers_settings';
	const KEY      = 'site_answers_api_key';
	const LAST_ERR = 'site_answers_last_error';

	const CRON_DAILY    = 'site_answers_daily_rebuild';
	const CRON_CONTINUE = 'site_answers_rebuild_continue';
	const CRON_QUEUE    = 'site_answers_process_queue';

	/**
	 * Default model: the first model of the hosted engine's list (fast and accurate on site questions).
	 */
	const DEFAULT_MODEL    = 'google/gemini-3.8-flash';
	const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';

	/**
	 * Hooks.
	 */
	public static function init() {
		load_plugin_textdomain( 'site-answers', false, dirname( plugin_basename( SITE_ANSWERS_FILE ) ) . '/languages' );

		Site_Answers_Index::init();
		Site_Answers_Rest::init();
		Site_Answers_Frontend::init();
		if ( is_admin() ) {
			Site_Answers_Admin::init();
		}

		add_action( self::CRON_DAILY, array( __CLASS__, 'cron_daily' ) );
		add_action( self::CRON_CONTINUE, array( __CLASS__, 'cron_continue' ) );
		add_action( self::CRON_QUEUE, array( 'Site_Answers_Index', 'process_queue' ) );
	}

	/**
	 * Default settings. The API key is stored apart (see KEY), never in this array.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'      => 1,
			'base_url'     => self::DEFAULT_BASE_URL,
			'model'        => self::DEFAULT_MODEL,
			'color'        => '#1f6f5c',
			'greeting'     => '',
			'starters'     => array( '', '', '' ),
			'contact_page' => 0,
			'ip_per_hour'  => 20,
			'daily_cap'    => 300,
			'post_types'   => array( 'post', 'page', 'product' ),
			'demo_mode'    => 0,
		);
	}

	/**
	 * Saved settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		$s['starters'] = array_slice( array_pad( (array) $s['starters'], 3, '' ), 0, 3 );
		return $s;
	}

	/**
	 * The site owner's AI key: the SITE_ANSWERS_API_KEY constant (wp-config.php) wins over the saved option.
	 * Server side only: never printed, never sent to the browser.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'SITE_ANSWERS_API_KEY' ) && is_string( SITE_ANSWERS_API_KEY ) && '' !== trim( SITE_ANSWERS_API_KEY ) ) {
			return trim( SITE_ANSWERS_API_KEY );
		}
		$key = get_option( self::KEY, '' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Greeting shown when the chat opens.
	 *
	 * @return string
	 */
	public static function greeting() {
		$s = self::settings();
		return '' !== trim( $s['greeting'] ) ? $s['greeting'] : __( 'Hi! Ask me anything about this site. I answer from its pages and show my sources.', 'site-answers' );
	}

	/**
	 * Contact page chosen in the settings, if it is published.
	 *
	 * @return array|null { title, url }
	 */
	public static function contact() {
		$id   = (int) self::settings()['contact_page'];
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		return array(
			'title' => wp_strip_all_tags( get_the_title( $post ) ),
			'url'   => get_permalink( $post ),
		);
	}

	/**
	 * Activation: options that must not autoload, the daily safety rebuild and a first index.
	 */
	public static function activate() {
		// Created with autoload off before any save, so later updates keep it off.
		add_option( self::KEY, '', '', false );
		add_option( self::LAST_ERR, array(), '', false );
		add_option( Site_Answers_Index::QUEUE, array(), '', false );

		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
		// A small site is indexed right away; a big one continues in the background (WP-Cron).
		Site_Answers_Index::rebuild( 5.0 );
	}

	/**
	 * Deactivation: stop the scheduled jobs (data stays until the plugin is deleted).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_DAILY );
		wp_clear_scheduled_hook( self::CRON_CONTINUE );
		wp_clear_scheduled_hook( self::CRON_QUEUE );
	}

	/**
	 * Daily safety rebuild (catches changes no hook reported, such as renamed categories).
	 */
	public static function cron_daily() {
		Site_Answers_Index::rebuild( 20.0 );
	}

	/**
	 * Next slice of a rebuild that ran out of time.
	 */
	public static function cron_continue() {
		Site_Answers_Index::continue_build( 20.0 );
	}

	/**
	 * Remember the last AI failure (code and time only) for the settings page.
	 *
	 * @param string $message Short reason, without secrets.
	 */
	public static function note_error( $message ) {
		update_option(
			self::LAST_ERR,
			array(
				'message' => substr( (string) $message, 0, 200 ),
				'time'    => time(),
			),
			false
		);
	}
}
