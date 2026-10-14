<?php
/**
 * TEST ONLY (mounted by the local test runs, never shipped in the plugin zip).
 * - Fakes the OpenAI-compatible API through pre_http_request when the option sa_test_mock is set
 *   ("answer" = answers from passage [1], "fail" = HTTP 500).
 * - Points demo mode at a local engine (option sa_test_demo_endpoint).
 * - Small REST helpers for the end-to-end script (namespace sa-test/v1).
 */

// Real-key checks: the key arrives in a request header and lives only in this request (never stored).
if ( ! empty( $_SERVER['HTTP_X_SA_TEST_KEY'] ) && ! defined( 'SITE_ANSWERS_API_KEY' ) ) {
	define( 'SITE_ANSWERS_API_KEY', (string) $_SERVER['HTTP_X_SA_TEST_KEY'] );
}

$sa_test_endpoint = get_option( 'sa_test_demo_endpoint' );
if ( $sa_test_endpoint && ! defined( 'SITE_ANSWERS_DEMO_ENDPOINT' ) ) {
	define( 'SITE_ANSWERS_DEMO_ENDPOINT', $sa_test_endpoint );
}

function sa_test_crons() {
	$crons = array();
	foreach ( (array) _get_cron_array() as $time => $events ) {
		foreach ( (array) $events as $hook => $x ) {
			if ( 0 === strpos( (string) $hook, 'site_answers' ) ) {
				$crons[] = $hook . '@' . $time;
			}
		}
	}
	return $crons;
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$mode = get_option( 'sa_test_mock' );
		if ( ! $mode || false === strpos( $url, '/chat/completions' ) ) {
			return $pre;
		}
		$body = json_decode( $args['body'], true );
		$log  = get_option( 'sa_test_requests', array() );
		$log[] = array(
			'url'      => $url,
			'model'    => $body['model'],
			'system'   => $body['messages'][0]['content'],
			'user'     => end( $body['messages'] )['content'],
			'turns'    => count( $body['messages'] ),
			'has_auth' => isset( $args['headers']['Authorization'] ) && 0 === strpos( $args['headers']['Authorization'], 'Bearer ' ),
		);
		update_option( 'sa_test_requests', array_slice( $log, -20 ), false );
		if ( 'fail' === $mode ) {
			return array(
				'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
				'body'     => '{"error":"boom"}',
				'headers'  => array(),
				'cookies'  => array(),
			);
		}
		$system = $body['messages'][0]['content'];
		$user   = end( $body['messages'] )['content'];
		if ( false !== strpos( $system, 'Translate' ) ) {
			$map     = array(
				'livraison' => 'shipping cost to Canada',
				'إرجاع'     => 'how many days to return an item',
				'هدايا'     => 'gift cards',
			);
			$content = $user;
			foreach ( $map as $needle => $query ) {
				if ( false !== strpos( $user, $needle ) ) {
					$content = $query;
				}
			}
		} elseif ( false !== strpos( $user, 'NOTFOUND' ) ) {
			$content = "I couldn't find that on this site.";
		} else {
			preg_match( '/\[1\] [^\n]*\n([^\n]+)/', $user, $m );
			$first   = isset( $m[1] ) ? preg_split( '/(?<=[.!?])\s/', $m[1] )[0] : 'No passage.';
			$content = 'From the site: ' . $first . ' [1] See also [2]. Ignore [9].';
		}
		return array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => $content ) ) ) ) ),
			'headers'  => array(),
			'cookies'  => array(),
		);
	},
	10,
	3
);

