<?php
/**
 * Core: settings storage, actor model, menu snapshot.
 *
 * @package Admin_Menu_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings store and actor resolution.
 *
 * Actor IDs follow the Admin Menu Editor convention:
 *   role:<role_id>   e.g. role:editor
 *   user:<login>     e.g. user:john
 * Priority for merging: user rules first, then roles.
 */
class AMENEDIT_Core {

	const OPTION    = 'amenuedit_settings';
	const CAP       = 'manage_options';
	const PAGE_SLUG = 'admin-menu-edit';

	/**
	 * Singleton instance.
	 *
	 * @var AMENEDIT_Core|null
	 */
	private static $instance = null;

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Original admin menu tree, captured before the applier modifies it.
	 *
	 * @var array|null
	 */
	private static $menu_snapshot = null;

	/**
	 * Get the singleton.
	 *
	 * @return AMENEDIT_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Capture the menu right before the applier (9999) modifies it.
		add_action( 'admin_menu', array( $this, 'snapshot_menu' ), 9998 );
	}

	/**
	 * Get all plugin settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$defaults       = array(
				'actors'       => array(),
				'hidden_users' => array(),
			);
			$this->settings = wp_parse_args( get_option( self::OPTION, array() ), $defaults );
		}
		return $this->settings;
	}

	/**
	 * Persist settings.
	 *
	 * @param array $settings Settings array.
	 */
	public function save_settings( $settings ) {
		$this->settings = $settings;
		update_option( self::OPTION, $settings );
	}

	/**
	 * Get settings for one actor.
	 *
	 * @param string $actor Actor ID, e.g. "role:editor" or "user:john".
	 * @return array
	 */
	public function get_actor_settings( $actor ) {
		$settings = $this->get_settings();
		return isset( $settings['actors'][ $actor ] ) && is_array( $settings['actors'][ $actor ] ) ? $settings['actors'][ $actor ] : array();
	}

	/**
	 * Actor IDs that apply to a user, highest priority first.
	 *
	 * @param WP_User|null $user User object. Defaults to the current user.
	 * @return string[]
	 */
	public function current_actors( $user = null ) {
		if ( ! $user ) {
			$user = wp_get_current_user();
		}
		$actors = array();
		if ( $user && $user->exists() ) {
			$actors[] = 'user:' . $user->user_login;
			foreach ( (array) $user->roles as $role ) {
				$actors[] = 'role:' . $role;
			}
		}
		return $actors;
	}

	/**
	 * Merged rules for the current user.
	 *
	 * Per-section priority: if the user-level actor has a non-empty section,
	 * it completely overrides all role-level sections (user wins). Otherwise
	 * role sections are merged (union; "block" wins over "hide").
	 * Menu order: first non-empty order wins (user rules before role rules).
	 *
	 * @return array{menus: array, plugins: array, renames: array, menu_order: array, submenu_order: array}
	 */
	public function rules_for_current_user() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$settings    = $this->get_settings();
		$actors      = $this->current_actors();
		$user_actor  = ! empty( $actors ) ? $actors[0] : null;
		$role_actors = array_slice( $actors, 1 );

		$user_cfg = ( $user_actor && ! empty( $settings['actors'][ $user_actor ] ) && is_array( $settings['actors'][ $user_actor ] ) )
			? $settings['actors'][ $user_actor ]
			: array();

		$role_list = array();
		foreach ( $role_actors as $ra ) {
			if ( ! empty( $settings['actors'][ $ra ] ) && is_array( $settings['actors'][ $ra ] ) ) {
				$role_list[] = $settings['actors'][ $ra ];
			}
		}

		// --- Menus (hide/block): user section overrides roles entirely. ---
		$menus = array();
		if ( ! empty( $user_cfg['menus'] ) && is_array( $user_cfg['menus'] ) ) {
			$menus = $user_cfg['menus'];
		} else {
			foreach ( $role_list as $cfg ) {
				if ( empty( $cfg['menus'] ) || ! is_array( $cfg['menus'] ) ) {
					continue;
				}
				foreach ( $cfg['menus'] as $slug => $mode ) {
					if ( ! isset( $menus[ $slug ] ) || 'block' === $mode ) {
						$menus[ $slug ] = $mode;
					}
				}
			}
		}

