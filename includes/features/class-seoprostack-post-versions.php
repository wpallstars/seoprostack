<?php
/**
 * Staged new versions of published posts.
 *
 * "New version" copies a published post to a draft that remembers its
 * original. Edit, preview or share the draft while the live post stays as
 * it is. Publishing the draft (now or scheduled) copies it over the original
 * — title, content, excerpt, terms, custom fields, featured image, template
 * and format — so the URL, comments and publish date are kept and core
 * stores a revision. The draft is then deleted.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Post_Versions extends SEOProStack_Feature {

    const KEY = 'post_versions';

    /** admin-post.php action that stages a version. */
    const ACTION = 'seoprostack_new_version';

    /** admin-post.php action and cron hook that publish a version. */
    const MERGE = 'seoprostack_merge_version';

    /** Meta on the draft: original post ID. */
    const META = '_seoprostack_version_of';

    /**
     * Internal status a staged draft takes instead of "publish" while it is
     * copied over its original, so it is never live at its own address and
     * never triggers publish actions (sharing, pings, sitemaps).
     */
    const STATUS = 'sps-version';

    /** Statuses of a staged draft that is still being edited. */
    const EDITING = array('draft', 'pending', 'future');

    /**
     * Drafts merged in this request: draft ID => original ID.
     *
     * @var array<int,int>
     */
    private static $merged = array();

    /**
     * Original ID => newest staged draft, for this request.
     *
     * @var array<int,WP_Post>|null
     */
    private static $pending = null;

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
                'label'       => __('Staged new versions', 'seoprostack'),
                'description' => __('Edit a published post as a draft copy while the live post stays unchanged. Publishing the copy replaces the original and keeps its address, comments and date.', 'seoprostack'),
            ),
            'post_versions_types' => array(
                'type'    => 'multi',
                'open'    => true,
                'default' => array('post', 'page'),
                'parent'  => self::KEY,
                'label'   => __('Post types', 'seoprostack'),
                'options' => array('SEOProStack_Duplicate_Posts', 'post_type_options'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }

        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_filter('post_row_actions', array(__CLASS__, 'row_action'), 10, 2);
        add_filter('page_row_actions', array(__CLASS__, 'row_action'), 10, 2);
        add_filter('display_post_states', array(__CLASS__, 'post_states'), 10, 2);
        add_action('admin_bar_menu', array(__CLASS__, 'admin_bar_link'), 81);
        add_action('post_submitbox_misc_actions', array(__CLASS__, 'submitbox'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'block_editor'));
        add_action('admin_notices', array(__CLASS__, 'notices'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));

        register_post_status(self::STATUS, array(
            'label'                     => _x('Publishing new version', 'post status', 'seoprostack'),
            'internal'                  => true,
            'public'                    => false,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => false,
            'show_in_admin_status_list' => false,
        ));
        add_filter('wp_insert_post_data', array(__CLASS__, 'hold_status'), 20, 2);
        add_action('wp_after_insert_post', array(__CLASS__, 'on_publish'), 20, 2);
        add_action('publish_future_post', array(__CLASS__, 'publish_scheduled'), 5);
        add_filter('redirect_post_location', array(__CLASS__, 'classic_redirect'), 20, 2);
        add_action('admin_post_' . self::MERGE, array(__CLASS__, 'handle_merge'));
        add_action(self::MERGE, array(__CLASS__, 'cron_merge'));
    }

    /**
     * Original post of a staged draft.
     *
     * @param WP_Post|int $post Draft.
     * @return WP_Post|null
     */
    public static function original_of($post) {
        $post = get_post($post);
        $id   = $post ? (int) get_post_meta($post->ID, self::META, true) : 0;
        $orig = $id ? get_post($id) : null;
        return ($orig && 'trash' !== $orig->post_status) ? $orig : null;
    }

    /**
     * Pending staged draft for an original, if any.
     *
     * @param int $original_id Original post ID.
     * @return WP_Post|null
     */
    public static function pending_version($original_id) {
        if (null === self::$pending) {
            // One query per request: post lists ask for every row.
            self::$pending = array();
            $drafts        = get_posts(array(
                'post_type'        => (array) SEOProStack_Settings::get('post_versions_types'),
                'post_status'      => self::EDITING,
                'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- few rows carry this key.
                'posts_per_page'   => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- pending versions only, once per request.
                'orderby'          => 'ID',
                'order'            => 'ASC',
            )); // get_posts() skips query filters by default.
            foreach ($drafts as $draft) {
                self::$pending[(int) get_post_meta($draft->ID, self::META, true)] = $draft;
            }
        }
        $original_id = (int) $original_id;
        return isset(self::$pending[$original_id]) ? self::$pending[$original_id] : null;
    }

    /**
     * Whether the current user may stage a version of a post.
     *
     * @param WP_Post|int|null $post Post.
     * @return bool
     */
    public static function can_stage($post) {
        $post = get_post($post);
        if (!$post || 'publish' !== $post->post_status || get_post_meta($post->ID, self::META, true)) {
            return false;
        }
        if (!in_array($post->post_type, (array) SEOProStack_Settings::get('post_versions_types'), true)) {
            return false;
        }
        $type = get_post_type_object($post->post_type);
        return $type && current_user_can('edit_post', $post->ID) && current_user_can($type->cap->create_posts);
    }

    /**
     * Link that opens (or creates) the staged version.
     *
     * @param int $post_id Original post ID.
     * @return string
     */
    public static function url($post_id) {
        return wp_nonce_url(
            add_query_arg(array('action' => self::ACTION, 'post' => (int) $post_id), admin_url('admin-post.php')),
            self::ACTION . '_' . (int) $post_id
        );
    }

    /* --------------------------------------------------------------------- */
    /* Links and labels                                                       */
    /* --------------------------------------------------------------------- */

    /**
     * Row action for published posts.
     *
     * @param array   $actions Row actions.
     * @param WP_Post $post    Post.
     * @return array
     */
    public static function row_action($actions, $post) {
        if (self::can_stage($post)) {
            $pending = self::pending_version($post->ID);
            $actions['seoprostack_new_version'] = sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url(self::url($post->ID)),
                $pending ? esc_html__('Edit new version', 'seoprostack') : esc_html__('New version', 'seoprostack')
            );
        }
        return $actions;
    }

    /**
     * Label drafts and originals in post lists.
     *
     * @param string[] $states Post states.
     * @param WP_Post  $post   Post.
     * @return string[]
     */
    public static function post_states($states, $post) {
        $original = self::original_of($post);
        if ($original) {
            /* translators: %s: original post title */
            $states['seoprostack_version'] = sprintf(__('New version of “%s”', 'seoprostack'), get_the_title($original));
        } elseif ('publish' === $post->post_status && self::pending_version($post->ID)) {
            $states['seoprostack_version'] = __('New version in progress', 'seoprostack');
        }
        return $states;
    }

    /**
     * Admin bar link on published single views and edit screens.
     *
     * @param WP_Admin_Bar $bar Admin bar.
     */
    public static function admin_bar_link($bar) {
        $post = null;
        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $post   = ($screen && 'post' === $screen->base) ? get_post() : null;
        } elseif (is_singular()) {
            $post = get_queried_object();
        }
        if ($post instanceof WP_Post && self::can_stage($post)) {
            $bar->add_node(array(
                'id'    => 'seoprostack-new-version',
                'title' => esc_html__('New version', 'seoprostack'),
                'href'  => self::url($post->ID),
                'meta'  => array('title' => __('Edit a copy and publish it over this post later', 'seoprostack')),
            ));
        }
    }

    /**
     * Classic editor Publish box.
     *
     * @param WP_Post $post Post.
     */
    public static function submitbox($post) {
        $original = self::original_of($post);
        if ($original) {
            printf(
                '<div class="misc-pub-section seoprostack-version">%s</div>',
                wp_kses(sprintf(
                    /* translators: %s: linked original post title */
                    __('New version of %s. Publishing replaces it.', 'seoprostack'),
                    sprintf('<a href="%1$s">%2$s</a>', esc_url(get_edit_post_link($original->ID)), esc_html(get_the_title($original)))
                ), array('a' => array('href' => array())))
            );
        } elseif (self::can_stage($post)) {
            printf(
                '<div class="misc-pub-section seoprostack-version"><a href="%1$s">%2$s</a></div>',
                esc_url(self::url($post->ID)),
                esc_html__('Stage a new version', 'seoprostack')
            );
        }
    }

    /**
     * Block editor: button on published posts; notice and redirect on drafts.
     */
    public static function block_editor() {
        $post = get_post();
        if (!$post) {
            return;
        }
        $original = self::original_of($post);
        if (!$original && !self::can_stage($post)) {
            return;
        }

        $data = $original
            ? array(
                'mode'     => 'draft',
                /* translators: %s: original post title */
                'notice'   => sprintf(__('This is a new version of “%s”. Publishing it replaces the live post and removes this draft.', 'seoprostack'), get_the_title($original)),
                'status'   => self::STATUS,
                'mergeUrl' => self::merge_url($post->ID, $original->ID),
            )
            : array(
                'mode'  => 'original',
                'url'   => self::url($post->ID),
                'label' => self::pending_version($post->ID) ? __('Edit new version', 'seoprostack') : __('Stage a new version', 'seoprostack'),
            );

        wp_register_script('seoprostack-versions', false, array('wp-plugins', 'wp-element', 'wp-components', 'wp-editor', 'wp-data', 'wp-notices'), SEOPROSTACK_VERSION, true);
        wp_enqueue_script('seoprostack-versions');
        wp_add_inline_script('seoprostack-versions', sprintf(
            '(function (wp, cfg) {
                if (cfg.mode === "original") {
                    var Info = (wp.editor && wp.editor.PluginPostStatusInfo) || (wp.editPost && wp.editPost.PluginPostStatusInfo);
                    if (!Info) { return; }
                    wp.plugins.registerPlugin("seoprostack-versions", {
                        render: function () {
                            return wp.element.createElement(Info, null,
                                wp.element.createElement(wp.components.Button, { variant: "secondary", href: cfg.url, __next40pxDefaultSize: true }, cfg.label));
                        }
                    });
                    return;
                }
                wp.domReady && wp.domReady(function () {
                    wp.data.dispatch("core/notices").createNotice("info", cfg.notice, { id: "seoprostack-version", isDismissible: false });
                });
                // Publishing leaves the draft in an internal status. Wait for meta
                // boxes (custom field plugins) to save too, then let the server
                // copy the draft over the original.
                var timer = null;
                function busy() {
                    var editor = wp.data.select("core/editor");
                    var edit = wp.data.select("core/edit-post");
                    return editor.isSavingPost() || (edit && edit.isSavingMetaBoxes && edit.isSavingMetaBoxes());
                }
                wp.data.subscribe(function () {
                    var editor = wp.data.select("core/editor");
                    if (timer || !editor || editor.getCurrentPostAttribute("status") !== cfg.status || busy()) {
                        return;
                    }
                    timer = setTimeout(function check() {
                        if (busy()) {
                            timer = setTimeout(check, 300);
                            return;
                        }
                        window.location.href = cfg.mergeUrl;
                    }, 800);
                });
            })(window.wp, %s);',
            wp_json_encode($data)
        ));
    }

    /**
     * Notices on the original's edit screen.
     */
    public static function notices() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        if (!empty($_GET['seoprostack_version_published'])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('The new version is live. Its draft was removed.', 'seoprostack'));
        }
    }

    /**
     * Drop our notice flag from the address bar.
     *
     * @param string[] $args Query args.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = 'seoprostack_version_published';
        return $args;
    }

    /* --------------------------------------------------------------------- */
    /* Stage and merge                                                        */
    /* --------------------------------------------------------------------- */

    /**
     * admin-post.php?action=seoprostack_new_version
     */
    public static function handle() {
        $post_id = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
        check_admin_referer(self::ACTION . '_' . $post_id);

        if (!self::can_stage($post_id)) {
            wp_die(esc_html__('Sorry, you cannot stage a new version of this item.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }

        $draft = self::pending_version($post_id);
        if ($draft) {
            wp_safe_redirect(get_edit_post_link($draft->ID, 'url'));
            exit;
        }

        $parts  = array_diff(SEOProStack_Duplicate_Posts::all_parts(), array('date'));
        $new_id = SEOProStack_Duplicate_Posts::duplicate(get_post($post_id), $parts, array('post_status' => 'draft'));
        if (is_wp_error($new_id)) {
            wp_die(esc_html($new_id->get_error_message()), '', array('response' => 500, 'back_link' => true));
        }
        update_post_meta($new_id, self::META, $post_id);

        wp_safe_redirect(get_edit_post_link($new_id, 'url'));
        exit;
    }

    /**
     * Link the block editor opens once a published draft has fully saved.
     *
     * @param int $draft_id    Draft ID.
     * @param int $original_id Original ID.
     * @return string
     */
    public static function merge_url($draft_id, $original_id) {
        return wp_nonce_url(
            add_query_arg(array('action' => self::MERGE, 'post' => (int) $draft_id, 'original' => (int) $original_id), admin_url('admin-post.php')),
            self::MERGE . '_' . (int) $draft_id
        );
    }

    /**
     * Publishing a staged draft gives it the internal status instead.
     *
     * Permission checks for publishing have already run. Scheduled drafts
     * keep "future" and are handled by publish_scheduled().
     *
     * @param array $data    Slashed post data.
     * @param array $postarr Raw post data.
     * @return array
     */
    public static function hold_status($data, $postarr) {
        if ('publish' === $data['post_status'] && !empty($postarr['ID']) && get_post_meta((int) $postarr['ID'], self::META, true)) {
            $data['post_status'] = self::STATUS;
        }
        return $data;
    }

    /**
     * A staged draft was published (wp_after_insert_post).
     *
     * Classic editor, quick edit and the block editor's meta box request have
     * saved everything by now, so merge and delete the draft at shutdown. The
     * block editor's REST save comes before its meta box save, so it calls
     * merge_url() when done; a cron event 2 minutes out covers a closed tab.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post.
     */
    public static function on_publish($post_id, $post) {
        if (!$post instanceof WP_Post || self::STATUS !== $post->post_status || isset(self::$merged[$post_id])) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            if (!wp_next_scheduled(self::MERGE, array($post_id))) {
                wp_schedule_single_event(time() + 2 * MINUTE_IN_SECONDS, self::MERGE, array($post_id));
            }
            return;
        }
        if (self::merge($post)) {
            add_action('shutdown', function () use ($post_id) {
                wp_clear_scheduled_hook(self::MERGE, array($post_id));
                wp_delete_post($post_id, true);
            });
        }
    }

    /**
     * Scheduled staged draft is due: merge it before core would publish it.
     *
     * @param int $post_id Post ID.
     */
    public static function publish_scheduled($post_id) {
        $post = get_post($post_id);
        if (!$post || 'future' !== $post->post_status || !self::original_of($post)) {
            return;
        }
        if (strtotime($post->post_date_gmt . ' GMT') > time()) {
            return; // Core reschedules early events.
        }
        if (self::merge($post)) {
            wp_delete_post($post->ID, true);
        }
    }

    /**
     * admin-post.php?action=seoprostack_merge_version (block editor).
     */
    public static function handle_merge() {
        $draft_id    = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
        $original_id = isset($_GET['original']) ? absint(wp_unslash($_GET['original'])) : 0;
        check_admin_referer(self::MERGE . '_' . $draft_id);

        $done  = add_query_arg('seoprostack_version_published', 1, get_edit_post_link($original_id, 'url'));
        $draft = get_post($draft_id);
        if (!$draft) {
            // The meta box save already merged and removed it.
            wp_safe_redirect(current_user_can('edit_post', $original_id) ? $done : admin_url());
            exit;
        }

        $original = self::original_of($draft);
        if (!$original || $original->ID !== $original_id || !current_user_can('edit_post', $draft_id) || !current_user_can('edit_post', $original_id)) {
            wp_die(esc_html__('Sorry, this version cannot be published.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
        if (self::STATUS !== $draft->post_status || !self::merge($draft)) {
            wp_safe_redirect(get_edit_post_link($draft_id, 'url'));
            exit;
        }

        wp_clear_scheduled_hook(self::MERGE, array($draft_id));
        wp_delete_post($draft_id, true);
        wp_safe_redirect($done);
        exit;
    }

    /**
     * Cron fallback when the editor tab closed before calling merge_url().
     *
     * @param int $draft_id Draft ID.
     */
    public static function cron_merge($draft_id) {
        $draft = get_post((int) $draft_id);
        if ($draft && self::STATUS === $draft->post_status && self::merge($draft)) {
            wp_delete_post($draft->ID, true);
        }
    }

    /**
     * Copy a published draft over its original.
     *
     * @param WP_Post $post Published staged draft.
     * @return bool Whether the original was updated.
     */
    private static function merge(WP_Post $post) {
        $post_id  = $post->ID;
        $original = self::original_of($post);
        if (!$original || isset(self::$merged[$post_id])) {
            return false;
        }
        self::$merged[$post_id] = $original->ID;

        $updated = wp_update_post(wp_slash(array(
            'ID'             => $original->ID,
            'post_title'     => $post->post_title,
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_author'    => (int) $post->post_author,
            'menu_order'     => $post->menu_order,
            'post_password'  => $post->post_password,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
        )), true);
        if (is_wp_error($updated) || !$updated) {
            // Keep the edits as a draft rather than lose them or leave a duplicate live.
            unset(self::$merged[$post_id]);
            wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
            return false;
        }

        SEOProStack_Duplicate_Posts::copy_terms($post, $original->ID);
        SEOProStack_Duplicate_Posts::copy_meta($post_id, $original->ID, true);

        if (has_post_thumbnail($post)) {
            set_post_thumbnail($original->ID, (int) get_post_thumbnail_id($post));
        } else {
            delete_post_thumbnail($original->ID);
        }
        $template = get_post_meta($post_id, '_wp_page_template', true);
        if ($template) {
            update_post_meta($original->ID, '_wp_page_template', $template);
        } else {
            delete_post_meta($original->ID, '_wp_page_template');
        }
        if (post_type_supports($post->post_type, 'post-formats')) {
            set_post_format($original->ID, (string) get_post_format($post));
        }

        /**
         * Fires after a staged version is copied over its original.
         *
         * @param int $original_id Original post ID.
         * @param int $draft_id    Staged draft ID (about to be deleted).
         */
        do_action('seoprostack_version_published', $original->ID, $post_id);

        return true;
    }

    /**
     * Classic editor: go to the original after publishing a staged draft.
     *
     * @param string $location Redirect URL.
     * @param int    $post_id  Post ID.
     * @return string
     */
    public static function classic_redirect($location, $post_id) {
        if (isset(self::$merged[$post_id])) {
            return add_query_arg('seoprostack_version_published', 1, get_edit_post_link(self::$merged[$post_id], 'url'));
        }
        return $location;
    }
}
