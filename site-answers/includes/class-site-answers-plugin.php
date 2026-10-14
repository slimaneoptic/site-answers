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
		Site_Answers_Index::init();
		Site_Answers_Rest::init();
		Site_Answers_Frontend::init();
		if ( is_admin() ) {
			Site_Answers_Admin::init();
		}

		add_action( self::CRON_DAILY, array( __CLASS__, 'cron_daily' ) );
		add_action( self::CRON_CONTINUE, array( __CLASS__, 'cron_continue' ) );
		add_action( self::CRON_QUEUE, array( 'Site_Answers_Index', 'process_queue' ) );
		add_action( 'init', array( __CLASS__, 'maybe_set_up_site' ) );
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
		if ( self::key_from_config() ) {
			return trim( SITE_ANSWERS_API_KEY );
		}
		$key = self::stored_key();
		return isset( $key['k'] ) ? $key['k'] : '';
	}

	/**
	 * Is the key set in wp-config.php (SITE_ANSWERS_API_KEY)?
	 *
	 * @return bool
	 */
	public static function key_from_config() {
		return defined( 'SITE_ANSWERS_API_KEY' ) && is_string( SITE_ANSWERS_API_KEY ) && '' !== trim( SITE_ANSWERS_API_KEY );
	}

	/**
	 * The saved key as stored: array( 'k' => key ), or an empty array. Stored as an array so that the
	 * wp-admin/options.php screen shows "SERIALIZED DATA" instead of the key. Older versions saved a plain string.
	 *
	 * @return array
	 */
	public static function stored_key() {
		$key = get_option( self::KEY, array() );
		if ( is_string( $key ) ) {
			$key = array( 'k' => $key );
		}
		$key = is_array( $key ) && isset( $key['k'] ) && is_string( $key['k'] ) ? trim( $key['k'] ) : '';
		return '' !== $key ? array( 'k' => $key ) : array();
	}

	/**
	 * AI base URL. A key set in wp-config.php only goes to the address set next to it (SITE_ANSWERS_BASE_URL) or to
	 * the default service, never to an address saved in the settings: on a network, a site administrator could
	 * otherwise send the network's key to a server of their choice.
	 *
	 * @return string Without a trailing slash.
	 */
	public static function base_url() {
		if ( self::key_from_config() ) {
			$url = defined( 'SITE_ANSWERS_BASE_URL' ) && is_string( SITE_ANSWERS_BASE_URL ) && '' !== trim( SITE_ANSWERS_BASE_URL ) ? trim( SITE_ANSWERS_BASE_URL ) : self::DEFAULT_BASE_URL;
		} else {
			$url = (string) self::settings()['base_url'];
		}
		return untrailingslashit( $url );
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
			'title' => Site_Answers_Text::plain( get_the_title( $post ) ),
			'url'   => get_permalink( $post ),
		);
	}

	/**
	 * Activation: options that must not autoload, the daily safety rebuild and a first index.
	 *
	 * @param bool $network_wide Activated for every site of a network.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			return; // Each site sets itself up on its next request (maybe_set_up_site), not all in this one.
		}
		self::add_options();
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
		// A small site is indexed right away; a big one continues in the background (WP-Cron).
		Site_Answers_Index::rebuild( 5.0 );
	}

	/**
	 * Options created with autoload off before any save, so later updates keep it off.
	 */
	private static function add_options() {
		add_option( self::KEY, array(), '', false );
		add_option( self::LAST_ERR, array(), '', false );
		add_option( Site_Answers_Index::QUEUE, array(), '', false );
	}

	/**
	 * A site where the plugin runs but was never set up (network activation, a site created after it): add the
	 * options and schedule the daily rebuild, which builds the first index at the next cron run (or at the first
	 * question, see Site_Answers_Index::catch_up()).
	 */
	public static function maybe_set_up_site() {
		if ( wp_installing() || wp_next_scheduled( self::CRON_DAILY ) ) {
			return;
		}
		self::add_options();
		wp_schedule_event( Site_Answers_Index::meta()['gen'] ? time() + HOUR_IN_SECONDS : time(), 'daily', self::CRON_DAILY );
	}

	/**
	 * Deactivation: stop the scheduled jobs (data stays until the plugin is deleted), on every site of a network
	 * when it was deactivated for the network.
	 *
	 * @param bool $network_wide Deactivated for every site of a network.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( $site_id );
				self::clear_cron();
				restore_current_blog();
			}
			return;
		}
		self::clear_cron();
	}

	/**
	 * Remove the plugin's scheduled jobs on the current site.
	 */
	private static function clear_cron() {
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
