<?php
/**
 * Plugin Name:       Tumil's Migration Safety Net for Divi 5
 * Description:       Verify your Divi 4 → Divi 5 migration: snapshot every page before, re-scan after, and see exactly what changed — or get the clean bill of health.
 * Version:           1.0.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Dario Bimbi
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tumils-migration-safety-net-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TUMILS_MSN_VERSION', '1.0.1' );
define( 'TUMILS_MSN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TUMILS_MSN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// (array) cast: glob() returns false on some filesystem errors, and foreach
// over false is a fatal on PHP 8.
foreach ( (array) glob( TUMILS_MSN_PLUGIN_DIR . 'includes/class-tumils-msn-*.php' ) as $tumils_msn_file ) {
	require_once $tumils_msn_file;
}
unset( $tumils_msn_file );

if ( class_exists( 'Tumils_MSN_Store' ) ) {
	register_activation_hook( __FILE__, array( 'Tumils_MSN_Store', 'install' ) );
}
if ( is_admin() && class_exists( 'Tumils_MSN_Admin' ) ) {
	Tumils_MSN_Admin::init();
}
