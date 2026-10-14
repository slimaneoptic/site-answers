<?php
/**
 * Text helpers: HTML to plain text, chunks, normalisation and search tokens for English, French and Arabic.
 *
 * @package Site_Answers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure text functions (no database, no network).
 */
class Site_Answers_Text {

	const CHUNK_CHARS   = 900;
	const OVERLAP_CHARS = 150;

	/**
	 * Words that carry no meaning for search, in English, French and Arabic. Written plainly: they go through
	 * the same normalisation and stemming as page text on first use.
	 */
	const STOPWORDS = '
		a an the and or but if of to in on at by for with from as is are was were be been being am do does did doing done
		have has had having i me my mine we our ours us you your yours he him his she her it its they them their this that
		these those what which who whom whose when where why how can could would should will shall may might must not no
		yes there here than then so too very just also about into over under up out any some all each every more most
		much many such only own same other s t don get got want need please tell know let like one thing things anything
		something explain describe detail details everything long often soon take takes took taking
		policy policies info information rule rules process procedure option options question questions
		le la les un une des du de d l et ou est sont a ai as avez avons ce cet cette ces je tu il elle nous vous ils
		elles mon ma mes ton ta tes son sa ses notre nos votre vos leur leurs que qui quoi quel quelle quels quelles quand
		comment combien pourquoi où dans sur pour par avec sans en au aux y ne pas plus se s qu c peut puis faire fait
		temps dois doit politique politiques
		ما ماذا هل كيف كم متى أين من في على إلى عن مع هذا هذه ذلك تلك هناك هو هي هم أنا نحن أنت أنتم التي الذي الذين أو
		ثم لا لم لن أن إن قد كل أي بعد قبل عند حتى يمكن يمكنني ممكن أريد لماذا لكم لي لك عندكم لدي لديكم سياسة
	';

	/**
	 * Question words that should also find the site's own words ("How much does it cost?" finds the price).
	 * Each line: trigger words => extra words. Applied to questions only, never to page text.
	 */
	const SYNONYMS = array(
		'cost costs price prices pricing fee fees tarif tarifs prix coût coûts سعر ثمن أسعار بكم' => 'price pricing tarif prix سعر ثمن',
		'hours opening open horaires ouverture ساعات أوقات توقيت'                               => 'open opening hour am pm horaires ouvert ساعات',
		'contact reach call phone email téléphone joindre contacter تواصل اتصل هاتف'           => 'contact phone email téléphone call',
		'shipping ship delivery deliver livraison livrer expédition توصيل شحن الشحن'           => 'shipping delivery livraison توصيل شحن',
		'return returns refund refunds retour retourner remboursement إرجاع إسترجاع استرداد'  => 'return refund retour remboursement إرجاع',
		'warranty guarantee garantie ضمان'                                                     => 'warranty guarantee garantie ضمان',
		'location address adresse عنوان موقع'                                                  => 'location address adresse عنوان',
	);

	/**
	 * Function words that mark a Latin-script language (a short question is enough to tell French from English).
	 */
	const LANG_MARKS = array(
		'en' => 'the and is are you your with for of to we our this that what how do does can which when where who why i my it there have has will would should',
		'fr' => 'le la les des du et est vous votre vos nous avec pour dans une sur quel quels quelle quelles comment combien pourquoi quand qui je peux puis sont ai avez acceptez',
		'es' => 'el los las del y es usted ustedes con para una por cómo cuánto cuándo dónde puedo hay tienen aceptan son',
		'de' => 'der die das und ist sie ich wir mit für ein eine wie was wann wo warum kann können gibt nicht auf ihr ihre',
	);

	/**
	 * Arabic letter forms unified for search, and Arabic-Indic digits.
	 */
	const AR_MAP = array(
		'أ' => 'ا',
		'إ' => 'ا',
		'آ' => 'ا',
		'ٱ' => 'ا',
		'ى' => 'ي',
		'ی' => 'ي',
		'ة' => 'ه',
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
		'۰' => '0',
		'۱' => '1',
		'۲' => '2',
		'۳' => '3',
		'۴' => '4',
		'۵' => '5',
		'۶' => '6',
		'۷' => '7',
		'۸' => '8',
		'۹' => '9',
	);

	/**
	 * Arabic article prefixes, longest first ("لل" is ل + ال).
	 */
	const AR_PREFIXES = array( 'وال', 'فال', 'بال', 'كال', 'لل', 'ال' );

