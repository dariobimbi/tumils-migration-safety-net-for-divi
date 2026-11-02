<?php
/**
 * Admin UI: snapshot runs list, capture/compare triggers with AJAX progress.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tumils_MSN_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_tumils_msn_capture_batch', array( __CLASS__, 'ajax_capture_batch' ) );
		add_action( 'wp_ajax_tumils_msn_compare_batch', array( __CLASS__, 'ajax_compare_batch' ) );
		add_action( 'admin_post_tumils_msn_delete_run', array( __CLASS__, 'handle_delete_run' ) );
	}

	public static function menu() {
		add_management_page(
			__( "Tumil's Migration Safety Net for Divi 5", 'tumils-migration-safety-net-for-divi' ),
			__( 'Migration Safety Net', 'tumils-migration-safety-net-for-divi' ),
			'manage_options',
			'tumils-msn',
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		if ( 'tools_page_tumils-msn' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'tumils-msn-admin', TUMILS_MSN_PLUGIN_URL . 'assets/admin.css', array(), TUMILS_MSN_VERSION );
		wp_enqueue_script( 'tumils-msn-admin', TUMILS_MSN_PLUGIN_URL . 'assets/admin.js', array(), TUMILS_MSN_VERSION, true );
		wp_localize_script(
			'tumils-msn-admin',
			'tumilsMsnAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'tumils_msn_batch' ),
				// The JS displays these three in the progress text and the delete
				// confirm, so they belong in the .pot like every other visible string.
				'confirmDelete' => __( 'Delete this snapshot?', 'tumils-migration-safety-net-for-divi' ),
				'serverError'   => __( 'the server rejected the request', 'tumils-migration-safety-net-for-divi' ),
				/* translators: %s: reason the batch failed */
				'batchFailed'   => __( 'Error: %s — fix the selection and retry.', 'tumils-migration-safety-net-for-divi' ),
			)
		);
	}

	/** Router. */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tumils-migration-safety-net-for-divi' ) );
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'runs'; // phpcs:ignore WordPress.Security.NonceVerification
		$run_a = isset( $_GET['run_a'] ) ? sanitize_key( wp_unslash( $_GET['run_a'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$run_b = isset( $_GET['run_b'] ) ? sanitize_key( wp_unslash( $_GET['run_b'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap tumils-msn-wrap"><h1>' . esc_html__( "Tumil's Migration Safety Net for Divi 5", 'tumils-migration-safety-net-for-divi' ) . '</h1>';
		if ( 'report' === $view && $run_a && $run_b ) {
			self::render_report( $run_a, $run_b );
		} elseif ( 'page' === $view && $run_a && $run_b && isset( $_GET['url_hash'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::render_page_detail( $run_a, $run_b, sanitize_key( wp_unslash( $_GET['url_hash'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			self::render_runs();
		}
		echo '</div>';
	}

	/** Runs list + new-snapshot + compare forms. */
	private static function render_runs() {
		$runs = Tumils_MSN_Store::get_runs();
		?>
		<div class="tumils-msn-card">
			<h2><?php esc_html_e( '1. Take a snapshot', 'tumils-migration-safety-net-for-divi' ); ?></h2>
			<p><?php esc_html_e( 'Capture how every public page renders right now. Take one before you migrate and one after.', 'tumils-migration-safety-net-for-divi' ); ?></p>
			<input type="text" id="tumils-msn-label" maxlength="64" placeholder="<?php esc_attr_e( 'Label, e.g. pre-migration', 'tumils-migration-safety-net-for-divi' ); ?>">
			<button class="button button-primary" id="tumils-msn-capture"><?php esc_html_e( 'Snapshot now', 'tumils-migration-safety-net-for-divi' ); ?></button>
			<div class="tumils-msn-progress" id="tumils-msn-capture-progress" hidden><div class="tumils-msn-progress-bar"></div><span class="tumils-msn-progress-text"></span></div>
		</div>

		<div class="tumils-msn-card">
			<h2><?php esc_html_e( '2. Compare two snapshots', 'tumils-migration-safety-net-for-divi' ); ?></h2>
			<?php if ( count( $runs ) < 2 ) : ?>
				<p><?php esc_html_e( 'You need at least two snapshots to compare.', 'tumils-migration-safety-net-for-divi' ); ?></p>
			<?php else : ?>
				<select id="tumils-msn-run-a">
					<?php foreach ( $runs as $idx => $r ) : ?>
						<option value="<?php echo esc_attr( $r->run_id ); ?>"<?php selected( 1 === $idx ); ?>><?php echo esc_html( self::run_option_label( $r ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="tumils-msn-vs"><?php esc_html_e( 'vs', 'tumils-migration-safety-net-for-divi' ); ?></span>
				<select id="tumils-msn-run-b">
					<?php foreach ( $runs as $idx => $r ) : ?>
						<option value="<?php echo esc_attr( $r->run_id ); ?>"<?php selected( 0 === $idx ); ?>><?php echo esc_html( self::run_option_label( $r ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button button-primary" id="tumils-msn-compare"><?php esc_html_e( 'Compare', 'tumils-migration-safety-net-for-divi' ); ?></button>
				<div class="tumils-msn-progress" id="tumils-msn-compare-progress" hidden><div class="tumils-msn-progress-bar"></div><span class="tumils-msn-progress-text"></span></div>
			<?php endif; ?>
		</div>

		<?php if ( $runs ) : ?>
		<div class="tumils-msn-card">
			<h2><?php esc_html_e( 'Snapshots', 'tumils-migration-safety-net-for-divi' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Label', 'tumils-migration-safety-net-for-divi' ); ?></th>
					<th><?php esc_html_e( 'Taken', 'tumils-migration-safety-net-for-divi' ); ?></th>
					<th><?php esc_html_e( 'Pages', 'tumils-migration-safety-net-for-divi' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $runs as $r ) : ?>
					<?php $tumils_msn_failed = count( Tumils_MSN_Store::get_run_errors( $r->run_id ) ); ?>
					<tr>
						<td><?php echo esc_html( $r->label ); ?> <code><?php echo esc_html( $r->run_id ); ?></code></td>
						<td><?php echo esc_html( $r->created ); ?></td>
						<td>
							<?php echo esc_html( number_format_i18n( $r->pages ) ); ?>
							<?php if ( $tumils_msn_failed ) : ?>
								<span class="tumils-msn-badge tumils-msn-badge-cosmetic"><?php
									printf(
										/* translators: %s: number of pages that failed to capture */
										esc_html( _n( '%s failed', '%s failed', $tumils_msn_failed, 'tumils-migration-safety-net-for-divi' ) ),
										esc_html( number_format_i18n( $tumils_msn_failed ) )
									);
								?></span>
							<?php endif; ?>
						</td>
						<td>
							<form class="tumils-msn-delete-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'tumils_msn_delete_run' ); ?>
								<input type="hidden" name="action" value="tumils_msn_delete_run">
								<input type="hidden" name="run_id" value="<?php echo esc_attr( $r->run_id ); ?>">
								<button class="button-link-delete" type="submit"><?php esc_html_e( 'Delete', 'tumils-migration-safety-net-for-divi' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * One <option> label for the snapshot pickers.
	 *
	 * A helper rather than two inline concatenations so the two pickers cannot
	 * drift apart, and so the label is a single translatable format string:
	 * spliced English (' pages)') cannot be translated or reordered, and the
	 * count had no plural form, so a one-page snapshot read "(1 pages)".
	 *
	 * @param object $run One row from Tumils_MSN_Store::get_runs().
	 * @return string
	 */
	private static function run_option_label( $run ) {
		return sprintf(
			/* translators: 1: snapshot label, 2: date taken, 3: number of pages captured */
			_n( '%1$s — %2$s (%3$s page)', '%1$s — %2$s (%3$s pages)', (int) $run->pages, 'tumils-migration-safety-net-for-divi' ),
			$run->label,
			$run->created,
			number_format_i18n( $run->pages )
		);
	}

	/** Site-level report: ranked worklist, or the clean bill of health. */
	public static function render_report( $run_a, $run_b ) {
		$rows    = Tumils_MSN_Compare::results( $run_a, $run_b );
		$changed = array_filter(
			$rows,
			function ( $r ) {
				return 'clean' !== $r['status'];
			}
		);
		$back = add_query_arg( 'page', 'tumils-msn', admin_url( 'tools.php' ) );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to snapshots', 'tumils-migration-safety-net-for-divi' ) . '</a></p>';

		// Guard the hero: "all 0 pages render identically" and "this run matches
		// itself" are both vacuously true and both read as a verified migration.
		if ( $run_a === $run_b ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Choose two different snapshots.', 'tumils-migration-safety-net-for-divi' ) . '</p></div>';
			return;
		}
		if ( ! $rows ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Nothing to compare.', 'tumils-migration-safety-net-for-divi' ) . '</p></div>';
			return;
		}

		self::render_capture_error_notices( $run_a, $run_b );

		if ( ! $changed ) {
			// The payoff state: nothing broke.
			echo '<div class="tumils-msn-card tumils-msn-clean-hero"><span class="tumils-msn-clean-mark">&#10003;</span>';
			printf(
				/* translators: %d: number of pages checked */
				esc_html__( 'Migration verified clean — all %d pages render identically.', 'tumils-migration-safety-net-for-divi' ),
				count( $rows )
			);
			echo '</div>';
			return;
		}

		printf(
			/* translators: 1: number of changed pages, 2: total pages compared */
			'<p>' . esc_html__( '%1$d of %2$d pages changed.', 'tumils-migration-safety-net-for-divi' ) . '</p>',
			(int) count( $changed ),
			(int) count( $rows )
		);
		$table = new Tumils_MSN_Report_Table( $rows, $run_a, $run_b );
		$table->prepare_items();
		$table->display();
	}

	/**
	 * Warn when either snapshot has pages that never captured. Those pages are
	 * simply absent from the run, so the report shows them as "Missing after" or
	 * "New page" — the same rows a genuinely deleted page produces. Without this
	 * notice the reader has no way to tell a broken fetch from a real change.
	 */
	private static function render_capture_error_notices( $run_a, $run_b ) {
		$labels = array();
		foreach ( Tumils_MSN_Store::get_runs() as $run ) {
			$labels[ $run->run_id ] = $run->label;
		}
		foreach ( array( $run_a, $run_b ) as $run_id ) {
			$failed = count( Tumils_MSN_Store::get_run_errors( $run_id ) );
			if ( ! $failed ) {
				continue;
			}
			$label = isset( $labels[ $run_id ] ) && '' !== $labels[ $run_id ] ? $labels[ $run_id ] : $run_id;
			echo '<div class="notice notice-warning"><p>';
			printf(
				esc_html(
					/* translators: 1: number of pages that failed to capture, 2: snapshot label */
					_n(
						'%1$s page failed to capture in snapshot “%2$s” — rows marked Missing after / New page may be capture failures rather than real changes.',
						'%1$s pages failed to capture in snapshot “%2$s” — rows marked Missing after / New page may be capture failures rather than real changes.',
						$failed,
						'tumils-migration-safety-net-for-divi'
					)
				),
				esc_html( number_format_i18n( $failed ) ),
				esc_html( $label )
			);
			echo '</p></div>';
		}
	}

	/** Per-page drill-down: every hunk with context. */
	public static function render_page_detail( $run_a, $run_b, $url_hash ) {
		$hunks = Tumils_MSN_Compare::page_hunks( $run_a, $run_b, $url_hash );
		$url   = '';
		foreach ( Tumils_MSN_Compare::results( $run_a, $run_b ) as $r ) {
			if ( $r['url_hash'] === $url_hash ) {
				$url = $r['url'];
				break;
			}
		}
		$back = add_query_arg(
			array( 'page' => 'tumils-msn', 'view' => 'report', 'run_a' => $run_a, 'run_b' => $run_b ),
			admin_url( 'tools.php' )
		);
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to report', 'tumils-migration-safety-net-for-divi' ) . '</a></p>';
		echo '<h2>' . esc_html( $url ) . '</h2>';
		echo '<p><a class="button" target="_blank" href="' . esc_url( $url ) . '">' . esc_html__( 'View page', 'tumils-migration-safety-net-for-divi' ) . '</a> ';
		echo '<a class="button" target="_blank" href="' . esc_url( add_query_arg( 'et_fb', '1', $url ) ) . '">' . esc_html__( 'Open in Divi builder', 'tumils-migration-safety-net-for-divi' ) . '</a></p>';

		if ( ! $hunks ) {
			echo '<p>' . esc_html__( 'No differences on this page.', 'tumils-migration-safety-net-for-divi' ) . '</p>';
			return;
		}
		// Slug -> label, mirroring Tumils_MSN_Report_Table::column_status(). The
		// stored hunk keeps the machine slug because that is the record; the wording
		// is chosen here so it can be translated, and an unrecognised slug falls
		// back to itself rather than rendering an empty badge.
		$severities = array(
			'structural' => __( 'Structural', 'tumils-migration-safety-net-for-divi' ),
			'cosmetic'   => __( 'Cosmetic', 'tumils-migration-safety-net-for-divi' ),
		);
		$types      = array(
			'classes'          => __( 'Classes changed', 'tumils-migration-safety-net-for-divi' ),
			'attrs'            => __( 'Attributes changed', 'tumils-migration-safety-net-for-divi' ),
			'style'            => __( 'Inline style changed', 'tumils-migration-safety-net-for-divi' ),
			'text'             => __( 'Text changed', 'tumils-migration-safety-net-for-divi' ),
			'added'            => __( 'Element added', 'tumils-migration-safety-net-for-divi' ),
			'removed'          => __( 'Element removed', 'tumils-migration-safety-net-for-divi' ),
			'opaque'           => __( 'Change could not be localized', 'tumils-migration-safety-net-for-divi' ),
			'missing-snapshot' => __( 'Cannot verify', 'tumils-migration-safety-net-for-divi' ),
		);
		foreach ( $hunks as $h ) {
			$note = self::hunk_note( $h );
			echo '<div class="tumils-msn-hunk tumils-msn-hunk-' . esc_attr( $h['severity'] ) . '">';
			echo '<span class="tumils-msn-badge tumils-msn-badge-' . esc_attr( $h['severity'] ) . '">' . esc_html( isset( $severities[ $h['severity'] ] ) ? $severities[ $h['severity'] ] : $h['severity'] ) . '</span> ';
			echo '<strong>' . esc_html( isset( $types[ $h['type'] ] ) ? $types[ $h['type'] ] : $h['type'] ) . '</strong>';
			echo '<div class="tumils-msn-path">' . esc_html( $h['path'] ) . '</div>';
			// A gap hunk explains itself in prose and has no markup to show. The
			// else also keeps a report cached by an older version — which stored the
			// English sentence in 'before' — from printing that sentence twice.
			if ( '' !== $note ) {
				echo '<p class="tumils-msn-note">' . esc_html( $note ) . '</p>';
			} else {
				if ( '' !== $h['before'] ) {
					echo '<pre class="tumils-msn-before">&minus; ' . esc_html( $h['before'] ) . '</pre>';
				}
				if ( '' !== $h['after'] ) {
					echo '<pre class="tumils-msn-after">+ ' . esc_html( $h['after'] ) . '</pre>';
				}
			}
			echo '</div>';
		}
	}

	/**
	 * The sentence for a hunk that reports a gap rather than a markup change.
	 *
	 * Built here rather than in Tumils_MSN_Compare because compare results are
	 * cached as JSON: a sentence written at compare time would freeze the report
	 * in whichever locale ran the comparison, and each which-run variant has to be
	 * a whole sentence to be translatable at all.
	 *
	 * @param array $hunk One hunk from the cached compare result.
	 * @return string Empty for an ordinary markup hunk.
	 */
	private static function hunk_note( array $hunk ) {
		if ( 'opaque' === $hunk['type'] ) {
			return __( 'The rendered page differs, but the change could not be localized — it may be outside the page body, or a parse anomaly.', 'tumils-migration-safety-net-for-divi' );
		}
		if ( 'missing-snapshot' !== $hunk['type'] ) {
			return '';
		}
		switch ( isset( $hunk['which'] ) ? $hunk['which'] : '' ) {
			case 'before':
				return __( 'Cannot verify this page: the stored snapshot for the before run is missing or unreadable, so no comparison was made.', 'tumils-migration-safety-net-for-divi' );
			case 'after':
				return __( 'Cannot verify this page: the stored snapshot for the after run is missing or unreadable, so no comparison was made.', 'tumils-migration-safety-net-for-divi' );
			case 'both':
				return __( 'Cannot verify this page: the stored snapshots for both runs are missing or unreadable, so no comparison was made.', 'tumils-migration-safety-net-for-divi' );
			default:
				// A report cached before the slug existed carries no 'which'.
				return __( 'Cannot verify this page: a stored snapshot is missing or unreadable, so no comparison was made.', 'tumils-migration-safety-net-for-divi' );
		}
	}

	private static function check_ajax() {
		check_ajax_referer( 'tumils_msn_batch' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
	}

	public static function ajax_capture_batch() {
		self::check_ajax();
		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : Tumils_MSN_Capture::new_run_id(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in check_ajax().
		$label  = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in check_ajax().
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in check_ajax().
		$result = Tumils_MSN_Capture::capture_batch( $run_id, $label, $offset );
		// Persist failures: the browser only sees this one batch's errors, and a
		// page that failed to capture shows up later as "Missing after" — a
		// silent lie about the migration unless the run remembers it broke.
		if ( ! empty( $result['errors'] ) ) {
			Tumils_MSN_Store::add_run_errors( $run_id, $result['errors'] );
		}
		$result['run_id'] = $run_id;
		wp_send_json_success( $result );
	}

	public static function ajax_compare_batch() {
		self::check_ajax();
		$run_a  = isset( $_POST['run_a'] ) ? sanitize_key( wp_unslash( $_POST['run_a'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in check_ajax().
		$run_b  = isset( $_POST['run_b'] ) ? sanitize_key( wp_unslash( $_POST['run_b'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in check_ajax().
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in check_ajax().
		if ( ! $run_a || ! $run_b || $run_a === $run_b ) {
			wp_send_json_error( 'bad runs', 400 );
		}
		$result = Tumils_MSN_Compare::compare_batch( $run_a, $run_b, $offset );
		$result['report_url'] = add_query_arg(
			array( 'page' => 'tumils-msn', 'view' => 'report', 'run_a' => $run_a, 'run_b' => $run_b ),
			admin_url( 'tools.php' )
		);
		wp_send_json_success( $result );
	}

	public static function handle_delete_run() {
		check_admin_referer( 'tumils_msn_delete_run' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tumils-migration-safety-net-for-divi' ) );
		}
		Tumils_MSN_Store::delete_run( sanitize_key( wp_unslash( $_POST['run_id'] ?? '' ) ) );
		wp_safe_redirect( add_query_arg( 'page', 'tumils-msn', admin_url( 'tools.php' ) ) );
		exit;
	}
}
