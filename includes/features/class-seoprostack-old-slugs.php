<?php
/**
 * Old post addresses.
 *
 * WordPress keeps a post's old slugs (`_wp_old_slug` post meta) so links to
 * its old address still redirect. This keeps the useful ones and clears
 * the ones that can no longer redirect anyone:
 * - the post's current slug;
 * - the same old slug stored twice for one post;
 * - old slugs of posts that no longer exist;
 * - old slugs another published post of the same type now uses (its
 *   address answers first), for types whose addresses have no date.
 * It does that when a post's slug changes and when Tools → Old addresses
 * opens. A working redirect is never removed automatically.
 *
 * It also records when each old address last redirected someone (at most
 * once a day per address), and lists them under Tools → Old addresses with
 * the post and the last use, so the owner can remove the ones nobody needs.
 *
 * Replaces Slugs Manager: Delete Old Permalinks (closed on WordPress.org).
 *
 * @package SEOProStack
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Old_Slugs extends SEOProStack_Feature {

    const KEY = 'old_slugs';

    /** Core's post meta. */
    const META = '_wp_old_slug';

    /** Post meta: old slug => when it last redirected someone. */
    const USED = '_seoprostack_old_slug_used';

    /** Option: when recording started. */
    const SINCE = 'seoprostack_old_slugs_since';

    /** Option: when redundant old slugs were last cleared. */
    const SWEPT = 'seoprostack_old_slugs_swept';

    /** Tools page. */
    const PAGE = 'seoprostack-old-addresses';

    /** admin-post action and nonce action. */
    const ACTION = 'seoprostack_old_slugs';

    /** Rows per page. */
    const PER_PAGE = 50;

    /** Most rows cleared in one go. */
    const BATCH = 1000;

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
                'tab'         => 'links',
                'label'       => __('Old post addresses', 'seoprostack'),
                'description' => __('WordPress keeps the old addresses of renamed posts so links to them still work. This clears the ones that can no longer lead anywhere and lists the rest under Tools → Old addresses, with when each was last used, so you can remove those nobody needs.', 'seoprostack'),
                'replaces'    => array('remove-old-slugspermalinks' => 'Slugs Manager: Delete Old Permalinks'),
            ),
        );
    }

    /**
     * Switch on while Slugs Manager is active (it has no settings).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        return isset(self::active_plugins()['remove-old-slugspermalinks']) ? self::import_setting($options, self::KEY, true) : $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('old_slug_redirect_post_id', array(__CLASS__, 'record'));
        // After core's wp_check_for_changed_slugs() (priority 12).
        add_action('post_updated', array(__CLASS__, 'post_updated'), 20, 3);
        if (is_admin()) {
            add_action('admin_menu', array(__CLASS__, 'menu'));
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        }
    }

    /* --------------------------------------------------------------------- */
    /* Recording and clearing                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * An old address is redirecting: note when, at most once a day.
     *
     * @param int $post_id Post the old slug belongs to (0 when none).
     * @return int
     */
    public static function record($post_id) {
        $slug = (string) get_query_var('name');
        if (!$post_id || '' === $slug) {
            return $post_id;
        }
        $used = get_post_meta($post_id, self::USED, true);
        $used = is_array($used) ? $used : array();
        if (empty($used[$slug]) || time() - (int) $used[$slug] > DAY_IN_SECONDS) {
            $used[$slug] = time();
            update_post_meta($post_id, self::USED, $used);
        }
        if (!get_option(self::SINCE)) {
            add_option(self::SINCE, time(), '', false);
        }
        return $post_id;
    }

    /**
     * A post was saved: clear its own redundant old slugs, and other posts'
     * old slugs that this post's address now answers for.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $after   Post after the update.
     * @param WP_Post $before  Post before the update.
     */
    public static function post_updated($post_id, $after, $before) {
        if (!$after instanceof WP_Post || $after->post_name === $before->post_name) {
            return;
        }
        self::clear(self::redundant((int) $post_id));
    }

    /**
     * Old slug rows that cannot redirect anyone.
     *
     * @param int $post_id Only rows of, or taken by, this post (0: all).
     * @return array<int,array{0:int,1:int}> meta ID and post ID pairs.
     */
    private static function redundant($post_id = 0) {
        global $wpdb;
        $rows  = array();
        $limit = self::BATCH;
        $id    = (int) $post_id;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads old slug rows to clear; not cached.
        // Posts that no longer exist.
        if (!$id) {
            $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare("SELECT pm.meta_id, pm.post_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.ID IS NULL LIMIT %d", self::META, $limit), ARRAY_N));
        }
        // The post's current slug, or the same old slug stored twice.
        $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare("SELECT pm.meta_id, pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = p.post_name AND (%d = 0 OR pm.post_id = %d) LIMIT %d", self::META, $id, $id, $limit), ARRAY_N));
        $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare("SELECT pm.meta_id, pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->postmeta} d ON d.post_id = pm.post_id AND d.meta_key = pm.meta_key AND d.meta_value = pm.meta_value AND d.meta_id < pm.meta_id WHERE pm.meta_key = %s AND (%d = 0 OR pm.post_id = %d) LIMIT %d", self::META, $id, $id, $limit), ARRAY_N));

        // Another published post of the same type now has the slug.
        $types = self::dateless_types();
        if ($types) {
            $in = implode(',', array_fill(0, count($types), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one placeholder per type, built from the count only.
            $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare("SELECT pm.meta_id, pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id INNER JOIN {$wpdb->posts} o ON o.post_type = p.post_type AND o.post_name = pm.meta_value AND o.ID <> p.ID AND o.post_status IN ('publish', 'private') WHERE pm.meta_key = %s AND p.post_type IN ($in) AND (%d = 0 OR pm.post_id = %d OR o.ID = %d) LIMIT %d", array_merge(array(self::META), $types, array($id, $id, $id, $limit))), ARRAY_N));
        }
        // phpcs:enable

        $unique = array();
        foreach ($rows as $row) {
            $unique[(int) $row[0]] = array((int) $row[0], (int) $row[1]);
        }
        return array_values($unique);
    }

    /**
     * Post types whose addresses are only their slug (plus a fixed base),
     * so a published post with the same slug always answers first. Posts
     * with a date in their address and hierarchical types are left out:
     * there the old address can still be different.
     *
     * @return string[]
     */
    private static function dateless_types() {
        $types     = array();
        $structure = (string) get_option('permalink_structure');
        if ('' === $structure) {
            return $types;
        }
        foreach (get_post_types(array('public' => true), 'objects') as $type) {
            if ($type->hierarchical || 'attachment' === $type->name) {
                continue;
            }
            if ('post' === $type->name && preg_match('/%(year|monthnum|day|hour|minute|second|post_id|category|author)%/', $structure)) {
                continue;
            }
            $types[] = $type->name;
        }
        return $types;
    }

    /**
     * Delete old slug rows, with their last-used record.
     *
     * @param array<int,array{0:int,1:int}> $rows Meta ID and post ID pairs.
     * @return int Rows deleted.
     */
    private static function clear(array $rows) {
        $done = 0;
        foreach ($rows as $row) {
            $meta = get_metadata_by_mid('post', $row[0]);
            if (!$meta || self::META !== $meta->meta_key) {
                continue;
            }
            if (delete_metadata_by_mid('post', $row[0])) {
                $done++;
                self::forget_use((int) $meta->post_id, (string) $meta->meta_value);
            }
        }
        return $done;
    }

    /**
     * Drop the last-used record of a slug that is no longer an old slug.
     *
     * @param int    $post_id Post ID.
     * @param string $slug    Slug.
     */
    private static function forget_use($post_id, $slug) {
        $used = get_post_meta($post_id, self::USED, true);
        if (!is_array($used) || !isset($used[$slug])) {
            return;
        }
        if (in_array($slug, (array) get_post_meta($post_id, self::META), true)) {
            return;
        }
        unset($used[$slug]);
        if ($used) {
            update_post_meta($post_id, self::USED, $used);
        } else {
            delete_post_meta($post_id, self::USED);
        }
    }

    /**
     * Clear every redundant old slug, at most every ten minutes.
     *
     * @return int Rows deleted.
     */
    private static function sweep() {
        $last = (int) get_option(self::SWEPT, 0);
        if ($last && time() - $last < 10 * MINUTE_IN_SECONDS) {
            return 0;
        }
        update_option(self::SWEPT, time(), false);
        if (!get_option(self::SINCE)) {
            add_option(self::SINCE, time(), '', false);
        }
        return self::clear(self::redundant());
    }

    /* --------------------------------------------------------------------- */
    /* Tools → Old addresses                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * Add the Tools page.
     */
    public static function menu() {
        add_management_page(__('Old addresses', 'seoprostack'), __('Old addresses', 'seoprostack'), 'edit_others_posts', self::PAGE, array(__CLASS__, 'page'));
    }

    /**
     * The address an old slug leads from: the post's address with the old
     * slug in place of the current one.
     *
     * @param WP_Post $post Post.
     * @param string  $slug Old slug.
     * @return string Empty when it cannot be shown.
     */
    private static function old_url($post, $slug) {
        if ('' === $post->post_name || !in_array($post->post_status, array('publish', 'private'), true)) {
            return '';
        }
        $url = get_permalink($post);
        if (!$url) {
            return '';
        }
        $pos = strrpos($url, '/' . $post->post_name);
        return false === $pos ? '' : substr_replace($url, '/' . $slug, $pos, strlen($post->post_name) + 1);
    }

    /**
     * The page.
     */
    public static function page() {
        global $wpdb;
        if (!current_user_can('edit_others_posts')) {
            return;
        }
        $cleared = self::sweep();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- list state and messages only.
        $paged   = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $removed = isset($_GET['removed']) ? absint($_GET['removed']) : -1;
        // phpcs:enable

        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- lists old slug rows; not cached.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s", self::META));
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT pm.meta_id, pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s ORDER BY p.post_title ASC, pm.post_id ASC, pm.meta_id ASC LIMIT %d OFFSET %d", self::META, self::PER_PAGE, ($paged - 1) * self::PER_PAGE));
        // phpcs:enable
        $since = (int) get_option(self::SINCE, time());
        $pages = (int) ceil($total / self::PER_PAGE);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Old addresses', 'seoprostack'); ?></h1>
            <?php if ($removed >= 0) : ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    /* translators: %d: number of old addresses */
                    echo esc_html(sprintf(_n('%d old address removed.', '%d old addresses removed.', $removed, 'seoprostack'), $removed));
                ?></p></div>
            <?php endif; ?>
            <?php if ($cleared > 0) : ?>
                <div class="notice notice-info is-dismissible"><p><?php
                    /* translators: %d: number of old addresses */
                    echo esc_html(sprintf(_n('%d old address that could no longer lead anywhere was cleared.', '%d old addresses that could no longer lead anywhere were cleared.', $cleared, 'seoprostack'), $cleared));
                ?></p></div>
            <?php endif; ?>
            <p><?php esc_html_e('When a post’s address changes, WordPress keeps the old one so links to it still lead to the post. Remove one only when nobody needs it any more: its address then shows “not found”.', 'seoprostack'); ?></p>
            <?php if (!$rows) : ?>
                <p><?php esc_html_e('No old addresses.', 'seoprostack'); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                    <?php wp_nonce_field(self::ACTION); ?>
                    <div class="tablenav top">
                        <div class="alignleft actions">
                            <?php submit_button(__('Remove selected', 'seoprostack'), 'secondary', 'remove', false); ?>
                        </div>
                        <div class="tablenav-pages"><span class="displaying-num"><?php
                            /* translators: %s: number of old addresses */
                            echo esc_html(sprintf(_n('%s old address', '%s old addresses', $total, 'seoprostack'), number_format_i18n($total)));
                        ?></span>
                        <?php
                        if ($pages > 1) {
                            echo wp_kses_post(paginate_links(array(
                                'base'    => add_query_arg('paged', '%#%'),
                                'format'  => '',
                                'current' => $paged,
                                'total'   => $pages,
                            )));
                        }
                        ?>
                        </div>
                    </div>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <td class="manage-column column-cb check-column"><input type="checkbox" aria-label="<?php esc_attr_e('Select all', 'seoprostack'); ?>"></td>
                                <th scope="col"><?php esc_html_e('Old address', 'seoprostack'); ?></th>
                                <th scope="col"><?php esc_html_e('Leads to', 'seoprostack'); ?></th>
                                <th scope="col"><?php esc_html_e('Last used', 'seoprostack'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        foreach ($rows as $row) {
                            $post = get_post((int) $row->post_id);
                            if (!$post) {
                                continue;
                            }
                            $slug   = (string) $row->meta_value;
                            $url    = self::old_url($post, $slug);
                            $used   = get_post_meta($post->ID, self::USED, true);
                            $time   = is_array($used) && !empty($used[$slug]) ? (int) $used[$slug] : 0;
                            $remove = wp_nonce_url(add_query_arg(array('action' => self::ACTION, 'mid' => (int) $row->meta_id), admin_url('admin-post.php')), self::ACTION);
                            $type   = get_post_type_object($post->post_type);
                            $title  = '' !== $post->post_title ? $post->post_title : __('(no title)', 'seoprostack');
                            ?>
                            <tr>
                                <th scope="row" class="check-column"><input type="checkbox" name="mids[]" value="<?php echo (int) $row->meta_id; ?>" aria-label="<?php echo esc_attr($slug); ?>"></th>
                                <td>
                                    <strong><?php echo $url ? '<a href="' . esc_url($url) . '">' . esc_html(wp_parse_url($url, PHP_URL_PATH)) . '</a>' : esc_html($slug); ?></strong>
                                    <?php if (current_user_can('edit_post', $post->ID)) : ?>
                                        <div class="row-actions"><span class="delete"><a class="submitdelete" href="<?php echo esc_url($remove); ?>"><?php esc_html_e('Remove', 'seoprostack'); ?></a></span></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $link = get_edit_post_link($post->ID);
                                    echo $link ? '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a>' : esc_html($title);
                                    if ($type) {
                                        echo ' <span class="description">(' . esc_html($type->labels->singular_name) . ')</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php
                                if ($time) {
                                    /* translators: %s: time ago, such as "3 days" */
                                    echo esc_html(sprintf(__('%s ago', 'seoprostack'), human_time_diff($time)));
                                } else {
                                    /* translators: %s: date */
                                    echo esc_html(sprintf(__('Not since %s', 'seoprostack'), date_i18n(get_option('date_format'), $since)));
                                }
                                ?></td>
                            </tr>
                            <?php
                        }
                        ?>
                        </tbody>
                    </table>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * admin-post: remove one old address (link) or the selected ones (form).
     */
    public static function handle() {
        check_admin_referer(self::ACTION);
        if (!current_user_can('edit_others_posts')) {
            wp_die(esc_html__('You are not allowed to remove old addresses.', 'seoprostack'), '', array('response' => 403));
        }
        $mids = array();
        // phpcs:disable WordPress.Security.NonceVerification -- checked above.
        if (isset($_GET['mid'])) {
            $mids[] = absint($_GET['mid']);
        }
        if (isset($_POST['mids'])) {
            $mids = array_merge($mids, array_map('absint', (array) wp_unslash($_POST['mids'])));
        }
        // phpcs:enable
        $rows = array();
        foreach (array_unique(array_filter($mids)) as $mid) {
            $meta = get_metadata_by_mid('post', $mid);
            if ($meta && self::META === $meta->meta_key && current_user_can('edit_post', (int) $meta->post_id)) {
                $rows[] = array((int) $mid, (int) $meta->post_id);
            }
        }
        $done = self::clear($rows);
        $back = wp_get_referer();
        $back = $back ? remove_query_arg(array('removed'), $back) : admin_url('tools.php?page=' . self::PAGE);
        wp_safe_redirect(add_query_arg('removed', $done, $back));
        exit;
    }
}
