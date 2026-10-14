<?php
/**
 * One answer: search, an optional cross-language retry, then the AI with numbered passages, or an honest
 * fallback (the best passages with links) when there is no key, no budget left or the AI fails.
 *
 * @package Site_Answers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answer orchestration.
 */
class Site_Answers_Answer {

	/**
	 * Short texts in the visitor's language when it differs from the site language (no AI is involved).
	 */
	const LOCAL = array(
		'en' => array(
			'not_found' => "I couldn't find that on this site.",
			'contact'   => 'Contact us',
		),
		'fr' => array(
			'not_found' => "Je n'ai pas trouvé cette information sur ce site.",
			'contact'   => 'Nous contacter',
		),
		'ar' => array(
			'not_found' => 'لم أجد هذه المعلومة في هذا الموقع.',
			'contact'   => 'تواصل معنا',
		),
		'es' => array(
			'not_found' => 'No encontré esa información en este sitio.',
			'contact'   => 'Contáctanos',
		),
	);

	/**
	 * "Not on this site" phrases in English, French and Arabic answers.
	 */
	const NOT_FOUND_RE = '/couldn\'t find|could not find|can\'t find|cannot find|not (?:mentioned|covered|listed) (?:on|in)|no information|doesn\'t (?:say|mention)|does not (?:say|mention)|n\'ai pas trouvé|n\'ai pas pu trouver|ne trouve pas|pas d\'information|ne (?:mentionne|précise|contient) pas|لم أتمكن من العثور|لم أجد|لا تتوفر|لا توجد معلومات|لا يذكر|لم يتم ذكر|غير متوفرة/iu';

	/**
	 * Answer a question.
	 *
	 * @param string $question Question (validated).
	 * @param array  $history  Earlier turns { role, content } (validated).
	 * @param array  $opts {
	 *     Options.
	 *
	 *     @type bool $count Count it against the site's daily cap (false for the admin test box).
	 *     @type bool $debug Add the passages, scores and errors (admin test box only).
	 * }
	 * @return array { answer, sources: [ { n, title, url, passage } ], mode: ai|extractive|not_found, contact }
	 */
	public static function answer( $question, $history, $opts = array() ) {
		$opts      = wp_parse_args(
			$opts,
			array(
				'count' => true,
				'debug' => false,
			)
		);
		$site_lang = Site_Answers_Text::site_lang();
		$contact   = Site_Answers_Plugin::contact();
		$prev      = self::previous_question( $history );
		$has_key   = '' !== Site_Answers_Plugin::api_key();
		$used_ai   = false; // One daily unit per question, whether the AI translates, answers or both.
		$debug     = array(
			'translated' => '',
			'error'      => '',
		);

		$hits = Site_Answers_Search::search( $question, array( 'prev' => $prev ) );
		if ( ! $hits && $has_key && Site_Answers_Text::is_foreign( $question, $site_lang ) ) {
			// Nothing found and the question is in another language: ask the AI once for a query in the site language.
			if ( ! $opts['count'] || Site_Answers_Limits::take_daily() ) {
				$used_ai = true;
				$query   = Site_Answers_AI::translate_query( $question, $site_lang );
				if ( '' !== $query ) {
					$debug['translated'] = $query;
					$hits                = Site_Answers_Search::search(
						$question,
						array(
							'prev'  => $prev,
							'extra' => $query,
						)
					);
				}
			}
		}

		if ( ! $hits ) {
			$out = self::not_found( $question, $contact );
		} elseif ( ! $has_key ) {
			$out = self::extractive( $hits, 'no_key' );
		} elseif ( ! $used_ai && $opts['count'] && ! Site_Answers_Limits::take_daily() ) {
			$out = self::extractive( $hits, 'daily_cap' );
		} else {
			$reply = Site_Answers_AI::chat( Site_Answers_AI::messages( $question, $hits, $history, $contact ), Site_Answers_AI::ANSWER_MAX_TOKENS );
			if ( is_wp_error( $reply ) ) {
				$debug['error'] = $reply->get_error_message();
				$out            = self::extractive( $hits, 'ai_error' );
			} else {
				$out = self::from_reply( $reply, $hits, $question, $contact );
			}
		}

		if ( $opts['debug'] ) {
			$debug['passages'] = $hits;
			$out['debug']      = $debug;
		}
		return $out;
	}

	/**
	 * Passages only, for demo mode: the browser sends them to the hosted demo AI.
	 *
	 * @param string $question Question.
	 * @param array  $history  Earlier turns.
	 * @param string $query    Optional translated query (second try after a cross-language miss).
	 * @return array { found, passages: [ { n, title, url, text } ], foreign, site_lang, site_label, answer?, contact?, hint? }
	 */
	public static function passages( $question, $history, $query = '' ) {
		$site_lang = Site_Answers_Text::site_lang();
		$hits      = Site_Answers_Search::search(
			$question,
			array(
				'prev'  => self::previous_question( $history ),
				'extra' => $query,
			)
		);
		$out       = array(
			'found'      => (bool) $hits,
			'passages'   => array(),
			'foreign'    => Site_Answers_Text::is_foreign( $question, $site_lang ),
			'site_lang'  => $site_lang,
			'site_label' => wp_strip_all_tags( get_bloginfo( 'name' ) ),
		);
		foreach ( self::sources( $hits ) as $source ) {
			$out['passages'][] = array(
				'n'     => $source['n'],
				'title' => $source['title'],
				'url'   => $source['url'],
				'text'  => $source['passage'],
			);
		}
		if ( ! $hits ) {
			$out = array_merge( $out, self::not_found( $question, Site_Answers_Plugin::contact() ) );
			if ( $out['foreign'] && '' === $query ) {
				$out['hint'] = Site_Answers_Index::site_words(); // For the one translation try in the browser.
			}
		}
		return $out;
	}

