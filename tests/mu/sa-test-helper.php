<?php
/**
 * TEST ONLY (mounted by the local test runs, never shipped in the plugin zip).
 * - Fakes the OpenAI-compatible API through pre_http_request when the option sa_test_mock is set
 *   ("answer" = answers from passage [1], "fail" = HTTP 500); option sa_test_reply = a fixed reply instead.
 * - Points demo mode at a local engine (option sa_test_demo_endpoint).
 * - Stand-ins for other plugins: a [members_only] shortcode (shows "Members only" to logged-out visitors) and
 *   pmpro_has_membership_access() (pages with the meta _sa_test_locked need a login), as membership plugins do.
 * - Small REST helpers for the end-to-end scripts (namespace sa-test/v1).
 */

// Real-key checks: the key arrives in a request header and lives only in this request (never stored).
if ( ! empty( $_SERVER['HTTP_X_SA_TEST_KEY'] ) && ! defined( 'SITE_ANSWERS_API_KEY' ) ) {
	define( 'SITE_ANSWERS_API_KEY', (string) $_SERVER['HTTP_X_SA_TEST_KEY'] );
}

$sa_test_endpoint = get_option( 'sa_test_demo_endpoint' );
if ( $sa_test_endpoint && ! defined( 'SITE_ANSWERS_DEMO_ENDPOINT' ) ) {
	define( 'SITE_ANSWERS_DEMO_ENDPOINT', $sa_test_endpoint );
}

// The visitor address for the per-IP limit, from a test header (IPv6 /64 checks).
add_filter(
	'site_answers_client_ip',
	function ( $ip ) {
		return empty( $_SERVER['HTTP_X_SA_TEST_IP'] ) ? $ip : (string) $_SERVER['HTTP_X_SA_TEST_IP'];
	}
);

// The online demo's origin, set to this local site for the "demo AI is down" check.
add_filter(
	'site_answers_demo_origins',
	function ( $origins ) {
		$extra = get_option( 'sa_test_demo_origin' );
		return $extra ? array_merge( $origins, array( (string) $extra ) ) : $origins;
	}
);

// A MySQL table in utf8mb3 (the options table here is SQLite): option sa_test_utf8mb3.
add_filter(
	'pre_get_col_charset',
	function ( $charset, $table ) {
		global $wpdb;
		return get_option( 'sa_test_utf8mb3' ) && $table === $wpdb->options ? 'utf8' : $charset;
	},
	10,
	2
);

// A members-only shortcode like those of membership plugins: hidden text for logged-out visitors.
add_shortcode(
	'members_only',
	function ( $atts, $content = '' ) {
		return is_user_logged_in() ? do_shortcode( (string) $content ) : '<p>Members only. Please log in.</p>';
	}
);

// Paid Memberships Pro's access check, simulated: a page with the meta _sa_test_locked needs a logged-in user.
if ( ! function_exists( 'pmpro_has_membership_access' ) ) {
	function pmpro_has_membership_access( $post_id = null ) {
		return ! get_post_meta( (int) $post_id, '_sa_test_locked', true ) || is_user_logged_in();
	}
}

// Rebuild race: while the next rebuild indexes the page with this slug, a second rebuild runs to the end.
add_filter(
	'site_answers_document_text',
	function ( $text, $post ) {
		static $fired = false;
		$slug = get_option( 'sa_test_race_slug' );
		if ( $slug && ! $fired && $post->post_name === $slug ) {
			$fired = true;
			delete_option( 'sa_test_race_slug' );
			Site_Answers_Index::rebuild( 60.0 );
		}
		return $text;
	},
	10,
	2
);

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

