<?php
/**
 * Page capture: enumerate public URLs, fetch each via loopback wp_remote_get.
 * Batched so a big site never hits a PHP timeout — shared hosting is the target.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tumils_MSN_Capture {

	/** Every public URL we snapshot, stable order, deduped. */
	public static function urls() {
		$urls  = array( home_url( '/' ) );
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		$cap = (int) apply_filters( 'tumils_msn_max_urls', 200 );
		$ids = get_posts(
			array(
				'post_type'      => array_values( $types ),
				'post_status'    => 'publish',
				'posts_per_page' => $cap,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}

		// One sample archive per type: post-type archives + first term of each public taxonomy.
		foreach ( get_post_types( array( 'has_archive' => true ), 'names' ) as $pt ) {
			$urls[] = get_post_type_archive_link( $pt );
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'number' => 1, 'hide_empty' => true ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$link = get_term_link( $terms[0] );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/** Loopback fetch. False on any non-200. */
	public static function fetch( $url ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'sslverify'   => false, // loopback to self; local certs are often self-signed
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return false;
		}
		return wp_remote_retrieve_body( $res );
	}

	/** Capture one slice of the URL list. */
	public static function capture_batch( $run_id, $label, $offset, $size = 5 ) {
		$urls   = self::urls();
		$errors = array();
		foreach ( array_slice( $urls, $offset, $size ) as $url ) {
			$html = self::fetch( $url );
			if ( false === $html ) {
				$errors[] = $url;
			} elseif ( ! Tumils_MSN_Store::save_snapshot( $run_id, $label, $url, $html ) ) {
				$errors[] = $url;
			}
		}
		return array(
			'done'   => min( $offset + $size, count( $urls ) ),
			'total'  => count( $urls ),
			'errors' => $errors,
		);
	}

	/** New unique run id. */
	public static function new_run_id() {
		return substr( md5( uniqid( 'tumils-msn', true ) ), 0, 12 );
	}
}