	/**
	 * Arabic unit words whose plural is not a suffix ("كم يوما" must find "30 يوم"). Keys are normalised.
	 */
	const AR_FORMS = array(
		'ايام'   => 'يوم',
		'يوما'   => 'يوم',
		'اياما'  => 'يوم',
		'ساعات'  => 'ساعه',
		'اسابيع' => 'اسبوع',
		'اسبوعا' => 'اسبوع',
		'شهور'   => 'شهر',
		'اشهر'   => 'شهر',
		'شهرا'   => 'شهر',
		'سنوات'  => 'سنه',
		'منتجات' => 'منتج',
		'طلبات'  => 'طلب',
	);

	/**
	 * Lazy caches.
	 *
	 * @var array|null
	 */
	private static $stop = null;

	/**
	 * Lazy synonym map: token => list of tokens.
	 *
	 * @var array|null
	 */
	private static $syn = null;

	/**
	 * Lazy language marks: lang => set of words.
	 *
	 * @var array|null
	 */
	private static $marks = null;

	// ------------------------------------------------------------------ HTML to text and chunks.

	/**
	 * Plain text from post content: blocks, shortcodes and HTML removed, one paragraph per line.
	 *
	 * @param string $html Post content.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$html = self::shortcodes_to_text( (string) $html );
		$html = preg_replace( '/<!--.*?-->/s', '', $html ); // Block delimiters and other comments.
		$html = preg_replace( '#<(script|style|noscript|template|svg|iframe|form|select)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<(br|hr)\b[^>]*>#i', "\n", $html );
		$html = preg_replace( '#</?(p|div|h[1-6]|li|ul|ol|dl|dt|dd|table|thead|tbody|tr|blockquote|section|article|header|footer|aside|figure|figcaption|pre|details|summary)\b[^>]*>#i', "\n", $html );
		$html = preg_replace( '#</t[dh]>#i', ' ', $html );
		$text = wp_strip_all_tags( $html, false );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_check_invalid_utf8( $text, true );

		$lines = array();
		foreach ( preg_split( '/\R/u', $text ) as $line ) {
			$line = trim( preg_replace( '/[\s\x{00A0}\x{200B}]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Shortcodes out, but the text inside enclosing shortcodes kept ("[note]Free returns[/note]" -> "Free returns").
	 * Core strip_shortcodes() would drop that text, which page builders often wrap in shortcodes.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private static function shortcodes_to_text( $content ) {
		if ( false === strpos( $content, '[' ) ) {
			return $content;
		}
		global $shortcode_tags;
		if ( ! empty( $shortcode_tags ) && is_array( $shortcode_tags ) ) {
			$pattern = get_shortcode_regex();
			for ( $i = 0; $i < 3 && preg_match( "/$pattern/", $content ); $i++ ) {
				$content = preg_replace_callback(
					"/$pattern/",
					static function ( $m ) {
						if ( '[' === $m[1] && ']' === $m[6] ) {
							return substr( $m[0], 1, -1 ); // [[escaped]] stays as text.
						}
						return $m[1] . ' ' . $m[5] . ' ' . $m[6];
					},
					$content
				);
			}
		}
		$content = strip_shortcodes( $content );
		// Tags of shortcodes whose plugin is not active: [name attr="x"] and [/name].
		return preg_replace( '/\[\/?[a-z][a-z0-9_-]*(?:\s[^\]]{0,300})?\]/i', ' ', $content );
	}

	/**
	 * Split text into passages of about CHUNK_CHARS characters, on paragraph and sentence ends, with a short
	 * overlap so a fact cut in two still has its context.
	 *
	 * @param string $text Plain text, one paragraph per line.
	 * @param int    $max  Target size in characters.
	 * @return string[]
	 */
	public static function chunk( $text, $max = self::CHUNK_CHARS ) {
		$pieces = array(); // Each piece: array( text, separator before it ).
		foreach ( preg_split( '/\n+/u', trim( (string) $text ) ) as $para ) {
			$para = trim( $para );
			if ( '' === $para ) {
				continue;
			}
			if ( mb_strlen( $para ) <= $max ) {
				$pieces[] = array( $para, "\n" );
				continue;
			}
			$sep = "\n";
			foreach ( preg_split( '/(?<=[.!?؟。])\s+/u', $para ) as $sentence ) {
				while ( mb_strlen( $sentence ) > $max ) {
					$part     = self::cut_words( $sentence, $max );
					$pieces[] = array( $part, $sep );
					$sep      = ' ';
					$sentence = ltrim( mb_substr( $sentence, mb_strlen( $part ) ) );
				}
				if ( '' !== $sentence ) {
					$pieces[] = array( $sentence, $sep );
					$sep      = ' ';
				}
			}
		}

		$out = array();
		$buf = '';
		foreach ( $pieces as $piece ) {
			list( $part, $sep ) = $piece;
			if ( '' !== $buf && mb_strlen( $buf ) + 1 + mb_strlen( $part ) > $max ) {
				$out[] = $buf;
				$tail  = self::tail_words( $buf, self::OVERLAP_CHARS );
				$buf   = ( '' !== $tail ? '… ' . $tail . "\n" : '' ) . $part;
			} else {
				$buf = '' === $buf ? $part : $buf . $sep . $part;
			}
		}
		if ( '' !== $buf ) {
			$out[] = $buf;
		}
		return $out;
	}

