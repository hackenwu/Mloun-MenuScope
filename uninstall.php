<?php
/**
 * Clean up plugin data on uninstall.
 *
 * @package Admin_Menu_Edit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Everything runs inside a static closure so uninstall.php does not leak
// unprefixed variables into the global scope.
( static function () {
	if ( ! is_multisite() ) {
		delete_option( 'amenuedit_settings' );
		return;
	}

	$sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $sites as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'amenuedit_settings' );
		restore_current_blog();
	}
} )();
