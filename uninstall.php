<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tumils_msn_snapshots" ); // phpcs:ignore
delete_option( 'tumils_msn_run_errors' );

$tumils_msn_up  = wp_upload_dir();
$tumils_msn_dir = trailingslashit( $tumils_msn_up['basedir'] ) . 'tumils-msn-snapshots';
if ( is_dir( $tumils_msn_dir ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tumils_msn_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $tumils_msn_file ) {
		$tumils_msn_file->isDir() ? rmdir( $tumils_msn_file->getPathname() ) : wp_delete_file( $tumils_msn_file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own empty snapshot dirs; WP_Filesystem is unwarranted here.
	}
	rmdir( $tumils_msn_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own empty snapshot dirs; WP_Filesystem is unwarranted here.
}
