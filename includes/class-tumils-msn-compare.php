<?php
/**
 * Compares two snapshot runs. Hash-equal pages are clean without parsing;
 * changed pages get a full Tumils_MSN_Differ pass, cached as JSON per page.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tumils_MSN_Compare {

	public static function dir( $run_a, $run_b ) {
		return Tumils_MSN_Store::dir( 'compare-' . $run_a . '-' . $run_b );
	}

	/** URLs present in either run, keyed by url_hash. */
	private static function url_map( $run_a, $run_b ) {
		$map = array();
		foreach ( Tumils_MSN_Store::get_run_pages( $run_a ) as $row ) {
			$map[ $row->url_hash ] = array( 'url' => $row->url, 'a' => $row->content_hash, 'b' => null );
		}
		foreach ( Tumils_MSN_Store::get_run_pages( $run_b ) as $row ) {
			if ( ! isset( $map[ $row->url_hash ] ) ) {
				$map[ $row->url_hash ] = array( 'url' => $row->url, 'a' => null, 'b' => null );
			}
			$map[ $row->url_hash ]['b'] = $row->content_hash;
		}
		return $map;
	}

	public static function compare_batch( $run_a, $run_b, $offset, $size = 5 ) {
		$map  = self::url_map( $run_a, $run_b );
		$keys = array_keys( $map );
		$dir  = self::dir( $run_a, $run_b );

		foreach ( array_slice( $keys, $offset, $size ) as $url_hash ) {
			$row  = $map[ $url_hash ];
			$file = $dir . '/' . $url_hash . '.json';
			if ( null === $row['a'] || null === $row['b'] || $row['a'] === $row['b'] ) {
				continue; // missing on one side or hash-clean: no JSON needed
			}
			$html_a = Tumils_MSN_Store::get_html( $run_a, $url_hash );
			$html_b = Tumils_MSN_Store::get_html( $run_b, $url_hash );
			$gap    = self::missing_snapshot_hunks( false === $html_a, false === $html_b );
			if ( $gap ) {
				self::write_result( $file, $row['url'], $gap );
				continue;
			}
			$hunks = Tumils_MSN_Differ::diff( $html_a, $html_b );
			// No hunks on a hash-differing pair has two very different causes,
			// and they must not share an outcome.
			//
			// If the canonicals really do differ, the page provably changed and
			// the differ failed to localize it. Never let that read as clean:
			// report the change we know about and say we could not place it.
			//
			// If the canonicals are EQUAL, the stored hashes are simply stale —
			// they were stamped at capture time by whatever normalizer shipped
			// then, and a plugin update that adds or fixes a normalization rule
			// retroactively invalidates every hash taken before it. The page is
			// clean under the current rules. Emitting the opaque hunk here would
			// mark a genuinely clean page 'changed' forever (its stored hash
			// cannot be recomputed for a pre-migration run that can never be
			// re-captured), which is the false positive the spec calls fatal.
			// Leaving $hunks empty writes a 0/0 JSON, and results() reads an
			// empty hunk list back as a page verified clean by full re-diff.
			if ( ! $hunks && Tumils_MSN_Normalizer::canonical( $html_a ) !== Tumils_MSN_Normalizer::canonical( $html_b ) ) {
				$hunks = array(
					array(
						// Wording lives in Tumils_MSN_Admin for the same reason as the
						// missing-snapshot hunk below: this array gets cached as JSON.
						'type'     => 'opaque',
						'severity' => 'cosmetic',
						'path'     => 'body',
						'before'   => '',
						'after'    => '',
					),
				);
			}
			self::write_result( $file, $row['url'], $hunks );
		}

		return array(
			'done'  => min( $offset + $size, count( $keys ) ),
			'total' => count( $keys ),
		);
	}

	/**
	 * The one hunk to report when a snapshot file behind an index row is gone.
	 *
	 * Without this, an unreadable pair is the worst possible outcome: two empty
	 * strings diff to nothing, the canonicals match, and the page is written out
	 * as verified clean — the tool signing off on a comparison it never made.
	 * Pure on purpose so tests/ can cover it without WordPress.
	 *
	 * @param bool $a_missing Before-run snapshot unreadable.
	 * @param bool $b_missing After-run snapshot unreadable.
	 * @return array[] Empty when both files are readable.
	 */
	public static function missing_snapshot_hunks( $a_missing, $b_missing ) {
		if ( ! $a_missing && ! $b_missing ) {
			return array();
		}
		// Which side is missing is recorded as a slug, not a sentence. Hunks are
		// cached to JSON at compare time, so prose written here would freeze the
		// report in whatever locale happened to run the comparison — and a spliced
		// sentence fragment ("the before run") cannot be translated at all, however
		// it is wrapped. Tumils_MSN_Admin turns the slug into a whole sentence at
		// render time.
		return array(
			array(
				'type'     => 'missing-snapshot',
				'severity' => 'structural',
				'path'     => 'body',
				'which'    => $a_missing && $b_missing ? 'both' : ( $a_missing ? 'before' : 'after' ),
				'before'   => '',
				'after'    => '',
			),
		);
	}

	/** Write one page's compare result, with severity counts, to its JSON file. */
	private static function write_result( $file, $url, array $hunks ) {
		$structural = 0;
		foreach ( $hunks as $h ) {
			if ( 'structural' === $h['severity'] ) {
				$structural++;
			}
		}
		file_put_contents(
			$file,
			wp_json_encode(
				array(
					'url'        => $url,
					'hunks'      => $hunks,
					'structural' => $structural,
					'cosmetic'   => count( $hunks ) - $structural,
				)
			)
		);
	}

	/** Site-level result rows for the report table. */
	public static function results( $run_a, $run_b ) {
		$dir = self::dir( $run_a, $run_b );
		$out = array();
		foreach ( self::url_map( $run_a, $run_b ) as $url_hash => $row ) {
			$item = array(
				'url'        => $row['url'],
				'url_hash'   => $url_hash,
				'status'     => 'clean',
				'structural' => 0,
				'cosmetic'   => 0,
			);
			if ( null === $row['a'] ) {
				$item['status'] = 'only-after';
			} elseif ( null === $row['b'] ) {
				$item['status'] = 'only-before';
			} elseif ( $row['a'] !== $row['b'] ) {
				$json = self::read_json( $dir . '/' . $url_hash . '.json' );
				if ( null === $json ) {
					$item['status'] = 'pending'; // hash differs but compare_batch hasn't reached it
				} else {
					// An empty hunk list here is not "the differ found nothing" —
					// compare_batch writes a synthetic 'opaque' hunk in that case.
					// It means the opposite and is written deliberately: the pair
					// hashes differently only because the stored hashes were
					// stamped by a superseded normalizer, and a full re-diff
					// confirmed the two canonicals are identical under the current
					// rules. That is a verified-clean page, and reporting it as
					// changed would leave a green migration permanently red.
					$item['status']     = $json['hunks'] ? 'changed' : 'clean';
					$item['structural'] = $json['structural'];
					$item['cosmetic']   = $json['cosmetic'];
					// Not 'changed': we never saw the two versions, so claiming a
					// change is as wrong as claiming none. Its own status keeps it
					// out of the clean hero and off the "changed" tally.
					if ( 1 === count( $json['hunks'] ) && 'missing-snapshot' === $json['hunks'][0]['type'] ) {
						$item['status'] = 'unverified';
					}
				}
			}
			$out[] = $item;
		}
		usort(
			$out,
			function ( $x, $y ) {
				return array( $y['structural'], $y['cosmetic'] ) <=> array( $x['structural'], $x['cosmetic'] );
			}
		);
		return $out;
	}

	public static function page_hunks( $run_a, $run_b, $url_hash ) {
		$json = self::read_json( self::dir( $run_a, $run_b ) . '/' . $url_hash . '.json' );
		return $json ? $json['hunks'] : array();
	}

	private static function read_json( $file ) {
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}
}
