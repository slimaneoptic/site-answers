<?php
/**
 * Abuse limits for the public endpoints: questions per IP per hour and AI answers per day for the whole site.
 *
 * @package Site_Answers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rate limit and daily cap.
 */
class Site_Answers_Limits {

	const DAILY = 'site_answers_daily';

	/**
	 * Visitor address for the per-IP limit: REMOTE_ADDR. A visitor can fake forwarding headers, so they are
	 * only used when the site opts in through the filter (for a proxy or CDN it trusts).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP used for the per-IP limit. Example for a site behind Cloudflare:
		 * add_filter( 'site_answers_client_ip', function ( $ip ) {
		 *     return isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $ip;
		 * } );
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'site_answers_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	/**
	 * Count one request for this visitor; refuse when the hourly limit is reached. Keeps only a salted hash
	 * of the address with a counter, for one hour.
	 *
	 * @return true|WP_Error
	 */
	public static function take_ip() {
		$limit  = max( 1, (int) Site_Answers_Plugin::settings()['ip_per_hour'] );
		$key    = 'site_answers_rl_' . substr( wp_hash( self::client_ip(), 'nonce' ), 0, 24 );
		$now    = time();
		$bucket = get_transient( $key );
		if ( ! is_array( $bucket ) || (int) $bucket['until'] <= $now ) {
			$bucket = array(
				'n'     => 0,
				'until' => $now + HOUR_IN_SECONDS,
			);
		}
		if ( (int) $bucket['n'] >= $limit ) {
			return new WP_Error(
				'site_answers_rate_limited',
				__( 'You have asked a lot of questions in a short time. Please try again a bit later.', 'site-answers' ),
				array( 'status' => 429 )
			);
		}
		++$bucket['n'];
		set_transient( $key, $bucket, max( 1, (int) $bucket['until'] - $now ) );
		return true;
	}

	/**
	 * Use one of today's AI answers for the site. False when the daily cap is reached.
	 *
	 * @return bool
	 */
	public static function take_daily() {
		$cap   = (int) Site_Answers_Plugin::settings()['daily_cap'];
		$today = current_time( 'Y-m-d' );
		$day   = get_option( self::DAILY, array() );
		if ( ! is_array( $day ) || ! isset( $day['day'] ) || $day['day'] !== $today ) {
			$day = array(
				'day' => $today,
				'n'   => 0,
			);
		}
		if ( (int) $day['n'] >= $cap ) {
			return false;
		}
		++$day['n'];
		update_option( self::DAILY, $day, false );
		return true;
	}

	/**
	 * AI answers used today.
	 *
	 * @return int
	 */
	public static function used_today() {
		$day = get_option( self::DAILY, array() );
		return is_array( $day ) && isset( $day['day'] ) && current_time( 'Y-m-d' ) === $day['day'] ? (int) $day['n'] : 0;
	}
}
