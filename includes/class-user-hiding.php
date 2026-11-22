<?php
/**
 * Hides selected users from the Users screen and author dropdowns.
 *
 * @package Admin_Menu_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User hiding module.
 */
class AMENEDIT_User_Hiding {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'pre_get_users', array( $this, 'exclude_hidden_users' ) );
		add_filter( 'views_users', array( $this, 'adjust_all_count' ) );
		add_action( 'admin_init', array( $this, 'block_user_edit' ) );
	}

	/**
	 * Hidden user IDs.
	 *
	 * @return int[]
	 */
	private function hidden_ids() {
		$settings = AMENEDIT_Core::instance()->get_settings();
		return array_filter( array_map( 'absint', (array) $settings['hidden_users'] ) );
	}

	/**
	 * Exclude hidden users from user queries (Users screen, author dropdowns,
	 * REST API user lists, frontend author widgets).
	 *
	 * This deliberately runs outside wp-admin as well: REST user endpoints
	 * (/wp-json/wp/v2/users) are served in the front-end context where
	 * is_admin() is false, and without filtering there the hidden users would
	 * be publicly enumerable, defeating the whole feature.
	 *
	 * Our own settings page opts out with the 'ame_include_hidden' argument.
	 *
	 * @param WP_User_Query $query User query.
	 */
	public function exclude_hidden_users( $query ) {
		if ( $query instanceof WP_User_Query && $query->get( 'ame_include_hidden' ) ) {
			return;
		}
		$hidden = $this->hidden_ids();
		if ( empty( $hidden ) ) {
			return;
		}
		$exclude = (array) $query->get( 'exclude' );
		$query->set( 'exclude', array_unique( array_merge( $exclude, $hidden ) ) );
	}

	/**
	 * Subtract hidden users from the "All" count on the Users screen.
	 *
	 * @param array $views View links.
	 * @return array
	 */
	public function adjust_all_count( $views ) {
		$hidden = $this->hidden_ids();
		if ( empty( $hidden ) || empty( $views['all'] ) ) {
			return $views;
		}
		$count         = count( $hidden );
		$views['all'] = preg_replace_callback(
			'/\((\d+)\)/',
			function ( $m ) use ( $count ) {
				return '(' . max( 0, (int) $m[1] - $count ) . ')';
			},
			$views['all']
		);
		return $views;
	}

	/**
	 * Block direct access to a hidden user's profile edit page.
	 */
	public function block_user_edit() {
		global $pagenow;
		if ( 'user-edit.php' !== $pagenow ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		if ( ! $user_id || get_current_user_id() === $user_id ) {
			return;
		}
		if ( in_array( $user_id, $this->hidden_ids(), true ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to access this page.', 'mloun-menroscope'),
				esc_html__( 'Access denied', 'mloun-menroscope'),
				array( 'response' => 403 )
			);
		}
	}
}
