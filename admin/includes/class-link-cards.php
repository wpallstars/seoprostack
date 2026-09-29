<?php
/**
 * Shared renderer for the Pro Plugins, Hosting and Tools directories.
 *
 * Items use the data-file shape:
 *   array( 'name' => '', 'description' => '', 'button_group' => array(
 *       array( 'text' => '', 'url' => '', 'primary' => true ),
 *   ), 'free_slug' => '' | array( '', ... ) )
 *
 * @package WP_ALLSTARS
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Link_Cards {

    /**
     * Render a filterable card grid.
     *
     * @param array  $items Items keyed by slug.
     * @param string $id    Directory id (pro|hosting|tools), used in filter hooks.
     * @param array  $args  intro (string), search_label (string).
     */
    public static function render(array $items, $id, array $args = array()) {
        /**
         * Filter the items shown in a directory tab.
         *
         * @param array $items Items keyed by slug.
         */
        $items = (array) apply_filters("wp_allstars_{$id}_items", $items);

        uasort($items, function ($a, $b) {
            return strcasecmp(isset($a['name']) ? $a['name'] : '', isset($b['name']) ? $b['name'] : '');
        });

        $search_id = 'wpa-search-' . $id;
        ?>
        <div class="wpa-directory" data-wpa-directory="<?php echo esc_attr($id); ?>">
            <div class="wpa-directory__bar">
                <?php if (!empty($args['intro'])) : ?>
                    <p class="wpa-directory__intro"><?php echo esc_html($args['intro']); ?></p>
                <?php endif; ?>
                <label class="screen-reader-text" for="<?php echo esc_attr($search_id); ?>">
                    <?php echo esc_html(isset($args['search_label']) ? $args['search_label'] : __('Filter', 'wp-allstars')); ?>
                </label>
                <input type="search"
                       id="<?php echo esc_attr($search_id); ?>"
                       class="wpa-directory__search"
                       data-wpa-filter="<?php echo esc_attr($id); ?>"
                       placeholder="<?php echo esc_attr(isset($args['search_label']) ? $args['search_label'] : __('Filter…', 'wp-allstars')); ?>" />
                <span class="wpa-directory__count" aria-live="polite"><?php echo esc_html(sprintf(/* translators: %d: number of items */ _n('%d item', '%d items', count($items), 'wp-allstars'), count($items))); ?></span>
            </div>

            <div class="wpa-grid">
                <?php foreach ($items as $slug => $item) : ?>
                    <?php self::render_card((string) $slug, $item); ?>
                <?php endforeach; ?>
            </div>
            <p class="wpa-directory__empty" hidden><?php esc_html_e('No matches.', 'wp-allstars'); ?></p>
        </div>
        <?php
    }

    /**
     * Render one card.
     *
     * @param string $slug Item slug.
     * @param array  $item Item data.
     */
    public static function render_card($slug, array $item) {
        if (empty($item['name'])) {
            return;
        }

        $buttons = isset($item['button_group']) && is_array($item['button_group']) ? $item['button_group'] : array();
        if (!$buttons && !empty($item['url'])) {
            $buttons = array(array('text' => __('Learn more', 'wp-allstars'), 'url' => $item['url'], 'primary' => true));
        }
        $search = strtolower($item['name'] . ' ' . (isset($item['description']) ? $item['description'] : ''));
        ?>
        <article class="wpa-card wpa-link-card" data-wpa-search="<?php echo esc_attr($search); ?>">
            <h3 class="wpa-link-card__title">
                <?php echo esc_html($item['name']); ?>
                <?php echo self::free_version_badge($item); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
            </h3>
            <?php if (!empty($item['description'])) : ?>
                <p class="wpa-link-card__desc"><?php echo esc_html($item['description']); ?></p>
            <?php endif; ?>
            <?php if ($buttons) : ?>
                <div class="wpa-link-card__actions">
                    <?php foreach ($buttons as $button) : ?>
                        <?php
                        if (empty($button['url']) || empty($button['text'])) {
                            continue;
                        }
                        $class = !empty($button['primary']) ? 'button button-primary' : 'button';
                        ?>
                        <a class="<?php echo esc_attr($class); ?>" href="<?php echo esc_url($button['url']); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html($button['text']); ?>
                            <span class="screen-reader-text"><?php echo esc_html(sprintf(/* translators: %s: item name */ __('for %s (opens in a new tab)', 'wp-allstars'), $item['name'])); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
        <?php
    }

    /**
     * "Free version active/installed" badge for pro items with a free_slug.
     *
     * @param array $item Item data.
     * @return string HTML.
     */
    private static function free_version_badge(array $item) {
        if (empty($item['free_slug'])) {
            return '';
        }

        static $installed = null;
        if (null === $installed) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $installed = array();
            foreach (array_keys(get_plugins()) as $file) {
                $installed[dirname($file)] = $file;
            }
        }

        // free_slug may be one slug or a list of related free plugins.
        $found  = false;
        $active = false;
        foreach ((array) $item['free_slug'] as $slug) {
            if (is_string($slug) && isset($installed[$slug])) {
                $found  = true;
                $active = $active || is_plugin_active($installed[$slug]);
            }
        }
        if (!$found) {
            return '';
        }

        return sprintf(
            '<span class="wpa-badge %1$s">%2$s</span>',
            $active ? 'wpa-badge--success' : '',
            esc_html($active ? __('Free version active', 'wp-allstars') : __('Free version installed', 'wp-allstars'))
        );
    }
}
