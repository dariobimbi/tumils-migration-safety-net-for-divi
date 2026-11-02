<?php
/**
 * Report list table: one row per URL, ranked worst-first.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Tumils_MSN_Report_Table extends WP_List_Table {

	private $rows;
	private $run_a;
	private $run_b;

	public function __construct( array $rows, $run_a, $run_b ) {
		parent::__construct( array( 'plural' => 'tumils_msn_pages', 'ajax' => false ) );
		$this->rows  = $rows;
		$this->run_a = $run_a;
		$this->run_b = $run_b;
	}

	public function get_columns() {
		return array(
			'url'        => __( 'Page', 'tumils-migration-safety-net-for-divi' ),
			'status'     => __( 'Status', 'tumils-migration-safety-net-for-divi' ),
			'structural' => __( 'Structural', 'tumils-migration-safety-net-for-divi' ),
			'cosmetic'   => __( 'Cosmetic', 'tumils-migration-safety-net-for-divi' ),
		);
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->rows; // already ranked by Tumils_MSN_Compare::results()
	}

	protected function column_url( $item ) {
		$detail = add_query_arg(
			array(
				'page'     => 'tumils-msn',
				'view'     => 'page',
				'run_a'    => $this->run_a,
				'run_b'    => $this->run_b,
				'url_hash' => $item['url_hash'],
			),
			admin_url( 'tools.php' )
		);
		$actions = array(
			'view_page' => '<a href="' . esc_url( $item['url'] ) . '" target="_blank">' . esc_html__( 'View page', 'tumils-migration-safety-net-for-divi' ) . '</a>',
		);
		// 'unverified' rows link through too: the detail view is where the reason
		// (which run's snapshot is unreadable) is actually written down.
		$has_detail = in_array( $item['status'], array( 'changed', 'unverified' ), true );
		if ( $has_detail ) {
			$actions = array( 'detail' => '<a href="' . esc_url( $detail ) . '">' . esc_html__( 'View changes', 'tumils-migration-safety-net-for-divi' ) . '</a>' ) + $actions;
		}
		$title = $has_detail
			? '<a href="' . esc_url( $detail ) . '"><strong>' . esc_html( $item['url'] ) . '</strong></a>'
			: esc_html( $item['url'] );
		return $title . $this->row_actions( $actions );
	}

	protected function column_status( $item ) {
		$map = array(
			'clean'       => array( 'tumils-msn-badge-clean', __( 'Clean', 'tumils-migration-safety-net-for-divi' ) ),
			'changed'     => array( 'tumils-msn-badge-structural', __( 'Changed', 'tumils-migration-safety-net-for-divi' ) ),
			'only-before' => array( 'tumils-msn-badge-cosmetic', __( 'Missing after', 'tumils-migration-safety-net-for-divi' ) ),
			'only-after'  => array( 'tumils-msn-badge-cosmetic', __( 'New page', 'tumils-migration-safety-net-for-divi' ) ),
			'pending'     => array( 'tumils-msn-badge-cosmetic', __( 'Not compared yet', 'tumils-migration-safety-net-for-divi' ) ),
			'unverified'  => array( 'tumils-msn-badge-structural', __( 'Cannot verify', 'tumils-migration-safety-net-for-divi' ) ),
		);
		list( $class, $text ) = $map[ $item['status'] ];
		return '<span class="tumils-msn-badge ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( (string) $item[ $column_name ] );
	}
}
