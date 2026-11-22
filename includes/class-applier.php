<?php
/**
 * Applies menu rename, hiding, blocking and reordering in wp-admin.
 *
 * @package Admin_Menu_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu applier.
 */
class AMENEDIT_Applier {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Runs right after the core snapshot (9998).
		add_action( 'admin_menu', array( $this, 'apply' ), 9999 );
		add_action( 'admin_init', array( $this, 'block_disallowed_pages' ), 1 );
	}

	/**
	 * Rename, hide and reorder the admin menu.
	 */
	public function apply() {
		global $menu, $submenu;

		if ( ! is_array( $menu ) ) {
			return;
		}

		$core  = AMENEDIT_Core::instance();
		$rules = $core->rules_for_current_user();

		// 1. Menu rename (per role / user, merged for the current user).
		if ( ! empty( $rules['renames'] ) ) {
			foreach ( $menu as $i => $item ) {
				if ( ! is_array( $item ) || empty( $item[2] ) ) {
					continue;
				}
				if ( isset( $rules['renames'][ $item[2] ] ) ) {
					$menu[ $i ][0] = $rules['renames'][ $item[2] ];
				}
			}
			foreach ( (array) $submenu as $parent => $subs ) {
				if ( ! is_array( $subs ) ) {
					continue;
				}
				foreach ( $subs as $j => $sub ) {
					if ( ! is_array( $sub ) || ! isset( $sub[2] ) ) {
						continue;
					}
					$key = $parent . '|' . $sub[2];
					if ( isset( $rules['renames'][ $key ] ) ) {
						$submenu[ $parent ][ $j ][0] = $rules['renames'][ $key ];
					}
				}
			}
		}

		// 2. Hide menus for the current user (both "hide" and "block" remove the menu).
		//    Submenu rules use the composite key "parent_slug|submenu_slug" so that a
		//    hidden child never hides its parent (they often share the same slug).
		if ( ! empty( $rules['menus'] ) ) {
			foreach ( $menu as $i => $item ) {
				if ( ! is_array( $item ) || empty( $item[2] ) ) {
					continue;
				}
				if ( isset( $rules['menus'][ $item[2] ] ) ) {
					unset( $menu[ $i ] );
					unset( $submenu[ $item[2] ] );
				}
			}
			foreach ( (array) $submenu as $parent => $subs ) {
				if ( ! is_array( $subs ) ) {
					continue;
				}
				foreach ( $subs as $j => $sub ) {
					if ( ! is_array( $sub ) || ! isset( $sub[2] ) ) {
						continue;
					}
					if ( isset( $rules['menus'][ $parent . '|' . $sub[2] ] ) ) {
						unset( $submenu[ $parent ][ $j ] );
					}
				}
			}
		}

		// 3. Custom menu order.
		if ( ! empty( $rules['menu_order'] ) ) {
			$menu = $this->reorder_menu( $menu, $rules['menu_order'] );
		}
		if ( ! empty( $rules['submenu_order'] ) ) {
			foreach ( $rules['submenu_order'] as $parent => $order ) {
				if ( ! empty( $submenu[ $parent ] ) && is_array( $submenu[ $parent ] ) ) {
					$submenu[ $parent ] = $this->reorder_submenu( $submenu[ $parent ], $order );
				}
			}
		}
	}

	/**
	 * Reorder the top-level menu, keeping separators at their relative slots.
	 *
	 * @param array $menu  Global $menu array.
	 * @param array $order Saved slug order.
	 * @return array
	 */
	private function reorder_menu( $menu, $order ) {
		$position   = array_flip( $order );
		$items      = array();
		$separators = array(); // slot n => separators located after the n-th item.
		$count      = 0;

		foreach ( $menu as $item ) {
			$is_sep = is_array( $item ) && isset( $item[4] ) && false !== strpos( (string) $item[4], 'wp-menu-separator' );
			if ( $is_sep ) {
				$separators[ $count ][] = $item;
				continue;
			}
			if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
				continue;
			}
			$pos     = isset( $position[ $item[2] ] ) ? $position[ $item[2] ] : 10000 + $count;
			$items[] = array( $pos, $count, $item );
			$count++;
		}

		usort(
			$items,
			function ( $a, $b ) {
				if ( $a[0] === $b[0] ) {
					return $a[1] - $b[1];
				}
				return $a[0] < $b[0] ? -1 : 1;
			}
		);

		$result = array();
		$k      = 1;
		$n      = 0;
		if ( ! empty( $separators[0] ) ) {
			foreach ( $separators[0] as $sep ) {
				$result[ $k ] = $sep;
				$k++;
			}
		}
		foreach ( $items as $row ) {
			$result[ $k ] = $row[2];
			$k++;
			$n++;
			if ( ! empty( $separators[ $n ] ) ) {
				foreach ( $separators[ $n ] as $sep ) {
					$result[ $k ] = $sep;
					$k++;
				}
			}
		}
		return $result;
	}

	/**
	 * Reorder one parent menu's submenu.
	 *
	 * @param array $subs  Submenu items.
	 * @param array $order Saved slug order.
	 * @return array
	 */
	private function reorder_submenu( $subs, $order ) {
		$position = array_flip( $order );
		$rows     = array();
		$idx      = 0;
		foreach ( $subs as $sub ) {
			if ( ! is_array( $sub ) || ! isset( $sub[2] ) ) {
				continue;
			}
			$pos    = isset( $position[ $sub[2] ] ) ? $position[ $sub[2] ] : 10000 + $idx;
			$rows[] = array( $pos, $idx, $sub );
			$idx++;
		}
		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a[0] === $b[0] ) {
					return $a[1] - $b[1];
				}
				return $a[0] < $b[0] ? -1 : 1;
			}
		);
		$result = array();
		foreach ( $rows as $row ) {
			$result[] = $row[2];
		}
		return $result;
	}

	/**
	 * Block direct URL access to menus configured as "Hide & block access".
	 */
	public function block_disallowed_pages() {
		$rules = AMENEDIT_Core::instance()->rules_for_current_user();
		if ( empty( $rules['menus'] ) ) {
			return;
		}
		$blocked = array();
		foreach ( $rules['menus'] as $key => $mode ) {
			if ( 'block' !== $mode ) {
				continue;
			}
			// Submenu rules use the composite key "parent_slug|submenu_slug";
			// only the submenu slug is relevant for URL matching.
			$blocked[] = false !== strpos( $key, '|' ) ? substr( $key, strpos( $key, '|' ) + 1 ) : $key;
		}
		if ( empty( $blocked ) ) {
			return;
		}
		foreach ( $blocked as $slug ) {
			if ( $this->current_page_matches( $slug ) ) {
				wp_die(
					esc_html__( 'Sorry, you are not allowed to access this page.', 'mloun-menroscope'),
					esc_html__( 'Access denied', 'mloun-menroscope'),
					array( 'response' => 403 )
				);
			}
		}
	}

	/**
	 * Check whether the current request matches a menu slug.
	 *
	 * Handles plain file slugs ("edit.php"), page slugs ("my-plugin" served via
	 * admin.php?page=my-plugin) and slugs with query args ("edit.php?post_type=page").
	 *
	 * @param string $slug Menu slug.
	 * @return bool
	 */
	private function current_page_matches( $slug ) {
		global $pagenow;

		if ( false !== strpos( $slug, '?' ) ) {
			list( $file, $query ) = explode( '?', $slug, 2 );
			if ( $pagenow !== $file ) {
				return false;
			}
			parse_str( $query, $args );
			foreach ( $args as $k => $v ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page matching, no state changed.
				if ( ! isset( $_GET[ $k ] ) ) {
					return false;
				}
				// Read-only comparison against a menu slug: the value is never
				// stored, printed or used in a query. It is still unslashed and
				// sanitized, which also turns array input (?post_type[]=page)
				// into an empty string instead of raising an "Array to string
				// conversion" error on PHP 8.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page matching, no state changed.
				$actual = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );
				if ( (string) $actual !== (string) $v ) {
					return false;
				}
			}
			return true;
		}

		if ( $pagenow === $slug ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page matching.
		if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && $_GET['page'] === $slug ) {
			return true;
		}
		return false;
	}
}