	/**
	 * First $max characters of $text, cut on a space when one is near.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	public static function cut_words( $text, $max ) {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = strrpos( $cut, ' ' );
		if ( false !== $space && $space > strlen( $cut ) / 2 ) {
			$cut = substr( $cut, 0, $space );
		}
		return rtrim( $cut );
	}

	/**
	 * Last whole words of $text within $chars characters.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Characters.
	 * @return string
	 */
	private static function tail_words( $text, $chars ) {
		$tail  = mb_substr( $text, -$chars );
		$space = strpos( $tail, ' ' );
		return false === $space ? '' : trim( str_replace( "\n", ' ', substr( $tail, $space + 1 ) ) );
	}

	// ------------------------------------------------------------------ Normalisation and tokens.

	/**
	 * Lower case, accents folded (é -> e), "8am" -> "8 am"; Arabic: no tashkeel or tatweel, أ/إ/آ -> ا,
	 * ى -> ي, ة -> ه, Arabic-Indic digits -> 0-9.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = wp_check_invalid_utf8( (string) $text, true );
		if ( '' === $text ) {
			return '';
		}
		$text = remove_accents( $text );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/(\d)(am|pm|h)\b/u', '$1 $2', $text );
		$text = preg_replace( '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E8}\x{06EA}-\x{06ED}\x{0640}]/u', '', $text );
		return strtr( $text, self::AR_MAP );
	}

	/**
	 * Search tokens: normalised words, lightly stemmed.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function tokens( $text ) {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', self::normalize( $text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return array();
		}
		$out = array();
		foreach ( $words as $word ) {
			$out[] = self::stem( $word );
		}
		return $out;
	}

	/**
	 * Light, symmetric stemming (same rules for pages and questions): Arabic article prefixes on words of 4+
	 * letters and a few unit plurals; Latin plural -s / -ies and -ing on long words.
	 *
	 * @param string $t Normalised word.
	 * @return string
	 */
	public static function stem( $t ) {
		$n = mb_strlen( $t );
		if ( $n < 4 ) {
			return $t;
		}
		if ( preg_match( '/[^\x00-\x7F]/', $t ) ) {
			foreach ( self::AR_PREFIXES as $pre ) {
				if ( 0 === strpos( $t, $pre ) && $n - mb_strlen( $pre ) >= 2 ) {
					$t = substr( $t, strlen( $pre ) );
					break;
				}
			}
			return isset( self::AR_FORMS[ $t ] ) ? self::AR_FORMS[ $t ] : $t;
		}
		if ( 'ies' === substr( $t, -3 ) && $n > 4 ) {
			return substr( $t, 0, -3 ) . 'y';
		}
		if ( 's' === substr( $t, -1 ) && ! in_array( substr( $t, -2 ), array( 'ss', 'us', 'is' ), true ) ) {
			$t = substr( $t, 0, -1 );
			--$n;
		}
		if ( 'ing' === substr( $t, -3 ) && $n >= 7 ) {
			$t = substr( $t, 0, -3 );
			$l = strlen( $t );
			if ( $t[ $l - 1 ] === $t[ $l - 2 ] && false === strpos( 'lsz', $t[ $l - 1 ] ) ) {
				$t = substr( $t, 0, -1 ); // shipping -> ship.
			}
		}
		return $t;
	}

	/**
	 * Stopword, or و/ف glued to an Arabic stopword ("وكم").
	 *
	 * @param string $t Token.
	 * @return bool
	 */
	public static function is_stop( $t ) {
		if ( null === self::$stop ) {
			self::$stop = array_flip( self::tokens( self::STOPWORDS ) );
		}
		if ( isset( self::$stop[ $t ] ) ) {
			return true;
		}
		if ( mb_strlen( $t ) > 1 && preg_match( '/^[وف]/u', $t ) ) {
			return isset( self::$stop[ mb_substr( $t, 1 ) ] );
		}
		return false;
	}

