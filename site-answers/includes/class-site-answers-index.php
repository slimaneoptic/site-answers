<?php
/**
 * The search index: published content split into passages, stored in options with autoload off
 * (works the same on MySQL and on SQLite), kept current by hooks, a queue and WP-Cron.
 *
 * Storage
 *   site_answers_index          meta: generation, shard counts, post => shard map, build times
 *   site_answers_shard_{g}_{i}  up to SHARD_PASSAGES passages of whole posts (generation g, shard i)
 *   site_answers_build          state of a rebuild in progress (a new generation, swapped in when complete)
 *   site_answers_queue          post IDs changed since the last update, processed by one cron event
 *
 * @package Site_Answers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Index build, incremental updates and read access.
 */
class Site_Answers_Index {

	const META  = 'site_answers_index';
	const BUILD = 'site_answers_build';
	const QUEUE = 'site_answers_queue';
	const SHARD = 'site_answers_shard_';

	const SHARD_PASSAGES        = 150; // Keeps each option far below common max_allowed_packet sizes.
	const MAX_PASSAGES_PER_POST = 40;  // About 36,000 characters of one page.
	const BATCH                 = 25;
	const BUILD_STALE_SECONDS   = 600;

	/**
	 * Passages loaded for this request.
	 *
	 * @var array|null
	 */
	private static $passages = null;

	/**
	 * Hooks that keep the index current.
	 */
	public static function init() {
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_save' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'on_remove' ) );
		add_action( 'deleted_post', array( __CLASS__, 'on_remove' ) );
		// WooCommerce saves prices and stock after the post itself, and stock changes with orders.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'queue_parent' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_parent' ) );
	}

	// ------------------------------------------------------------------ What gets indexed.

