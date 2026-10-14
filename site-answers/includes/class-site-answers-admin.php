<?php
/**
 * Settings > Site Answers: settings, index status, Rebuild button and a test box.
 *
 * @package Site_Answers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen.
 */
class Site_Answers_Admin {

	const PAGE  = 'site-answers';
	const GROUP = 'site_answers';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_site_answers_rebuild', array( __CLASS__, 'handle_rebuild' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'update_option_' . Site_Answers_Plugin::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . Site_Answers_Plugin::OPTION, array( __CLASS__, 'settings_added' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( SITE_ANSWERS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_options_page( __( 'Site Answers', 'site-answers' ), __( 'Site Answers', 'site-answers' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'site-answers' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings API registration.
	 */
	public static function register() {
		// Make sure the key option exists with autoload off before options.php first saves it.
		if ( false === get_option( Site_Answers_Plugin::KEY, false ) ) {
			add_option( Site_Answers_Plugin::KEY, '', '', false );
		}
		register_setting(
			self::GROUP,
			Site_Answers_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Site_Answers_Plugin::defaults(),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUP,
			Site_Answers_Plugin::KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_key' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		add_settings_section( 'sa_widget', __( 'Chat widget', 'site-answers' ), '__return_false', self::PAGE );
		add_settings_section( 'sa_ai', __( 'AI (your own key)', 'site-answers' ), array( __CLASS__, 'section_ai' ), self::PAGE );
		add_settings_section( 'sa_limits', __( 'Limits', 'site-answers' ), array( __CLASS__, 'section_limits' ), self::PAGE );
		add_settings_section( 'sa_index', __( 'Content', 'site-answers' ), '__return_false', self::PAGE );
		add_settings_section( 'sa_demo', __( 'Demo mode', 'site-answers' ), '__return_false', self::PAGE );

		$fields = array(
			array( 'enabled', __( 'Chat button', 'site-answers' ), 'sa_widget' ),
			array( 'color', __( 'Brand colour', 'site-answers' ), 'sa_widget' ),
			array( 'greeting', __( 'Greeting', 'site-answers' ), 'sa_widget' ),
			array( 'starters', __( 'Starter questions', 'site-answers' ), 'sa_widget' ),
			array( 'contact_page', __( 'Contact page', 'site-answers' ), 'sa_widget' ),
			array( 'base_url', __( 'AI base URL', 'site-answers' ), 'sa_ai' ),
			array( 'api_key', __( 'API key', 'site-answers' ), 'sa_ai' ),
			array( 'model', __( 'Model', 'site-answers' ), 'sa_ai' ),
			array( 'ip_per_hour', __( 'Questions per visitor', 'site-answers' ), 'sa_limits' ),
			array( 'daily_cap', __( 'AI answers per day', 'site-answers' ), 'sa_limits' ),
			array( 'post_types', __( 'Content to index', 'site-answers' ), 'sa_index' ),
			array( 'demo_mode', __( 'Demo mode', 'site-answers' ), 'sa_demo' ),
		);
		foreach ( $fields as $field ) {
			add_settings_field(
				'sa_' . $field[0],
				$field[1],
				array( __CLASS__, 'field_' . $field[0] ),
				self::PAGE,
				$field[2],
				in_array( $field[0], array( 'starters', 'post_types', 'enabled', 'demo_mode' ), true ) ? array() : array( 'label_for' => 'sa-' . $field[0] )
			);
		}
	}

	/**
	 * Settings page assets.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'site-answers-admin', SITE_ANSWERS_URL . 'assets/admin.css', array(), SITE_ANSWERS_VERSION );
		wp_enqueue_script( 'site-answers-admin', SITE_ANSWERS_URL . 'assets/admin.js', array( 'jquery', 'wp-color-picker', 'wp-api-fetch' ), SITE_ANSWERS_VERSION, true );
		wp_localize_script(
			'site-answers-admin',
			'siteAnswersAdmin',
			array(
				'answer'   => __( 'Answer', 'site-answers' ),
				'passages' => __( 'Passages used (best first)', 'site-answers' ),
				'none'     => __( 'No passage passed the relevance cutoff, so the chat says it could not find this and points to the contact page.', 'site-answers' ),
				'mode'     => __( 'Mode', 'site-answers' ),
				'score'    => __( 'score', 'site-answers' ),
				'coverage' => __( 'coverage', 'site-answers' ),
				'query'    => __( 'Searched with the translated query', 'site-answers' ),
				'aiError'  => __( 'AI error', 'site-answers' ),
				'failed'   => __( 'The test failed. Please reload the page and try again.', 'site-answers' ),
				'asking'   => __( 'Asking…', 'site-answers' ),
				'modes'    => array(
					'ai'         => __( 'AI answer with sources', 'site-answers' ),
					'extractive' => __( 'Passages only (no AI answer)', 'site-answers' ),
					'not_found'  => __( 'Not found on this site', 'site-answers' ),
				),
			)
		);
	}

	// ------------------------------------------------------------------ Page.

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = Site_Answers_Index::status();
		$s      = Site_Answers_Plugin::settings();
		?>
		<div class="wrap site-answers-admin">
			<h1><?php esc_html_e( 'Site Answers', 'site-answers' ); ?></h1>
			<p class="sa-lead"><?php esc_html_e( 'A chat assistant that answers visitors from your published content, with numbered sources. It says so when the answer is not on your site.', 'site-answers' ); ?></p>
			<?php self::rebuild_notice( $status ); ?>

			<div class="sa-cards">
				<section class="sa-card" aria-labelledby="sa-index-h">
					<h2 id="sa-index-h"><?php esc_html_e( 'Index', 'site-answers' ); ?></h2>
					<?php self::status_table( $status, $s ); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="site_answers_rebuild">
						<?php wp_nonce_field( 'site_answers_rebuild' ); ?>
						<?php submit_button( __( 'Rebuild index', 'site-answers' ), 'secondary', 'submit', false ); ?>
						<p class="description"><?php esc_html_e( 'Edits are picked up automatically; a full rebuild also runs once a day.', 'site-answers' ); ?></p>
					</form>
				</section>

				<section class="sa-card" aria-labelledby="sa-test-h">
					<h2 id="sa-test-h"><?php esc_html_e( 'Test a question', 'site-answers' ); ?></h2>
					<form id="site-answers-test" class="sa-test">
						<label for="sa-test-q" class="screen-reader-text"><?php esc_html_e( 'Question', 'site-answers' ); ?></label>
						<input type="text" id="sa-test-q" class="regular-text" maxlength="500" dir="auto" required placeholder="<?php esc_attr_e( 'For example: how long does shipping take?', 'site-answers' ); ?>">
						<?php submit_button( __( 'Ask', 'site-answers' ), 'primary', 'sa-test-submit', false ); ?>
					</form>
					<p class="description">
						<?php
						echo esc_html(
							! empty( $s['demo_mode'] )
								? __( 'Uses your own key (or shows the passages if none is saved). Visitors in demo mode get the hosted demo AI instead. Not counted in the daily limit.', 'site-answers' )
								: __( 'Shows the answer and the passages it used. Not counted in the daily limit.', 'site-answers' )
						);
						?>
					</p>
					<div id="site-answers-test-result" class="sa-test-result" aria-live="polite"></div>
				</section>
			</div>

			<form method="post" action="options.php" class="sa-settings" autocomplete="off">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Notice after a rebuild.
	 *
	 * @param array $status Index status.
	 */
	private static function rebuild_notice( $status ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: the rebuild itself was nonce-checked.
		$done = isset( $_GET['site_answers_rebuilt'] ) ? sanitize_key( wp_unslash( $_GET['site_answers_rebuilt'] ) ) : '';
		if ( 'done' === $done ) {
			$message = sprintf(
				/* translators: 1: number of pages/posts/products, 2: number of passages. */
				__( 'Index rebuilt: %1$s items in %2$s passages.', 'site-answers' ),
				number_format_i18n( $status['posts'] ),
				number_format_i18n( $status['passages'] )
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		} elseif ( 'partial' === $done ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Rebuild started. It continues in the background; the current index answers until the new one is ready.', 'site-answers' ) . '</p></div>';
		}
	}

	/**
	 * Index status table.
	 *
	 * @param array $status Index status.
	 * @param array $s      Settings.
	 */
	private static function status_table( $status, $s ) {
		$types = array();
		foreach ( $status['types'] as $type => $n ) {
			$object  = get_post_type_object( $type );
			$label   = $object ? ( 1 === (int) $n ? $object->labels->singular_name : $object->labels->name ) : $type;
			$types[] = number_format_i18n( $n ) . ' ' . strtolower( $label );
		}
		$rows = array(
			__( 'Indexed', 'site-answers' )      => $status['posts']
				/* translators: 1: number of items, 2: breakdown such as "8 pages, 2 posts". */
				? sprintf( __( '%1$s items (%2$s)', 'site-answers' ), number_format_i18n( $status['posts'] ), implode( ', ', $types ) )
				: __( 'Nothing yet', 'site-answers' ),
			__( 'Passages', 'site-answers' )     => number_format_i18n( $status['passages'] ),
			__( 'Last rebuild', 'site-answers' ) => self::ago( $status['built'] ),
			__( 'Last update', 'site-answers' )  => self::ago( $status['updated'] ),
		);
		if ( null !== $status['building'] ) {
			/* translators: %s: number of items indexed so far. */
			$rows[ __( 'Rebuild running', 'site-answers' ) ] = sprintf( __( '%s items so far, continuing in the background', 'site-answers' ), number_format_i18n( $status['building'] ) );
		}
		if ( $status['queued'] ) {
			$rows[ __( 'Waiting edits', 'site-answers' ) ] = number_format_i18n( $status['queued'] );
		}
		if ( defined( 'SITE_ANSWERS_API_KEY' ) && '' !== Site_Answers_Plugin::api_key() ) {
			$key = __( 'Set in wp-config.php', 'site-answers' );
		} else {
			$key = '' !== Site_Answers_Plugin::api_key() ? __( 'Saved', 'site-answers' ) : __( 'Not saved: visitors get passages, not AI answers', 'site-answers' );
		}
		$rows[ __( 'AI key', 'site-answers' ) ] = $key;
		/* translators: 1: AI answers used today, 2: daily limit. */
		$rows[ __( 'AI answers today', 'site-answers' ) ] = sprintf( __( '%1$s of %2$s', 'site-answers' ), number_format_i18n( Site_Answers_Limits::used_today() ), number_format_i18n( (int) $s['daily_cap'] ) );
		$error = get_option( Site_Answers_Plugin::LAST_ERR, array() );
		if ( is_array( $error ) && ! empty( $error['message'] ) && time() - (int) $error['time'] < DAY_IN_SECONDS ) {
			$rows[ __( 'Last AI error', 'site-answers' ) ] = $error['message'] . ' (' . self::ago( (int) $error['time'] ) . ')';
		}

		echo '<table class="sa-status"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * "5 minutes ago", or "Never".
	 *
	 * @param int $time Timestamp.
	 * @return string
	 */
	private static function ago( $time ) {
		if ( ! $time ) {
			return __( 'Never', 'site-answers' );
		}
		/* translators: %s: time span such as "5 mins". */
		return sprintf( __( '%s ago', 'site-answers' ), human_time_diff( (int) $time, time() ) );
	}

	/**
	 * AI section intro.
	 */
	public static function section_ai() {
		echo '<p>' . esc_html__( 'Any OpenAI-compatible service works (OpenRouter by default). The key stays on your server: it is never shown again, never sent to the browser.', 'site-answers' ) . '</p>';
	}

	/**
	 * Limits section intro.
	 */
	public static function section_limits() {
		echo '<p>' . esc_html__( 'They protect your AI bill from abuse. When a limit is reached, visitors get a short notice or the most relevant passages instead of an AI answer.', 'site-answers' ) . '</p>';
	}

	// ------------------------------------------------------------------ Fields.

	/**
	 * Name attribute of a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Site_Answers_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Field: enabled.
	 */
	public static function field_enabled() {
		$s = Site_Answers_Plugin::settings();
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label><p class="description">%4$s</p>',
			esc_attr( self::name( 'enabled' ) ),
			checked( ! empty( $s['enabled'] ), true, false ),
			esc_html__( 'Show the chat button on every page', 'site-answers' ),
			esc_html__( 'Or place the chat inside any page with the [site_answers] shortcode.', 'site-answers' )
		);
	}

	/**
	 * Field: color.
	 */
	public static function field_color() {
		$s = Site_Answers_Plugin::settings();
		printf(
			'<input type="text" id="sa-color" class="site-answers-color" name="%1$s" value="%2$s" data-default-color="#1f6f5c">',
			esc_attr( self::name( 'color' ) ),
			esc_attr( $s['color'] )
		);
	}

	/**
	 * Field: greeting.
	 */
	public static function field_greeting() {
		$s = Site_Answers_Plugin::settings();
		printf(
			'<input type="text" id="sa-greeting" class="large-text" maxlength="200" name="%1$s" value="%2$s" placeholder="%3$s">',
			esc_attr( self::name( 'greeting' ) ),
			esc_attr( $s['greeting'] ),
			esc_attr( Site_Answers_Plugin::greeting() )
		);
	}

	/**
	 * Field: starter questions.
	 */
	public static function field_starters() {
		$s = Site_Answers_Plugin::settings();
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Starter questions', 'site-answers' ) . '</legend>';
		foreach ( $s['starters'] as $i => $starter ) {
			printf(
				'<p><label class="screen-reader-text" for="sa-starter-%1$d">%2$s</label><input type="text" id="sa-starter-%1$d" class="regular-text" maxlength="120" name="%3$s[]" value="%4$s"></p>',
				(int) $i,
				/* translators: %d: starter question number. */
				esc_html( sprintf( __( 'Starter question %d', 'site-answers' ), $i + 1 ) ),
				esc_attr( self::name( 'starters' ) ),
				esc_attr( $starter )
			);
		}
		echo '<p class="description">' . esc_html__( 'Up to three questions shown as buttons when the chat opens.', 'site-answers' ) . '</p></fieldset>';
	}

	/**
	 * Field: contact page.
	 */
	public static function field_contact_page() {
		$s = Site_Answers_Plugin::settings();
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( self::name( 'contact_page' ) ),
				'id'                => 'sa-contact_page',
				'selected'          => (int) $s['contact_page'],
				'show_option_none'  => esc_html__( '— None —', 'site-answers' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__( 'Linked when the answer is not on your site.', 'site-answers' ) . '</p>';
	}

	/**
	 * Field: AI base URL.
	 */
	public static function field_base_url() {
		$s = Site_Answers_Plugin::settings();
		printf(
			'<input type="url" id="sa-base_url" class="regular-text code" name="%1$s" value="%2$s" pattern="https://.*"><p class="description">%3$s</p>',
			esc_attr( self::name( 'base_url' ) ),
			esc_attr( $s['base_url'] ),
			esc_html__( 'HTTPS only. The plugin calls {base URL}/chat/completions.', 'site-answers' )
		);
	}

	/**
	 * Field: API key. The saved key is never printed; an empty field keeps it.
	 */
	public static function field_api_key() {
		if ( defined( 'SITE_ANSWERS_API_KEY' ) ) {
			echo '<input type="password" id="sa-api_key" class="regular-text" value="" disabled placeholder="' . esc_attr__( 'Set in wp-config.php', 'site-answers' ) . '">';
			echo '<p class="description">' . esc_html__( 'The SITE_ANSWERS_API_KEY constant in wp-config.php is used.', 'site-answers' ) . '</p>';
			return;
		}
		$saved = '' !== Site_Answers_Plugin::api_key();
		printf(
			'<input type="password" id="sa-api_key" class="regular-text" name="%1$s" value="" autocomplete="new-password" spellcheck="false" placeholder="%2$s">',
			esc_attr( Site_Answers_Plugin::KEY ),
			esc_attr( $saved ? __( 'Saved (hidden). Type a new key to replace it.', 'site-answers' ) : __( 'Paste your API key', 'site-answers' ) )
		);
		if ( $saved ) {
			echo '<p class="sa-key-state"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'A key is saved.', 'site-answers' ) . ' ';
			echo '<label><input type="checkbox" name="site_answers_api_key_remove" value="1"> ' . esc_html__( 'Remove the saved key', 'site-answers' ) . '</label></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Without a key, visitors get the most relevant passages with links instead of an AI answer.', 'site-answers' ) . '</p>';
		}
	}

	/**
	 * Field: model.
	 */
	public static function field_model() {
		$s = Site_Answers_Plugin::settings();
		printf(
			'<input type="text" id="sa-model" class="regular-text code" maxlength="100" name="%1$s" value="%2$s"><p class="description">%3$s</p>',
			esc_attr( self::name( 'model' ) ),
			esc_attr( $s['model'] ),
			/* translators: %s: default model name. */
			esc_html( sprintf( __( 'Model name as your AI service writes it. Default: %s', 'site-answers' ), Site_Answers_Plugin::DEFAULT_MODEL ) )
		);
	}

	/**
	 * Field: questions per IP per hour.
	 */
	public static function field_ip_per_hour() {
		$s = Site_Answers_Plugin::settings();
		printf(
			'<input type="number" id="sa-ip_per_hour" class="small-text" min="1" max="1000" name="%1$s" value="%2$d"> %3$s',
			esc_attr( self::name( 'ip_per_hour' ) ),
			(int) $s['ip_per_hour'],
			esc_html__( 'per hour from one IP address', 'site-answers' )
		);
	}

	/**
	 * Field: daily cap.
	 */
	public static function field_daily_cap() {
		$s = Site_Answers_Plugin::settings();
		printf(
			'<input type="number" id="sa-daily_cap" class="small-text" min="0" max="100000" name="%1$s" value="%2$d"> %3$s',
			esc_attr( self::name( 'daily_cap' ) ),
			(int) $s['daily_cap'],
			esc_html__( 'for the whole site', 'site-answers' )
		);
	}

	/**
	 * Field: post types.
	 */
	public static function field_post_types() {
		$s = Site_Answers_Plugin::settings();
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Content to index', 'site-answers' ) . '</legend>';
		foreach ( self::public_types() as $name => $label ) {
			printf(
				'<label class="sa-type"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( self::name( 'post_types' ) ),
				esc_attr( $name ),
				checked( in_array( $name, (array) $s['post_types'], true ), true, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Only published content. Password-protected, private and noindex pages (Yoast SEO, Rank Math) are skipped, and so are hidden products.', 'site-answers' ) . '</p></fieldset>';
	}

	/**
	 * Field: demo mode.
	 */
	public static function field_demo_mode() {
		$s = Site_Answers_Plugin::settings();
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label><p class="description">%4$s</p>',
			esc_attr( self::name( 'demo_mode' ) ),
			checked( ! empty( $s['demo_mode'] ), true, false ),
			esc_html__( 'Use the hosted demo AI (for the public online demo only)', 'site-answers' ),
			esc_html__( 'The browser sends the best passages to the demo AI, which only accepts the online demo. On your own site, leave this off and add your key above.', 'site-answers' )
		);
	}

	/**
	 * Public post types that can be indexed.
	 *
	 * @return array name => label
	 */
	private static function public_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( 'attachment' !== $name ) {
				$out[ $name ] = $object->labels->name;
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------ Saving.

	/**
	 * Sanitize the settings array.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old      = Site_Answers_Plugin::settings();
		$defaults = Site_Answers_Plugin::defaults();
		$in       = is_array( $input ) ? $input : array();
		$out      = array();

		$out['enabled']   = empty( $in['enabled'] ) ? 0 : 1;
		$out['demo_mode'] = empty( $in['demo_mode'] ) ? 0 : 1;

		$url = isset( $in['base_url'] ) ? trim( (string) $in['base_url'] ) : $old['base_url'];
		$url = untrailingslashit( esc_url_raw( preg_replace( '#/chat/completions/?$#', '', $url ), array( 'https' ) ) );
		if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) {
			add_settings_error( Site_Answers_Plugin::OPTION, 'sa_base_url', __( 'The AI base URL must start with https://. The previous value was kept.', 'site-answers' ) );
			$url = $old['base_url'];
		}
		$out['base_url'] = $url;

		$model        = isset( $in['model'] ) ? preg_replace( '#[^A-Za-z0-9._:/@-]#', '', (string) $in['model'] ) : '';
		$out['model'] = '' !== $model ? substr( $model, 0, 100 ) : $defaults['model'];

		$color        = isset( $in['color'] ) ? sanitize_hex_color( $in['color'] ) : '';
		$out['color'] = $color ? $color : $defaults['color'];

		$out['greeting'] = isset( $in['greeting'] ) ? mb_substr( sanitize_text_field( $in['greeting'] ), 0, 200 ) : '';
		$starters        = isset( $in['starters'] ) && is_array( $in['starters'] ) ? array_values( $in['starters'] ) : array();
		$out['starters'] = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$out['starters'][] = isset( $starters[ $i ] ) ? mb_substr( sanitize_text_field( $starters[ $i ] ), 0, 120 ) : '';
		}

		$page                = isset( $in['contact_page'] ) ? absint( $in['contact_page'] ) : 0;
		$out['contact_page'] = ( $page && 'page' === get_post_type( $page ) ) ? $page : 0;

		$out['ip_per_hour'] = isset( $in['ip_per_hour'] ) ? min( 1000, max( 1, absint( $in['ip_per_hour'] ) ) ) : $defaults['ip_per_hour'];
		$out['daily_cap']   = isset( $in['daily_cap'] ) ? min( 100000, absint( $in['daily_cap'] ) ) : $defaults['daily_cap'];

		$public            = array_keys( self::public_types() );
		$types             = isset( $in['post_types'] ) && is_array( $in['post_types'] ) ? array_map( 'sanitize_key', $in['post_types'] ) : array();
		$kept              = array_diff( (array) $old['post_types'], $public ); // Types of inactive plugins (e.g. WooCommerce off) stay chosen.
		$out['post_types'] = array_values( array_unique( array_merge( array_intersect( $types, $public ), $kept ) ) );

		return $out;
	}

	/**
	 * Sanitize the API key: an empty field keeps the saved key; the "Remove" box clears it.
	 *
	 * @param mixed $value Submitted key.
	 * @return string
	 */
	public static function sanitize_key( $value ) {
		$old = get_option( Site_Answers_Plugin::KEY, '' );
		$old = is_string( $old ) ? $old : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checked the settings nonce before sanitizing.
		$from_form = isset( $_POST['option_page'] ) && self::GROUP === $_POST['option_page'];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same request as above.
		if ( $from_form && ! empty( $_POST['site_answers_api_key_remove'] ) ) {
			return '';
		}
		$value = preg_replace( '/\s+/', '', is_string( $value ) ? $value : '' );
		if ( '' === $value ) {
			return $from_form ? $old : '';
		}
		if ( strlen( $value ) > 400 || ! preg_match( '/^[\x21-\x7E]+$/', $value ) ) {
			add_settings_error( Site_Answers_Plugin::OPTION, 'sa_api_key', __( 'That API key has characters a key cannot have. The saved key was kept.', 'site-answers' ) );
			return $old;
		}
		return $value;
	}

	/**
	 * The indexed post types changed: rebuild.
	 *
	 * @param array $old Old settings.
	 * @param array $new New settings.
	 */
	public static function settings_changed( $old, $new ) {
		$before = isset( $old['post_types'] ) ? (array) $old['post_types'] : array();
		$after  = isset( $new['post_types'] ) ? (array) $new['post_types'] : array();
		sort( $before );
		sort( $after );
		if ( $before !== $after ) {
			Site_Answers_Index::rebuild( 10.0 );
		}
	}

	/**
	 * First save of the settings.
	 *
	 * @param string $option Option name.
	 * @param array  $value  Settings.
	 */
	public static function settings_added( $option, $value ) {
		self::settings_changed( Site_Answers_Plugin::defaults(), $value );
	}

	/**
	 * Rebuild button (admin-post.php).
	 */
	public static function handle_rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'site-answers' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'site_answers_rebuild' );
		$result = Site_Answers_Index::rebuild( 20.0 );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => self::PAGE,
					'site_answers_rebuilt' => 'done' === $result ? 'done' : 'partial',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
