<?php
/**
 * Site templates: shipped designs with site-owned edits preserved.
 *
 * site-templates/{slug}.json: slug, name, version, updated, notes; pages and
 * elements are lists of {key,title,slug,template,parts:[{key,markup}],meta:{}}.
 * Parts are top-level core/group blocks with a stable className. Never rename
 * keys: a missing tracked part is a site deletion, not permission to recreate.
 * custom_css is a string (the leading base; the remainder belongs to the site).
 * options and widgets map option names to named-key trees; theme_mods is a
 * named-key tree. menus maps theme locations to {name,items:{key:{title,url}}}.
 * images is a list of {key,filename,sha256,placeholder}; files live beneath
 * site-templates/{slug}/images/. {{image:key}} resolves to an imported URL.
 * Use the existing Form by key block/shortcode, never numeric form IDs.
 * See site-templates/README.md for limits, ownership and undo semantics.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Site_Templates extends SEOProStack_Feature {

    const KEY = 'site_templates';
    const OPTION = 'seoprostack_site_template';
    const HOOK = 'seoprostack_site_template_update';
    const SEEN = 'seoprostack_site_template_seen';
    const META = '_seoprostack_template_key';

    /** @var array Site ownership, journal and resumable progress. */
    private $record;
    /** @var bool Read-only comparison. */
    private $dry;
    /** @var array Comparison rows. */
    private $rows = array();

    /** @return array */
    public static function settings() {
        return array(self::KEY => array(
            'type' => 'bool', 'default' => true, 'tab' => 'content',
            'label' => __('Update unedited template parts automatically', 'seoprostack'),
            'description' => __('Only sites made from a site template are updated. Edited or removed parts stay as they are. WP-CLI can compare, apply and undo template changes.', 'seoprostack'),
        ));
    }

    /** Register CLI independently of automatic updates. */
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-site-templates-cli.php';
            WP_CLI::add_command('seoprostack site-templates', 'SEOProStack_Site_Templates_CLI');
        }
        add_action(self::HOOK, array(__CLASS__, 'cron'));
        add_action('wp_loaded', array(__CLASS__, 'maybe_schedule'));
        add_action('upgrader_process_complete', array(__CLASS__, 'maybe_schedule'));
        add_action('seoprostack_settings_changed', array(__CLASS__, 'maybe_schedule'));
    }

    /** Each site discovers its own version change; never walk a network here. */
    public static function maybe_schedule() {
        if (!self::enabled() || !get_option(self::OPTION)) {
            return;
        }
        if (SEOPROSTACK_VERSION !== get_option(self::SEEN) && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 30, self::HOOK);
        }
    }

    /** A bounded batch; failures remain visible in the site record. */
    public static function cron() {
        $record = get_option(self::OPTION, array());
        if (!self::enabled() || empty($record['slug'])) {
            return;
        }
        $result = self::run($record['slug']);
        if (is_wp_error($result)) {
            $record = get_option(self::OPTION, array());
            $record['error'] = $result->get_error_message();
            update_option(self::OPTION, $record, false);
            return;
        }
        if ($result['pending']) {
            wp_schedule_single_event(time() + 30, self::HOOK);
        } else {
            update_option(self::SEEN, SEOPROSTACK_VERSION, false);
        }
    }

    /** @return array Shipped, valid manifests. */
    public static function manifests() {
        $out = array();
        foreach (glob(SEOPROSTACK_DIR . 'site-templates/*.json') ?: array() as $file) {
            $slug = basename($file, '.json');
            $manifest = self::manifest($slug);
            if (!is_wp_error($manifest)) {
                $out[$slug] = $manifest;
            }
        }
        return $out;
    }

    /**
     * Validate the entire manifest before any writes, including duplicate keys.
     *
     * @param string $slug Shipped slug, never a path or URL.
     * @return array|WP_Error
     */
    public static function manifest($slug) {
        if (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
            return new WP_Error('template_slug', 'Invalid template slug.');
        }
        $file = SEOPROSTACK_DIR . 'site-templates/' . $slug . '.json';
        if (!is_readable($file) || filesize($file) > 2097152) {
            return new WP_Error('template_file', 'Template missing or larger than 2 MB.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded, shipped local JSON only.
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || ($data['slug'] ?? '') !== $slug || empty($data['version']) || !is_string($data['version']) || empty($data['name']) || !is_string($data['name'])) {
            return new WP_Error('template_schema', 'Template needs slug, name and a string version.');
        }
        foreach (array('pages', 'elements', 'images', 'options', 'widgets', 'theme_mods', 'menus') as $section) {
            if (isset($data[$section]) && !is_array($data[$section])) {
                return new WP_Error('template_schema', 'Invalid section: ' . $section);
            }
            $data[$section] = $data[$section] ?? array();
        }
        foreach (array('pages', 'elements', 'images') as $section) {
            $keys = array();
            foreach ($data[$section] as $item) {
                if (!is_array($item) || !self::valid_key($item['key'] ?? '') || isset($keys[$item['key']])) {
                    return new WP_Error('template_schema', 'Invalid or duplicate key in ' . $section);
                }
                $keys[$item['key']] = true;
                if ('images' === $section) {
                    $filename = $item['filename'] ?? '';
                    if (!is_string($filename) || basename($filename) !== $filename || !preg_match('/\.(png|jpe?g|webp|gif|avif)$/iD', $filename) || !isset($item['sha256']) || !is_string($item['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $item['sha256'])) {
                        return new WP_Error('template_schema', 'Invalid image filename or SHA-256.');
                    }
                    continue;
                }
                if (empty($item['title']) || empty($item['slug']) || !isset($item['parts']) || !is_array($item['parts']) || (isset($item['meta']) && !is_array($item['meta']))) {
                    return new WP_Error('template_schema', 'Posts need title, slug and parts.');
                }
                if (!is_string($item['title']) || !is_string($item['slug']) || sanitize_title($item['slug']) !== $item['slug'] || (isset($item['template']) && !is_string($item['template']))) {
                    return new WP_Error('template_schema', 'Invalid post title, slug or page template.');
                }
                $parts = array();
                foreach ($item['parts'] as $part) {
                    if (!is_array($part) || !self::valid_key($part['key'] ?? '') || isset($parts[$part['key']]) || !isset($part['markup']) || !is_string($part['markup'])) {
                        return new WP_Error('template_schema', 'Invalid or duplicate part key/markup.');
                    }
                    $parts[$part['key']] = true;
                }
            }
        }
        if (isset($data['custom_css']) && !is_string($data['custom_css'])) {
            return new WP_Error('template_schema', 'custom_css must be a string.');
        }
        foreach ($data['menus'] as $location => $menu) {
            if (!self::valid_key($location) || !is_array($menu) || empty($menu['name']) || !is_string($menu['name']) || !isset($menu['items']) || !is_array($menu['items'])) {
                return new WP_Error('template_schema', 'Menus need a location, name and keyed items.');
            }
            foreach ($menu['items'] as $key => $item) {
                if (!self::valid_key($key) || !is_array($item) || !isset($item['title'], $item['url']) || !is_string($item['title']) || !is_string($item['url']) || !esc_url_raw($item['url'])) {
                    return new WP_Error('template_schema', 'Invalid menu item.');
                }
            }
        }
        return $data;
    }

    /** @param mixed $key Stable key. @return bool */
    private static function valid_key($key) {
        return is_string($key) && 1 === preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D', $key);
    }

    /** @param array $record Stored ownership. @param bool $dry Read-only. */
    private function __construct($record, $dry) {
        $this->record = $record;
        $this->dry = $dry;
    }

    /**
     * Apply one bounded batch, or compare without saving anything.
     *
     * @param string $slug Manifest slug.
     * @param bool   $dry Read only.
     * @return array|WP_Error
     */
    public static function run($slug, $dry = false) {
        $manifest = self::manifest($slug);
        if (is_wp_error($manifest)) {
            return $manifest;
        }
        $record = get_option(self::OPTION, array());
        if ($record && ($record['slug'] ?? '') !== $slug) {
            return new WP_Error('template_owned', 'This site already follows another template.');
        }
        $runner = new self(is_array($record) ? $record : array(), $dry);
        if ($dry) {
            return $runner->batch($manifest);
        }
        // MySQL advisory locks are connection-owned and released even on a crash.
        // Unlike an expiring option lease, a slow worker cannot lose ownership.
        global $wpdb;
        $lock = 'sps-template-' . hash('sha256', DB_NAME . ':' . $wpdb->prefix);
        $lock = substr($lock, 0, 64);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-scoped exclusion, no table data/cache involved.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return new WP_Error('template_busy', 'Another template run owns this site.');
        }
        try {
            // Re-read after acquiring the lock; another runner may have finished.
            wp_cache_delete(self::OPTION, 'options');
            $fresh = get_option(self::OPTION, array());
            if ($fresh && ($fresh['slug'] ?? '') !== $slug) {
                return new WP_Error('template_owned', 'This site already follows another template.');
            }
            $runner->record = is_array($fresh) ? $fresh : array();
            return $runner->batch($manifest);
        } catch (RuntimeException $error) {
            return new WP_Error('template_storage', $error->getMessage());
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release this connection's advisory lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** @param array $manifest Validated input. @return array|WP_Error */
    private function batch($manifest) {
        $signature = hash('sha256', (string) wp_json_encode($manifest));
        if (!empty($this->record['pending']) && $this->record['pending']['signature'] !== $signature) {
            return new WP_Error('template_pending', 'Finish or undo the pending manifest before replacing it.');
        }
        if (!$this->dry) {
            if (!empty($this->record['undoing'])) {
                return new WP_Error('template_undo_pending', 'Finish the interrupted undo before applying again.');
            }
            foreach ($this->record['backup']['writes'] ?? array() as $write) {
                if (empty($write['complete']) || !empty($write['undone'])) {
                    return new WP_Error('template_interrupted', 'A resource write was interrupted. Undo the partial run before applying again.');
                }
            }
        }
        $ops = array();
        foreach (array('images', 'pages', 'elements', 'menus') as $section) {
            foreach ($manifest[$section] as $key => $item) {
                $ops[] = array($section, 'menus' === $section ? $key : $item['key'], $item);
            }
        }
        // Core stores the custom CSS post ID in theme mods. Write CSS before
        // snapshotting named settings, so that cache update is not mistaken
        // for a later site edit during undo's preflight.
        if (isset($manifest['custom_css'])) {
            $ops[] = array('css', get_stylesheet(), $manifest['custom_css']);
        }
        foreach (array('options', 'widgets') as $section) {
            foreach ($manifest[$section] as $name => $values) {
                if (!is_string($name) || preg_match('/(?:password|secret|token|licen[cs]e|api.?key|^seoprostack_|^active_plugins$|^siteurl$|^home$|^cron$|^users_can_register$|^default_role$)/i', $name)) {
                    return new WP_Error('template_option', 'Protected option name.');
                }
                $ops[] = array('option', $name, $values);
            }
        }
        if ($manifest['theme_mods']) {
            $ops[] = array('option', 'theme_mods_' . get_stylesheet(), $manifest['theme_mods']);
        }
        if (count($ops) > 500) {
            return new WP_Error('template_size', 'A manifest may contain at most 500 resources.');
        }
        if ($this->dry) {
            $this->record['pending']['initial'] = empty($this->record['slug']);
            $this->record['slug'] = $manifest['slug'];
        }
        if (!$this->dry && empty($this->record['pending'])) {
            $before = $this->record;
            unset($before['backup']);
            $previous_backup = $this->record['backup'] ?? null;
            $this->record['backup'] = array('record' => $before, 'writes' => array());
            $this->record['pending'] = array('signature' => $signature, 'cursor' => 0, 'initial' => empty($before), 'previous_backup' => $previous_backup);
            $this->record['slug'] = $manifest['slug'];
            $this->record['last_result'] = array();
            unset($this->record['error']);
            $this->save();
        }
        $start = microtime(true);
        $cursor = $this->dry ? 0 : $this->record['pending']['cursor'];
        $count = 0;
        foreach (array_slice($ops, $cursor) as $op) {
            $method = 'apply_' . $op[0];
            $result = $this->$method($op[1], $op[2]);
            if (is_wp_error($result)) {
                return $result;
            }
            ++$cursor;
            ++$count;
            if (!$this->dry) {
                foreach ($this->record['backup']['writes'] as &$write) {
                    $write['complete'] = true;
                }
                unset($write);
                $this->record['pending']['cursor'] = $cursor;
                $this->save();
                if ($count >= 20 || microtime(true) - $start > 10) {
                    break;
                }
            }
        }
        $pending = !$this->dry && $cursor < count($ops);
        if (!$this->dry && !$pending) {
            $named_posts = array();
            foreach (array('pages', 'elements') as $section) {
                foreach ($manifest[$section] as $item) {
                    $named_posts[] = $section . '/' . $item['key'];
                }
            }
            foreach (array_diff(array_keys($this->record['posts'] ?? array()), $named_posts) as $path) {
                if (is_string($path)) {
                    $this->report($path, 'removed-from-template');
                }
            }
            $this->record['version'] = $manifest['version'];
            if (!$this->record['backup']['writes'] && !empty($this->record['pending']['previous_backup'])) {
                $this->record['backup'] = $this->record['pending']['previous_backup'];
            }
            unset($this->record['pending']);
            $this->save();
        }
        return array('pending' => $pending, 'rows' => $this->rows, 'record' => $this->record);
    }

    /** Persist only our own state, never autoload the backup/content. */
    private function save() {
        if (!$this->dry) {
            update_option(self::OPTION, $this->record, false);
            if (get_option(self::OPTION) !== $this->record) {
                throw new RuntimeException('Unable to save the template journal; stopped before further changes.');
            }
        }
    }

    /** @param string $key Resource. @param string $status Outcome. */
    private function report($key, $status) {
        $this->rows[] = array('part' => $key, 'status' => $status);
        $this->record['last_result'][$key] = $status;
    }

    /** @param mixed $value Value to fingerprint. @return string */
    private static function fingerprint($value) {
        return hash('sha256', is_string($value) ? $value : (string) wp_json_encode($value));
    }

    /**
     * Remember a write before executing it. Undo checks the exact after-image
     * so edits made after apply are not overwritten either.
     *
     * @param string $type Storage kind.
     * @param int|string $id Target.
     * @param mixed $before Previous value.
     * @param mixed $after Intended value.
     */
    private function journal($type, $id, $before, $after) {
        $this->record['backup']['writes'][] = array('type' => $type, 'id' => $id, 'before' => $before, 'after' => $after);
        $this->save();
    }

    /** @param string $key Page key. @param array $item Post manifest. @return true|WP_Error */
    private function apply_pages($key, $item) {
        return $this->apply_post('pages/' . $key, 'page', $item);
    }

    /** @param string $key Element key. @param array $item Post manifest. @return true|WP_Error */
    private function apply_elements($key, $item) {
        if (!post_type_exists('kadence_element')) {
            $this->report('elements/' . $key, 'skipped: Kadence Elements inactive');
            return true;
        }
        return $this->apply_post('elements/' . $key, 'kadence_element', $item);
    }

    /**
     * @param string $path Stable resource key.
     * @param string $type Post type.
     * @param array $item Manifest post.
     * @return true|WP_Error
     */
    private function apply_post($path, $type, $item) {
        $old = $this->record['posts'][$path] ?? array();
        $post = !empty($old['id']) ? get_post($old['id']) : null;
        if ($old && (!$post || 'trash' === $post->post_status || $post->post_type !== $type || get_post_meta($post->ID, self::META, true) !== $path)) {
            $this->report($path, 'removed-on-site');
            return true;
        }
        if (!$old && get_page_by_path($item['slug'], OBJECT, $type)) {
            $this->report($path, 'edited-on-site');
            return true;
        }
        $blocks = $post ? parse_blocks($post->post_content) : array();
        $indices = array();
        foreach ($blocks as $index => $block) {
            $class = $block['attrs']['className'] ?? '';
            if ('core/group' === $block['blockName'] && 0 === strpos($class, 'sps-template-part-')) {
                $indices[substr($class, 18)][] = $index;
            }
        }
        $hashes = $old['parts'] ?? array();
        foreach ($item['parts'] as $part) {
            $key = $part['key'];
            $label = $path . '/' . $key;
            $markup = $this->image_tokens($part['markup']);
            if (null === $markup) {
                $this->report($label, 'skipped: image unavailable');
                continue;
            }
            if (false !== strpos($markup, 'seoprostack/form') || false !== strpos($markup, '[seoprostack_form')) {
                if (!shortcode_exists('seoprostack_form')) {
                    $this->report($label, 'skipped: Fluent Forms inactive');
                    continue;
                }
            }
            $class = 'sps-template-part-' . $key;
            $wrapped = '<!-- wp:group {"className":"' . $class . '","layout":{"type":"default"}} -->' . "\n" . '<div class="wp-block-group ' . $class . '">' . $markup . '</div>' . "\n" . '<!-- /wp:group -->';
            $new = parse_blocks($wrapped)[0];
            $hash = self::fingerprint(serialize_block($new));
            $found = $indices[$key] ?? array();
            if (isset($hashes[$key]) && !$found) {
                $this->report($label, 'removed-on-site');
                continue;
            }
            if (count($found) > 1 || ($found && (!isset($hashes[$key]) || self::fingerprint(serialize_block($blocks[$found[0]])) !== $hashes[$key]))) {
                $this->report($label, 'edited-on-site');
                continue;
            }
            $this->report($label, !$found ? 'added' : ($hash === $hashes[$key] ? 'same' : 'updated'));
            if ($found) {
                $blocks[$found[0]] = $new;
            } else {
                $blocks[] = $new;
            }
            $hashes[$key] = $hash;
        }
        $named = array_column($item['parts'], 'key');
        foreach (array_diff(array_keys($hashes), $named) as $key) {
            $this->report($path . '/' . $key, 'removed-from-template');
        }
        $fields = array('post_title' => $item['title'], 'post_name' => $item['slug']);
        $write = array('post_type' => $type, 'post_status' => $post ? $post->post_status : 'publish', 'post_content' => serialize_blocks($blocks));
        $field_hashes = $old['fields'] ?? array();
        foreach ($fields as $field => $value) {
            if (!$post || (isset($field_hashes[$field]) && self::fingerprint($post->$field) === $field_hashes[$field])) {
                $write[$field] = $value;
                $field_hashes[$field] = self::fingerprint($value);
            } else {
                $this->report($path . '/' . $field, 'edited-on-site');
            }
        }
        $meta = $item['meta'] ?? array();
        if (isset($item['template'])) {
            $meta['_wp_page_template'] = $item['template'];
        }
        $meta[self::META] = $path;
        $meta_hashes = $old['meta'] ?? array();
        $write['meta_input'] = array();
        foreach ($meta as $name => $value) {
            if (is_scalar($value) || null === $value) {
                $value = (string) $value;
            }
            $current = $post ? get_post_meta($post->ID, $name, true) : '';
            if (!$post || (isset($meta_hashes[$name]) && self::fingerprint($current) === $meta_hashes[$name])) {
                $write['meta_input'][$name] = $value;
                $meta_hashes[$name] = self::fingerprint($value);
            } elseif (self::META !== $name) {
                $this->report($path . '/meta/' . $name, 'edited-on-site');
            }
        }
        if ($this->dry) {
            return true;
        }
        if ($post) {
            $write['ID'] = $post->ID;
        }
        $before = $post ? self::post_snapshot($post->ID, array_keys($write['meta_input'])) : null;
        $after = $before ? array_replace($before, $write) : $write;
        unset($after['ID']);
        if ($before !== $after) {
            $this->journal('post', $post ? $post->ID : 0, $before, $after);
            $id = $post ? wp_update_post(wp_slash($write), true) : wp_insert_post(wp_slash($write), true);
            if (is_wp_error($id)) {
                return $id;
            }
            $last = count($this->record['backup']['writes']) - 1;
            $this->record['backup']['writes'][$last]['id'] = $id;
            $this->record['backup']['writes'][$last]['after'] = self::post_snapshot($id, array_keys($write['meta_input']));
            SEOProStack_Page_Cache::purge_posts(array($id));
        } else {
            $id = $post->ID;
        }
        $this->record['posts'][$path] = array('id' => $id, 'parts' => $hashes, 'fields' => $field_hashes, 'meta' => $meta_hashes);
        // Core may normalise metadata or filter block markup. Own exactly what
        // reached storage, never the pre-filter input or preserved site edits.
        $saved = get_post($id);
        if ($saved) {
            $this->record['posts'][$path]['sha256'] = self::fingerprint($saved->post_content);
            foreach ($write['meta_input'] as $name => $value) {
                $this->record['posts'][$path]['meta'][$name] = self::fingerprint(get_post_meta($id, $name, true));
            }
            foreach ($fields as $field => $value) {
                if (isset($write[$field])) {
                    $this->record['posts'][$path]['fields'][$field] = self::fingerprint($saved->$field);
                }
            }
            foreach (parse_blocks($saved->post_content) as $block) {
                $class = $block['attrs']['className'] ?? '';
                if ('core/group' !== $block['blockName'] || 0 !== strpos($class, 'sps-template-part-')) {
                    continue;
                }
                $key = substr($class, 18);
                if (isset($hashes[$key]) && ($old['parts'][$key] ?? null) !== $hashes[$key]) {
                    $this->record['posts'][$path]['parts'][$key] = self::fingerprint(serialize_block($block));
                }
            }
        }
        $this->save();
        return true;
    }

    /** @param int $id Post. @param array $keys Meta names. @return array */
    private static function post_snapshot($id, $keys) {
        $post = get_post($id);
        if (!$post) {
            return array();
        }
        $out = array();
        foreach (array('post_type', 'post_status', 'post_content', 'post_title', 'post_name') as $field) {
            $out[$field] = $post->$field;
        }
        $out['meta_input'] = array();
        foreach ($keys as $key) {
            $out['meta_input'][$key] = metadata_exists('post', $id, $key) ? get_post_meta($id, $key, true) : null;
        }
        return $out;
    }

    /** @param string $markup Markup with local image tokens. @return string|null */
    private function image_tokens($markup) {
        $missing = false;
        $out = preg_replace_callback('/\{\{image:([a-z0-9_-]+)\}\}/', function ($match) use (&$missing) {
            $id = $this->record['images'][$match[1]]['id'] ?? 0;
            $url = $id ? wp_get_attachment_url($id) : false;
            if (!$url) {
                $missing = true;
            }
            return $url ? esc_url($url) : '';
        }, $markup);
        return $missing ? null : $out;
    }

    /** @param string $name Option. @param mixed $values Named keys or scalar. @return true|WP_Error */
    private function apply_option($name, $values) {
        // Non-serialized option scalars return as strings on the next request.
        // Preserve types inside arrays, which WordPress serializes intact.
        if (is_scalar($values) || null === $values) {
            $values = (string) $values;
        }
        $before = self::option_snapshot($name);
        $removed = !$before['exists'] && $this->owned_path('options/' . $name);
        $after = $this->merge_keys('options/' . $name, $before['value'], $values, $before['exists']);
        if (!$this->dry && $before['value'] !== $after && !$removed) {
            $this->journal('option', $name, $before, array('exists' => true, 'value' => $after));
            update_option($name, $after, false);
            if (self::option_snapshot($name) !== array('exists' => true, 'value' => $after)) {
                return new WP_Error('template_write', 'Option write failed: ' . $name);
            }
            $this->purge();
        }
        return true;
    }

    /** @param string $name Option name. @return array */
    private static function option_snapshot($name) {
        $missing = new stdClass();
        $value = get_option($name, $missing);
        if (is_scalar($value) || null === $value) {
            $value = (string) $value;
        }
        return array('exists' => $value !== $missing, 'value' => $value === $missing ? null : $value);
    }

    /** @param string $path Ownership subtree. @return bool */
    private function owned_path($path) {
        foreach (array_keys($this->record['values'] ?? array()) as $key) {
            if (is_string($key) && ($key === $path || 0 === strpos($key, $path . '/'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Merge only manifest leaves, preserving unmentioned and site-edited keys.
     * @param string $path Ownership path.
     * @param mixed $current Current subtree.
     * @param mixed $desired Manifest subtree.
     * @param bool $exists Key exists.
     * @return mixed
     */
    private function merge_keys($path, $current, $desired, $exists) {
        if (!$exists && $this->owned_path($path)) {
            $this->report($path, 'removed-on-site');
            return $current;
        }
        if (is_array($desired)) {
            if ($exists && !is_array($current)) {
                $this->report($path, 'edited-on-site');
                return $current;
            }
            $out = is_array($current) ? $current : array();
            foreach ($desired as $key => $value) {
                $child = $path . '/' . rawurlencode((string) $key);
                if (!array_key_exists($key, $out) && $this->owned_path($child)) {
                    $this->report($child, 'removed-on-site');
                    continue;
                }
                $out[$key] = $this->merge_keys($child, $out[$key] ?? null, $value, array_key_exists($key, $out));
            }
            return $out;
        }
        $hash = $this->record['values'][$path] ?? null;
        if ($exists && (!$hash || self::fingerprint($current) !== $hash) && empty($this->record['pending']['initial'])) {
            $this->report($path, $current === $desired && !$hash ? 'same' : 'edited-on-site');
            // Equal pre-existing values may be adopted by an explicit apply.
            if ($current === $desired && !$hash) {
                $this->record['values'][$path] = self::fingerprint($desired);
            }
            return $current;
        }
        $this->report($path, !$exists ? 'added' : ($current === $desired ? 'same' : 'updated'));
        $this->record['values'][$path] = self::fingerprint($desired);
        return $desired;
    }

    /** @param string $theme Theme. @param string $base Leading CSS. @return true|WP_Error */
    private function apply_css($theme, $base) {
        $current = wp_get_custom_css($theme);
        $old = $this->record['css'][$theme] ?? null;
        $prefix = is_array($old) ? $old['base'] : '';
        if ((null === $old && '' !== $current) || 0 !== strpos($current, $prefix)) {
            $this->report('custom_css', 'edited-on-site');
            return true;
        }
        $after = $base . substr($current, strlen($prefix));
        $this->report('custom_css', $after === $current ? 'same' : 'updated');
        if (!$this->dry && $after !== $current) {
            $this->journal('css', $theme, $current, $after);
            $result = wp_update_custom_css_post($after, array('stylesheet' => $theme));
            if (is_wp_error($result)) {
                return $result;
            }
            $stored = wp_get_custom_css($theme);
            $last = count($this->record['backup']['writes']) - 1;
            $this->record['backup']['writes'][$last]['after'] = $stored;
            $this->save();
            $this->purge();
            if ($stored !== $after) {
                return new WP_Error('template_css_filtered', 'A CSS filter changed the base. Undo this run before applying again.');
            }
        }
        $this->record['css'][$theme] = array('base' => $base, 'sha256' => self::fingerprint($base));
        return true;
    }

    /** Purge this site's page caches, including deferred cache plugins. */
    private function purge() {
        delete_option(SEOProStack_Page_Cache::OPTION);
        SEOProStack_Page_Cache::after_update();
    }

    /** @param string $key Image key. @param array $image Manifest image. @return true|WP_Error */
    private function apply_images($key, $image) {
        $old = $this->record['images'][$key] ?? null;
        if ($old) {
            $attachment = get_post($old['id']);
            if (!$attachment || 'attachment' !== $attachment->post_type || 'trash' === $attachment->post_status || get_post_meta($attachment->ID, self::META, true) !== 'images/' . $key) {
                $this->report('images/' . $key, 'removed-on-site');
                return true;
            }
            $file = get_attached_file($old['id']);
            $same = $file && is_readable($file) && hash_file('sha256', $file) === $old['sha256'];
            if (!$same || empty($old['placeholder']) || ($old['source_sha256'] ?? $old['sha256']) === $image['sha256']) {
                $this->report('images/' . $key, $same ? 'same' : ($file && is_readable($file) ? 'edited-on-site' : 'removed-on-site'));
                return true;
            }
            // Only an untouched placeholder may advance to a new image. Import
            // a new attachment; never alter/delete the old file or attachment.
        }
        $file = SEOPROSTACK_DIR . 'site-templates/' . $this->record['slug'] . '/images/' . $image['filename'];
        if (!is_readable($file) || hash_file('sha256', $file) !== $image['sha256']) {
            return new WP_Error('template_image', 'Missing image or SHA-256 mismatch: ' . $key);
        }
        $this->report('images/' . $key, $old ? 'updated' : 'added');
        if ($this->dry) {
            return true;
        }
        $this->journal('retained_media', $key, null, null);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $temp = wp_tempnam($image['filename']);
        if (!$temp || !copy($file, $temp)) {
            return new WP_Error('template_image', 'Unable to prepare image import.');
        }
        $id = media_handle_sideload(array('name' => $image['filename'], 'tmp_name' => $temp), 0);
        if (is_wp_error($id)) {
            wp_delete_file($temp);
            return $id;
        }
        update_post_meta($id, self::META, 'images/' . $key);
        $imported = get_attached_file($id);
        if (!$imported || !is_readable($imported)) {
            return new WP_Error('template_image', 'Imported image has no readable attachment.');
        }
        $this->record['images'][$key] = array('id' => $id, 'sha256' => hash_file('sha256', $imported), 'source_sha256' => $image['sha256'], 'placeholder' => !empty($image['placeholder']));
        $this->save();
        // Undo detaches ownership but retains media so existing links never break.
        return true;
    }

    /** @param string $location Theme menu location. @param array $menu Manifest menu. @return true|WP_Error */
    private function apply_menus($location, $menu) {
        $locations = get_nav_menu_locations();
        $old = $this->record['menus'][$location] ?? array();
        if (!$old && !empty($locations[$location])) {
            $this->report('menus/' . $location, 'edited-on-site');
            return true;
        }
        $id = $old['id'] ?? 0;
        $existing_menu = $id ? wp_get_nav_menu_object($id) : false;
        if ($id && (!$existing_menu || ($locations[$location] ?? 0) !== $id)) {
            $this->report('menus/' . $location, 'removed-on-site');
            return true;
        }
        if (!$id && !$this->dry) {
            $this->journal('retained_menu', $location, null, null);
            $id = wp_create_nav_menu($menu['name']);
            if (is_wp_error($id)) {
                return $id;
            }
            $this->journal('locations', get_stylesheet(), $locations, array_merge($locations, array($location => $id)));
            $locations[$location] = $id;
            set_theme_mod('nav_menu_locations', $locations);
        }
        foreach ($menu['items'] as $key => $item) {
            $path = 'menus/' . $location . '/' . $key;
            $tracked = $old['items'][$key] ?? array();
            $post = !empty($tracked['id']) ? get_post($tracked['id']) : null;
            $current = $post ? array('title' => $post->post_title, 'url' => get_post_meta($post->ID, '_menu_item_url', true), 'status' => $post->post_status) : null;
            if ($tracked && (!$post || 'trash' === $post->post_status || 'nav_menu_item' !== $post->post_type || get_post_meta($post->ID, self::META, true) !== $path || !$existing_menu || !has_term($existing_menu->term_id, 'nav_menu', $post->ID))) {
                $this->report($path, 'removed-on-site');
                continue;
            }
            if ($tracked && self::fingerprint($current) !== $tracked['hash']) {
                $this->report($path, 'edited-on-site');
                continue;
            }
            $desired = array('title' => $item['title'], 'url' => esc_url_raw($item['url']), 'status' => $post ? $post->post_status : 'publish');
            $this->report($path, $desired === $current ? 'same' : ($post ? 'updated' : 'added'));
            if ($desired !== $current && !$this->dry) {
                // A stable post name makes a creation discoverable after a crash.
                // Existing items update only title and URL: core's menu helper
                // otherwise defaults (and clears) unmentioned site-owned fields.
                $marker = 'sps-template-' . substr(hash('sha256', $path), 0, 32);
                $meta = array('_menu_item_url' => $desired['url']);
                if (!$post) {
                    $meta += array('_menu_item_type' => 'custom', '_menu_item_menu_item_parent' => '0', '_menu_item_object_id' => '0', '_menu_item_object' => 'custom', self::META => $path);
                }
                $data = array('post_type' => 'nav_menu_item', 'post_status' => $desired['status'], 'post_title' => $desired['title'], 'meta_input' => $meta);
                if ($post) {
                    $data['ID'] = $post->ID;
                } else {
                    $data['post_name'] = $marker;
                }
                $this->journal('menu_item', $post ? $post->ID : 0, $current, $desired);
                $last = count($this->record['backup']['writes']) - 1;
                $this->record['backup']['writes'][$last]['lookup'] = array('slug' => $post ? $post->post_name : $marker, 'type' => 'nav_menu_item', 'key' => $path);
                $this->save();
                $item_id = $post ? wp_update_post(wp_slash($data), true) : wp_insert_post(wp_slash($data), true);
                if (is_wp_error($item_id)) {
                    return $item_id;
                }
                if (!$post) {
                    // Cron has no current user; tax_input's capability check
                    // would silently leave the new item outside the menu.
                    $terms = wp_set_object_terms($item_id, array($id), 'nav_menu');
                    if (is_wp_error($terms)) {
                        return $terms;
                    }
                }
                $this->record['backup']['writes'][$last]['id'] = $item_id;
                $old['items'][$key] = array('id' => $item_id, 'hash' => self::fingerprint($desired));
                update_post_meta($item_id, self::META, $path);
                $old['id'] = $id;
                $this->record['menus'][$location] = $old;
                $this->save();
                $this->purge();
            }
        }
        $old['id'] = $id;
        $this->record['menus'][$location] = $old;
        return true;
    }

    /**
     * Restore the last run, refusing to overwrite any subsequent site edits.
     * Created posts become drafts, never deleted. Imported media stay usable.
     *
     * @return true|WP_Error
     */
    public static function undo() {
        global $wpdb;
        $lock = substr('sps-template-' . hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 64);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned lock shared with apply.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return new WP_Error('template_busy', 'Another template run owns this site.');
        }
        try {
            wp_cache_delete(self::OPTION, 'options');
            $record = get_option(self::OPTION, array());
            if (empty($record['backup'])) {
                return new WP_Error('template_undo', 'No template run to undo.');
            }
            $runner = new self($record, false);
            return $runner->restore();
        } catch (RuntimeException $error) {
            return new WP_Error('template_storage', $error->getMessage());
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release the connection-owned lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** @return true|WP_Error */
    private function restore() {
        // Recover identities for journalled creations before inspecting storage.
        foreach ($this->record['backup']['writes'] as &$write) {
            if (!$write['id'] && 'post' === $write['type']) {
                $after = $write['after'];
                $found = get_page_by_path($after['post_name'], OBJECT, $after['post_type']);
                if ($found && get_post_meta($found->ID, self::META, true) === ($after['meta_input'][self::META] ?? null)) {
                    $write['id'] = $found->ID;
                }
            } elseif (!$write['id'] && 'menu_item' === $write['type'] && isset($write['lookup'])) {
                $lookup = $write['lookup'];
                $found = get_page_by_path($lookup['slug'], OBJECT, $lookup['type']);
                if ($found && get_post_meta($found->ID, self::META, true) === $lookup['key']) {
                    $write['id'] = $found->ID;
                }
            }
        }
        unset($write);
        $this->save();
        $writes = array_reverse($this->record['backup']['writes'], true);
        // Preflight every resource before touching anything. Multiple writes to
        // one target are checked against a virtual reverse-order snapshot.
        $virtual = array();
        foreach ($writes as $index => $write) {
            if (!empty($write['undone'])) {
                continue;
            }
            $key = $write['type'] . '/' . $write['id'];
            $current = array_key_exists($key, $virtual) ? $virtual[$key] : self::read_write($write);
            $target = self::undo_target($write);
            if (!self::equal($current, $write['after']) && !self::equal($current, $write['before']) && !self::equal($current, $target)) {
                return new WP_Error('template_undo_edited', 'Undo stopped: changed since apply: ' . $key);
            }
            $virtual[$key] = $target;
        }
        $this->record['undoing'] = true;
        $this->save();
        foreach ($writes as $index => $write) {
            if (!empty($write['undone'])) {
                continue;
            }
            $current = self::read_write($write);
            $target = self::undo_target($write);
            if (!self::equal($current, $write['before']) && !self::equal($current, $target)) {
                if (!self::equal($current, $write['after'])) {
                    return new WP_Error('template_undo_edited', 'Resource changed during undo; stopped without overwriting it.');
                }
                $result = self::restore_write($write);
                if (is_wp_error($result)) {
                    return $result;
                }
                if (!self::equal(self::read_write($write), $target)) {
                    return new WP_Error('template_undo_write', 'Undo write did not reach its recorded target.');
                }
            }
            $this->record['backup']['writes'][$index]['undone'] = true;
            $this->save();
        }
        $before = $this->record['backup']['record'];
        if ($before) {
            update_option(self::OPTION, $before, false);
        } else {
            delete_option(self::OPTION);
        }
        wp_clear_scheduled_hook(self::HOOK);
        // Do not immediately reapply the run being undone on the next request.
        update_option(self::SEEN, SEOPROSTACK_VERSION, false);
        $this->purge();
        return true;
    }

    /** @param array $write Journal entry. @return mixed */
    private static function read_write($write) {
        switch ($write['type']) {
            case 'post':
                return $write['id'] && get_post($write['id']) ? self::post_snapshot($write['id'], array_keys($write['after']['meta_input'])) : null;
            case 'option':
                return self::option_snapshot($write['id']);
            case 'css':
                return wp_get_custom_css($write['id']);
            case 'locations':
                return get_nav_menu_locations();
            case 'menu_item':
                $post = $write['id'] ? get_post($write['id']) : null;
                return $post ? array('title' => $post->post_title, 'url' => get_post_meta($post->ID, '_menu_item_url', true), 'status' => $post->post_status) : null;
        }
        return null;
    }

    /** Compare structured snapshots without depending on JSON/PHP key order.
     * @param mixed $left First value.
     * @param mixed $right Second value.
     * @return bool
     */
    private static function equal($left, $right) {
        if (is_array($left) && is_array($right)) {
            if (count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $key => $value) {
                if (!array_key_exists($key, $right) || !self::equal($value, $right[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $left === $right;
    }

    /** @param array $write Journal entry. @return mixed */
    private static function undo_target($write) {
        if (null === $write['before'] && 'post' === $write['type']) {
            return array_replace($write['after'], array('post_status' => 'draft'));
        }
        if (null === $write['before'] && 'menu_item' === $write['type']) {
            return array_replace($write['after'], array('status' => 'draft'));
        }
        return $write['before'];
    }

    /** @param array $write Journal entry. @return true|WP_Error */
    private static function restore_write($write) {
        $id = $write['id'];
        $before = $write['before'];
        switch ($write['type']) {
            case 'post':
                if (!$id) {
                    break;
                }
                $data = null === $before ? array('post_status' => 'draft') : $before;
                foreach ($data['meta_input'] ?? array() as $key => $value) {
                    if (null === $value) {
                        delete_post_meta($id, $key);
                        unset($data['meta_input'][$key]);
                    }
                }
                $data['ID'] = $id;
                $result = wp_update_post(wp_slash($data), true);
                if (is_wp_error($result)) {
                    return $result;
                }
                break;
            case 'option':
                if ($before['exists']) {
                    update_option($id, $before['value'], false);
                } else {
                    delete_option($id);
                }
                break;
            case 'css':
                $result = wp_update_custom_css_post($before, array('stylesheet' => $id));
                if (is_wp_error($result)) {
                    return $result;
                }
                break;
            case 'locations':
                set_theme_mod('nav_menu_locations', $before);
                break;
            case 'menu_item':
                if ($id) {
                    $result = wp_update_post(wp_slash(array('ID' => $id, 'post_status' => null === $before ? 'draft' : $before['status'], 'post_title' => null === $before ? $write['after']['title'] : $before['title'])), true);
                    if (is_wp_error($result)) {
                        return $result;
                    }
                    if (null !== $before) {
                        update_post_meta($id, '_menu_item_url', $before['url']);
                    }
                }
                break;
        }
        return true;
    }
}