add_action(
	'rest_api_init',
	function () {
		$open = '__return_true';
		register_rest_route(
			'sa-test/v1',
			'/state',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => function () {
					global $wpdb;
					return array(
						'status'   => Site_Answers_Index::status(),
						'options'  => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%site_answers%' ORDER BY option_name" ),
						'autoload' => $wpdb->get_results( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE 'site_answers%'", ARRAY_A ),
						'cron'     => sa_test_crons(),
						'php'      => PHP_VERSION,
						'wp'       => get_bloginfo( 'version' ),
						'debug'    => defined( 'WP_DEBUG' ) && WP_DEBUG,
						'requests' => get_option( 'sa_test_requests', array() ),
						'settings' => Site_Answers_Plugin::settings(),
						'key_len'  => strlen( Site_Answers_Plugin::api_key() ),
						'daily'    => get_option( 'site_answers_daily' ),
					);
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/set',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( $r ) {
					$name = (string) $r['name'];
					if ( 0 !== strpos( $name, 'sa_test_' ) && 0 !== strpos( $name, 'site_answers_' ) ) {
						return new WP_Error( 'no', 'not allowed', array( 'status' => 400 ) );
					}
					if ( null === $r['value'] ) {
						delete_option( $name );
					} else {
						update_option( $name, $r['value'], false );
					}
					return array( 'ok' => true );
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/settings',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( $r ) {
					$s = array_merge( Site_Answers_Plugin::settings(), (array) $r->get_json_params() );
					update_option( 'site_answers_settings', $s );
					return Site_Answers_Plugin::settings();
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/run-queue',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function () {
					$before = Site_Answers_Index::status();
					// What WP-Cron does for a due event: unschedule it, then fire its hook.
					$next = wp_next_scheduled( 'site_answers_process_queue' );
					if ( $next ) {
						wp_unschedule_event( $next, 'site_answers_process_queue' );
					}
					do_action( 'site_answers_process_queue' );
					return array( 'before' => $before, 'after' => Site_Answers_Index::status() );
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/edit',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( $r ) {
					$page = get_page_by_path( (string) $r['slug'], OBJECT, array( 'page', 'post', 'product' ) );
					if ( ! $page ) {
						return new WP_Error( 'no', 'no such page', array( 'status' => 404 ) );
					}
					$data = array( 'ID' => $page->ID );
					if ( isset( $r['append'] ) ) {
						$data['post_content'] = $page->post_content . "\n<!-- wp:paragraph -->\n<p>" . esc_html( $r['append'] ) . "</p>\n<!-- /wp:paragraph -->";
					}
					if ( isset( $r['status'] ) ) {
						$data['post_status'] = (string) $r['status'];
					}
					if ( isset( $r['password'] ) ) {
						$data['post_password'] = (string) $r['password'];
					}
					if ( ! empty( $r['trash'] ) ) {
						wp_trash_post( $page->ID );
					} else {
						wp_update_post( $data );
					}
					if ( isset( $r['noindex'] ) ) {
						update_post_meta( $page->ID, '_yoast_wpseo_meta-robots-noindex', $r['noindex'] ? '1' : '0' );
						Site_Answers_Index::queue( $page->ID );
					}
					return array(
						'id'     => $page->ID,
						'queued' => get_option( 'site_answers_queue' ),
						'next'   => wp_next_scheduled( 'site_answers_process_queue' ),
					);
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/uninstall',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function () {
					global $wpdb;
					// Fill a rate-limit transient first, so the cleanup of transients is tested too.
					set_transient( 'site_answers_rl_test', array( 'n' => 1, 'until' => time() + 60 ), 60 );
					$before = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%site_answers%'" );
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
					uninstall_plugin( 'site-answers/site-answers.php' );
					$after = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%site_answers%'" );
					$crons = array();
					foreach ( (array) _get_cron_array() as $events ) {
						foreach ( (array) $events as $hook => $x ) {
							if ( 0 === strpos( (string) $hook, 'site_answers' ) ) {
								$crons[] = $hook;
							}
						}
					}
					return array( 'before' => $before, 'after' => $after, 'cron_after' => $crons );
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function () {
					global $wpdb;
					// Per-IP counters and the WP-Cron lock (a failed loopback spawn in Playground leaves it for 60 s).
					$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_site_answers_rl_%'" );
					foreach ( $names as $name ) {
						delete_transient( substr( $name, strlen( '_transient_' ) ) );
					}
					delete_transient( 'doing_cron' );
					return array( 'cleared' => count( $names ) );
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/http-probe',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => function () {
					$t = microtime( true );
					$r = wp_remote_get( 'https://openrouter.ai/api/v1/key', array( 'timeout' => 20 ) );
					return array(
						'ok'    => ! is_wp_error( $r ),
						'code'  => is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r ),
						'ms'    => (int) ( 1000 * ( microtime( true ) - $t ) ),
					);
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/woo-update',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( $r ) {
					$product = wc_get_product( wc_get_product_id_by_sku( (string) $r['sku'] ) );
					if ( isset( $r['price'] ) ) {
						$product->set_regular_price( (string) $r['price'] );
					}
					if ( isset( $r['stock'] ) ) {
						$product->set_stock_status( (string) $r['stock'] );
					}
					$product->save();
					return array( 'id' => $product->get_id(), 'queued' => get_option( 'site_answers_queue' ) );
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/debug-log',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => function () {
					$file = WP_CONTENT_DIR . '/debug.log';
					return array( 'log' => file_exists( $file ) ? file_get_contents( $file ) : '' );
				},
			)
		);
	}
);