		// --- Plugins: user section overrides roles entirely. ---
		$plugins = array();
		$plugin_source = ( ! empty( $user_cfg['plugins'] ) && is_array( $user_cfg['plugins'] ) )
			? array( $user_cfg )
			: $role_list;
		foreach ( $plugin_source as $cfg ) {
			if ( empty( $cfg['plugins'] ) || ! is_array( $cfg['plugins'] ) ) {
				continue;
			}
			foreach ( array_keys( $cfg['plugins'] ) as $basename ) {
				$plugins[ $basename ] = true;
			}
		}

		// --- Renames: user section overrides roles entirely. ---
		$renames = array();
		if ( ! empty( $user_cfg['renames'] ) && is_array( $user_cfg['renames'] ) ) {
			$renames = $user_cfg['renames'];
		} else {
			foreach ( $role_list as $cfg ) {
				if ( empty( $cfg['renames'] ) || ! is_array( $cfg['renames'] ) ) {
					continue;
				}
				foreach ( $cfg['renames'] as $key => $label ) {
					if ( ! isset( $renames[ $key ] ) ) {
						$renames[ $key ] = $label;
					}
				}
			}
		}

		// --- Menu order: first non-empty wins (user first, then roles). ---
		$menu_order    = array();
		$submenu_order = array();
		foreach ( array_merge( array( $user_cfg ), $role_list ) as $cfg ) {
			if ( empty( $cfg ) ) {
				continue;
			}
			if ( empty( $menu_order ) && ! empty( $cfg['menu_order'] ) && is_array( $cfg['menu_order'] ) ) {
				$menu_order = $cfg['menu_order'];
			}
			if ( ! empty( $cfg['submenu_order'] ) && is_array( $cfg['submenu_order'] ) ) {
				// Existing entries (from a higher-priority actor) win.
				$submenu_order = array_merge( $cfg['submenu_order'], $submenu_order );
			}
		}

		// Safety net: an administrator must never be able to lock themselves
		// (and every other administrator) out of the admin. A "block" on any of
		// these infrastructure menus is downgraded to "hide", so the page stays
		// reachable by URL and the rule can always be undone.
		//
		// "options-general.php" is critical: this plugin's own settings page
		// lives under Settings, so hard-blocking the parent would 403 the page
		// that is needed to undo the rule.
		if ( current_user_can( self::CAP ) ) {
			$protected = array(
				self::PAGE_SLUG,
				'options-general.php|' . self::PAGE_SLUG,
				'options-general.php',
				'plugins.php',
				'index.php',
				'admin.php',
				'profile.php',
				'users.php',
			);
			foreach ( $protected as $k ) {
				if ( isset( $menus[ $k ] ) && 'block' === $menus[ $k ] ) {
					$menus[ $k ] = 'hide';
				}
			}
		}

