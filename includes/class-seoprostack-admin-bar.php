<?php
/**
 * Keep SEO Pro Stack's admin bar icons in a fixed place on the right.
 *
 * Features pin their `top-secondary` node with a rank. Just before the
 * admin bar renders, pinned nodes are moved next to WordPress's own items
 * on the right (the account menu, and on the site the search box): rank 0
 * nearest to them, higher ranks further left. Every other right-hand item,
 * from any plugin, goes to the left of the pinned ones, in its own order.
 *
 * WordPress 6.6 shows `top-secondary` items left to right in the order
 * they were added; earlier versions float them right, so the first added
 * is furthest right. The order is built for whichever applies.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Admin_Bar {

    /** WordPress's own right-hand items, which keep their places. */
    const CORE = array('my-account', 'recovery-mode', 'search');

    /**
     * Pinned node ID => rank (0 is nearest to WordPress's own items).
     *
     * @var array<string,int>
     */
    private static $pinned = array();

    /**
     * Keep a node on the right of the admin bar.
     *
     * @param string $id   Node ID, a child of `top-secondary`.
     * @param int    $rank 0 sits next to the account menu; higher sits further left.
     */
    public static function pin($id, $rank) {
        if (!self::$pinned) {
            // Last thing before the admin bar renders, after every plugin has added its items.
            add_action('wp_before_admin_bar_render', array(__CLASS__, 'order'), PHP_INT_MAX);
        }
        self::$pinned[(string) $id] = (int) $rank;
    }

    /**
     * Put the right-hand items in order.
     */
    public static function order() {
        global $wp_admin_bar;
        if (!($wp_admin_bar instanceof WP_Admin_Bar)) {
            return;
        }
        $nodes = $wp_admin_bar->get_nodes();
        if (!$nodes) {
            return;
        }

        $core   = array();
        $pinned = array();
        $others = array();
        foreach ($nodes as $id => $node) {
            if ('top-secondary' !== $node->parent) {
                continue;
            }
            if (in_array($id, self::CORE, true)) {
                $core[] = $id;
            } elseif (isset(self::$pinned[$id])) {
                $pinned[$id] = self::$pinned[$id];
            } else {
                $others[] = $id;
            }
        }
        if (!$pinned) {
            return;
        }
        asort($pinned);
        $pinned = array_keys($pinned); // Nearest to WordPress's items first.

        $current = array_keys(array_filter($nodes, function ($node) {
            return 'top-secondary' === $node->parent;
        }));
        $wanted = self::left_to_right()
            ? array_merge($others, array_reverse($pinned), $core)
            : array_merge($core, $pinned, $others);
        if ($wanted === $current) {
            return;
        }

        // Nodes keep the order they were added in, so add them again in the wanted order.
        // Their child items point at their IDs and are not touched.
        foreach ($wanted as $id) {
            $wp_admin_bar->remove_node($id);
            $wp_admin_bar->add_node(get_object_vars($nodes[$id]));
        }
    }

    /**
     * Whether right-hand items show in the order they were added
     * (WordPress 6.6 and later) rather than reversed.
     *
     * @return bool
     */
    private static function left_to_right() {
        return version_compare(get_bloginfo('version'), '6.6-alpha', '>=');
    }
}
