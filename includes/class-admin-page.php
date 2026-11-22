<?php
/**
 * Settings page: Menus, Plugins and Users tabs.
 *
 * @package Admin_Menu_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page.
 */
class AMENEDIT_Admin_Page {

	const NONCE_ACTION = 'amenuedit_save';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AMENEDIT_DIR . 'mloun-menroscope.php' ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Add a quick "Settings" link under the plugin name on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$url = admin_url( 'options-general.php?page=' . AMENEDIT_Core::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'mloun-menroscope' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the settings page under Settings.
	 */
	public function add_menu() {
		add_options_page(
			esc_html__( 'MenuScope', 'mloun-menroscope'),
			esc_html__( 'MenuScope', 'mloun-menroscope'),
			AMENEDIT_Core::CAP,
			AMENEDIT_Core::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue assets on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'settings_page_' . AMENEDIT_Core::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'amenuedit-admin', AMENEDIT_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), AMENEDIT_VERSION, true );
		wp_enqueue_style( 'amenuedit-admin', AMENEDIT_URL . 'assets/css/admin.css', array(), AMENEDIT_VERSION );
		wp_localize_script(
			'amenuedit-admin',
			'ameAdmin',
			array(
				'strings' => array(
					'confirmDeleteActor' => __( 'Delete all saved settings for this role/user?', 'mloun-menroscope'),
				),
			)
		);
	}

