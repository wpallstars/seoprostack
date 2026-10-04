<?php
/**
 * Copy ACF/SCF text to content for search and content analysis.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Field_Content extends SEOProStack_Feature {

    const KEY = 'field_content';
    const HASH_META = '_seoprostack_field_content_hash';

    /** Prevent nested save_post calls while updating core content. @var bool */
    private static $syncing = false;

    /**
     * Settings, with separate preferences for each saved post-type selection.
     *
     * @return array
     */
    public static function settings() {
        $schema = array(
            self::KEY => array(
                'type' => 'bool',
                'default' => false,
                'tab' => 'content',
                'label' => __('Custom fields to content', 'seoprostack'),
                'description' => __('Copies ACF or SCF text into post content for search, SEO analysis and internal links. This replaces existing content. Warning: if your template also displays post content, the copied fields will appear twice. Check your template before enabling a post type. Copies remain when disabled.', 'seoprostack'),
            ),
            'field_content_types' => array(
                'type' => 'multi',
                'open' => true,
                'default' => array(),
                'parent' => self::KEY,
                'label' => __('Post types', 'seoprostack'),
                'description' => __('Choose the post types to sync, then save and reload to set their field options. Uncheck a type to stop syncing it.', 'seoprostack'),
                'reload' => true,
                'options' => array(__CLASS__, 'post_type_options'),
            ),
        );
        // Schema is cached at init:0, before custom post types register.
        $stored = get_option('seoprostack_options', array());
        $types = is_array($stored) && isset($stored['field_content_types']) ? (array) $stored['field_content_types'] : array();
        // Keep deselected types in the schema: the shared store drops unknown
        // keys on any setting save. Losing a skip list could expose private text
        // when the owner enables that type again later.
        foreach (is_array($stored) ? array_keys($stored) : array() as $key) {
            if (preg_match('/^field_content_(.+)_(fields|skip|excerpt|format)$/', (string) $key, $matches)) {
                $types[] = $matches[1];
            }
        }
        foreach ($types as $type) {
            if (!is_string($type) || sanitize_key($type) !== $type || '' === $type) {
                continue;
            }
            $prefix = 'field_content_' . $type . '_';
            $fields = array(
                'fields' => array(
                    'type' => 'multi',
                    'default' => array('text', 'textarea', 'wysiwyg'),
                    'label' => __('Field types', 'seoprostack'),
                    'options' => array('text' => __('Text', 'seoprostack'), 'textarea' => __('Text area', 'seoprostack'), 'wysiwyg' => __('WYSIWYG', 'seoprostack')),
                ),
                'skip' => array(
                    'type' => 'lines',
                    'default' => '',
                    'label' => __('Fields to skip', 'seoprostack'),
                    'description' => __('One field name or field key per line. Skipped fields are never copied, including into the excerpt. Only top-level text fields are copied.', 'seoprostack'),
                ),
                'excerpt' => array(
                    'type' => 'text',
                    'default' => '',
                    'label' => __('Excerpt source field', 'seoprostack'),
                    'description' => __('Field name or field key. Copies its plain text to the excerpt; leave blank to keep the existing excerpt. The field must have an included type and not be skipped.', 'seoprostack'),
                ),
                'format' => array(
                    'type' => 'select',
                    'default' => 'labels',
                    'label' => __('Content format', 'seoprostack'),
                    'options' => array('labels' => __('Label and value paragraphs', 'seoprostack'), 'values' => __('Values only', 'seoprostack')),
                ),
            );
            foreach ($fields as $key => $field) {
                $field['parent'] = self::KEY;
                $field['label'] = $type . ': ' . $field['label'];
                $schema[$prefix . $key] = $field;
            }
        }
        return $schema;
    }

    /**
     * Post types that owners can edit.
     *
     * @return array<string,string>
     */
    public static function post_type_options() {
        $options = array();
        foreach (get_post_types(array('show_ui' => true), 'objects') as $type) {
            if ('attachment' !== $type->name && 0 !== strpos($type->name, 'wp_')) {
                $options[$type->name] = $type->labels->name;
            }
        }
        return $options;
    }

    /** Register after ACF's save_post callback, not save_post_{type}. */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_action('save_post', array(__CLASS__, 'save'), 99);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('seoprostack fields sync', array(__CLASS__, 'cli'));
        }
    }

    /**
     * Sync a normal save, never an autosave or revision.
     *
     * @param int $post_id Post ID.
     */
    public static function save($post_id) {
        if (!self::$syncing && !(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            self::sync($post_id);
        }
    }

    /**
     * Copy generated content once, recording the hash only after success.
     *
     * @param int $post_id Post ID.
     * @return bool|WP_Error True when changed, false when skipped.
     */
    public static function sync($post_id) {
        if (self::$syncing || !self::enabled() || !function_exists('get_field_objects') || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return false;
        }
        $post = get_post($post_id);
        if (!$post || in_array($post->post_status, array('auto-draft', 'trash'), true) || !in_array($post->post_type, (array) SEOProStack_Settings::get('field_content_types'), true)) {
            return false;
        }
        // Raw values avoid shortcodes, embeds and content filters from ACF formatting.
        $fields = get_field_objects($post_id, false);
        if (!is_array($fields)) {
            return false;
        }
        $prefix = 'field_content_' . $post->post_type . '_';
        $types = (array) SEOProStack_Settings::get($prefix . 'fields');
        $skip = preg_split('/[\r\n]+/', (string) SEOProStack_Settings::get($prefix . 'skip'));
        $skip = array_map('trim', $skip ?: array());
        $source = trim((string) SEOProStack_Settings::get($prefix . 'excerpt'));
        $labels = 'values' !== SEOProStack_Settings::get($prefix . 'format');
        $parts = array();
        $excerpt = '';
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['name'], $field['key'], $field['type']) || !in_array($field['type'], array('text', 'textarea', 'wysiwyg'), true) || !in_array($field['type'], $types, true) || in_array($field['name'], $skip, true) || in_array($field['key'], $skip, true) || !isset($field['value']) || !is_scalar($field['value'])) {
                continue;
            }
            $value = wp_kses_post((string) $field['value']);
            if ($source === $field['name'] || $source === $field['key']) {
                $excerpt = trim(wp_strip_all_tags($value));
            }
            if ('' === trim($value)) {
                continue;
            }
            $label = isset($field['label']) ? (string) $field['label'] : (string) $field['name'];
            $parts[] = ($labels ? '<p><strong>' . esc_html($label) . "</strong></p>\n" : '') . wpautop($value);
        }
        $content = implode("\n", $parts);
        // Keeping an excerpt differs from explicitly replacing it with empty text.
        $hash = md5($content . "\0" . ('' !== $source ? "excerpt\0" . $excerpt : 'keep_excerpt')); // NOSONAR: a fingerprint to notice changes, not security; stored, so it stays md5.
        if ($hash === get_post_meta($post_id, self::HASH_META, true)) {
            return false;
        }
        $update = array('ID' => $post_id, 'post_content' => $content);
        if ('' !== $source) {
            $update['post_excerpt'] = $excerpt;
        }
        if ($post->post_content === $content && ('' === $source || $post->post_excerpt === $excerpt)) {
            update_post_meta($post_id, self::HASH_META, $hash);
            return false;
        }
        self::$syncing = true;
        try {
            $result = wp_update_post(wp_slash($update), true);
        } finally {
            self::$syncing = false;
        }
        if (is_wp_error($result)) {
            return $result;
        }
        if (!$result) {
            return new WP_Error('seoprostack_field_content', __('Could not sync custom fields.', 'seoprostack'));
        }
        update_post_meta($post_id, self::HASH_META, $hash);
        return true;
    }

    /**
     * Sync existing posts in bounded batches; run as an authorised site user.
     *
     * ## OPTIONS
     *
     * --post-type=<type>
     * : An enabled post type. Requires --user with manage_options permission.
     *
     * @param string[] $args Positional arguments.
     * @param array    $assoc_args Named arguments.
     */
    public static function cli($args, $assoc_args) {
        if (!current_user_can('manage_options') || !function_exists('get_field_objects')) {
            WP_CLI::error(__('Choose an administrator with --user and activate ACF or SCF first.', 'seoprostack'));
            return;
        }
        $type = isset($assoc_args['post-type']) ? (string) $assoc_args['post-type'] : '';
        if (!post_type_exists($type) || !in_array($type, (array) SEOProStack_Settings::get('field_content_types'), true)) {
            WP_CLI::error(__('Choose a post type enabled in Custom fields to content.', 'seoprostack'));
            return;
        }
        $changed = 0;
        $page = 1;
        do {
            $query = new WP_Query(array('post_type' => $type, 'post_status' => 'any', 'posts_per_page' => 100, 'paged' => $page, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true));
            foreach ($query->posts as $id) {
                if (!current_user_can('edit_post', $id)) {
                    WP_CLI::error(__('You cannot edit a post in this batch.', 'seoprostack'));
                    return;
                }
                $result = self::sync($id instanceof WP_Post ? $id->ID : (int) $id);
                if (is_wp_error($result)) {
                    WP_CLI::error($result->get_error_message());
                    return;
                }
                $changed += true === $result ? 1 : 0;
            }
            ++$page;
        } while (100 === count($query->posts));
        /* translators: %d: number of posts changed. */
        WP_CLI::success(sprintf(__('Synced %d posts; unchanged posts were left alone.', 'seoprostack'), $changed));
    }
}