	/**
	 * Post types to index: the ones chosen in the settings that exist and are public.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = array();
		foreach ( (array) Site_Answers_Plugin::settings()['post_types'] as $type ) {
			if ( is_string( $type ) && post_type_exists( $type ) && is_post_type_viewable( $type ) ) {
				$types[] = $type;
			}
		}
		/**
		 * Filters the post types that are indexed.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( array_unique( (array) apply_filters( 'site_answers_post_types', $types ) ) );
	}

	/**
	 * Published, public, not password protected, not set to noindex in Yoast or Rank Math, not a hidden product.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_indexable( $post ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}
		if ( '1' === (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return false;
		}
		$rank_math = get_post_meta( $post->ID, 'rank_math_robots', true );
		if ( ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) || ( is_string( $rank_math ) && false !== strpos( $rank_math, 'noindex' ) ) ) {
			return false;
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post );
			if ( ! $product || 'hidden' === $product->get_catalog_visibility() ) {
				return false;
			}
			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $product->is_in_stock() ) {
				return false;
			}
		}
		/**
		 * Filters whether a post is indexed.
		 *
		 * @param bool    $index Index it.
		 * @param WP_Post $post  Post.
		 */
		return (bool) apply_filters( 'site_answers_index_post', true, $post );
	}

	/**
	 * One post as stored in a shard, or null when it is not indexable or has no text.
	 *
	 * @param int|WP_Post $post Post.
	 * @return array|null { t: title, u: url, y: type, h: title words, c: [ { x: text, f: term counts, l: tokens } ] }
	 */
	public static function document( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! self::is_indexable( $post ) ) {
			return null;
		}
		$title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$text = self::product_text( $post );
		} else {
			$parts = array();
			if ( has_excerpt( $post ) ) {
				$parts[] = Site_Answers_Text::html_to_text( $post->post_excerpt );
			}
			$parts[] = Site_Answers_Text::html_to_text( $post->post_content );
			$text    = trim( implode( "\n", array_filter( $parts ) ) );
		}
		/**
		 * Filters the plain text indexed for a post (add custom fields, remove boilerplate...).
		 *
		 * @param string  $text Plain text, one paragraph per line.
		 * @param WP_Post $post Post.
		 */
		$text = trim( (string) apply_filters( 'site_answers_document_text', $text, $post ) );
		if ( '' === $text ) {
			return null; // Cart, checkout and other pages made only of blocks or shortcodes.
		}
		$url    = get_permalink( $post );
		$chunks = array();
		foreach ( array_slice( Site_Answers_Text::chunk( $text ), 0, self::MAX_PASSAGES_PER_POST ) as $chunk ) {
			$tokens   = Site_Answers_Text::tokens( $title . "\n" . $chunk ); // Every passage carries its page title.
			$chunks[] = array(
				'x' => $chunk,
				'f' => array_count_values( $tokens ),
				'l' => count( $tokens ),
			);
		}
		return array(
			't' => '' !== $title ? $title : $url,
			'u' => $url,
			'y' => $post->post_type,
			'h' => Site_Answers_Text::content_words( Site_Answers_Text::tokens( $title . ' ' . Site_Answers_Text::path_words( $url ) ) ),
			'c' => $chunks,
		);
	}

	/**
	 * A WooCommerce product as text: price, SKU, stock, categories, attributes, size, then descriptions.
	 *
	 * @param WP_Post $post Product post.
	 * @return string
	 */
	private static function product_text( $post ) {
		$product = wc_get_product( $post );
		if ( ! $product ) {
			return '';
		}
		$lines = array();
		$price = self::product_price( $product );
		if ( '' !== $price ) {
			/* translators: %s: product price, for example "$49.00". */
			$lines[] = sprintf( __( 'Price: %s', 'site-answers' ), $price );
		}
		if ( $product->get_sku() ) {
			/* translators: %s: product SKU. */
			$lines[] = sprintf( __( 'SKU: %s', 'site-answers' ), $product->get_sku() );
		}
		$stock = array(
			'instock'     => __( 'In stock', 'site-answers' ),
			'outofstock'  => __( 'Out of stock', 'site-answers' ),
			'onbackorder' => __( 'Available on backorder', 'site-answers' ),
		);
		if ( isset( $stock[ $product->get_stock_status() ] ) ) {
			/* translators: %s: stock status such as "In stock". */
			$lines[] = sprintf( __( 'Availability: %s', 'site-answers' ), $stock[ $product->get_stock_status() ] );
		}
		$cats = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $cats ) && $cats ) {
			/* translators: %s: comma-separated product categories. */
			$lines[] = sprintf( __( 'Categories: %s', 'site-answers' ), implode( ', ', $cats ) );
		}
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ( ! $attribute->get_visible() && ! $attribute->get_variation() ) ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			if ( $values ) {
				$lines[] = wc_attribute_label( $attribute->get_name(), $product ) . ': ' . implode( ', ', array_map( 'strval', $values ) );
			}
		}
		if ( $product->has_dimensions() ) {
			/* translators: %s: product dimensions. */
			$lines[] = sprintf( __( 'Dimensions: %s', 'site-answers' ), self::plain( wc_format_dimensions( $product->get_dimensions( false ) ) ) );
		}
		if ( $product->has_weight() ) {
			/* translators: 1: weight number, 2: weight unit. */
			$lines[] = sprintf( __( 'Weight: %1$s %2$s', 'site-answers' ), wc_format_localized_decimal( $product->get_weight() ), get_option( 'woocommerce_weight_unit' ) );
		}
		$lines[] = Site_Answers_Text::html_to_text( $product->get_short_description() );
		$lines[] = Site_Answers_Text::html_to_text( $product->get_description() );
		return trim( implode( "\n", array_filter( $lines ) ) );
	}

	/**
	 * Price as shown to shoppers: a range for variable products, sale and regular price when on sale.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function product_price( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$min = $product->get_variation_price( 'min', true );
			$max = $product->get_variation_price( 'max', true );
			if ( '' === (string) $min ) {
				return '';
			}
			/* translators: 1: lowest price, 2: highest price. */
			return $min === $max ? self::money( $min ) : sprintf( __( '%1$s to %2$s', 'site-answers' ), self::money( $min ), self::money( $max ) );
		}
		$price = $product->get_price();
		if ( '' === (string) $price ) {
			return '';
		}
		if ( $product->is_on_sale() && '' !== (string) $product->get_regular_price() ) {
			/* translators: 1: sale price, 2: regular price. */
			return sprintf( __( '%1$s (on sale, regular price %2$s)', 'site-answers' ), self::money( $price ), self::money( $product->get_regular_price() ) );
		}
		return self::money( $price );
	}

	/**
	 * Amount formatted with the shop's currency, as plain text.
	 *
	 * @param string|float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		return self::plain( wc_price( $amount ) );
	}

	/**
	 * HTML fragment to plain text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function plain( $html ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	// ------------------------------------------------------------------ Storage helpers.

	/**
	 * Index meta (empty index when none was built yet).
	 *
	 * @return array
	 */
	public static function meta() {
		$meta = get_option( self::META );
		if ( ! is_array( $meta ) || empty( $meta['gen'] ) ) {
			return array(
				'gen'     => 0,
				'shards'  => array(),
				'map'     => array(),
				'built'   => 0,
				'updated' => 0,
			);
		}
		return $meta;
	}

	/**
	 * Save the meta.
	 *
	 * @param array $meta Meta.
	 */
	private static function save_meta( $meta ) {
		update_option( self::META, $meta, false );
		self::$passages = null;
	}

	/**
	 * Option name of a shard.
	 *
	 * @param int $gen Generation.
	 * @param int $i   Shard number.
	 * @return string
	 */
	private static function shard_name( $gen, $i ) {
		return self::SHARD . (int) $gen . '_' . (int) $i;
	}

	/**
	 * Read a shard.
	 *
	 * @param int $gen Generation.
	 * @param int $i   Shard number.
	 * @return array post ID => document
	 */
	private static function read_shard( $gen, $i ) {
		$shard = get_option( self::shard_name( $gen, $i ), array() );
		return is_array( $shard ) ? $shard : array();
	}

	/**
	 * Write a shard (autoload off).
	 *
	 * @param int   $gen  Generation.
	 * @param int   $i    Shard number.
	 * @param array $docs post ID => document.
	 */
	private static function write_shard( $gen, $i, $docs ) {
		update_option( self::shard_name( $gen, $i ), $docs, false );
		self::$passages = null;
	}

	/**
	 * Delete the shards of a generation.
	 *
	 * @param int $gen   Generation.
	 * @param int $count Number of shards.
	 */
	private static function drop_shards( $gen, $count ) {
		if ( (int) $gen <= 0 ) {
			return;
		}
		for ( $i = 0; $i <= (int) $count; $i++ ) {
			delete_option( self::shard_name( $gen, $i ) );
		}
	}

	/**
	 * Counters of an empty shard.
	 *
	 * @return array
	 */
	private static function empty_info() {
		return array(
			'posts'    => 0,
			'passages' => 0,
			'tokens'   => 0,
			'types'    => array(),
		);
	}

	/**
	 * Add or remove a document from a shard's counters.
	 *
	 * @param array $info Shard counters (changed in place).
	 * @param array $doc  Document.
	 * @param int   $sign 1 to add, -1 to remove.
	 */
	private static function count_doc( &$info, $doc, $sign ) {
		$info['posts']    += $sign;
		$info['passages'] += $sign * count( $doc['c'] );
		foreach ( $doc['c'] as $chunk ) {
			$info['tokens'] += $sign * (int) $chunk['l'];
		}
		$type                   = $doc['y'];
		$info['types'][ $type ] = ( isset( $info['types'][ $type ] ) ? $info['types'][ $type ] : 0 ) + $sign;
		if ( $info['types'][ $type ] <= 0 ) {
			unset( $info['types'][ $type ] );
		}
	}

	// ------------------------------------------------------------------ Full rebuild (batched, swapped in when complete).

	/**
	 * Rebuild the whole index into a new generation. Stops after $budget seconds and continues in WP-Cron;
	 * the current index keeps answering until the new one is complete.
	 *
	 * @param float $budget Seconds to spend in this request.
	 * @return string "done" | "partial"
	 */
	public static function rebuild( $budget = 20.0 ) {
		$old = get_option( self::BUILD );
		if ( is_array( $old ) && ! empty( $old['gen'] ) ) {
			self::drop_shards( $old['gen'], count( $old['shards'] ) ); // An unfinished earlier rebuild.
		}
		$meta = self::meta();
		$gen  = max( (int) $meta['gen'], is_array( $old ) && isset( $old['gen'] ) ? (int) $old['gen'] : 0 ) + 1;

		// A full rebuild reads every post, so what was queued before it starts is included.
		update_option( self::QUEUE, array(), false );
		wp_clear_scheduled_hook( Site_Answers_Plugin::CRON_QUEUE );
		wp_clear_scheduled_hook( Site_Answers_Plugin::CRON_CONTINUE );

		$state = array(
			'gen'     => $gen,
			'types'   => self::post_types(),
			'after'   => 0,
			'cur'     => 0,
			'shards'  => array( 0 => self::empty_info() ),
			'map'     => array(),
			'done'    => 0,
			'replay'  => array(),
			'started' => time(),
			'touched' => time(),
		);
		update_option( self::BUILD, $state, false );
		return self::continue_build( $budget );
	}

	/**
	 * Index the next posts of the rebuild in progress.
	 *
	 * @param float $budget Seconds to spend in this request.
	 * @return string "done" | "partial" | "idle"
	 */
	public static function continue_build( $budget = 20.0 ) {
		global $wpdb;
		$state = get_option( self::BUILD );
		if ( ! is_array( $state ) || empty( $state['gen'] ) ) {
			return 'idle';
		}
		$start = microtime( true );
		$cur   = self::read_shard( $state['gen'], $state['cur'] );
		$types = $state['types'];

		while ( $types ) {
			$in  = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ID cursor over posts, cached per post by get_post().
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One array holds all the values.
					"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_password = '' AND post_type IN ($in) ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds only %s placeholders.
					array_merge( array( (int) $state['after'] ), $types, array( self::BATCH ) )
				)
			);
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				$state['after'] = (int) $id;
				$doc            = self::document( (int) $id );
				if ( $doc ) {
					self::build_add( $state, $cur, (int) $id, $doc );
				}
				if ( microtime( true ) - $start > $budget ) {
					self::write_shard( $state['gen'], $state['cur'], $cur );
					$state['touched'] = time();
					self::save_build( $state );
					if ( ! wp_next_scheduled( Site_Answers_Plugin::CRON_CONTINUE ) ) {
						wp_schedule_single_event( time() + 5, Site_Answers_Plugin::CRON_CONTINUE );
					}
					return 'partial';
				}
			}
			self::write_shard( $state['gen'], $state['cur'], $cur );
			$state['touched'] = time();
			self::save_build( $state );
		}

		self::write_shard( $state['gen'], $state['cur'], $cur );
		self::finish_build( $state );
		return 'done';
	}

	/**
	 * Save the build state, keeping post IDs that process_queue() added meanwhile.
	 *
	 * @param array $state Build state.
	 */
	private static function save_build( $state ) {
		$stored = get_option( self::BUILD );
		if ( is_array( $stored ) && ! empty( $stored['replay'] ) ) {
			$state['replay'] = array_values( array_unique( array_merge( (array) $state['replay'], $stored['replay'] ) ) );
		}
		update_option( self::BUILD, $state, false );
	}

	/**
	 * Add a document to the shard being filled; start a new shard when it is full.
	 *
	 * @param array $state Build state (changed in place).
	 * @param array $cur   Documents of the current shard (changed in place).
	 * @param int   $id    Post ID.
	 * @param array $doc   Document.
	 */
	private static function build_add( &$state, &$cur, $id, $doc ) {
		$i = (int) $state['cur'];
		if ( $state['shards'][ $i ]['passages'] > 0 && $state['shards'][ $i ]['passages'] + count( $doc['c'] ) > self::SHARD_PASSAGES ) {
			self::write_shard( $state['gen'], $i, $cur );
			++$i;
			$state['cur']          = $i;
			$state['shards'][ $i ] = self::empty_info();
			$cur                   = array();
		}
		$cur[ $id ]          = $doc;
		$state['map'][ $id ] = $i;
		self::count_doc( $state['shards'][ $i ], $doc, 1 );
		++$state['done'];
	}

	/**
	 * Swap the new generation in, drop the old one, and replay edits made during the rebuild.
	 *
	 * @param array $state Build state.
	 */
	private static function finish_build( $state ) {
		$old    = self::meta();
		$stored = get_option( self::BUILD );
		$replay = is_array( $stored ) && ! empty( $stored['replay'] ) ? $stored['replay'] : array();

		self::save_meta(
			array(
				'gen'     => (int) $state['gen'],
				'shards'  => $state['shards'],
				'map'     => $state['map'],
				'types'   => $state['types'],
				'built'   => time(),
				'updated' => time(),
			)
		);
		delete_option( self::BUILD );
		if ( (int) $old['gen'] !== (int) $state['gen'] ) {
			self::drop_shards( $old['gen'], count( $old['shards'] ) );
		}
		foreach ( $replay as $id ) {
			self::queue( (int) $id );
		}
	}

	/**
	 * Is a rebuild running (touched in the last ten minutes)?
	 *
	 * @return bool
	 */
	public static function building() {
		$state = get_option( self::BUILD );
		return is_array( $state ) && ! empty( $state['gen'] ) && time() - (int) $state['touched'] < self::BUILD_STALE_SECONDS;
	}

	// ------------------------------------------------------------------ Incremental updates.

	/**
	 * A post was saved: queue it if it is (or was) indexed.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_save( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$indexed = isset( self::meta()['map'][ (int) $post_id ] );
		if ( $indexed || ( 'publish' === $post->post_status && in_array( $post->post_type, self::post_types(), true ) ) ) {
			self::queue( $post_id );
		}
	}

	/**
	 * A post was trashed or deleted: queue it if it is indexed.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_remove( $post_id ) {
		if ( isset( self::meta()['map'][ (int) $post_id ] ) ) {
			self::queue( $post_id );
		}
	}

	/**
	 * A variation changed: queue its product.
	 *
	 * @param int $variation_id Variation ID.
	 */
	public static function queue_parent( $variation_id ) {
		$parent = wp_get_post_parent_id( (int) $variation_id );
		if ( $parent ) {
			self::queue( $parent );
		}
	}

	/**
	 * Add a post to the update queue and schedule the single cron event that processes it.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function queue( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		if ( ! in_array( $post_id, $queue, true ) ) {
			$queue[] = $post_id;
			update_option( self::QUEUE, $queue, false );
		}
		if ( ! wp_next_scheduled( Site_Answers_Plugin::CRON_QUEUE ) ) {
			/**
			 * Filters the delay in seconds before queued edits are indexed (one cron event handles a burst of saves).
			 *
			 * @param int $delay Seconds.
			 */
			$delay = (int) apply_filters( 'site_answers_queue_delay', 15 );
			wp_schedule_single_event( time() + max( 0, $delay ), Site_Answers_Plugin::CRON_QUEUE );
		}
	}

	/**
	 * Cron: re-index the queued posts (200 per run).
	 */
	public static function process_queue() {
		$queue = get_option( self::QUEUE, array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			return;
		}
		if ( ! self::meta()['gen'] && ! self::building() ) {
			self::rebuild( 20.0 ); // No index yet: build it (includes the queued posts).
			return;
		}
		$now  = array_slice( $queue, 0, 200 );
		$rest = array_slice( $queue, 200 );
		update_option( self::QUEUE, $rest, false );
		foreach ( $now as $id ) {
			self::update_post( (int) $id );
		}
		$state = get_option( self::BUILD );
		if ( is_array( $state ) && ! empty( $state['gen'] ) ) {
			// A rebuild is running: it may have read these posts before the edit, so replay them when it ends.
			$state['replay'] = array_values( array_unique( array_merge( (array) $state['replay'], $now ) ) );
			update_option( self::BUILD, $state, false );
		}
		if ( $rest && ! wp_next_scheduled( Site_Answers_Plugin::CRON_QUEUE ) ) {
			wp_schedule_single_event( time(), Site_Answers_Plugin::CRON_QUEUE );
		}
	}

	/**
	 * Re-index one post now: remove its passages, add the new ones if it is still indexable.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function update_post( $post_id ) {
		$meta = self::meta();
		if ( ! $meta['gen'] ) {
			return;
		}
		$post_id = (int) $post_id;
		$doc     = self::document( $post_id );
		$changed = false;

		if ( isset( $meta['map'][ $post_id ] ) ) {
			$i     = (int) $meta['map'][ $post_id ];
			$shard = self::read_shard( $meta['gen'], $i );
			if ( ! isset( $meta['shards'][ $i ] ) ) {
				$meta['shards'][ $i ] = self::empty_info();
			}
			if ( isset( $shard[ $post_id ] ) ) {
				self::count_doc( $meta['shards'][ $i ], $shard[ $post_id ], -1 );
				unset( $shard[ $post_id ] );
			}
			unset( $meta['map'][ $post_id ] );
			if ( $doc && $meta['shards'][ $i ]['passages'] + count( $doc['c'] ) <= self::SHARD_PASSAGES ) {
				$shard[ $post_id ]       = $doc; // Same shard: one write.
				$meta['map'][ $post_id ] = $i;
				self::count_doc( $meta['shards'][ $i ], $doc, 1 );
				$doc = null;
			}
			self::write_shard( $meta['gen'], $i, $shard );
			$changed = true;
		}

		if ( $doc ) {
			$last = $meta['shards'] ? max( array_keys( $meta['shards'] ) ) : 0;
			if ( ! isset( $meta['shards'][ $last ] ) ) {
				$meta['shards'][ $last ] = self::empty_info();
			}
			if ( $meta['shards'][ $last ]['passages'] > 0 && $meta['shards'][ $last ]['passages'] + count( $doc['c'] ) > self::SHARD_PASSAGES ) {
				++$last;
				$meta['shards'][ $last ] = self::empty_info();
			}
			$shard             = self::read_shard( $meta['gen'], $last );
			$shard[ $post_id ] = $doc;
			self::write_shard( $meta['gen'], $last, $shard );
			$meta['map'][ $post_id ] = $last;
			self::count_doc( $meta['shards'][ $last ], $doc, 1 );
			$changed = true;
		}

		if ( $changed ) {
			$meta['updated'] = time();
			self::save_meta( $meta );
		}
	}

	// ------------------------------------------------------------------ Read access.

	/**
	 * All passages, loaded once per request.
	 *
	 * @return array { chunks: [ { post, text, tf, len } ], docs: post ID => { title, url, type, head } }
	 */
	public static function passages() {
		if ( null !== self::$passages ) {
			return self::$passages;
		}
		$meta   = self::meta();
		$chunks = array();
		$docs   = array();
		if ( $meta['gen'] ) {
			foreach ( array_keys( $meta['shards'] ) as $i ) {
				foreach ( self::read_shard( $meta['gen'], $i ) as $post_id => $doc ) {
					$docs[ $post_id ] = array(
						'title' => $doc['t'],
						'url'   => $doc['u'],
						'type'  => $doc['y'],
						'head'  => array_flip( array_map( 'strval', $doc['h'] ) ),
					);
					foreach ( $doc['c'] as $chunk ) {
						$chunks[] = array(
							'post' => (int) $post_id,
							'text' => $chunk['x'],
							'tf'   => $chunk['f'],
							'len'  => (int) $chunk['l'],
						);
					}
				}
			}
		}
		self::$passages = array(
			'chunks' => $chunks,
			'docs'   => $docs,
		);
		return self::$passages;
	}

	/**
	 * Page titles and the words most passages use, comma-separated: a hint for translating a question into the
	 * site's own words ("refund" rather than "reimbursement"), so the translated search finds them.
	 *
	 * @return string At most 900 characters.
	 */
	public static function site_words() {
		$index  = self::passages();
		$titles = array();
		foreach ( $index['docs'] as $doc ) {
			$titles[ $doc['title'] ] = true;
			if ( count( $titles ) >= 8 ) {
				break;
			}
		}
		$df = array();
		foreach ( array_slice( $index['chunks'], 0, 400 ) as $chunk ) {
			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $chunk['text'], 'UTF-8' ) : strtolower( $chunk['text'] );
			preg_match_all( '/\p{L}{4,}/u', $lower, $m );
			foreach ( array_unique( $m[0] ) as $word ) {
				if ( ! Site_Answers_Text::is_stop( Site_Answers_Text::stem( Site_Answers_Text::normalize( $word ) ) ) ) {
					$df[ $word ] = isset( $df[ $word ] ) ? $df[ $word ] + 1 : 1;
				}
			}
		}
		arsort( $df );
		$words = array_merge( array_keys( $titles ), array_map( 'strval', array_slice( array_keys( $df ), 0, 40 ) ) );
		return mb_substr( implode( ', ', $words ), 0, 900 );
	}

	/**
	 * Numbers for the settings page.
	 *
	 * @return array
	 */
	public static function status() {
		$meta     = self::meta();
		$posts    = 0;
		$passages = 0;
		$types    = array();
		foreach ( $meta['shards'] as $info ) {
			$posts    += (int) $info['posts'];
			$passages += (int) $info['passages'];
			foreach ( (array) $info['types'] as $type => $n ) {
				$types[ $type ] = ( isset( $types[ $type ] ) ? $types[ $type ] : 0 ) + (int) $n;
			}
		}
		$state = get_option( self::BUILD );
		$queue = get_option( self::QUEUE, array() );
		return array(
			'posts'    => $posts,
			'passages' => $passages,
			'types'    => $types,
			'built'    => (int) $meta['built'],
			'updated'  => (int) $meta['updated'],
			'building' => is_array( $state ) && ! empty( $state['gen'] ) ? (int) $state['done'] : null,
			'queued'   => is_array( $queue ) ? count( $queue ) : 0,
		);
	}
}
