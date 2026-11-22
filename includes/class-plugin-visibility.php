<?php
/**
 * Hides plugins from the Plugins screen per role / user.
 *
 * @package Admin_Menu_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin visibility module.
 */
class AMENEDIT_Plugin_Visibility {

	/**
	 * Nonce actions that change a plugin. Both the singular and the bulk
	 * variants are covered: single actions carry the plugin basename in the
	 * action name, bulk actions are all named "bulk-plugins".
	 *
	 * @var string[]
	 */
	const ACTION_PREFIXES = array(
		'activate-plugin_',
		'deactivate-plugin_',
		'delete-plugin_',
		'upgrade-plugin_',
		'edit-plugin_',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Remove hidden plugins from Plugins -> Installed Plugins.
		add_filter( 'all_plugins', array( $this, 'filter_plugin_list' ), 20 );
		// Block activate/deactivate/delete/update/edit actions on hidden plugins.
		add_action( 'check_admin_referer', array( $this, 'block_plugin_actions' ), 10, 2 );
		// The plugin file editor opens without a nonce, so guard the page itself.
		add_action( 'admin_init', array( $this, 'block_plugin_editor' ), 20 );
	}

	/**
	 * Plugin basenames hidden from the current user.
	 *
	 * @return string[]
	 */
	private function hidden_plugins() {
		$rules = AMENEDIT_Core::instance()->rules_for_current_user();
		return ! empty( $rules['plugins'] ) ? array_keys( $rules['plugins'] ) : array();
	}

	/**
	 * Filter the plugin list.
	 *
	 * @param array $plugins Plugins keyed by basename.
	 * @return array
	 */
	public function filter_plugin_list( $plugins ) {
		foreach ( $this->hidden_plugins() as $basename ) {
			unset( $plugins[ $basename ] );
		}
		return $plugins;
	}

	/**
	 * Block (de)activation and deletion of hidden plugins.
	 *
	 * @param string    $action Nonce action.
	 * @param false|int $result Current result.
	 */
	public function block_plugin_actions( $action, $result = false ) {
		$hidden = $this->hidden_plugins();
		if ( empty( $hidden ) ) {
			return;
		}

		$targets = array();
		foreach ( self::ACTION_PREFIXES as $prefix ) {
			if ( 0 === strpos( (string) $action, $prefix ) ) {
				$targets[] = substr( (string) $action, strlen( $prefix ) );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified by core (check_admin_referer).
		if ( 'bulk-plugins' === $action && ! empty( $_REQUEST['checked'] ) && is_array( $_REQUEST['checked'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$targets = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['checked'] ) );
		}

		if ( empty( $targets ) ) {
			return;
		}
		foreach ( $targets as $plugin ) {
			if ( in_array( $plugin, $hidden, true ) ) {
				wp_die(
					esc_html__( 'Sorry, you are not allowed to manage this plugin.', 'mloun-menroscope'),
					esc_html__( 'Access denied', 'mloun-menroscope'),
					array( 'response' => 403 )
				);
			}
		}
	}

	/**
	 * Block the plugin file editor for hidden plugins.
	 *
	 * plugin-editor.php has no nonce on page load, so the "edit-plugin_" nonce
	 * check only covers saving. Opening the editor for a hidden plugin must be
	 * denied here, otherwise a user with the "edit_plugins" capability could
	 * read and rewrite the source of a plugin they are not supposed to see.
	 */
	public function block_plugin_editor() {
		global $pagenow;

		if ( 'plugin-editor.php' !== $pagenow ) {
			return;
		}

		$hidden = $this->hidden_plugins();
		if ( empty( $hidden ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page guard, no state changed.
		$plugin = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page guard, no state changed.
		$file = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';

		foreach ( array( $plugin, $file ) as $target ) {
			if ( '' === $target || ! in_array( $target, $hidden, true ) ) {
				continue;
			}
			wp_die(
				esc_html__( 'Sorry, you are not allowed to manage this plugin.', 'mloun-menroscope'),
				esc_html__( 'Access denied', 'mloun-menroscope'),
				array( 'response' => 403 )
			);
		}
	}
}
