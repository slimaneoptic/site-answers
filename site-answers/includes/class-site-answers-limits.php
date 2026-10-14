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

	const DAILY    = 'site_answers_daily'; // Early builds: one option for the day's count (still cleaned up).
	const USED_DAY = 'site_answers_used_'; // One option per day: AI answers used that day (site time, Ymd).

	/**
	 * Visitor address for the per-IP limit: REMOTE_ADDR. A visitor can fake forwarding headers, so they are
	 * only used when the site opts in through the filter (for a proxy or CDN it trusts). An IPv6 address counts
	 * as its /64 network: one connection usually holds a whole /64, so per-address counting would not limit it.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP used for the per-IP limit. Behind a proxy or CDN, the best fix is the web server's
		 * real-IP module (nginx real_ip, Apache mod_remoteip) with the proxy's address ranges, so REMOTE_ADDR is
		 * already the visitor's. Otherwise return here the address from the header your proxy sets, and only when
		 * REMOTE_ADDR is one of that proxy's addresses: visitors can send any header themselves.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'site_answers_client_ip', $ip );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return 'unknown';
		}
		$bin = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && function_exists( 'inet_pton' ) ? inet_pton( $ip ) : false;
		if ( is_string( $bin ) && 16 === strlen( $bin ) ) {
			if ( 0 === strpos( $bin, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
				return (string) inet_ntop( substr( $bin, 12 ) ); // An IPv4 address written as IPv6 (::ffff:192.0.2.1).
			}
			return inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
		}
		return $ip;
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
	 * Use one of today's AI answers for the site. False when the daily cap is reached. The check and the count are
	 * one SQL statement, so parallel requests cannot pass the cap together.
	 *
	 * @return bool
	 */
	public static function take_daily() {
		global $wpdb;
		$cap  = (int) Site_Answers_Plugin::settings()['daily_cap'];
		$name = self::day_option();
		if ( $cap <= 0 ) {
			return false;
		}
		if ( add_option( $name, '0', '', false ) ) {
			self::drop_old_days( $name ); // First answer of a new day.
		}
		$taken = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic check-and-add; the option cache is cleared below.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND option_value + 0 < %d",
				$name,
				$cap
			)
		);
		wp_cache_delete( $name, 'options' );
		return 1 === (int) $taken;
	}

	/**
	 * AI answers used today.
	 *
	 * @return int
	 */
	public static function used_today() {
		return (int) get_option( self::day_option(), 0 );
	}

	/**
	 * Option that counts today's AI answers (site time).
	 *
	 * @return string
	 */
	private static function day_option() {
		return self::USED_DAY . current_time( 'Ymd' );
	}

	/**
	 * Delete the counters of earlier days (and the single counter of early builds).
	 *
	 * @param string $today Today's option name.
	 */
	private static function drop_old_days( $today ) {
		global $wpdb;
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Once a day, a few rows.
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::USED_DAY ) . '%' )
		);
		foreach ( (array) $names as $name ) {
			if ( $name !== $today ) {
				delete_option( $name );
			}
		}
		delete_option( self::DAILY );
	}
}