// Real-key runs: count the real calls to the AI service and refuse any call past sa_test_call_budget.
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( get_option( 'sa_test_mock' ) || false === strpos( $url, '/chat/completions' ) || false === get_option( 'sa_test_call_budget', false ) ) {
			return $pre;
		}
		$used = (int) get_option( 'sa_test_real_calls', 0 );
		if ( $used >= (int) get_option( 'sa_test_call_budget' ) ) {
			return new WP_Error( 'sa_test_budget', 'Test call budget reached: call not sent' );
		}
		update_option( 'sa_test_real_calls', $used + 1, false );
		return $pre;
	},
	5,
	3
);

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
			'safe'     => ! empty( $args['reject_unsafe_urls'] ), // Sent with wp_safe_remote_post().
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
		$fixed  = get_option( 'sa_test_reply' );
		if ( $fixed && false === strpos( $system, 'Translate' ) ) {
			$content = (string) $fixed;
		} elseif ( false !== strpos( $system, 'Translate' ) ) {
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
					if ( ! class_exists( 'Site_Answers_Index' ) ) {
						return array(
							'active' => false,
							'cron'   => sa_test_crons(),
						);
					}
					return array(
						'active'   => true,
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
						'daily'    => Site_Answers_Limits::used_today(),
						'key_raw'  => gettype( get_option( 'site_answers_api_key' ) ),
						'shards'   => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'site\\_answers\\_shard\\_%' ORDER BY option_name" ),
						'blog'     => get_current_blog_id(),
						'search'   => count( Site_Answers_Index::passages()['chunks'] ),
						'last_err' => get_option( 'site_answers_last_error' ),
						'title'    => get_bloginfo( 'name' ),
						'real_calls' => (int) get_option( 'sa_test_real_calls', 0 ),
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
					if ( 0 !== strpos( $name, 'sa_test_' ) && 0 !== strpos( $name, 'site_answers_' ) && 'blogname' !== $name ) {
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
					if ( isset( $r['title'] ) ) {
						$data['post_title'] = (string) $r['title'];
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
					if ( isset( $r['rank_math'] ) ) {
						update_post_meta( $page->ID, 'rank_math_robots', $r['rank_math'] ? array( 'noindex', 'nofollow' ) : array( 'index' ) );
						Site_Answers_Index::queue( $page->ID );
					}
					if ( isset( $r['locked'] ) ) {
						update_post_meta( $page->ID, '_sa_test_locked', $r['locked'] ? '1' : '' );
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
				'callback'            => function ( $r ) {
					global $wpdb;
					// Per-IP counters and the WP-Cron lock (a failed loopback spawn in Playground leaves it for 60 s).
					$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_site_answers_rl_%'" );
					foreach ( $names as $name ) {
						delete_transient( substr( $name, strlen( '_transient_' ) ) );
					}
					delete_transient( 'doing_cron' );
					if ( ! empty( $r['daily'] ) ) { // Today's AI answer count.
						foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'site\\_answers\\_used\\_%'" ) as $name ) {
							delete_option( $name );
						}
					}
					return array( 'cleared' => count( $names ) );
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/add-page',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( $r ) {
					$old = get_page_by_path( (string) $r['slug'], OBJECT, 'page' );
					if ( $old ) {
						wp_delete_post( $old->ID, true );
					}
					$id = wp_insert_post(
						array(
							'post_type'    => 'page',
							'post_status'  => 'publish',
							'post_title'   => (string) $r['title'],
							'post_name'    => (string) $r['slug'],
							'post_content' => (string) $r['content'],
						)
					);
					return array(
						'id'  => $id,
						'url' => get_permalink( $id ),
					);
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/rebuild',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( $r ) {
					// Optional: while this rebuild indexes the page with slug "race", a second rebuild runs to the end.
					if ( ! empty( $r['race'] ) ) {
						update_option( 'sa_test_race_slug', (string) $r['race'], false );
					}
					if ( ! empty( $r['as_admin'] ) ) {
						wp_set_current_user( 1 ); // As the Rebuild button does it, in an administrator's request.
					}
					$result = Site_Answers_Index::rebuild( 60.0 );
					return array(
						'result' => $result,
						'status' => Site_Answers_Index::status(),
						'meta'   => array_intersect_key( Site_Answers_Index::meta(), array( 'gen' => 1 ) ),
						'build'  => get_option( 'site_answers_build' ),
						'user'   => get_current_user_id(),
					);
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/network',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( $r ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
					$file = 'site-answers/site-answers.php';
					if ( 'activate' === $r['do'] ) {
						$res = activate_plugin( $file, '', true ); // Network Activate.
					} elseif ( 'deactivate' === $r['do'] ) {
						deactivate_plugins( $file, false, true ); // Network Deactivate.
						$res = null;
					} elseif ( 'site' === $r['do'] ) {
						$res = wp_insert_site(
							array(
								'domain' => wp_parse_url( network_home_url(), PHP_URL_HOST ),
								'path'   => '/' . trim( (string) $r['slug'], '/' ) . '/',
								'title'  => (string) $r['slug'],
							)
						);
					}
					$sites = array();
					foreach ( get_sites( array( 'number' => 0 ) ) as $site ) {
						switch_to_blog( $site->blog_id );
						$sites[ $site->path ] = sa_test_crons();
						restore_current_blog();
					}
					return array(
						'result'         => is_wp_error( $res ) ? $res->get_error_message() : $res,
						'network_active' => is_plugin_active_for_network( $file ),
						'crons'          => $sites,
					);
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/sanitize',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( $r ) {
					// The settings form's sanitizing, as a site administrator ("site") or a network administrator.
					if ( 'site' === $r['as'] ) {
						$user = get_user_by( 'login', 'siteadmin' );
						$id   = $user ? $user->ID : wp_insert_user(
							array(
								'user_login' => 'siteadmin',
								'user_pass'  => wp_generate_password(),
								'user_email' => 'siteadmin@example.test',
								'role'       => 'administrator',
							)
						);
						if ( is_multisite() ) {
							add_user_to_blog( get_current_blog_id(), $id, 'administrator' );
						}
					} else {
						$id = 1;
					}
					wp_set_current_user( $id );
					return array(
						'out'           => Site_Answers_Admin::sanitize( (array) $r['input'] ),
						'network_admin' => current_user_can( 'manage_network_options' ),
						'site_admin'    => current_user_can( 'manage_options' ),
						'base_url'      => Site_Answers_Plugin::base_url(),
					);
				},
			)
		);
		register_rest_route(
			'sa-test/v1',
			'/url-check',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => function ( $r ) {
					// What wp_safe_remote_post() would accept (WordPress's own URL check), and the DNS answer it used.
					$out = array();
					foreach ( (array) $r['urls'] as $url ) {
						$host  = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
						$out[] = array(
							'url'  => $url,
							'ip'   => $host ? gethostbyname( $host ) : '',
							'safe' => false !== wp_http_validate_url( (string) $url ),
						);
					}
					return $out;
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
