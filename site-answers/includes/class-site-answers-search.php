<?php
/**
 * Retrieval: BM25+ over the passages, with a relevance cutoff so off-topic questions find nothing.
 *
 * @package Site_Answers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Passage search.
 */
class Site_Answers_Search {

	const K1    = 1.5;
	const B     = 0.75;
	const DELTA = 1.0;

	const TOP_K        = 5;
	const MIN_COVERAGE = 0.34; // Share (idf-weighted) of the question's content words a passage must contain.
	const REL_CUTOFF   = 0.5;  // Keep passages scoring at least half the best one.
	const PER_PAGE     = 2;    // At most two passages of one page, so other pages get a place.
	const PREV_WEIGHT  = 0.5;  // Weight of the previous question's words in a follow-up.
	const SYN_WEIGHT   = 0.6;  // Weight of synonym words.
	const HEAD_WEIGHT  = 0.6;  // Bonus for question words in the page title or address.

	/**
	 * Best passages for a question.
	 *
	 * @param string $question Question as asked.
	 * @param array  $args {
	 *     Search options.
	 *
	 *     @type int    $k     Passages to return.
	 *     @type string $prev  Previous question (follow-ups such as "and how much is it?").
	 *     @type string $extra The question translated into the site language (cross-language search).
	 *     @type array  $index Passages to search instead of the stored index (tests).
	 * }
	 * @return array[] { post_id, title, url, text, score, coverage }, best first.
	 */
	public static function search( $question, $args = array() ) {
		$args  = wp_parse_args(
			$args,
			array(
				'k'     => self::TOP_K,
				'prev'  => '',
				'extra' => '',
				'index' => null,
			)
		);
		$index = is_array( $args['index'] ) ? $args['index'] : Site_Answers_Index::passages();
		$n     = count( $index['chunks'] );
		if ( 0 === $n ) {
			return array();
		}

		$cur  = Site_Answers_Text::content_words( Site_Answers_Text::tokens( $question ) );
		$tr   = Site_Answers_Text::content_words( Site_Answers_Text::tokens( $args['extra'] ) );
		$prev = Site_Answers_Text::content_words( Site_Answers_Text::tokens( $args['prev'] ) );
		$main = array_values( array_unique( array_merge( $cur, $tr ) ) );
		// Coverage is measured on the question's own words (the previous question's when it has none),
		// and on the translation; the better of the two counts.
		$cov_sets = array_values( array_filter( array( $cur ? $cur : $prev, $tr ) ) );
		if ( ! $cov_sets ) {
			return array();
		}

		$alts = array();
		foreach ( array_unique( array_merge( $main, $prev ) ) as $t ) {
			$alts[ $t ] = Site_Answers_Text::synonyms( $t );
		}
		$extra = array();
		foreach ( $main as $t ) {
			foreach ( $alts[ $t ] as $x ) {
				if ( ! in_array( $x, $main, true ) ) {
					$extra[ $x ] = true;
				}
			}
		}
		$extra = array_map( 'strval', array_keys( $extra ) );

		// Document frequencies of every word we may score.
		$terms = array_unique( array_merge( $main, $prev, $extra, ...array_values( $alts ) ) );
		$df    = array_fill_keys( $terms, 0 );
		$total = 0;
		foreach ( $index['chunks'] as $chunk ) {
			$total += $chunk['len'];
			foreach ( $terms as $t ) {
				if ( isset( $chunk['tf'][ $t ] ) ) {
					++$df[ $t ];
				}
			}
		}
		$avg     = $total > 0 ? $total / $n : 1.0;
		$unknown = log( $n + 1 ); // A word the site never uses weighs like the rarest word.
		$idf     = array();
		foreach ( $df as $t => $d ) {
			$idf[ $t ] = $d > 0 ? log( ( $n + 1 ) / $d ) : 0.0;
		}
		// Synonyms the site actually uses.
		foreach ( $alts as $t => $list ) {
			$alts[ $t ] = array_values(
				array_filter(
					$list,
					static function ( $x ) use ( $df ) {
						return ! empty( $df[ $x ] );
					}
				)
			);
		}

		$cands = array();
		foreach ( $index['chunks'] as $i => $chunk ) {
			$gain = self::bm25( $chunk, $main, $idf, $avg )
				+ self::PREV_WEIGHT * self::bm25( $chunk, $prev, $idf, $avg )
				+ self::SYN_WEIGHT * self::bm25( $chunk, $extra, $idf, $avg );
			if ( $gain <= 1e-9 ) {
				continue;
			}
			$coverage = 0.0;
			foreach ( $cov_sets as $words ) {
				$all = 0.0;
				$hit = 0.0;
				foreach ( $words as $t ) {
					$w    = isset( $idf[ $t ] ) && $idf[ $t ] > 0 ? $idf[ $t ] : $unknown;
					$all += $w;
					if ( self::has( $chunk, $t, $alts ) ) {
						$hit += $w;
					}
				}
				$coverage = max( $coverage, $all > 0 ? $hit / $all : 0.0 );
			}
			if ( $coverage < self::MIN_COVERAGE ) {
				continue;
			}
			// A page whose title or address names what is asked is the likely answer.
			$head = $index['docs'][ $chunk['post'] ]['head'];
			foreach ( $main as $t ) {
				if ( isset( $head[ $t ] ) ) {
					$gain += self::HEAD_WEIGHT * $idf[ $t ];
				} else {
					foreach ( $alts[ $t ] as $x ) {
						if ( isset( $head[ $x ] ) ) {
							$gain += self::HEAD_WEIGHT * self::SYN_WEIGHT * $idf[ $x ];
							break;
						}
					}
				}
			}
			$cands[] = array( $i, $gain, $coverage );
		}
		if ( ! $cands ) {
			return array();
		}

		$best  = max( array_column( $cands, 1 ) );
		$cands = array_filter(
			$cands,
			static function ( $c ) use ( $best ) {
				return $c[1] >= self::REL_CUTOFF * $best;
			}
		);
		usort(
			$cands,
			static function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);

		$out      = array();
		$held     = array();
		$per_page = array();
		$seen     = array();
		foreach ( $cands as $c ) {
			$chunk = $index['chunks'][ $c[0] ];
			$key   = md5( Site_Answers_Text::normalize( $chunk['text'] ) );
			if ( isset( $seen[ $key ] ) ) {
				continue; // Same text on two pages.
			}
			$seen[ $key ] = true;
			$doc          = $index['docs'][ $chunk['post'] ];
			$hit          = array(
				'post_id'  => $chunk['post'],
				'title'    => $doc['title'],
				'url'      => $doc['url'],
				'text'     => $chunk['text'],
				'score'    => round( $c[1], 4 ),
				'coverage' => round( $c[2], 4 ),
			);
			$page         = $chunk['post'];
			if ( isset( $per_page[ $page ] ) && $per_page[ $page ] >= self::PER_PAGE ) {
				$held[] = $hit;
				continue;
			}
			$per_page[ $page ] = isset( $per_page[ $page ] ) ? $per_page[ $page ] + 1 : 1;
			$out[]             = $hit;
			if ( count( $out ) >= $args['k'] ) {
				break;
			}
		}
		if ( count( $out ) < $args['k'] && $held ) {
			$out = array_merge( $out, array_slice( $held, 0, $args['k'] - count( $out ) ) );
		}
		return $out;
	}