	/**
	 * Tokens without stopwords, unique, in order.
	 *
	 * @param string[] $tokens Tokens.
	 * @return string[]
	 */
	public static function content_words( $tokens ) {
		$out = array();
		foreach ( $tokens as $t ) {
			if ( ! isset( $out[ $t ] ) && ! self::is_stop( $t ) ) {
				$out[ $t ] = true;
			}
		}
		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * Extra words to look for when a question uses one of the trigger words.
	 *
	 * @param string $t Token.
	 * @return string[]
	 */
	public static function synonyms( $t ) {
		if ( null === self::$syn ) {
			self::$syn = array();
			foreach ( self::SYNONYMS as $triggers => $extra ) {
				$triggers = self::tokens( $triggers );
				$group    = array_unique( array_merge( self::tokens( $extra ), $triggers ) );
				foreach ( $triggers as $trigger ) {
					$current                  = isset( self::$syn[ $trigger ] ) ? self::$syn[ $trigger ] : array();
					self::$syn[ $trigger ] = array_values( array_unique( array_merge( $current, array_diff( $group, array( $trigger ) ) ) ) );
				}
			}
		}
		return isset( self::$syn[ $t ] ) ? self::$syn[ $t ] : array();
	}

	/**
	 * Words of a URL path ("/returns-and-refunds/" -> "returns and refunds").
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function path_words( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$path = rawurldecode( $path );
		$out  = array();
		foreach ( preg_split( '#[/\-_.]+#', $path ) as $w ) {
			if ( '' !== $w && ! ctype_digit( $w ) && ! in_array( $w, array( 'html', 'htm', 'php' ), true ) ) {
				$out[] = $w;
			}
		}
		return implode( ' ', $out );
	}

	// ------------------------------------------------------------------ Languages.

	/**
	 * "ar" when Arabic letters dominate, else "latin".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function script( $text ) {
		$all    = preg_match_all( '/\p{L}/u', (string) $text );
		$arabic = preg_match_all( '/\p{Arabic}/u', (string) $text );
		return ( $arabic && $arabic * 2 > $all ) ? 'ar' : 'latin';
	}

	/**
	 * Language of a Latin-script text from its function words: "en", "fr", "es", "de", or "" when unsure.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function latin_lang( $text ) {
		if ( null === self::$marks ) {
			self::$marks = array();
			foreach ( self::LANG_MARKS as $lang => $words ) {
				self::$marks[ $lang ] = array_flip( self::plain_words( $words ) );
			}
		}
		$words = array_slice( self::plain_words( $text ), 0, 80 );
		$score = array();
		foreach ( self::$marks as $lang => $marks ) {
			$score[ $lang ] = 0;
			foreach ( $words as $w ) {
				if ( isset( $marks[ $w ] ) ) {
					++$score[ $lang ];
				}
			}
		}
		arsort( $score );
		$values = array_values( $score );
		if ( 0 === $values[0] || $values[0] === $values[1] ) {
			return '';
		}
		return (string) key( $score );
	}

	/**
	 * Lower-case words with accents folded (for language marks only).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function plain_words( $text ) {
		$text = strtolower( remove_accents( wp_check_invalid_utf8( (string) $text, true ) ) );
		preg_match_all( '/[a-z]+/', $text, $m );
		return $m[0];
	}

	/**
	 * Language of a question: "ar", a Latin language code, or "" when a short Latin question has no marks.
	 *
	 * @param string $text Question.
	 * @return string
	 */
	public static function question_lang( $text ) {
		return 'ar' === self::script( $text ) ? 'ar' : self::latin_lang( $text );
	}

	/**
	 * Two-letter language of the site (Settings > General > Site Language).
	 *
	 * @return string
	 */
	public static function site_lang() {
		$locale = strtolower( (string) get_locale() );
		$lang   = substr( $locale, 0, 2 );
		return '' !== $lang ? $lang : 'en';
	}

	/**
	 * Is the question in another language than the site? Another script always is; a Latin question counts
	 * as foreign only when its function words clearly name another language.
	 *
	 * @param string $question  Question.
	 * @param string $site_lang Site language code.
	 * @return bool
	 */
	public static function is_foreign( $question, $site_lang ) {
		$script = self::script( $question );
		if ( 'ar' === $site_lang ) {
			return 'ar' !== $script;
		}
		if ( 'ar' === $script ) {
			return true;
		}
		$lang = self::latin_lang( $question );
		return '' !== $lang && $lang !== $site_lang;
	}
}
