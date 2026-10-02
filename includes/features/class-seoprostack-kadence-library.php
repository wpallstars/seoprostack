<?php
/**
 * Faster editor with Kadence Blocks.
 *
 * Kadence Blocks prints its whole design library (every pattern's
 * pre-rendered HTML) into each block editor screen as the
 * kadence_blocks_params_library script variable: about 15 MB on a test
 * site, which made post-new.php about 24 MB. This turns that preload off
 * through Kadence's own kadence_blocks_preload_design_library filter, which
 * Kadence itself turns off when Gravity Forms is active. The editor then
 * fetches the library from Kadence's REST route
 * (kb-design-library/v1/get_library) the first time the design library is
 * opened, and its AI wizard settings the same way.
 *
 * Kadence keeps what it downloads in uploads/kadence_blocks_library, so
 * opening the library again does not reach Kadence's servers, but every new
 * editor page asked the site for the whole library again (twice). The
 * editor script keeps Kadence's answers in the browser until Kadence's copy
 * changes (see admin/js/seoprostack-kadence-library.js), and a style lets
 * the browser skip drawing patterns that are off screen.
 *
 * @package SEOProStack
 * @since 0.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Library extends SEOProStack_Feature {

    const KEY = 'kadence_library_on_demand';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'speed',
                'label'       => __('Faster editor with Kadence Blocks', 'seoprostack'),
                'description' => __('Kadence Blocks puts its whole design library into every editor page, often more than 10 MB. This loads the library when you open it instead, and keeps it in your browser until Kadence updates it, so the editor and the library open faster. Nothing changes if Kadence Blocks is not active.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin()) {
            return;
        }
        // Default priority, so a site's own filter at a higher one still wins.
        add_filter('kadence_blocks_preload_design_library', '__return_false');
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'browser_cache'));
    }

    /**
     * Keep Kadence's design library answers in the browser, and let the
     * browser skip drawing patterns that are off screen.
     *
     * The token names the kept copy. It changes when Kadence's files change
     * (a download or Sync rewrites them), with Kadence Blocks' and Kadence
     * Blocks Pro's versions and licence, and differs per user and site, so a
     * kept copy is never older than Kadence's own. The licence goes into a
     * salted hash only.
     */
    public static function browser_cache() {
        if (!defined('KADENCE_BLOCKS_VERSION')) {
            return;
        }
        $file = 'admin/js/seoprostack-kadence-library.js';
        $ver  = file_exists(SEOPROSTACK_DIR . $file) ? (string) filemtime(SEOPROSTACK_DIR . $file) : SEOPROSTACK_VERSION;
        wp_enqueue_script('seoprostack-kadence-library', SEOPROSTACK_URL . $file, array('wp-api-fetch'), $ver, false);
        wp_add_inline_script(
            'seoprostack-kadence-library',
            'window.seoprostackKadenceLibrary=' . wp_json_encode(array('token' => self::token())) . ';',
            'before'
        );

        // The library lays out all of its 800 or so patterns at once (a list
        // about 70,000 px tall). Let the browser skip laying out and drawing
        // the ones off screen until they are scrolled to; "auto" keeps each
        // pattern's real height once it has been drawn. Browsers without
        // content-visibility ignore this.
        wp_register_style('seoprostack-kadence-library', false, array(), $ver);
        wp_enqueue_style('seoprostack-kadence-library');
        wp_add_inline_style(
            'seoprostack-kadence-library',
            '.kb-css-masonry_column>.block-editor-block-patterns-list__list-item{content-visibility:auto;contain-intrinsic-size:auto 320px}'
        );
    }

    /**
     * Token for the kept copy.
     *
     * @return string
     */
    private static function token() {
        // Kadence's own folder, through Kadence's own filters (Cache_Provider).
        $base   = trailingslashit((string) apply_filters('kadence_block_library_local_data_base_path', trailingslashit(wp_get_upload_dir()['basedir'])));
        $folder = $base . apply_filters('kadence_block_library_local_data_subfolder_name', 'kadence_blocks_library');
        $files  = array();
        $found  = glob(trailingslashit($folder) . '*.json');
        foreach (is_array($found) ? $found : array() as $path) {
            $files[] = basename($path) . ':' . (int) @filemtime($path) . ':' . (int) @filesize($path);
        }
        sort($files);

        $licence = function_exists('kadence_blocks_get_current_license_data') ? (array) kadence_blocks_get_current_license_data() : array();
        $parts   = array(
            KADENCE_BLOCKS_VERSION,
            defined('KBP_VERSION') ? KBP_VERSION : '',
            get_current_user_id(),
            get_current_blog_id(),
            isset($licence['key']) ? (string) $licence['key'] : '',
            isset($licence['product']) ? (string) $licence['product'] : '',
            isset($licence['env']) ? (string) $licence['env'] : '',
            $files,
        );
        return substr(wp_hash(wp_json_encode($parts), 'nonce'), 0, 20);
    }
}
