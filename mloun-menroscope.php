<?php
/**
 * Plugin Name: Mloun MenuScope
 * Plugin URI:  https://wordpress.org/plugins/mloun-menroscope
 * Description: Hide or block admin menu items per role or user, rename menus per role or user, reorder menus with drag & drop, hide plugins from the plugin list, and hide users from the Users screen.
 * Version:     1.4.3
 * Author:      Mloun
 * Author URI:  https://mloun.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mloun-menroscope
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'AMENEDIT_VERSION', '1.4.3' );
define( 'AMENEDIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMENEDIT_URL', plugin_dir_url( __FILE__ ) );

require_once AMENEDIT_DIR . 'includes/class-core.php';
require_once AMENEDIT_DIR . 'includes/class-applier.php';
require_once AMENEDIT_DIR . 'includes/class-plugin-visibility.php';
require_once AMENEDIT_DIR . 'includes/class-user-hiding.php';
require_once AMENEDIT_DIR . 'includes/class-admin-page.php';

/**
 * Bootstrap the plugin.
 *
 * No bundled translation files and no manual textdomain loading: for a
 * WordPress.org-hosted plugin, core loads the translations under the
 * plugin's slug automatically.
 */
function amenedit_init() {
	AMENEDIT_Core::instance();

	// Registered on every request, not only in wp-admin: hidden users must also
	// be filtered out of REST user endpoints (/wp-json/wp/v2/users) and
	// front-end author queries, otherwise they stay publicly enumerable.
	new AMENEDIT_User_Hiding();

	if ( is_admin() ) {
		new AMENEDIT_Applier();
		new AMENEDIT_Plugin_Visibility();
		new AMENEDIT_Admin_Page();
	}
}
add_action( 'plugins_loaded', 'amenedit_init' );
