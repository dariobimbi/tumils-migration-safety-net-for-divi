<?php
/**
 * DOM tree differ. Compares two normalized pages and emits hunks
 * classified structural (layout-relevant) vs cosmetic. Pure PHP — no WordPress.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tumils_MSN_Differ {

	// Class fragments that make a change layout-relevant. Extend from real migrations.
	const LAYOUT_RX = '/et_pb_(section|row|column|gutters)|_builder|et_flex|flex_|_grid|grid_/';

	// Tags whose addition/removal is structural regardless of classes.
	const STRUCTURAL_TAGS = array( 'section', 'header', 'footer', 'nav', 'aside', 'main', 'table', 'ul', 'ol', 'article' );

	/**
	 * Diff two raw HTML pages.
	 *
	 * @return array[] Hunks: {type, severity, path, before, after}. Empty = clean.
	 */
	public static function diff( $before_html, $after_html ) {
		if ( Tumils_MSN_Normalizer::canonical( $before_html ) === Tumils_MSN_Normalizer::canonical( $after_html ) ) {
			return array();
		}
		$a = self::body( $before_html );
		$b = self::body( $after_html );
		$hunks = array();
		if ( $a && $b ) {
			self::compare_element( $a, $b, 'body', $hunks );
		}
		return $hunks;
	}

	private static function body( $html ) {
		$body = Tumils_MSN_Normalizer::document( $html )->getElementsByTagName( 'body' )->item( 0 );
		return ( $body instanceof DOMElement ) ? $body : null;
	}

	/** Compare two matched elements: classes, inline style, then children. */
	private static function compare_element( DOMElement $a, DOMElement $b, $path, array &$hunks ) {
		$ca = self::classes( $a );
		$cb = self::classes( $b );
		if ( $ca !== $cb ) {
			$added   = array_values( array_diff( $cb, $ca ) );
			$removed = array_values( array_diff( $ca, $cb ) );
			$hunks[] = array(
				'type'     => 'classes',
				'severity' => self::layoutish( array_merge( $added, $removed ) ) ? 'structural' : 'cosmetic',
				'path'     => $path,
				'before'   => implode( ' ', $removed ),
				'after'    => implode( ' ', $added ),
			);
		}
		if ( $a->getAttribute( 'style' ) !== $b->getAttribute( 'style' ) ) {
			$hunks[] = array(
				'type'     => 'style',
				'severity' => 'cosmetic',
				'path'     => $path,
				'before'   => $a->getAttribute( 'style' ),
				'after'    => $b->getAttribute( 'style' ),
			);
		}
		self::compare_attrs( $a, $b, $path, $hunks );
		self::compare_children( $a, $b, $path, $hunks );
	}

	/**
	 * Diff everything that is not class or style: src, href, alt, width, id,
	 * data-*, aria-*, … Without this the differ is blind to a swapped image, a
	 * rewritten link or a dropped dimension — the page's content_hash changes,
	 * no hunk is emitted, and the report calls a broken migration clean.
	 *
	 * One aggregated hunk per element (an element rarely changes one attribute
	 * in isolation, and N hunks on one <img> would drown the worklist). Severity
	 * is cosmetic: the spec's rule is that anything not known to be
	 * layout-relevant is cosmetic, and no attribute has yet earned a structural
	 * classification from a real migration.
	 */
	private static function compare_attrs( DOMElement $a, DOMElement $b, $path, array &$hunks ) {
		$aa   = self::attrs( $a );
		$ab   = self::attrs( $b );
		$keys = array_unique( array_merge( array_keys( $aa ), array_keys( $ab ) ) );
		sort( $keys ); // deterministic output regardless of source attribute order
		$before = array();
		$after  = array();
		foreach ( $keys as $key ) {
			$va = isset( $aa[ $key ] ) ? $aa[ $key ] : null;
			$vb = isset( $ab[ $key ] ) ? $ab[ $key ] : null;
			if ( $va === $vb ) {
				continue;
			}
			// A key missing on one side simply contributes nothing to that side.
			if ( null !== $va ) {
				$before[] = $key . '="' . $va . '"';
			}
			if ( null !== $vb ) {
				$after[] = $key . '="' . $vb . '"';
			}
		}
		if ( ! $before && ! $after ) {
			return;
		}
		$hunks[] = array(
			'type'     => 'attrs',
			'severity' => 'cosmetic',
			'path'     => $path,
			'before'   => implode( ' ', $before ),
			'after'    => implode( ' ', $after ),
		);
	}

	/** Attribute map minus class and style, which have their own hunk types. */
	private static function attrs( DOMElement $el ) {
		$out = array();
		foreach ( $el->attributes as $attr ) {
			if ( 'class' === $attr->name || 'style' === $attr->name ) {
				continue;
			}
			$out[ $attr->name ] = $attr->value;
		}
		return $out;
	}

	/** Align child lists, recurse on matches, emit add/remove for the rest. */
	private static function compare_children( DOMElement $a, DOMElement $b, $path, array &$hunks ) {
		$ka = self::child_nodes( $a );
		$kb = self::child_nodes( $b );
		// Strict pass: identity includes a content fingerprint, so identical
		// wrappers with different content never cross-match.
		$sa = array_map( array( __CLASS__, 'strict_signature' ), $ka );
		$sb = array_map( array( __CLASS__, 'strict_signature' ), $kb );
		$pairs   = self::lcs_pairs( $sa, $sb );
		$pairs[] = array( count( $ka ), count( $kb ) ); // sentinel

		$i = 0;
		$j = 0;
		foreach ( $pairs as $pair ) {
			// Tiered pass inside the gap: leftovers that share an explicit id,
			// or failing that their content, or failing that their position,
			// are the same element changed — not a remove+add. See compare_gap().
			self::compare_gap(
				array_slice( $ka, $i, $pair[0] - $i ),
				array_slice( $kb, $j, $pair[1] - $j ),
				$path,
				$hunks
			);
			$i = $pair[0];
			$j = $pair[1];
			if ( $pair[0] < count( $ka ) ) {
				$na = $ka[ $pair[0] ];
				$nb = $kb[ $pair[1] ];
				if ( XML_ELEMENT_NODE === $na->nodeType ) {
					self::compare_element( $na, $nb, $path . ' > ' . self::label( $na ), $hunks );
				}
				// Strict-matched text nodes are identical by construction — nothing to emit.
				$i++;
				$j++;
			}
		}
	}

	/**
	 * Pair gap leftovers in three tiers, then add/remove whatever is still
	 * unmatched.
	 *
	 * The tiers run in descending order of identity strength — explicit id >
	 * content fingerprint > tag position — and that ORDER is the whole point,
	 * not just the set of tiers. A weaker signal must never get to overrule a
	 * stronger one: when the content tier ran first, any gap where both tiers
	 * could pair had a text fingerprint outvote a stable id, so the differ
	 * invented id renames that never happened and blamed the wrong sibling for
	 * a deletion (an edited row reported as removed while the row actually
	 * deleted went unmentioned).
	 *
	 *   Pass 0 (id_signature) — same tag AND same non-empty id. An author-
	 *   or builder-assigned id is the strongest identity claim on the page, so
	 *   it is honoured before anything else. Elements without an id sit this
	 *   pass out rather than pairing on tag alone.
	 *
	 *   Pass 1 (content_signature) — same tag, same text, id ignored. The
	 *   id-regeneration case: Divi rewrites ids across builder versions, so
	 *   identical content is what proves this is the same element, and
	 *   recursing surfaces the new id as one 'attrs' hunk.
	 *
	 *   Pass 2 (signature) — same tag and id, content ignored. By now this
	 *   mostly pairs id-less elements and text nodes by position: the
	 *   rewritten-content case, where nothing but the shape is left to go on.
	 *
	 * What survives all three matched neither its id, its content nor its
	 * position on the other side, which is the definition of a removal or an
	 * addition — and is reported as one.
	 */
	private static function compare_gap( array $gap_a, array $gap_b, $path, array &$hunks ) {
		if ( ! $gap_a && ! $gap_b ) {
			return;
		}

		// Passes 0 and 1. Each consumes what it can match and hands the
		// remainder down, order preserved on both sides.
		foreach ( array( 'id_signature', 'content_signature' ) as $tier ) {
			$sa     = array_map( array( __CLASS__, $tier ), $gap_a );
			$sb     = array_map( array( __CLASS__, $tier ), $gap_b );
			$keep_a = $gap_a;
			$keep_b = $gap_b;
			foreach ( self::lcs_pairs( $sa, $sb ) as $pair ) {
				unset( $keep_a[ $pair[0] ], $keep_b[ $pair[1] ] );
				$na = $gap_a[ $pair[0] ];
				if ( XML_ELEMENT_NODE === $na->nodeType ) {
					self::compare_element( $na, $gap_b[ $pair[1] ], $path . ' > ' . self::label( $na ), $hunks );
				}
				// A text node cannot match in pass 0 at all, and one matched in
				// pass 1 holds an equal value by construction of the signature —
				// so neither pass has anything to emit for text.
			}
			$gap_a = array_values( $keep_a );
			$gap_b = array_values( $keep_b );
		}

		// Pass 2, on what is left, plus add/remove for the final leftovers.
		$rest_a  = $gap_a;
		$rest_b  = $gap_b;
		$la      = array_map( array( __CLASS__, 'signature' ), $rest_a );
		$lb      = array_map( array( __CLASS__, 'signature' ), $rest_b );
		$pairs   = self::lcs_pairs( $la, $lb );
		$pairs[] = array( count( $rest_a ), count( $rest_b ) ); // sentinel

		$i = 0;
		$j = 0;
		foreach ( $pairs as $pair ) {
			for ( ; $i < $pair[0]; $i++ ) {
				$hunks[] = self::node_hunk( 'removed', $rest_a[ $i ], $path );
			}
			for ( ; $j < $pair[1]; $j++ ) {
				$hunks[] = self::node_hunk( 'added', $rest_b[ $j ], $path );
			}
			if ( $pair[0] < count( $rest_a ) ) {
				$na = $rest_a[ $pair[0] ];
				$nb = $rest_b[ $pair[1] ];
				if ( XML_TEXT_NODE === $na->nodeType ) {
					if ( $na->nodeValue !== $nb->nodeValue ) {
						$hunks[] = array(
							'type'     => 'text',
							'severity' => 'cosmetic',
							'path'     => $path,
							'before'   => $na->nodeValue,
							'after'    => $nb->nodeValue,
						);
					}
				} else {
					self::compare_element( $na, $nb, $path . ' > ' . self::label( $na ), $hunks );
				}
				$i++;
				$j++;
			}
		}
	}

	/**
	 * Strongest gap-pairing signature: tag + a NON-EMPTY id. Gap pass 0; see
	 * compare_gap().
	 *
	 * Anything without an explicit id — an id-less element, any text node — gets
	 * a signature that cannot equal another node's, so it sits this pass out and
	 * falls through to a weaker tier instead of pairing on tag alone. Using
	 * spl_object_id for that is safe because every node of both gaps is held
	 * alive in the caller's arrays for the duration of the comparison, so no two
	 * of them can share an id.
	 */
	private static function id_signature( DOMNode $node ) {
		if ( XML_ELEMENT_NODE !== $node->nodeType || '' === $node->getAttribute( 'id' ) ) {
			return "\x00noid\x00" . spl_object_id( $node );
		}
		return $node->tagName . '#' . $node->getAttribute( 'id' );
	}

	/**
	 * Loose gap-pairing signature: tag + id, content deliberately ignored — so
	 * two nodes pair when they are the same element even though its classes,
	 * attributes or text changed. By the time this runs, pass 0 has already
	 * claimed everything with a matching non-empty id, so in practice this
	 * pairs id-less elements and text nodes by position. Gap pass 2; see
	 * compare_gap().
	 */
	private static function signature( DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return '#text';
		}
		return $node->tagName . '#' . $node->getAttribute( 'id' );
	}

	/**
	 * Content-aware gap-pairing signature: tag + a fingerprint of the text,
	 * id deliberately ignored — so an element pairs with its id-regenerated self
	 * (Divi rewrites element ids across builder versions) and the new id
	 * surfaces as one 'attrs' hunk. Runs only on what pass 0 could not match by
	 * id, so ignoring the id here can no longer discard a good one. Gap pass 1;
	 * see compare_gap().
	 */
	private static function content_signature( DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return '#text|' . $node->nodeValue;
		}
		return $node->tagName . '|' . md5( $node->textContent );
	}

	/** Strict identity: element + content fingerprint. Identical wrappers with different content never cross-match. */
	private static function strict_signature( DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return '#text|' . $node->nodeValue;
		}
		return $node->tagName . '#' . $node->getAttribute( 'id' ) . '|' . md5( $node->textContent );
	}

	/** Element + text children only. */
	private static function child_nodes( DOMElement $el ) {
		$out = array();
		foreach ( $el->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType || XML_TEXT_NODE === $child->nodeType ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	/** Longest common subsequence → matched index pairs, ascending. */
	private static function lcs_pairs( array $a, array $b ) {
		$n = count( $a );
		$m = count( $b );
		// ponytail: O(n*m) DP; child lists are short. Positional fallback guards pathological pages.
		if ( $n * $m > 250000 ) {
			$pairs = array();
			$min   = min( $n, $m );
			for ( $k = 0; $k < $min; $k++ ) {
				if ( $a[ $k ] === $b[ $k ] ) {
					$pairs[] = array( $k, $k );
				}
			}
			return $pairs;
		}
		$dp = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$dp[ $i ][ $j ] = ( $a[ $i ] === $b[ $j ] )
					? $dp[ $i + 1 ][ $j + 1 ] + 1
					: max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
			}
		}
		$pairs = array();
		$i     = 0;
		$j     = 0;
		while ( $i < $n && $j < $m ) {
			if ( $a[ $i ] === $b[ $j ] ) {
				$pairs[] = array( $i, $j );
				$i++;
				$j++;
			} elseif ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
				$i++;
			} else {
				$j++;
			}
		}
		return $pairs;
	}

	/** Hunk for an unmatched (added/removed) node. */
	private static function node_hunk( $type, DOMNode $node, $path ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return array(
				'type'     => $type,
				'severity' => 'cosmetic',
				'path'     => $path,
				'before'   => 'removed' === $type ? $node->nodeValue : '',
				'after'    => 'added' === $type ? $node->nodeValue : '',
			);
		}
		$structural = in_array( $node->tagName, self::STRUCTURAL_TAGS, true )
			|| self::layoutish( self::classes( $node ) );
		$snippet = self::snippet( $node );
		return array(
			'type'     => $type,
			'severity' => $structural ? 'structural' : 'cosmetic',
			'path'     => $path,
			'before'   => 'removed' === $type ? $snippet : '',
			'after'    => 'added' === $type ? $snippet : '',
		);
	}

	private static function classes( DOMElement $el ) {
		return preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
	}

	private static function layoutish( array $classes ) {
		foreach ( $classes as $c ) {
			if ( preg_match( self::LAYOUT_RX, $c ) ) {
				return true;
			}
		}
		return false;
	}

	private static function label( DOMElement $el ) {
		$classes = self::classes( $el );
		return $el->tagName . ( $classes ? '.' . $classes[0] : '' );
	}

	private static function snippet( DOMElement $el ) {
		$html = $el->ownerDocument->saveHTML( $el );
		return strlen( $html ) > 300 ? substr( $html, 0, 300 ) . '…' : $html;
	}
}
