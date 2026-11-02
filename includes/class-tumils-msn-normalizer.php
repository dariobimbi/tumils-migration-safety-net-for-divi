<?php
/**
 * HTML normalizer: parses a rendered page and erases request-to-request noise
 * so that the differ only ever sees real changes. Pure PHP — no WordPress.
 * This whitelist of noise rules is the product's accuracy; extend it from
 * real-world fixtures, never speculatively.
 *
 * DEFERRED — timestamps. The spec lists timestamps alongside nonces and cache
 * busters in the normalization set, but no real-world timestamp pattern has
 * been observed yet: Task 11's full Divi 4.27.8 -> 5.2.1 migration of a
 * nine-page site produced not one timestamp-driven diff. A speculative rule
 * here would be a pattern guessed at rather than measured, and this file's
 * doctrine is that every rule cites the fixture that forced it — a wrong guess
 * silently swallows real changes, which is worse than a missing rule that
 * merely reports a hunk. The rule lands the day a real capture produces one.
 *
 * Generated ids need no rule here: Tumils_MSN_Differ pairs unmatched siblings by their
 * content before it falls back to tag+id, so an element whose id the migration
 * regenerated still pairs with itself and surfaces the new id as one 'attrs'
 * hunk instead of a spurious structural remove+add pair.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tumils_MSN_Normalizer {

	/**
	 * Parse HTML into a DOMDocument, normalized in place.
	 *
	 * @param string $html Raw page HTML.
	 * @return DOMDocument
	 */
	public static function document( $html ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		self::strip_noise_nodes( $doc );
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( $body instanceof DOMElement ) {
			self::unwrap_bare_spans( $body );
			self::unwrap_blurb_wrappers( $body );
			self::strip_header_logo_dimensions( $body );
			// Unwrapping leaves the span's text as a sibling of the text that
			// surrounded it ("Hello ", "world", " today"). normalize_element()
			// then trims each text node independently, welding them into
			// "Helloworldtoday" — an unwrapped mid-sentence span would compare
			// unequal to the same sentence written plainly, which is exactly the
			// noise the unwrap exists to erase. Merging adjacent text nodes first
			// makes the collapse operate on the whole run.
			$body->normalize();
			self::normalize_element( $body );
		}
		return $doc;
	}

	/**
	 * Canonical string form of the normalized <body>.
	 * Equal canonicals ⇒ the pages are the same page.
	 *
	 * @param string $html Raw page HTML.
	 * @return string
	 */
	public static function canonical( $html ) {
		$doc  = self::document( $html );
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		return $body ? $doc->saveHTML( $body ) : '';
	}

	/**
	 * An attribute-less <span> is pure markup scaffolding — no class, no style,
	 * nothing a browser could render differently. D5 repeatedly drops or adds
	 * these around header/label text with the underlying text unchanged and
	 * in the same place (Task 11: Divi 4->5 blurb-header restructuring), so
	 * they're unwrapped into their parent before comparison.
	 */
	private static function unwrap_bare_spans( DOMElement $root ) {
		$xpath = new DOMXPath( $root->ownerDocument );
		$spans = array();
		foreach ( $xpath->query( './/span[not(@*)]', $root ) as $node ) {
			$spans[] = $node;
		}
		foreach ( $spans as $span ) {
			$parent = $span->parentNode;
			if ( ! $parent ) {
				continue;
			}
			while ( $span->firstChild ) {
				$parent->insertBefore( $span->firstChild, $span );
			}
			$parent->removeChild( $span );
		}
	}

	/**
	 * D4's blurb module wraps its icon and its header+description in two
	 * sibling divs (et_pb_main_blurb_image, et_pb_blurb_container); D5
	 * collapses/renames them. Neither wrapper carries CSS of its own beyond
	 * grouping — but because both are id-less, class-agnostic <div>s, the
	 * differ's positional alignment pairs the wrong one across versions when
	 * the count changes, turning one real change (the icon) into a cluster of
	 * spurious remove/add noise around it. Unwrapping both wrapper classes
	 * flattens D4 and D5 to the same shape, so their real, identical content
	 * (header, description) lines up directly and only the icon differs.
	 *
	 * Matched by class *token* membership, not attribute equality (Task 11
	 * fix round): an exact `@class="et_pb_main_blurb_image"` match silently
	 * stops firing the moment the wrapper carries any additional class (e.g.
	 * D5's own `et_block_*` markers alongside it), which would resurrect the
	 * whole spurious-hunk cluster this rule exists to prevent.
	 */
	private static function unwrap_blurb_wrappers( DOMElement $root ) {
		$xpath = new DOMXPath( $root->ownerDocument );
		$query = './/div[contains(concat(" ", normalize-space(@class), " "), " et_pb_main_blurb_image ") '
			. 'or contains(concat(" ", normalize-space(@class), " "), " et_pb_blurb_container ")]';
		$divs  = array();
		foreach ( $xpath->query( $query, $root ) as $node ) {
			$divs[] = $node;
		}
		foreach ( $divs as $div ) {
			$parent = $div->parentNode;
			if ( ! $parent ) {
				continue;
			}
			while ( $div->firstChild ) {
				$parent->insertBefore( $div->firstChild, $div );
			}
			$parent->removeChild( $div );
		}
	}

	/**
	 * Divi's theme header logo carries width/height attributes that D4 and D5
	 * compute differently (Task 12 real migration: width="93" -> width="43" on
	 * every single page, one third of the whole report). They cannot affect
	 * rendering: Divi's own stylesheet sets `#logo { width: auto; max-height:
	 * 54% }`, and the global `img { height: auto }` covers the other half, so
	 * the attributes are overridden before they can lay anything out. Measured
	 * in a real browser against the live site — two <img id="logo"> elements,
	 * same CSS, same loaded source, width="93" vs width="43": both rendered
	 * 43.19x43.19px, delta 0.
	 *
	 * Only the *derived* dimensions are dropped. The setting they are derived
	 * from, data-height-percentage, is left alone — so a user who actually
	 * resizes their logo still gets a hunk, and so does a swapped logo (src).
	 * Scoped to an id="logo" image inside Divi's .logo_container, never to
	 * content images, whose dimensions are real signal.
	 */
	private static function strip_header_logo_dimensions( DOMElement $root ) {
		$xpath = new DOMXPath( $root->ownerDocument );
		$query = './/div[contains(concat(" ", normalize-space(@class), " "), " logo_container ")]'
			. '//img[@id="logo"]';
		foreach ( $xpath->query( $query, $root ) as $img ) {
			$img->removeAttribute( 'width' );
			$img->removeAttribute( 'height' );
		}
	}

	/** Remove node types that never carry layout signal. */
	private static function strip_noise_nodes( DOMDocument $doc ) {
		$xpath = new DOMXPath( $doc );
		$kill  = array();
		foreach ( $xpath->query( '//script | //style | //noscript | //link | //meta | //comment()' ) as $node ) {
			$kill[] = $node;
		}
		foreach ( $kill as $node ) {
			$node->parentNode->removeChild( $node );
		}
	}

	/** Recursively normalize attributes and text of an element. */
	private static function normalize_element( DOMElement $el ) {
		// Class order is not a change.
		if ( $el->hasAttribute( 'class' ) ) {
			$classes = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
			$classes = array_map( array( __CLASS__, 'canonicalize_class' ), $classes );
			$classes = array_values( array_filter( $classes, array( __CLASS__, 'is_signal_class' ) ) );
			$classes = array_values( array_unique( $classes ) );
			sort( $classes );
			$el->setAttribute( 'class', implode( ' ', $classes ) );
		}

		// Cache-buster query args vary per asset version, not per layout.
		// Covers every attribute that can carry one now that the differ compares
		// full attribute maps: a ver= bump inside data-src or a srcset list would
		// otherwise read as a changed image.
		foreach ( array( 'href', 'src', 'data-src', 'srcset', 'action', 'formaction' ) as $attr ) {
			if ( $el->hasAttribute( $attr ) ) {
				$el->setAttribute( $attr, self::strip_cache_busters( $el->getAttribute( $attr ), 'srcset' === $attr ) );
			}
		}

		// An empty title="" is exactly as inert as no title at all — no tooltip,
		// no accessible name contribution, nothing rendered. D4 emits the empty
		// attribute on module images whenever the title field is blank and D5
		// omits it (Task 12 real migration), which is a diff in the markup and
		// nothing at all on the page. Only the empty value is dropped: a title
		// that actually changes text, or loses real text, stays a hunk. Note
		// this is emphatically NOT generalized to other attributes — alt=""
		// (decorative) differs from a missing alt, and for boolean attributes
		// like disabled="" the empty value IS the true state.
		if ( '' === $el->getAttribute( 'title' ) ) {
			$el->removeAttribute( 'title' );
		}

		// Nonces rotate every request.
		$remove = array();
		foreach ( $el->attributes as $a ) {
			if ( false !== stripos( $a->name, 'nonce' ) ) {
				$remove[] = $a->name;
			}
		}
		foreach ( $remove as $name ) {
			$el->removeAttribute( $name );
		}
		if ( 'input' === $el->tagName && false !== stripos( (string) $el->getAttribute( 'name' ), 'nonce' ) ) {
			$el->setAttribute( 'value', '' );
		}

		// Attribute order is not content. Nothing — no browser, no CSS selector,
		// no script — can tell `<div id="a" data-x="1">` from
		// `<div data-x="1" id="a">`, but canonical() serializes source order, so
		// an order-only difference hashed differently while the differ (which
		// already compares a sorted attribute map) correctly found nothing to
		// report. Hash says changed, diff says nothing: that is precisely the
		// combination that earns a page a synthetic 'opaque' hunk for a change
		// that does not exist. Rebuilt in sorted name order, last, so it sees
		// the final attribute set after the rules above have had their say.
		$ordered = array();
		foreach ( $el->attributes as $a ) {
			$ordered[ $a->name ] = $a->value;
		}
		ksort( $ordered );
		// removeAttribute() silently cannot touch a namespace declaration or a
		// reserved xml:* attribute: it returns false, hasAttribute() also says
		// false, and the setAttribute() below then appends a SECOND copy. Inline
		// SVG canonicalized to `<svg xmlns=".." xmlns=".." xml:space=".." …>`,
		// which poisoned the content hash and the snippets shown in the report —
		// and left those attributes pinned in source order, so an order-only
		// difference produced unequal canonicals with zero hunks: the exact
		// hash-says-changed / diff-says-nothing pair that earns a page a
		// synthetic 'opaque' hunk for a change that never happened. Removing the
		// node itself does work on them, so they join the sort like any other.
		foreach ( array_keys( $ordered ) as $name ) {
			$attr = $el->attributes->getNamedItem( $name );
			if ( $attr ) {
				$el->removeAttributeNode( $attr );
			}
		}
		foreach ( $ordered as $name => $value ) {
			$el->setAttribute( $name, $value );
		}

		// Children: collapse text whitespace, drop blank text, recurse into elements.
		$children = array();
		foreach ( $el->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text = preg_replace( '/\s+/u', ' ', $child->nodeValue );
				if ( '' === trim( $text ) ) {
					$el->removeChild( $child );
				} else {
					$child->nodeValue = trim( $text );
				}
			} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
				self::normalize_element( $child );
			}
		}
	}

	/**
	 * Drop ver= query args (WP cache busters). What terminates the version value
	 * depends on the attribute, because a comma means two different things:
	 *
	 * - In a srcset it separates entries, and a value that runs through it eats
	 *   the rest of the list — a three-image srcset collapsed to its first url
	 *   with every descriptor gone, so a genuine change to any candidate but the
	 *   first compared equal and the page was certified clean. Srcsets are split
	 *   on commas up front and each entry cleaned on its own.
	 * - In any other URL attribute a comma is an ordinary character inside a
	 *   query value. Stopping at it spliced the tail of "?ver=1,2,3&other=x"
	 *   into the path ("/page,2,3&other=x"), corrupting the url itself.
	 *
	 * @param string $url       Attribute value.
	 * @param bool   $is_srcset True when the value is a comma-separated entry list.
	 */
	private static function strip_cache_busters( $url, $is_srcset = false ) {
		if ( $is_srcset ) {
			$entries = array();
			foreach ( explode( ',', $url ) as $entry ) {
				$entry     = preg_replace( '/([?&])ver=[^&#\s]*/', '$1', trim( $entry ) );
				$entries[] = self::clean_url_delims( $entry );
			}
			return implode( ', ', $entries );
		}
		return self::clean_url_delims( preg_replace( '/([?&])ver=[^&#]*/', '$1', $url ) );
	}

	/**
	 * Collapse the delimiter strands a removed query param leaves behind.
	 *
	 * Necessary in every position, not just at the end: dropping the FIRST param
	 * leaves "?&w=300", so the same url with its params written in the other
	 * order canonicalized differently and the report showed a change nobody
	 * made. Attribute order and param order are not content.
	 */
	private static function clean_url_delims( $url ) {
		$url = preg_replace( '/\?&+/', '?', $url );
		$url = preg_replace( '/&&+/', '&', $url );
		return preg_replace( '/[?&]+(?=\s|$)/', '', $url );
	}

	/**
	 * A class token carries no comparable layout signal if it's one of Divi 5's
	 * migration-only markers — present/absent purely because of which builder
	 * version rendered the page, never because the layout itself changed.
	 * Found from a real Divi 4.27.8 -> 5.2.1 migration (Task 11); narrowly scoped
	 * so a genuine alignment/gutter change elsewhere still gets flagged.
	 */
	private static function is_signal_class( $class ) {
		// D5 block-editor marker classes: added to nearly every section/row/
		// column/module purely for the new builder's own targeting; no observed
		// visual effect (confirmed: pages with zero visual diff still emit these).
		if ( 0 === strpos( $class, 'et_block_' ) ) {
			return false;
		}
		// D5 drops D4's DEFAULT gutter-width marker class (et_pb_gutters3) in
		// favor of CSS not visible to this tool either way, so that one token's
		// disappearance alone is not new information. Scoped to the single
		// observed default only (Task 11 fix round) — non-default gutter tokens
		// (et_pb_gutters0/1/2, or a default vanishing without being replaced by
		// another gutters<N> class) are real, visible signal until real-world
		// evidence says otherwise, mirroring the et_pb_text_align_left narrowing
		// below.
		if ( 'et_pb_gutters3' === $class ) {
			return false;
		}
		// "Align left" is the CSS default for LTR text; D5 stops emitting the
		// explicit class and nothing visibly moves. Center/right/justify are NOT
		// filtered here — losing those *would* be a real, visible change.
		if ( 'et_pb_text_align_left' === $class ) {
			return false;
		}
		// Generic module-wrapper marker Divi adds inconsistently across versions
		// (e.g. present on a button link only from D5 on); carries no CSS of its
		// own, so its appearance/disappearance alone is not layout signal.
		if ( 'et_pb_module' === $class ) {
			return false;
		}
		// A pure background *toggle* class; the actual background (color or
		// image) is applied via generated CSS this tool can't see either way —
		// by design (see CLAUDE.md's ground-truth section: this tool is
		// shared-hosting-safe and never renders or diffs generated <style>
		// blocks, so any CSS-only visual change, e.g. Task 11's CTA
		// background-color shift, is an inherent blind spot, not a bug).
		// Reviewed in the Task 11 fix round and left as-is (STANDS): treating
		// this toggle as signal would flag every background module on every
		// migrated site, since the toggle class itself is unchanged even when
		// its invisible-to-us CSS color/image is.
		if ( 'et_pb_with_background' === $class ) {
			return false;
		}
		// Legacy float-clearing utility class, meaningless once Divi 5 lays
		// everything out with flex/grid.
		if ( 'clearfix' === $class ) {
			return false;
		}
		return true;
	}

	/**
	 * Fold value-carrying class tokens that encode "the default" two different
	 * ways into one canonical form, so an implicit default (D4) and an explicit
	 * default (D5) compare equal.
	 */
	private static function canonicalize_class( $class ) {
		// D4 leaves the modifier suffix empty when a divider's position is left
		// at its default; D5 always writes the explicit default ("top").
		if ( 'et_pb_divider_position_' === $class ) {
			return 'et_pb_divider_position_top';
		}
		return $class;
	}
}
