<?php
/**
 * Admin bar that notes who added each item on the left of the bar.
 *
 * Loaded only when "More menu in the admin bar" needs it, and only while
 * no other plugin has replaced WordPress's admin bar class. For each new
 * top-level item it looks at the file that called add_node() or
 * add_menu(): WordPress itself, a plugin, a must-use plugin or a theme.
 * Nothing else changes; the bar renders exactly as WordPress's own.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Bar_Sources extends WP_Admin_Bar {

    /**
     * Top-level item ID => source key ('core', a plugin folder or file
     * name, 'mu--{name}', 'theme--{folder}', or '' when unknown).
     *
     * @var array<string,string>
     */
    private $seoprostack_sources = array();

    /**
     * Add a node, noting the source of new top-level items.
     *
     * @param array|object|string $args Node arguments (or the pre-3.3 parent ID).
     */
    public function add_node($args) {
        $call = func_get_args();
        $data = is_object($args) ? get_object_vars($args) : $args;
        if (is_array($data) && !empty($data['id']) && empty($data['group']) && func_num_args() < 3) {
            $id     = (string) $data['id'];
            $parent = isset($data['parent']) ? $data['parent'] : false;
            $top    = !$parent || 'root' === $parent || 'root-default' === $parent;
            // Changing an existing item (such as a plugin retitling a core one) keeps its source.
            if ($top && !isset($this->seoprostack_sources[$id]) && null === $this->get_node($id)) {
                $this->seoprostack_sources[$id] = self::caller_source();
            }
        }
        parent::add_node(...$call);
    }

    /**
     * Sources of the top-level items added so far.
     *
     * @return array<string,string> Item ID => source key.
     */
    public function seoprostack_sources() {
        return $this->seoprostack_sources;
    }

    /**
     * Source of the code that called add_node(): the first file in the
     * call stack outside this class and WordPress's admin bar class.
     *
     * @return string
     */
    private static function caller_source() {
        static $skip = null;
        if (null === $skip) {
            $skip = array(
                wp_normalize_path(__FILE__),
                wp_normalize_path(ABSPATH . WPINC . '/class-wp-admin-bar.php'),
            );
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- reads the caller's file, once per new top-level item.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            if (empty($frame['file'])) {
                continue;
            }
            $file = wp_normalize_path($frame['file']);
            if (!in_array($file, $skip, true)) {
                return self::file_source($file);
            }
        }
        return '';
    }

    /**
     * Source key of a PHP file.
     *
     * @param string $file Normalised path.
     * @return string
     */
    public static function file_source($file) {
        static $roots = null;
        if (null === $roots) {
            $roots = array(
                'mu'     => wp_normalize_path(WPMU_PLUGIN_DIR) . '/',
                'plugin' => wp_normalize_path(WP_PLUGIN_DIR) . '/',
            );
            foreach ((array) $GLOBALS['wp_theme_directories'] as $i => $dir) {
                $roots['theme' . $i] = trailingslashit(wp_normalize_path($dir));
            }
            $roots['core'] = wp_normalize_path(ABSPATH);
        }
        // eval()'d code reports "path/file.php(12) : eval()'d code".
        $file = preg_replace('/\(\d+\) : .*$/', '', $file);

        foreach ($roots as $type => $root) {
            if (0 !== strpos($file, $root)) {
                continue;
            }
            $rest = substr($file, strlen($root));
            if ('core' === $type) {
                // Only WordPress's own folders; other files in the root are unknown.
                return (0 === strpos($rest, WPINC . '/') || 0 === strpos($rest, 'wp-admin/')) ? 'core' : '';
            }
            $slash = strpos($rest, '/');
            // A single-file plugin is known by its file name without ".php".
            $name = false === $slash ? preg_replace('/\.php$/', '', $rest) : substr($rest, 0, $slash);
            if ('' === $name) {
                return '';
            }
            if ('mu' === $type) {
                return 'mu--' . $name;
            }
            return 'plugin' === $type ? $name : 'theme--' . $name;
        }
        return '';
    }
}