		$cache = array(
			'menus'         => $menus,
			'plugins'       => $plugins,
			'renames'       => $renames,
			'menu_order'    => $menu_order,
			'submenu_order' => $submenu_order,
		);
		return $cache;
	}

	/**
	 * Capture the original admin menu tree (without our modifications).
	 * Runs at admin_menu priority 9998, after all menus are registered.
	 */
	public function snapshot_menu() {
		global $menu, $submenu;

		$tree = array();
		foreach ( (array) $menu as $item ) {
			if ( ! is_array( $item ) || empty( $item[2] ) ) {
				continue;
			}
			// Skip separators.
			if ( isset( $item[4] ) && false !== strpos( (string) $item[4], 'wp-menu-separator' ) ) {
				continue;
			}
			$slug     = $item[2];
			$children = array();
			if ( ! empty( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
				foreach ( $submenu[ $slug ] as $sub ) {
					if ( ! is_array( $sub ) || ! isset( $sub[2] ) ) {
						continue;
					}
					$children[] = array(
						'slug'  => $sub[2],
						'title' => wp_strip_all_tags( $sub[0] ),
						'cap'   => isset( $sub[1] ) ? $sub[1] : '',
					);
				}
			}
			$tree[] = array(
				'slug'     => $slug,
				'title'    => wp_strip_all_tags( $item[0] ),
				'cap'      => isset( $item[1] ) ? $item[1] : '',
				'children' => $children,
			);
		}
		self::$menu_snapshot = $tree;
	}

	/**
	 * Get the captured menu tree.
	 *
	 * @return array|null
	 */
	public function get_menu_snapshot() {
		return self::$menu_snapshot;
	}

	/**
	 * Reorder a snapshot tree according to a saved order.
	 *
	 * @param array $tree  Menu tree snapshot.
	 * @param array $order Saved top-level slug order.
	 * @param array $submenu_order Saved submenu orders keyed by parent slug.
	 * @return array
	 */
	public static function sort_tree_by_saved_order( $tree, $order, $submenu_order = array() ) {
		if ( ! empty( $order ) ) {
			$position = array_flip( $order );
			usort(
				$tree,
				function ( $a, $b ) use ( $position ) {
					$pa = isset( $position[ $a['slug'] ] ) ? $position[ $a['slug'] ] : 10000;
					$pb = isset( $position[ $b['slug'] ] ) ? $position[ $b['slug'] ] : 10000;
					if ( $pa === $pb ) {
						return 0;
					}
					return $pa < $pb ? -1 : 1;
				}
			);
		}
		foreach ( $tree as $i => $item ) {
			$parent = $item['slug'];
			if ( ! empty( $submenu_order[ $parent ] ) && ! empty( $item['children'] ) ) {
				$position = array_flip( $submenu_order[ $parent ] );
				$children = $item['children'];
				usort(
					$children,
					function ( $a, $b ) use ( $position ) {
						$pa = isset( $position[ $a['slug'] ] ) ? $position[ $a['slug'] ] : 10000;
						$pb = isset( $position[ $b['slug'] ] ) ? $position[ $b['slug'] ] : 10000;
						if ( $pa === $pb ) {
							return 0;
						}
						return $pa < $pb ? -1 : 1;
					}
				);
				$tree[ $i ]['children'] = $children;
			}
		}
		return $tree;
	}

	/**
	 * Human-readable label for an actor ID.
	 *
	 * @param string $actor Actor ID.
	 * @return string
	 */
	public static function actor_label( $actor ) {
		if ( 0 === strpos( $actor, 'role:' ) ) {
			$role_id = substr( $actor, 5 );
			$roles   = wp_roles()->roles;
			if ( isset( $roles[ $role_id ] ) ) {
				/* translators: %s: role name. */
				return sprintf( __( 'Role: %s', 'mloun-menroscope'), translate_user_role( $roles[ $role_id ]['name'] ) );
			}
			/* translators: %s: role ID. */
			return sprintf( __( 'Role: %s (missing)', 'mloun-menroscope'), $role_id );
		}
		if ( 0 === strpos( $actor, 'user:' ) ) {
			$login = substr( $actor, 5 );
			$user  = get_user_by( 'login', $login );
			if ( $user ) {
				/* translators: %s: user login. */
				return sprintf( __( 'User: %s', 'mloun-menroscope'), $user->user_login );
			}
			/* translators: %s: user login. */
			return sprintf( __( 'User: %s (missing)', 'mloun-menroscope'), $login );
		}
		return $actor;
	}

	/**
	 * Validate an actor ID format.
	 *
	 * @param string $actor Actor ID candidate.
	 * @return bool
	 */
	public static function is_valid_actor( $actor ) {
		return is_string( $actor ) && (bool) preg_match( '/^(role:[a-z0-9_\-]+|user:[^\s]+)$/', $actor );
	}
}
