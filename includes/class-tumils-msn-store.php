<?php
/**
 * Persistence: one index table + raw HTML files under uploads/tumils-msn-snapshots/.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tumils_MSN_Store {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tumils_msn_snapshots';
	}

	/** Activation: create/upgrade the index table. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id char(12) NOT NULL,
			label varchar(64) NOT NULL default '',
			url text NOT NULL,
			url_hash char(32) NOT NULL,
			content_hash char(32) NOT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY url_hash (url_hash)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	/** Snapshot storage dir (created on demand, listing-protected). */
	public static function dir( $sub = '' ) {
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['basedir'] ) . 'tumils-msn-snapshots';
		// && wp_mkdir_p(): an uploads dir that cannot be created — permissions
		// broken by a host move, quota exhausted — otherwise turns each write below
		// into a PHP warning, for a failure save_snapshot() already reports cleanly
		// as a capture error. ET's guidelines require a warning-free plugin.
		if ( ! is_dir( $base ) && wp_mkdir_p( $base ) ) {
			file_put_contents( $base . '/index.php', "<?php // Silence is golden.\n" );
			file_put_contents(
				$base . '/.htaccess',
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			);
		}
		$dir = $sub ? $base . '/' . $sub : $base;
		if ( ! is_dir( $dir ) && wp_mkdir_p( $dir ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	/** Store one captured page. False = file write or index insert failed. */
	public static function save_snapshot( $run_id, $label, $url, $html ) {
		global $wpdb;
		$url_hash = md5( $url );
		$dir      = self::dir( $run_id );
		// No directory means no writable uploads: bail before file_put_contents
		// warns. The caller turns false into an entry in the run's error list, which
		// the UI already surfaces as "N failed".
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$written = file_put_contents( $dir . '/' . $url_hash . '.html', $html );
		if ( false === $written ) {
			return false;
		}
		// The column is varchar(64); a longer label makes the insert fail outright
		// (strict mode) or get silently truncated, so cut it to fit here instead.
		// mb_substr, because varchar(64) counts characters and byte-slicing a
		// multibyte label mid-sequence leaves an invalid UTF-8 tail that a strict
		// connection rejects. WP core guarantees an mb_substr fallback via
		// wp-includes/compat.php, and this class is WP-dependent already.
		$label  = mb_substr( (string) $label, 0, 64 );
		$result = $wpdb->insert(
			self::table(),
			array(
				'run_id'       => $run_id,
				'label'        => $label,
				'url'          => $url,
				'url_hash'     => $url_hash,
				'content_hash' => md5( Tumils_MSN_Normalizer::canonical( $html ) ),
				'created'      => current_time( 'mysql' ),
			)
		);
		// A failed insert leaves an orphan HTML file the index will never list —
		// the page silently vanishes from the run. Report it as a capture error.
		return false !== $result;
	}

	/**
	 * Table names in this class are interpolated, never placeholders.
	 *
	 * self::table() is $wpdb->prefix plus a literal suffix, so no user input can
	 * reach it. %i would say that better, but it only exists in WP 6.2+ and the
	 * marketplace's PHPCS ruleset rejects it outright — and having rejected it,
	 * miscounts the remaining placeholders, which buries real prepare() mistakes
	 * in noise. Values still go through prepare().
	 */
	public static function get_runs() {
		global $wpdb;
		$sql = 'SELECT run_id, MAX(label) AS label, MIN(created) AS created, COUNT(*) AS pages
			FROM ' . self::table() . ' GROUP BY run_id ORDER BY created DESC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql interpolates the table name only (see above); this query takes no values.
		return $wpdb->get_results( $sql );
	}

	public static function get_run_pages( $run_id ) {
		global $wpdb;
		$sql = 'SELECT url, url_hash, content_hash FROM ' . self::table() . ' WHERE run_id = %s ORDER BY id ASC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql interpolates the table name only (see above); $run_id is bound through prepare().
		return $wpdb->get_results( $wpdb->prepare( $sql, $run_id ) );
	}

	/**
	 * Stored HTML for one page of a run, or false if the file cannot be read.
	 *
	 * false and '' must stay distinguishable: the index row can outlive its file
	 * (uploads restored without wp-content, the dir moved, files pruned), and a
	 * caller that treats "unreadable" as "empty page" diffs nothing against
	 * nothing and calls the page clean.
	 *
	 * @return string|false
	 */
	public static function get_html( $run_id, $url_hash ) {
		$file = self::dir( $run_id ) . '/' . $url_hash . '.html';
		return is_readable( $file ) ? (string) file_get_contents( $file ) : false;
	}

	/**
	 * URLs that failed to capture during a run, keyed by run_id.
	 *
	 * A failed capture leaves the page absent from the run, which the report
	 * then shows as "Missing after" / "New page" — indistinguishable from a page
	 * the migration actually deleted. Recording the failures lets the UI say
	 * which absences are the tool's fault rather than the migration's.
	 */
	const ERRORS_OPTION = 'tumils_msn_run_errors';

	/** @return string[] URLs that failed during this run. */
	public static function get_run_errors( $run_id ) {
		$map = get_option( self::ERRORS_OPTION, array() );
		return ( is_array( $map ) && isset( $map[ $run_id ] ) && is_array( $map[ $run_id ] ) )
			? $map[ $run_id ]
			: array();
	}

	/** Record failed URLs for a run (merged with any from earlier batches). */
	public static function add_run_errors( $run_id, array $urls ) {
		if ( ! $urls ) {
			return;
		}
		$map = get_option( self::ERRORS_OPTION, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$existing        = isset( $map[ $run_id ] ) && is_array( $map[ $run_id ] ) ? $map[ $run_id ] : array();
		$map[ $run_id ]  = array_values( array_unique( array_merge( $existing, $urls ) ) );
		update_option( self::ERRORS_OPTION, $map, false );
	}

	public static function delete_run( $run_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'run_id' => $run_id ) );
		$map = get_option( self::ERRORS_OPTION, array() );
		if ( is_array( $map ) && isset( $map[ $run_id ] ) ) {
			unset( $map[ $run_id ] );
			update_option( self::ERRORS_OPTION, $map, false );
		}
		$dir = self::dir( $run_id );
		foreach ( glob( $dir . '/*' ) ?: array() as $f ) {
			if ( is_file( $f ) ) {
				wp_delete_file( $f );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own empty snapshot dirs; WP_Filesystem is unwarranted here.
	}
}