	/**
	 * Handle all form submissions.
	 */
	public function handle_save() {
		if ( empty( $_POST['amenuedit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['amenuedit_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( AMENEDIT_Core::CAP ) ) {
			return;
		}

		$action   = isset( $_POST['amenuedit_action'] ) ? sanitize_key( wp_unslash( $_POST['amenuedit_action'] ) ) : '';
		$core     = AMENEDIT_Core::instance();
		$settings = $core->get_settings();
		$tab      = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'menus';
		$actor    = isset( $_POST['actor'] ) ? sanitize_text_field( wp_unslash( $_POST['actor'] ) ) : '';

		switch ( $action ) {
			case 'save_actor':
				if ( AMENEDIT_Core::is_valid_actor( $actor ) ) {
					$sections = isset( $_POST['sections'] ) ? sanitize_key( wp_unslash( $_POST['sections'] ) ) : '';
					$existing = isset( $settings['actors'][ $actor ] ) && is_array( $settings['actors'][ $actor ] ) ? $settings['actors'][ $actor ] : array();

					if ( 'menus' === $sections ) {
						$existing['menus']         = $this->sanitize_menus_input();
						$existing['menu_order']    = $this->sanitize_slug_list_input( 'menu_order' );
						$existing['submenu_order'] = $this->sanitize_submenu_order_input();
						$existing['renames']       = $this->sanitize_rename_input();
					} elseif ( 'plugins' === $sections ) {
						$existing['plugins'] = $this->sanitize_plugins_input();
					}

					$settings['actors'][ $actor ] = $existing;
					$core->save_settings( $settings );
				}
				break;

			case 'delete_actor':
				if ( AMENEDIT_Core::is_valid_actor( $actor ) && isset( $settings['actors'][ $actor ] ) ) {
					unset( $settings['actors'][ $actor ] );
					$core->save_settings( $settings );
					$actor = '';
				}
				break;

			case 'save_hidden_users':
				$ids = array();
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below with absint.
				if ( ! empty( $_POST['hidden_users'] ) && is_array( $_POST['hidden_users'] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
					$ids = array_map( 'absint', wp_unslash( $_POST['hidden_users'] ) );
				}
				$settings['hidden_users'] = array_values( array_filter( $ids ) );
				$core->save_settings( $settings );
				break;
		}

		$args = array(
			'page'    => AMENEDIT_Core::PAGE_SLUG,
			'tab'     => $tab,
			'updated' => 1,
		);
		if ( $actor && in_array( $tab, array( 'menus', 'plugins' ), true ) ) {
			$args['actor'] = $actor;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Sanitize the per-menu hide/block checkboxes.
	 * Submenu items arrive with the composite key "parent_slug|submenu_slug".
	 *
	 * @return array key => 'hide'|'block'
	 */
	private function sanitize_menus_input() {
		$menus = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
		$hide  = ! empty( $_POST['menus'] ) && is_array( $_POST['menus'] ) ? wp_unslash( $_POST['menus'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
		$block = ! empty( $_POST['block'] ) && is_array( $_POST['block'] ) ? wp_unslash( $_POST['block'] ) : array();

		foreach ( (array) $hide as $key => $v ) {
			$menus[ sanitize_text_field( $key ) ] = 'hide';
		}
		foreach ( (array) $block as $key => $v ) {
			$menus[ sanitize_text_field( $key ) ] = 'block';
		}
		return $menus;
	}

	/**
	 * Sanitize a flat slug list from POST.
	 *
	 * @param string $field POST field name.
	 * @return string[]
	 */
	private function sanitize_slug_list_input( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
		if ( empty( $_POST[ $field ] ) || ! is_array( $_POST[ $field ] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
		return array_map( 'sanitize_text_field', wp_unslash( $_POST[ $field ] ) );
	}

	/**
	 * Sanitize the submenu order input: submenu_order[parent][] = slug.
	 *
	 * @return array
	 */
	private function sanitize_submenu_order_input() {
		$out = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
		if ( empty( $_POST['submenu_order'] ) || ! is_array( $_POST['submenu_order'] ) ) {
			return $out;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
		foreach ( wp_unslash( $_POST['submenu_order'] ) as $parent => $slugs ) {
			if ( ! is_array( $slugs ) ) {
				continue;
			}
			$out[ sanitize_text_field( $parent ) ] = array_map( 'sanitize_text_field', $slugs );
		}
		return $out;
	}

	/**
	 * Sanitize the plugin visibility checkboxes.
	 *
	 * @return array basename => true
	 */
	private function sanitize_plugins_input() {
		$plugins = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
		if ( ! empty( $_POST['plugins'] ) && is_array( $_POST['plugins'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
			foreach ( wp_unslash( $_POST['plugins'] ) as $basename => $v ) {
				$plugins[ sanitize_text_field( $basename ) ] = true;
			}
		}
		return $plugins;
	}

	/**
	 * Sanitize the rename input: renames[key] = label.
	 *
	 * @return array
	 */
	private function sanitize_rename_input() {
		$rename = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
		if ( ! empty( $_POST['renames'] ) && is_array( $_POST['renames'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_save(); sanitized below.
			foreach ( wp_unslash( $_POST['renames'] ) as $key => $label ) {
				$label = sanitize_text_field( $label );
				if ( '' !== $label ) {
					$rename[ sanitize_text_field( $key ) ] = $label;
				}
			}
		}
		return $rename;
	}

	/**
	 * Check whether an actor has a capability (used to show the real menu
	 * tree that the selected role/user would actually see).
	 *
	 * @param string $actor Actor ID.
	 * @param string $cap   Required capability.
	 * @return bool
	 */
	private function actor_has_cap( $actor, $cap ) {
		if ( empty( $cap ) ) {
			return true;
		}
		if ( 0 === strpos( $actor, 'role:' ) ) {
			$role_id = substr( $actor, 5 );
			$roles   = wp_roles()->roles;
			return ! empty( $roles[ $role_id ]['capabilities'][ $cap ] );
		}
		if ( 0 === strpos( $actor, 'user:' ) ) {
			$user = get_user_by( 'login', substr( $actor, 5 ) );
			return $user && $user->has_cap( $cap );
		}
		return true;
	}

	/**
	 * Filter a menu tree to what the actor's role/caps would actually show.
	 *
	 * @param array  $tree  Menu tree snapshot.
	 * @param string $actor Actor ID.
	 * @return array
	 */
	private function filter_tree_for_actor( $tree, $actor ) {
		$out = array();
		foreach ( (array) $tree as $item ) {
			if ( ! empty( $item['cap'] ) && ! $this->actor_has_cap( $actor, $item['cap'] ) ) {
				continue;
			}
			$children = array();
			foreach ( (array) $item['children'] as $child ) {
				if ( ! empty( $child['cap'] ) && ! $this->actor_has_cap( $actor, $child['cap'] ) ) {
					continue;
				}
				$children[] = $child;
			}
			$item['children'] = $children;
			$out[]            = $item;
		}
		return $out;
	}

	/**
	 * Render the settings page.
	 */
	public function render() {
		if ( ! current_user_can( AMENEDIT_Core::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'mloun-menroscope') );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display state.
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'menus';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display state.
		$actor = isset( $_GET['actor'] ) ? sanitize_text_field( wp_unslash( $_GET['actor'] ) ) : '';
		if ( $actor && ! AMENEDIT_Core::is_valid_actor( $actor ) ) {
			$actor = '';
		}

		$tabs = array(
			'menus'   => __( 'Menus', 'mloun-menroscope'),
			'plugins' => __( 'Plugins', 'mloun-menroscope'),
			'users'   => __( 'Users', 'mloun-menroscope'),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'menus';
		}

		echo '<div class="wrap amenuedit-wrap">';
		echo '<div class="ame-page-header">';
		echo '<h1><span class="dashicons dashicons-list-view"></span> ' . esc_html__( 'Mloun MenuScope', 'mloun-menroscope');
		echo ' <span class="ame-version">v' . esc_html( AMENEDIT_VERSION ) . '</span></h1>';
		echo '</div>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display state.
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'mloun-menroscope') . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = admin_url( 'options-general.php?page=' . AMENEDIT_Core::PAGE_SLUG . '&tab=' . $slug );
			$class = $slug === $tab ? ' nav-tab nav-tab-active' : ' nav-tab';
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( trim( $class ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		$show_sidebar = ( 'users' === $tab ) || ( in_array( $tab, array( 'menus', 'plugins' ), true ) && $actor );

		echo '<div class="ame-layout">';
		echo '<div class="ame-main">';

		switch ( $tab ) {
			case 'menus':
				$this->render_menus_tab( $actor );
				break;
			case 'plugins':
				$this->render_plugins_tab( $actor );
				break;
			case 'users':
				$this->render_users_tab();
				break;
		}
		echo '</div>'; // .ame-main

		if ( $show_sidebar ) {
			$this->render_sidebar( $tab );
		}

		echo '</div>'; // .ame-layout
		echo '</div>'; // .wrap
	}

	/**
	 * Render the right sidebar: sticky save card + short how-to card.
	 *
	 * @param string $tab Current tab.
	 */
	private function render_sidebar( $tab ) {
		$steps = array();
		if ( 'menus' === $tab ) {
			$steps = array(
				__( 'Choose a role or user in the "Configure for" box.', 'mloun-menroscope'),
				__( 'Toggle "Hide" or "Block" for menus, or type a custom name to rename them.', 'mloun-menroscope'),
				__( 'Drag menu items to reorder them. Click the arrow to expand submenus.', 'mloun-menroscope'),
				__( 'Click "Save" to apply the settings.', 'mloun-menroscope'),
			);
		} elseif ( 'plugins' === $tab ) {
			$steps = array(
				__( 'Choose a role or user in the "Configure for" box.', 'mloun-menroscope'),
				__( 'Toggle the plugins that should be hidden from them.', 'mloun-menroscope'),
				__( 'Click "Save" to apply the settings.', 'mloun-menroscope'),
			);
		} elseif ( 'users' === $tab ) {
			$steps = array(
				__( 'Toggle the users that should be invisible to everyone else.', 'mloun-menroscope'),
				__( 'Click "Save" to apply the settings.', 'mloun-menroscope'),
			);
		}

		echo '<aside class="ame-sidebar">';

		// Save card.
		echo '<div class="ame-save-card">';
		echo '<button type="submit" form="amenuedit-form" class="button button-primary button-hero ame-save-btn">';
		echo esc_html__( 'Save', 'mloun-menroscope');
		echo '</button>';
		echo '<p class="ame-save-note">' . esc_html__( 'Settings apply immediately after saving.', 'mloun-menroscope') . '</p>';
		echo '</div>';

		// How-to card.
		if ( ! empty( $steps ) ) {
			echo '<div class="ame-help-card">';
			echo '<h3><span class="dashicons dashicons-lightbulb"></span> ' . esc_html__( 'How to use', 'mloun-menroscope') . '</h3>';
			echo '<ol>';
			foreach ( $steps as $step ) {
				echo '<li>' . esc_html( $step ) . '</li>';
			}
			echo '</ol>';
			echo '</div>';
		}

		echo '</aside>';
	}

	/**
	 * Render the actor selector used by the Menus and Plugins tabs.
	 *
	 * @param string $tab   Current tab.
	 * @param string $actor Current actor ID.
	 */
	private function render_actor_bar( $tab, $actor ) {
		$core     = AMENEDIT_Core::instance();
		$settings = $core->get_settings();
		$base     = admin_url( 'options-general.php?page=' . AMENEDIT_Core::PAGE_SLUG . '&tab=' . $tab . '&actor=' );

		echo '<div class="amenuedit-actor-bar">';
		echo '<div class="ame-actor-bar-title"><span class="dashicons dashicons-admin-users"></span> ' . esc_html__( 'Configure for', 'mloun-menroscope') . '</div>';

		if ( $actor ) {
			echo '<p class="amenuedit-current-actor">' . esc_html__( 'Currently editing:', 'mloun-menroscope') . ' <strong>' . esc_html( AMENEDIT_Core::actor_label( $actor ) ) . '</strong></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Choose a role or a user below to edit their settings.', 'mloun-menroscope') . '</p>';
		}

		// Configured actors.
		if ( ! empty( $settings['actors'] ) ) {
			echo '<ul class="amenuedit-actor-list">';
			foreach ( array_keys( $settings['actors'] ) as $a ) {
				echo '<li';
				if ( $a === $actor ) {
					echo ' class="current"';
				}
				echo '>';
				echo '<a href="' . esc_url( $base . rawurlencode( $a ) ) . '">' . esc_html( AMENEDIT_Core::actor_label( $a ) ) . '</a> ';
				echo '<form method="post" class="amenuedit-delete-actor">';
				wp_nonce_field( self::NONCE_ACTION, 'amenuedit_nonce' );
				echo '<input type="hidden" name="amenuedit_action" value="delete_actor">';
				echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
				echo '<input type="hidden" name="actor" value="' . esc_attr( $a ) . '">';
				echo '<button type="submit" class="button-link delete">' . esc_html__( 'Delete', 'mloun-menroscope') . '</button>';
				echo '</form>';
				echo '</li>';
			}
			echo '</ul>';
		}

		// Add a role.
		echo '<div class="amenuedit-add-actor">';
		echo '<label for="amenuedit-role-select">' . esc_html__( 'Role:', 'mloun-menroscope') . '</label>';
		echo '<select id="amenuedit-role-select">';
		echo '<option value="">' . esc_html__( '&mdash; Select role &mdash;', 'mloun-menroscope') . '</option>';
		foreach ( wp_roles()->roles as $role_id => $role ) {
			echo '<option value="' . esc_attr( 'role:' . $role_id ) . '">' . esc_html( translate_user_role( $role['name'] ) ) . '</option>';
		}
		echo '</select>';

		// Add a user (dropdown).
		$users = get_users(
			array(
				'number'             => 2000,
				'orderby'            => 'login',
				'order'              => 'ASC',
				'ame_include_hidden' => true,
			)
		);
		echo '<label for="amenuedit-user-select">' . esc_html__( 'User:', 'mloun-menroscope') . '</label>';
		echo '<select id="amenuedit-user-select">';
		echo '<option value="">' . esc_html__( '&mdash; Select user &mdash;', 'mloun-menroscope') . '</option>';
		foreach ( $users as $u ) {
			echo '<option value="' . esc_attr( 'user:' . $u->user_login ) . '">' . esc_html( $u->user_login . ' (' . $u->display_name . ')' ) . '</option>';
		}
		echo '</select>';
		echo '<input type="hidden" id="amenuedit-actor-base-url" value="' . esc_attr( $base ) . '">';
		echo '</div>';

		echo '<p class="description ame-priority-note">' . esc_html__( 'Tip: when both a role and a user have settings, the user settings take priority and completely override the role settings.', 'mloun-menroscope') . '</p>';
		echo '</div>';
	}

	/**
	 * Render one menu row (top-level or submenu item).
	 *
	 * @param array  $item            Item with slug/title.
	 * @param string $key             Rule key (slug for top-level, "parent|slug" for children).
	 * @param array  $rules           Saved rules: key => 'hide'|'block'.
	 * @param array  $renames         Saved renames: key => label.
	 * @param string $order_input_name Name of the hidden order input.
	 * @param bool   $has_children    Whether this row owns a collapsible submenu.
	 * @param int    $hidden_children Number of hidden/blocked children (badge).
	 */
	private function render_menu_row( $item, $key, $rules, $renames, $order_input_name, $has_children = false, $hidden_children = 0 ) {
		$mode      = isset( $rules[ $key ] ) ? $rules[ $key ] : '';
		$row_class = '';
		if ( 'hide' === $mode ) {
			$row_class = ' ame-is-hidden';
		} elseif ( 'block' === $mode ) {
			$row_class = ' ame-is-blocked';
		}

		echo '<div class="ame-item-row' . esc_attr( $row_class ) . '">';
		echo '<span class="ame-drag-handle dashicons dashicons-menu" title="' . esc_attr__( 'Drag to reorder', 'mloun-menroscope') . '"></span>';

		if ( $has_children ) {
			echo '<button type="button" class="ame-toggle" title="' . esc_attr__( 'Expand / collapse submenu', 'mloun-menroscope') . '">';
			echo '<span class="dashicons dashicons-arrow-right-alt2"></span>';
			if ( $hidden_children > 0 ) {
				echo '<span class="ame-hidden-badge">' . (int) $hidden_children . '</span>';
			}
			echo '</button>';
		} else {
			echo '<span class="ame-toggle-spacer"></span>';
		}

		echo '<input type="hidden" name="' . esc_attr( $order_input_name ) . '" value="' . esc_attr( $item['slug'] ) . '">';
		echo '<span class="ame-item-title">' . esc_html( $item['title'] ) . '</span>';

		echo '<input type="text" class="ame-rename-input" name="renames[' . esc_attr( $key ) . ']" value="' . esc_attr( isset( $renames[ $key ] ) ? $renames[ $key ] : '' ) . '" placeholder="' . esc_attr__( 'Custom name', 'mloun-menroscope') . '">';

		echo '<label class="ame-switch ame-sw-hide" title="' . esc_attr__( 'Hide (page stays reachable)', 'mloun-menroscope') . '">';
		echo '<input type="checkbox" class="ame-hide-cb" name="menus[' . esc_attr( $key ) . ']" value="1"' . checked( '' !== $mode, true, false ) . '><span class="ame-slider"></span>';
		echo '<span class="ame-sw-label">' . esc_html__( 'Hide', 'mloun-menroscope') . '</span></label>';

		echo '<label class="ame-switch ame-sw-block" title="' . esc_attr__( 'Hide & block access (403)', 'mloun-menroscope') . '">';
		echo '<input type="checkbox" class="ame-block-cb" name="block[' . esc_attr( $key ) . ']" value="1"' . checked( 'block', $mode, false ) . '><span class="ame-slider"></span>';
		echo '<span class="ame-sw-label">' . esc_html__( 'Block', 'mloun-menroscope') . '</span></label>';

		echo '</div>';
	}

	/**
	 * Render the Menus tab.
	 *
	 * @param string $actor Current actor ID.
	 */
	private function render_menus_tab( $actor ) {
		$this->render_actor_bar( 'menus', $actor );
		if ( ! $actor ) {
			return;
		}

		$core           = AMENEDIT_Core::instance();
		$tree           = $core->get_menu_snapshot();
		$actor_settings = $core->get_actor_settings( $actor );
		$rules          = isset( $actor_settings['menus'] ) ? $actor_settings['menus'] : array();
		$renames        = isset( $actor_settings['renames'] ) ? $actor_settings['renames'] : array();
		$menu_order     = isset( $actor_settings['menu_order'] ) ? $actor_settings['menu_order'] : array();
		$submenu_order  = isset( $actor_settings['submenu_order'] ) ? $actor_settings['submenu_order'] : array();

		if ( empty( $tree ) ) {
			echo '<p>' . esc_html__( 'Menu tree is not available yet. Please reload this page.', 'mloun-menroscope') . '</p>';
			return;
		}

		// Show only the menus this role/user would actually see in wp-admin.
		$tree = $this->filter_tree_for_actor( $tree, $actor );
		$tree = AMENEDIT_Core::sort_tree_by_saved_order( $tree, $menu_order, $submenu_order );

		if ( empty( $tree ) ) {
			echo '<p>' . esc_html__( 'This role/user has no accessible admin menus.', 'mloun-menroscope') . '</p>';
			return;
		}

		echo '<form method="post" class="amenuedit-form" id="amenuedit-form">';
		wp_nonce_field( self::NONCE_ACTION, 'amenuedit_nonce' );
		echo '<input type="hidden" name="amenuedit_action" value="save_actor">';
		echo '<input type="hidden" name="sections" value="menus">';
		echo '<input type="hidden" name="tab" value="menus">';
		echo '<input type="hidden" name="actor" value="' . esc_attr( $actor ) . '">';

		echo '<div class="ame-card">';
		echo '<div class="ame-tree-header">';
		echo '<span class="ame-col-drag"></span>';
		echo '<span class="ame-col-toggle"></span>';
		echo '<span class="ame-col-title">' . esc_html__( 'Menu', 'mloun-menroscope') . '</span>';
		echo '<span class="ame-col-rename">' . esc_html__( 'Custom name', 'mloun-menroscope') . '</span>';
		echo '<span class="ame-col-cb">' . esc_html__( 'Hide', 'mloun-menroscope') . '</span>';
		echo '<span class="ame-col-cb">' . esc_html__( 'Block', 'mloun-menroscope') . '</span>';
		echo '</div>';

		echo '<ul id="amenuedit-menu-tree">';
		foreach ( $tree as $item ) {
			$has_children    = ! empty( $item['children'] );
			$hidden_children = 0;
			if ( $has_children ) {
				foreach ( $item['children'] as $child ) {
					if ( isset( $rules[ $item['slug'] . '|' . $child['slug'] ] ) ) {
						$hidden_children++;
					}
				}
			}

			echo '<li class="ame-menu-item" data-slug="' . esc_attr( $item['slug'] ) . '">';
			$this->render_menu_row( $item, $item['slug'], $rules, $renames, 'menu_order[]', $has_children, $hidden_children );

			if ( $has_children ) {
				echo '<ul class="ame-submenu ame-collapsed" data-parent="' . esc_attr( $item['slug'] ) . '">';
				foreach ( $item['children'] as $child ) {
					echo '<li class="ame-sub-item" data-slug="' . esc_attr( $child['slug'] ) . '">';
					$this->render_menu_row( $child, $item['slug'] . '|' . $child['slug'], $rules, $renames, 'submenu_order[' . $item['slug'] . '][]' );
					echo '</li>';
				}
				echo '</ul>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';

		echo '<p class="description">' . esc_html__( 'Drag items to reorder the menu. "Hide" removes the menu but keeps the URL accessible. "Block" also blocks direct URL access with a 403 error. "Custom name" renames the menu for this role/user only.', 'mloun-menroscope') . '</p>';

		echo '</form>';
	}

	/**
	 * Render the Plugins tab.
	 *
	 * @param string $actor Current actor ID.
	 */
	private function render_plugins_tab( $actor ) {
		$this->render_actor_bar( 'plugins', $actor );
		if ( ! $actor ) {
			return;
		}

		// The Plugins screen requires the "activate_plugins" capability.
		// Roles/users without it never see the plugin list at all, so
		// visibility settings would have no effect for them.
		if ( ! $this->actor_has_cap( $actor, 'activate_plugins' ) ) {
			echo '<div class="ame-card ame-notice-card">';
			echo '<span class="dashicons dashicons-info-outline"></span> ';
			echo esc_html__( 'This role/user cannot access the Plugins screen (missing the "activate_plugins" capability), so plugin visibility settings have no effect for them.', 'mloun-menroscope');
			echo '</div>';
			return;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$core           = AMENEDIT_Core::instance();
		$actor_settings = $core->get_actor_settings( $actor );
		$hidden         = isset( $actor_settings['plugins'] ) ? $actor_settings['plugins'] : array();
		$all_plugins    = get_plugins();
		$self_basename  = plugin_basename( AMENEDIT_DIR . 'mloun-menroscope.php' );

		echo '<form method="post" class="amenuedit-form" id="amenuedit-form">';
		wp_nonce_field( self::NONCE_ACTION, 'amenuedit_nonce' );
		echo '<input type="hidden" name="amenuedit_action" value="save_actor">';
		echo '<input type="hidden" name="sections" value="plugins">';
		echo '<input type="hidden" name="tab" value="plugins">';
		echo '<input type="hidden" name="actor" value="' . esc_attr( $actor ) . '">';

		echo '<div class="ame-card">';
		echo '<p class="description ame-card-desc">' . esc_html__( 'Checked plugins are hidden from the Plugins screen for this role/user. Hidden plugins stay active but cannot be deactivated, deleted or updated by them. You can also hide Mloun MenuScope itself.', 'mloun-menroscope') . '</p>';

		echo '<table class="widefat striped ame-plugins-table">';
		echo '<thead><tr><th>' . esc_html__( 'Plugin', 'mloun-menroscope') . '</th><th class="ame-th-switch">' . esc_html__( 'Hidden', 'mloun-menroscope') . '</th></tr></thead><tbody>';
		foreach ( $all_plugins as $basename => $data ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $data['Name'] ) . '</strong> <code>' . esc_html( $basename ) . '</code>';
			if ( $self_basename === $basename ) {
				echo ' <span class="ame-self-tag">' . esc_html__( '(this plugin)', 'mloun-menroscope') . '</span>';
			}
			echo '</td>';
			echo '<td><label class="ame-switch"><input type="checkbox" name="plugins[' . esc_attr( $basename ) . ']" value="1"' . checked( isset( $hidden[ $basename ] ), true, false ) . '><span class="ame-slider"></span></label></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		echo '</form>';
	}

	/**
	 * Render the Users tab (hide selected users from everyone else).
	 */
	private function render_users_tab() {
		$settings   = AMENEDIT_Core::instance()->get_settings();
		$hidden     = array_map( 'absint', (array) $settings['hidden_users'] );
		$current_id = get_current_user_id();
		$users      = get_users(
			array(
				'number'             => 2000,
				'orderby'            => 'login',
				'order'              => 'ASC',
				'ame_include_hidden' => true,
			)
		);

		echo '<form method="post" class="amenuedit-form" id="amenuedit-form">';
		wp_nonce_field( self::NONCE_ACTION, 'amenuedit_nonce' );
		echo '<input type="hidden" name="amenuedit_action" value="save_hidden_users">';
		echo '<input type="hidden" name="tab" value="users">';

		echo '<div class="ame-card">';
		echo '<p class="description ame-card-desc">' . esc_html__( 'Checked users are invisible to everyone else: they disappear from the Users screen, author dropdowns and their profile cannot be opened directly. They can still log in and work normally. You can hide yourself as well.', 'mloun-menroscope') . '</p>';

		echo '<table class="widefat striped ame-users-table">';
		echo '<thead><tr><th>' . esc_html__( 'User', 'mloun-menroscope') . '</th><th>' . esc_html__( 'Role', 'mloun-menroscope') . '</th><th class="ame-th-switch">' . esc_html__( 'Hidden from others', 'mloun-menroscope') . '</th></tr></thead><tbody>';
		foreach ( $users as $user ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $user->user_login ) . '</strong> (' . esc_html( $user->display_name ) . ')';
			if ( $user->ID === $current_id ) {
				echo ' <span class="ame-self-tag">' . esc_html__( '(you)', 'mloun-menroscope') . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( implode( ', ', (array) $user->roles ) ) . '</td>';
			echo '<td><label class="ame-switch"><input type="checkbox" name="hidden_users[]" value="' . esc_attr( $user->ID ) . '"' . checked( in_array( $user->ID, $hidden, true ), true, false ) . '><span class="ame-slider"></span></label></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		echo '</form>';
	}
}