	/**
	 * BM25+ score of a passage for some words (only matched words count).
	 *
	 * @param array    $chunk Passage { tf, len }.
	 * @param string[] $words Words.
	 * @param array    $idf   Word => idf.
	 * @param float    $avg   Average passage length.
	 * @return float
	 */
	private static function bm25( $chunk, $words, $idf, $avg ) {
		$score = 0.0;
		$norm  = self::K1 * ( 1 - self::B + self::B * $chunk['len'] / $avg );
		foreach ( $words as $t ) {
			if ( isset( $chunk['tf'][ $t ] ) && ! empty( $idf[ $t ] ) ) {
				$tf     = $chunk['tf'][ $t ];
				$score += $idf[ $t ] * ( self::DELTA + $tf * ( self::K1 + 1 ) / ( $tf + $norm ) );
			}
		}
		return $score;
	}

	/**
	 * Does the passage contain the word or one of its synonyms?
	 *
	 * @param array  $chunk Passage.
	 * @param string $t     Word.
	 * @param array  $alts  Word => synonyms the site uses.
	 * @return bool
	 */
	private static function has( $chunk, $t, $alts ) {
		if ( isset( $chunk['tf'][ $t ] ) ) {
			return true;
		}
		if ( isset( $alts[ $t ] ) ) {
			foreach ( $alts[ $t ] as $x ) {
				if ( isset( $chunk['tf'][ $x ] ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
