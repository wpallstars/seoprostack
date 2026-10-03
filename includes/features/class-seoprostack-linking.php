<?php
/**
 * Local linking tools, optional health checks and anonymous event counts.
 * Supplements Rank Math and remains available while Link Whisper is active,
 * so owners can compare on staging before deciding to retire it.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Linking extends SEOProStack_Feature {
    const KEY = 'linking';
    const PAGE = 'seoprostack-links';
    const ACTION = 'seoprostack_link_action';

    /** @return array */
    public static function settings() {
        return array(
            self::KEY => array('type' => 'bool', 'default' => false, 'tab' => 'links', 'label' => __('Internal linking tools', 'seoprostack'), 'description' => __('Find local linking opportunities and orphan candidates under Tools → Links. Suggestions use page titles and Rank Math keywords, not a cloud service. Changes require your approval and can be undone.', 'seoprostack')),
            'linking_health' => array('type' => 'bool', 'default' => false, 'parent' => self::KEY, 'label' => __('Check linked addresses in the background', 'seoprostack'), 'description' => __('Contact linked websites in small cached batches, never while a visitor waits. Query-bearing, login and API addresses are excluded. A failed request is not proof of a broken link.', 'seoprostack')),
            'linking_clicks' => array('type' => 'bool', 'default' => false, 'parent' => self::KEY, 'label' => __('Count link clicks', 'seoprostack'), 'description' => __('Optional anonymous daily event totals on published pages. No cookies, IP addresses, user IDs, query strings or unique-visitor tracking. Honour Do Not Track and Global Privacy Control. Keep up to 90 days. Review your consent requirements before enabling.', 'seoprostack')),
        );
    }

    /** Load helpers only for enabled tools, admin, CLI or maintenance. */
    public static function boot() {
        $cli = defined('WP_CLI') && WP_CLI && class_exists('WP_CLI');
        if (!self::switched_on() && !is_admin() && !$cli && !wp_doing_cron()) {
            return;
        }
        foreach (array('index', 'suggestions', 'clicks', 'audit') as $helper) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-link-' . $helper . '.php';
        }
        add_action(SEOProStack_Link_Clicks::PRUNE, array('SEOProStack_Link_Clicks', 'prune'));
        add_action(SEOProStack_Link_Clicks::PRUNE_MORE, array('SEOProStack_Link_Clicks', 'prune'));
        add_action('update_option_' . SEOProStack_Settings::OPTION, array(__CLASS__, 'settings_saved'), 10, 2);
        if (is_admin()) {
            add_action('admin_menu', array(__CLASS__, 'menu'));
            add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
        }
        if ($cli) {
            WP_CLI::add_command('seoprostack links audit', array(__CLASS__, 'cli_audit'));
            WP_CLI::add_command('seoprostack links index', array(__CLASS__, 'cli_index'));
        }
        if (!self::switched_on()) {
            return;
        }
        add_action('admin_init', array(__CLASS__, 'initialize'));
        add_action('save_post', array('SEOProStack_Link_Index', 'saved'), 30);
        add_action('deleted_post', array(__CLASS__, 'deleted'));
        add_action(SEOProStack_Link_Index::CRON, array('SEOProStack_Link_Index', 'batch'));
        add_action('add_meta_boxes', array(__CLASS__, 'boxes'));
        if (SEOProStack_Settings::get('linking_clicks')) {
            SEOProStack_Link_Clicks::boot();
            add_action('admin_init', array(__CLASS__, 'privacy'));
        }
    }

    /** Initialize only after opt-in, not on a public page load. */
    public static function initialize() {
        SEOProStack_Link_Index::install();
        if (!get_option(SEOProStack_Link_Index::STATE)) {
            SEOProStack_Link_Index::start();
        }
    }

    /** @param array $old Previous settings. @param array $new New settings. */
    public static function settings_saved($old, $new) {
        if (empty($new[self::KEY])) {
            wp_unschedule_hook(SEOProStack_Link_Index::CRON);
            return;
        }
        foreach (array(self::KEY, 'linking_health', 'linking_clicks') as $key) {
            if ((isset($old[$key]) ? (bool) $old[$key] : false) !== (isset($new[$key]) ? (bool) $new[$key] : false)) {
                SEOProStack_Link_Index::start();
                break;
            }
        }
    }

    /** @param int $post_id Deleted source. */
    public static function deleted($post_id) {
        global $wpdb;
        if ('1' === get_option(SEOProStack_Link_Index::VERSION)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- remove only this post's derived fallback rows.
            $wpdb->delete($wpdb->prefix . 'seoprostack_links', array('post_id' => $post_id), array('%d'));
        }
    }

    /** A read-only retirement report remains available when the toolkit is off. */
    public static function menu() {
        add_management_page(__('Links', 'seoprostack'), __('Links', 'seoprostack'), 'manage_options', self::PAGE, array(__CLASS__, 'display'));
    }

    /** @param string $hook Current screen. */
    public static function assets($hook) {
        if ('tools_page_' . self::PAGE === $hook) {
            wp_enqueue_style('seoprostack-linking', SEOPROSTACK_URL . 'admin/css/seoprostack-linking.css', array(), SEOPROSTACK_VERSION);
        }
    }

    /** @param string $key Setting. @param array $field Schema. */
    public static function panel($key, $field) {
        if (self::KEY === $key) {
            echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('Open links and retirement checks', 'seoprostack') . '</a></p>';
        }
    }

    /** @param array $args View arguments. @return string */
    public static function url($args = array()) {
        return add_query_arg(array_merge(array('page' => self::PAGE), $args), admin_url('tools.php'));
    }

    /** @param string $name Read-only filter name. @param string $default Default. @return string */
    private static function query($name, $default = '') {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, escaped navigation filters; all mutations use handle() and scoped nonces.
        return isset($_GET[$name]) && is_string($_GET[$name]) ? sanitize_text_field(wp_unslash($_GET[$name])) : $default;
    }

    /** Native editor entry points, using the latest saved version only. */
    public static function boxes() {
        if (!current_user_can('manage_options')) {
            return;
        }
        foreach (SEOProStack_Link_Index::types() as $type) {
            add_meta_box('seoprostack-linking', __('Linking opportunities', 'seoprostack'), array(__CLASS__, 'box'), $type, 'side');
        }
    }

    /** @param WP_Post $post Editor source. */
    public static function box($post) {
        if (!SEOProStack_Link_Index::eligible($post)) {
            echo '<p>' . esc_html__('Publish this page without a password before checking links.', 'seoprostack') . '</p>';
            return;
        }
        echo '<p>' . esc_html__('Save your edits first. Opportunities use the saved content and open in a separate tab.', 'seoprostack') . '</p><p><a target="_blank" rel="noopener noreferrer" href="' . esc_url(self::url(array('view' => 'suggestions', 'post' => $post->ID))) . '">' . esc_html__('Find incoming and outgoing links', 'seoprostack') . '</a></p>';
    }

    /** Add accurate owner-editable privacy policy wording, not a compliance claim. */
    public static function privacy() {
        wp_add_privacy_policy_content('SEO Pro Stack', '<p>' . esc_html__('When enabled, link click counting stores daily event totals with the source page and destination path for up to 90 days. It does not store IP addresses, user IDs, cookies, query strings or visitor profiles, and does not count logged-in visitors or browsers sending Do Not Track or Global Privacy Control. Counts are approximate events, not unique visitors. Your web server and other plugins may keep separate request logs. Site owners must review their own consent requirements.', 'seoprostack') . '</p>');
    }

    /** Owner-approved actions only; no deactivation, deletion or third-party writes. */
    public static function handle() {
        if (!current_user_can('manage_options') || !self::switched_on()) {
            wp_die(esc_html__('You cannot change links here.', 'seoprostack'), '', array('response' => 403));
        }
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $source = isset($_POST['source']) ? absint($_POST['source']) : 0;
        $target = isset($_POST['target']) ? absint($_POST['target']) : 0;
        check_admin_referer(self::nonce($operation, $source, $target));
        $result = true;
        if ('index' === $operation) {
            SEOProStack_Link_Index::start();
        } elseif ('insert' === $operation) {
            $phrase = isset($_POST['phrase']) ? sanitize_text_field(wp_unslash($_POST['phrase'])) : '';
            $hash = isset($_POST['hash']) ? sanitize_text_field(wp_unslash($_POST['hash'])) : '';
            $result = SEOProStack_Link_Suggestions::insert($source, $target, $phrase, $hash);
        } elseif ('undo' === $operation) {
            $result = SEOProStack_Link_Suggestions::undo($source);
        } else {
            wp_die(esc_html__('Unknown link action.', 'seoprostack'), '', array('response' => 400));
        }
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', array('response' => 409, 'back_link' => true));
        }
        wp_safe_redirect(self::url(array('view' => $source ? 'suggestions' : 'report', 'post' => $source, 'notice' => $operation)));
        exit;
    }

    /** @param string $operation Action. @param int $source Source. @param int $target Target. @return string */
    private static function nonce($operation, $source, $target) {
        return self::ACTION . '_' . get_current_blog_id() . '_' . $operation . '_' . $source . '_' . $target;
    }

    /** @param string $operation Action. @param int $source Source. @param int $target Target. */
    private static function form($operation, $source = 0, $target = 0) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="source" value="' . esc_attr((string) $source) . '"><input type="hidden" name="target" value="' . esc_attr((string) $target) . '">';
        wp_nonce_field(self::nonce($operation, $source, $target));
    }

    /** Render the real WordPress tools path. */
    public static function display() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $views = array('report' => __('Pages', 'seoprostack'), 'suggestions' => __('Suggestions', 'seoprostack'), 'health' => __('Link health', 'seoprostack'), 'clicks' => __('Click events', 'seoprostack'), 'retire' => __('Retire Link Whisper', 'seoprostack'));
        $view = self::query('view', 'report');
        $view = isset($views[$view]) ? $view : 'report';
        echo '<div class="wrap sps-link-tools"><h1>' . esc_html__('Links', 'seoprostack') . '</h1><nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Link tools', 'seoprostack') . '">';
        foreach ($views as $key => $label) {
            echo '<a class="nav-tab' . ($key === $view ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url(array('view' => $key, 'post' => absint(self::query('post'))))) . '"' . ($key === $view ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        $notices = array('insert' => __('Link added. You can undo the last change below.', 'seoprostack'), 'undo' => __('Link change undone.', 'seoprostack'), 'index' => __('Background scan queued. WordPress cron must run to complete it.', 'seoprostack'));
        $notice = self::query('notice');
        if (isset($notices[$notice])) {
            echo '<div class="notice notice-success"><p>' . esc_html($notices[$notice]) . '</p></div>';
        }
        if ('retire' === $view) {
            self::audit_panel();
        } elseif (!self::switched_on()) {
            echo '<p>' . esc_html__('Enable Internal linking tools in SEO Pro Stack → Links to use these tools. Retirement checks are read-only and remain available.', 'seoprostack') . '</p>';
        } elseif ('suggestions' === $view) {
            self::suggestions_panel();
        } elseif ('health' === $view) {
            self::health_panel();
        } elseif ('clicks' === $view) {
            self::clicks_panel();
        } else {
            self::report_panel();
        }
        echo '</div>';
    }

    /** Explain freshness and offer an explicitly requested scan. */
    private static function report_panel() {
        $state = get_option(SEOProStack_Link_Index::STATE, array());
        $data = SEOProStack_Link_Index::report(max(1, absint(self::query('paged', '1'))));
        echo '<h2>' . esc_html__('Published pages', 'seoprostack') . '</h2><p>' . esc_html('rank_math' === $data['provider'] ? __('Counts come from Rank Math’s existing index. Unindexed pages can be absent and cached counts can lag behind edits. Orphans are candidates, not confirmed failures.', 'seoprostack') : __('Counts come from SEO Pro Stack’s stored-content index. Orphans are only candidates; navigation, widgets, dynamic blocks and builder fields can add other incoming links.', 'seoprostack')) . '</p>';
        if (empty($state['done'])) {
            echo '<p><strong>' . esc_html__('Background scan incomplete. Do not treat zero counts as confirmed orphans.', 'seoprostack') . '</strong></p>';
        }
        self::form('index');
        submit_button(__('Refresh background index', 'seoprostack'), 'secondary', 'submit', false);
        echo '</form><div class="sps-link-table"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Page', 'seoprostack') . '</th><th scope="col">' . esc_html__('Incoming', 'seoprostack') . '</th><th scope="col">' . esc_html__('Internal', 'seoprostack') . '</th><th scope="col">' . esc_html__('External', 'seoprostack') . '</th><th scope="col">' . esc_html__('Actions', 'seoprostack') . '</th></tr></thead><tbody>';
        foreach ($data['posts'] as $row) {
            $row = (object) $row;
            $post = get_post((int) $row->post_id);
            if (!SEOProStack_Link_Index::eligible($post)) {
                continue;
            }
            $scan = get_post_meta($post->ID, SEOProStack_Link_Index::META, true);
            $current = is_array($scan) && isset($scan['hash']) && hash_equals($scan['hash'], hash('sha256', $post->post_content));
            echo '<tr><td data-label="' . esc_attr__('Page', 'seoprostack') . '"><a href="' . esc_url(get_permalink($post)) . '">' . esc_html($post->post_title) . '</a>';
            if ('native' === $data['provider'] && (!$current || !empty($scan['truncated']))) {
                echo '<br><strong>' . esc_html__('Incomplete or stale scan', 'seoprostack') . '</strong>';
            }
            echo '</td><td data-label="' . esc_attr__('Incoming', 'seoprostack') . '">' . esc_html((string) $row->incoming_link_count) . '</td><td data-label="' . esc_attr__('Internal', 'seoprostack') . '">' . esc_html((string) $row->internal_link_count) . '</td><td data-label="' . esc_attr__('External', 'seoprostack') . '">' . esc_html((string) $row->external_link_count) . '</td><td><a href="' . esc_url(self::url(array('view' => 'suggestions', 'post' => $post->ID))) . '">' . esc_html__('Suggestions', 'seoprostack') . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
        $page = max(1, absint(self::query('paged', '1')));
        /* translators: 1: current report page, 2: total report pages. */
        echo '<p>' . esc_html(sprintf(__('Page %1$d of %2$d', 'seoprostack'), $page, max(1, $data['pages']))) . '</p>';
        if ($page > 1) {
            echo '<a class="button" href="' . esc_url(self::url(array('paged' => $page - 1))) . '">' . esc_html__('Previous', 'seoprostack') . '</a> ';
        }
        if ($page < $data['pages']) {
            echo '<a class="button" href="' . esc_url(self::url(array('paged' => $page + 1))) . '">' . esc_html__('Next', 'seoprostack') . '</a>';
        }
    }

    /** Show the actual source, target and placement before an approved insert. */
    private static function suggestions_panel() {
        $post_id = absint(self::query('post'));
        $post = get_post($post_id);
        if (!SEOProStack_Link_Index::eligible($post)) {
            echo '<p>' . esc_html__('Choose Suggestions beside a published page on the Pages tab.', 'seoprostack') . '</p>';
            return;
        }
        $direction = 'incoming' === self::query('direction') ? 'incoming' : 'outgoing';
        echo '<h2>' . esc_html($post->post_title) . '</h2><p>' . esc_html__('These are bounded local searches, not semantic AI suggestions. Existing links and protected text are excluded. Builder and dynamic content must be edited in its own editor.', 'seoprostack') . '</p><form method="get" action="' . esc_url(admin_url('tools.php')) . '"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><input type="hidden" name="view" value="suggestions"><input type="hidden" name="post" value="' . esc_attr((string) $post_id) . '"><label for="sps-link-direction">' . esc_html__('Direction', 'seoprostack') . '</label> <select id="sps-link-direction" name="direction"><option value="outgoing"' . selected($direction, 'outgoing', false) . '>' . esc_html__('Add outgoing links', 'seoprostack') . '</option><option value="incoming"' . selected($direction, 'incoming', false) . '>' . esc_html__('Find incoming links', 'seoprostack') . '</option></select> <label for="sps-link-target">' . esc_html__('Optional target title or keyword', 'seoprostack') . '</label> <input id="sps-link-target" type="search" name="target_search" value="' . esc_attr(self::query('target_search')) . '"> <button class="button">' . esc_html__('Find opportunities', 'seoprostack') . '</button></form>';
        $results = SEOProStack_Link_Suggestions::find($post_id, $direction, absint(self::query('target')), self::query('target_search'));
        if (!$results) {
            echo '<p>' . esc_html__('No safe text matches in this candidate set. Try incoming links, a specific target, or edit the source manually.', 'seoprostack') . '</p>';
        }
        foreach ($results as $proposal) {
            echo '<section class="sps-link-opportunity"><h3>' . esc_html(get_the_title($proposal['source'])) . ' → ' . esc_html(get_the_title($proposal['target'])) . '</h3><p>' . esc_html($proposal['context']) . '</p><p><a href="' . esc_url(get_permalink($proposal['target'])) . '">' . esc_html__('View target page', 'seoprostack') . '</a> · <a href="' . esc_url(get_edit_post_link($proposal['source'])) . '">' . esc_html__('Edit source manually', 'seoprostack') . '</a></p>';
            if ($proposal['editable']) {
                self::form('insert', $proposal['source'], $proposal['target']);
                echo '<input type="hidden" name="phrase" value="' . esc_attr($proposal['phrase']) . '"><input type="hidden" name="hash" value="' . esc_attr($proposal['hash']) . '">';
                submit_button(__('Approve and add this link', 'seoprostack'), 'secondary', 'submit', false);
                echo '</form>';
            } else {
                echo '<p>' . esc_html__('Manual edit required: this source contains builder or dynamic content.', 'seoprostack') . '</p>';
            }
            echo '</section>';
        }
        if (get_post_meta($post_id, SEOProStack_Link_Suggestions::UNDO, true)) {
            self::form('undo', $post_id);
            submit_button(__('Undo last link change on this page', 'seoprostack'), 'secondary', 'submit', false);
            echo '</form><p>' . esc_html__('Undo refuses to overwrite later edits. WordPress revisions remain available where enabled.', 'seoprostack') . '</p>';
        }
    }

    /** Display cached status only; never perform a remote check from this view. */
    private static function health_panel() {
        echo '<h2>' . esc_html__('Cached link health', 'seoprostack') . '</h2><p>' . esc_html__('Checks run only when enabled. A 404 or 410 is a broken-link candidate; authentication, rate limits and network errors need review. Queries and private/login/API addresses are not checked automatically.', 'seoprostack') . '</p>';
        if ('rank_math' === SEOProStack_Link_Index::health_provider()) {
            echo '<p>' . esc_html__('Rank Math Link Genius owns this site’s link-health index. SEO Pro Stack does not run a second crawler. Verify that its report is accessible and current before retiring Link Whisper.', 'seoprostack') . '</p><p><a class="button" href="' . esc_url(admin_url('admin.php?page=rank-math-links-page')) . '">' . esc_html__('Open Rank Math link health', 'seoprostack') . '</a></p>';
            return;
        }
        if (!SEOProStack_Settings::get('linking_health')) {
            echo '<p>' . esc_html__('Background checks are off. Enable them in SEO Pro Stack → Links, then refresh the background index.', 'seoprostack') . '</p>';
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded display of cached results only.
        $rows = $wpdb->get_results("SELECT url,status,redirects,checked_at,error FROM {$wpdb->prefix}seoprostack_link_health ORDER BY checked_at DESC LIMIT 100");
        echo '<div class="sps-link-table"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Address', 'seoprostack') . '</th><th scope="col">' . esc_html__('HTTP / error', 'seoprostack') . '</th><th scope="col">' . esc_html__('Redirects', 'seoprostack') . '</th><th scope="col">' . esc_html__('Checked', 'seoprostack') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td data-label="' . esc_attr__('Address', 'seoprostack') . '">' . esc_html($row->url) . '</td><td data-label="' . esc_attr__('HTTP / error', 'seoprostack') . '">' . esc_html($row->error ? $row->error : ($row->status ? (string) $row->status : __('Pending', 'seoprostack'))) . '</td><td data-label="' . esc_attr__('Redirects', 'seoprostack') . '">' . esc_html((string) $row->redirects) . '</td><td data-label="' . esc_attr__('Checked', 'seoprostack') . '">' . esc_html($row->checked_at ? wp_date('Y-m-d H:i', (int) $row->checked_at) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Aggregate counts only, including retained history after opt-out. */
    private static function clicks_panel() {
        echo '<h2>' . esc_html__('Anonymous click events', 'seoprostack') . '</h2><p>' . esc_html__('Approximate events, not unique visitors or proof of human traffic. Destination paths are grouped without queries or fragments. Logged-in visits, privacy signals and known automated browser events are excluded. Public signatures can still be replayed; each link is capped at 5,000 events per UTC day.', 'seoprostack') . '</p>';
        if (!SEOProStack_Settings::get('linking_clicks')) {
            echo '<p><strong>' . esc_html__('Counting is off. No visitor-side counting script or event route is added; retained totals remain visible.', 'seoprostack') . '</strong></p>';
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded aggregate report; no individual visitor records exist.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id,url_hash,MAX(url) AS url,SUM(clicks) AS events FROM {$wpdb->prefix}seoprostack_link_clicks WHERE day >= %s GROUP BY post_id,url_hash ORDER BY events DESC LIMIT 100", gmdate('Y-m-d', time() - 90 * DAY_IN_SECONDS)));
        echo '<div class="sps-link-table"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Source page', 'seoprostack') . '</th><th scope="col">' . esc_html__('Destination path', 'seoprostack') . '</th><th scope="col">' . esc_html__('Events (90 days)', 'seoprostack') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td data-label="' . esc_attr__('Source page', 'seoprostack') . '">' . esc_html(get_the_title((int) $row->post_id)) . '</td><td data-label="' . esc_attr__('Destination path', 'seoprostack') . '">' . esc_html($row->url) . '</td><td data-label="' . esc_attr__('Events (90 days)', 'seoprostack') . '">' . esc_html((string) $row->events) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Always a staging recommendation, never a blanket safety certificate. */
    private static function audit_panel() {
        $audit = SEOProStack_Link_Audit::collect();
        $statuses = array('clear' => __('No stored dependency found', 'seoprostack'), 'blocked' => __('Replacement needed', 'seoprostack'), 'review' => __('Owner review', 'seoprostack'), 'unknown' => __('Unknown schema or query failure', 'seoprostack'));
        echo '<h2>' . esc_html__('Link Whisper retirement checks', 'seoprostack') . '</h2><p>' . esc_html__('Read-only, point-in-time evidence for this site. It does not deactivate plugins, change third-party settings, delete data or prove rendered compatibility. Back up and validate on staging first.', 'seoprostack') . '</p><p><strong>' . esc_html('blocked' === $audit['result'] ? __('Unresolved dependencies or unknown checks remain.', 'seoprostack') : __('No checked blockers found. Staging validation and an explicit click-tracking decision are still required.', 'seoprostack')) . '</strong></p>';
        foreach ($audit['findings'] as $finding) {
            echo '<section class="sps-link-opportunity"><h3>' . esc_html($finding['item']) . '</h3><p><strong>' . esc_html($statuses[$finding['status']]) . '</strong>' . (null === $finding['count'] ? '' : ' — ' . esc_html((string) $finding['count'])) . '</p><p>' . esc_html($finding['note']) . '</p></section>';
        }
    }

    /** @param array $args Positional arguments. @param array $assoc Named arguments. */
    public static function cli_audit($args, $assoc) {
        $audit = SEOProStack_Link_Audit::collect();
        if (isset($assoc['format']) && 'json' === $assoc['format']) {
            WP_CLI::line((string) wp_json_encode($audit, JSON_PRETTY_PRINT));
        } else {
            WP_CLI\Utils\format_items('table', $audit['findings'], array('item', 'status', 'count', 'note'));
            WP_CLI::line($audit['result']);
        }
    }

    /** @param array $args Positional arguments. @param array $assoc Named arguments. */
    public static function cli_index($args, $assoc) {
        if (!self::switched_on()) {
            WP_CLI::error('Enable Internal linking tools first.');
        }
        if (isset($assoc['batch'])) {
            SEOProStack_Link_Index::batch();
        } else {
            SEOProStack_Link_Index::start();
        }
        WP_CLI::line((string) wp_json_encode(get_option(SEOProStack_Link_Index::STATE), JSON_PRETTY_PRINT));
    }
}