	/**
	 * Map the model's reply to the response: cited passages become the sources (numbers kept).
	 *
	 * @param string     $reply    Model text.
	 * @param array      $hits     Passages sent.
	 * @param string     $question Question.
	 * @param array|null $contact  Contact page.
	 * @return array
	 */
	private static function from_reply( $reply, $hits, $question, $contact ) {
		$reply     = trim( preg_replace( '/^#{1,6}\s*/m', '', $reply ) );
		$sources   = self::sources( $hits );
		$cited     = Site_Answers_AI::citations( $reply, count( $sources ) );
		$not_found = (bool) preg_match( self::NOT_FOUND_RE, mb_substr( $reply, 0, 400 ) );
		if ( $cited ) {
			$sources = array_values(
				array_filter(
					$sources,
					static function ( $s ) use ( $cited ) {
						return in_array( $s['n'], $cited, true );
					}
				)
			);
		} elseif ( $not_found ) {
			$sources = array();
		}
		$lang = Site_Answers_Text::question_lang( $question );
		return array(
			'answer'  => $reply,
			'sources' => $sources,
			'mode'    => 'ai',
			'contact' => $not_found ? self::contact_link( $contact, $lang ) : null,
		);
	}

	/**
	 * Best passages with links, and a note saying why there is no AI answer.
	 *
	 * @param array  $hits   Passages.
	 * @param string $reason no_key | daily_cap | ai_error.
	 * @return array
	 */
	private static function extractive( $hits, $reason ) {
		$notes = array(
			'no_key'    => __( 'AI answers are not set up on this site yet, so here are the most relevant passages from its pages.', 'site-answers' ),
			'daily_cap' => __( 'This site has used all of today\'s AI answers, so here are the most relevant passages from its pages.', 'site-answers' ),
			'ai_error'  => __( 'The AI did not answer just now, so here are the most relevant passages from this site.', 'site-answers' ),
		);
		return array(
			'answer'  => $notes[ $reason ],
			'sources' => self::sources( $hits ),
			'mode'    => 'extractive',
			'notice'  => $reason,
			'contact' => null,
		);
	}

	/**
	 * "Not found" answer that points to the contact page.
	 *
	 * @param string     $question Question.
	 * @param array|null $contact  Contact page.
	 * @return array
	 */
	private static function not_found( $question, $contact ) {
		$lang = Site_Answers_Text::question_lang( $question );
		return array(
			'answer'  => self::local( 'not_found', $lang ),
			'sources' => array(),
			'mode'    => 'not_found',
			'contact' => self::contact_link( $contact, $lang ),
		);
	}

	/**
	 * Contact link with a label in the visitor's language.
	 *
	 * @param array|null $contact Contact page.
	 * @param string     $lang    Question language.
	 * @return array|null
	 */
	private static function contact_link( $contact, $lang ) {
		if ( ! $contact ) {
			return null;
		}
		$contact['label'] = self::local( 'contact', $lang );
		return $contact;
	}

	/**
	 * A short text in the visitor's language: the translatable site text when the visitor writes in the site
	 * language (or we cannot tell), a built-in text for English, French, Arabic or Spanish otherwise.
	 *
	 * @param string $key  not_found | contact.
	 * @param string $lang Question language.
	 * @return string
	 */
	private static function local( $key, $lang ) {
		if ( '' === $lang || Site_Answers_Text::site_lang() === $lang || ! isset( self::LOCAL[ $lang ][ $key ] ) ) {
			$texts = array(
				'not_found' => __( "I couldn't find that on this site.", 'site-answers' ),
				'contact'   => __( 'Contact us', 'site-answers' ),
			);
			return $texts[ $key ];
		}
		return self::LOCAL[ $lang ][ $key ];
	}

	/**
	 * Hits as numbered sources.
	 *
	 * @param array $hits Passages.
	 * @return array
	 */
	private static function sources( $hits ) {
		$out = array();
		foreach ( array_values( $hits ) as $i => $hit ) {
			$out[] = array(
				'n'       => $i + 1,
				'title'   => $hit['title'],
				'url'     => $hit['url'],
				'passage' => $hit['text'],
			);
		}
		return $out;
	}

	/**
	 * Last question the visitor asked before this one.
	 *
	 * @param array $history Turns.
	 * @return string
	 */
	private static function previous_question( $history ) {
		foreach ( array_reverse( (array) $history ) as $turn ) {
			if ( 'user' === $turn['role'] ) {
				return $turn['content'];
			}
		}
		return '';
	}
}
