<?php
/**
 * Limit post revisions.
 *
 * WordPress keeps every revision of every post, so busy posts collect
 * hundreds of copies in the database. This keeps the newest few, with
 * core's own wp_revisions_to_keep filter; core removes older ones the next
 * time a post is saved. Nothing else is stored or deleted.
 *
 * Replaces Disable Bloat PRO's post revisions switch (none kept), which is
 * imported once as 0.
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Revisions extends SEOProStack_Feature {

    const KEY = 'revisions_limit';

    /** How many to keep. */
    const KEEP_KEY = 'revisions_keep';

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
                'tab'         => 'content',
                'label'       => __('Limit post revisions', 'seoprostack'),
                'description' => __('Keep only the newest revisions of each post and page, instead of every one. Older ones are removed the next time the post is saved.', 'seoprostack'),
                'replaces'    => SEOProStack_Disable_Bloat::PLUGINS,
            ),
            self::KEEP_KEY => array(
                'type'        => 'int',
                'default'     => 10,
                'min'         => 0,
                'max'         => 100,
                'unit'        => __('revisions', 'seoprostack'),
                'parent'      => self::KEY,
                'label'       => __('Revisions to keep', 'seoprostack'),
                'description' => __('0 keeps none (autosaves still work).', 'seoprostack'),
            ),
        );
    }

    /**
     * Import Disable Bloat PRO's switch: no revisions.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (SEOProStack_Disable_Bloat::imports('post_revisions_disable')) {
            $options = self::import_setting($options, self::KEY, true);
            $options = self::import_setting($options, self::KEEP_KEY, 0);
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('wp_revisions_to_keep', array(__CLASS__, 'keep'), 99, 2);
    }

    /**
     * Revisions to keep: the setting, or fewer where something else asks.
     *
     * @param int     $num  Revisions to keep (-1 for all).
     * @param WP_Post $post Post.
     * @return int
     */
    public static function keep($num, $post = null) {
        $keep = max(0, (int) SEOProStack_Settings::get(self::KEEP_KEY));
        $num  = (int) $num;
        return $num >= 0 ? min($num, $keep) : $keep;
    }
}
