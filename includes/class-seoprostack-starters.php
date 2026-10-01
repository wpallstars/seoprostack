<?php
/**
 * Starter data: example lists, tags, fields and boards for other plugins.
 *
 * Presets (SEOProStack_Presets) set options. Some plugins keep the way you
 * organise your data in their own tables instead: FluentCRM's lists and tags,
 * Fluent Boards' boards. A starter adds SEO Pro Stack's example set of those,
 * so a new site starts organised the way we organise ours.
 *
 * A starter is a JSON file in starters/, named after the plugin's folder
 * (starters/fluent-crm.json), with name, tested, updated, notes (how the
 * set is meant to be used and extended) and items: item type => list.
 * Item types and their fields:
 *
 * - fluentcrm_lists, fluentcrm_tags: slug, title. Matched by slug.
 * - fluentcrm_contact_fields: slug, label, type. Matched by slug.
 * - fluentcrm_settings: option (a FluentCRM setting), value, and paths in
 *   value that hold tag or list slugs ("tags", "lists"; "*" matches any key),
 *   turned into the site's IDs. Keys under "tag_mappings" that are not roles
 *   on this site are left out. Added only when the setting is not stored.
 * - fluentboards_boards: title, type, description, stages (title, closed).
 *   Matched by title among boards that are not archived.
 *
 * Any item can have "when": a plugin folder that must be active, so shop
 * lists only appear on shops.
 *
 * Adding never changes or removes anything already there: items that exist
 * are skipped. What was added is recorded, and Remove takes away only those
 * items, and only while unused (no contacts, no tasks, no contact values,
 * settings unchanged). Everything runs through the plugin's own models and
 * fires its own created and deleted actions.
 *
 * @package SEOProStack
 * @since 0.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Starters {

    /** Option recording what was added, per plugin folder. Not autoloaded. */
    const ADDED = 'seoprostack_starters_added';

    /** Item types, in the order they are added. Removal runs in reverse. */
    const TYPES = array('fluentcrm_lists', 'fluentcrm_tags', 'fluentcrm_contact_fields', 'fluentcrm_settings', 'fluentboards_boards');

    /**
     * Loaded starters.
     *
     * @var array<string,array>|null
     */
    private static $starters = null;

    /**
     * Every starter, keyed by plugin folder.
     *
     * @return array<string,array>
     */
    public static function all() {
        if (null !== self::$starters) {
            return self::$starters;
        }
        $starters = array();
        $files    = glob(SEOPROSTACK_DIR . 'starters/*.json');
        foreach (is_array($files) ? $files : array() as $file) {
            $data = wp_json_file_decode($file, array('associative' => true));
            if (is_array($data)) {
                $starters[basename($file, '.json')] = $data;
            }
        }

        /**
         * Filter the starter data.
         *
         * @param array<string,array> $starters Plugin folder => starter (name, tested, updated, notes, items).
         */
        $starters = (array) apply_filters('seoprostack_starters', $starters);

        self::$starters = array();
        foreach ($starters as $slug => $starter) {
            if (!is_string($slug) || '' === $slug || !is_array($starter) || empty($starter['items']) || !is_array($starter['items'])) {
                continue;
            }
            $items = array();
            foreach (self::TYPES as $type) {
                if (!empty($starter['items'][$type]) && is_array($starter['items'][$type])) {
                    $items[$type] = array_values(array_filter($starter['items'][$type], 'is_array'));
                }
            }
            if ($items) {
                self::$starters[$slug] = array(
                    'name'    => isset($starter['name']) ? (string) $starter['name'] : $slug,
                    'tested'  => isset($starter['tested']) ? (string) $starter['tested'] : '',
                    'updated' => isset($starter['updated']) ? (string) $starter['updated'] : '',
                    'notes'   => isset($starter['notes']) ? (string) $starter['notes'] : '',
                    'items'   => $items,
                );
            }
        }
        ksort(self::$starters);
        return self::$starters;
    }

    /**
     * A plugin's starter.
     *
     * @param string $slug Plugin folder.
     * @return array|null
     */
    public static function get($slug) {
        $all = self::all();
        return isset($all[$slug]) ? $all[$slug] : null;
    }

    /**
     * Whether the plugin a starter is for is running, so its models exist.
     *
     * @param string $slug Plugin folder.
     * @return bool
     */
    public static function ready($slug) {
        $starter = self::get($slug);
        if (!$starter) {
            return false;
        }
        foreach (array_keys($starter['items']) as $type) {
            if (!self::type_ready($type)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether an item type's plugin is loaded.
     *
     * @param string $type Item type.
     * @return bool
     */
    private static function type_ready($type) {
        if (0 === strpos($type, 'fluentcrm_')) {
            return class_exists('FluentCrm\App\Models\Lists') && function_exists('fluentcrm_get_option');
        }
        if (0 === strpos($type, 'fluentboards_')) {
            return class_exists('FluentBoards\App\Services\BoardService') && class_exists('FluentBoards\App\Models\Board');
        }
        return false;
    }

    /**
     * Items of a starter that apply to this site (their "when" plugin is active).
     *
     * @param string $slug Plugin folder.
     * @return array<string,array> Item type => items.
     */
    private static function items($slug) {
        $starter = self::get($slug);
        if (!$starter) {
            return array();
        }
        $active = self::active_folders();
        $out    = array();
        foreach ($starter['items'] as $type => $items) {
            foreach ($items as $item) {
                if (!empty($item['when']) && !isset($active[(string) $item['when']])) {
                    continue;
                }
                $out[$type][] = $item;
            }
        }
        return $out;
    }

    /**
     * Folders of active plugins, network-wide ones included.
     *
     * @return array<string,true>
     */
    private static function active_folders() {
        $files = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $files = array_merge($files, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        $out = array();
        foreach ($files as $file) {
            $out[SEOProStack_Presets::slug_of((string) $file)] = true;
        }
        return $out;
    }

    /**
     * Items the site does not have yet, as type => names.
     *
     * @param string $slug Plugin folder.
     * @return array<string,string[]>
     */
    public static function missing($slug) {
        if (!self::ready($slug)) {
            return array();
        }
        $out = array();
        foreach (self::items($slug) as $type => $items) {
            foreach ($items as $item) {
                if (!self::exists($type, $item)) {
                    $out[$type][] = self::label($type, $item);
                }
            }
        }
        return $out;
    }

    /**
     * Number of missing items.
     *
     * @param string $slug Plugin folder.
     * @return int
     */
    public static function missing_count($slug) {
        $count = 0;
        foreach (self::missing($slug) as $names) {
            $count += count($names);
        }
        return $count;
    }

    /**
     * What SEO Pro Stack added for a plugin, as type => records.
     *
     * @param string $slug Plugin folder.
     * @return array<string,array>
     */
    public static function added($slug) {
        $all = get_option(self::ADDED, array());
        return is_array($all) && !empty($all[$slug]) && is_array($all[$slug]) ? $all[$slug] : array();
    }

    /**
     * Number of items SEO Pro Stack added for a plugin and has not removed.
     *
     * @param string $slug Plugin folder.
     * @return int
     */
    public static function added_count($slug) {
        $count = 0;
        foreach (self::added($slug) as $records) {
            $count += count((array) $records);
        }
        return $count;
    }

    /**
     * Store what was added for a plugin.
     *
     * @param string $slug    Plugin folder.
     * @param array  $records Type => records; empty removes the plugin's entry.
     */
    private static function save_added($slug, array $records) {
        $all = get_option(self::ADDED, array());
        $all = is_array($all) ? $all : array();
        $records = array_filter($records);
        if ($records) {
            $all[$slug] = $records;
        } else {
            unset($all[$slug]);
        }
        if ($all) {
            update_option(self::ADDED, $all, false);
        } else {
            delete_option(self::ADDED);
        }
    }

    /**
     * Add the missing items. Existing items are left as they are.
     *
     * @param string $slug Plugin folder.
     * @return int|WP_Error Items added.
     */
    public static function add($slug) {
        if (!self::get($slug)) {
            return new WP_Error('seoprostack_no_starter', __('There is no starter data for this plugin.', 'seoprostack'));
        }
        if (!self::ready($slug)) {
            return new WP_Error('seoprostack_starter_inactive', __('Activate the plugin first.', 'seoprostack'));
        }
        $records = self::added($slug);
        $count   = 0;
        try {
            foreach (self::items($slug) as $type => $items) {
                foreach ($items as $item) {
                    if (self::exists($type, $item)) {
                        continue;
                    }
                    $record = self::create($type, $item);
                    if (null !== $record) {
                        $records[$type][] = $record;
                        ++$count;
                    }
                }
            }
        } catch (Throwable $e) {
            self::save_added($slug, $records);
            return new WP_Error('seoprostack_starter_failed', $e->getMessage());
        }
        self::save_added($slug, $records);
        return $count;
    }

    /**
     * Remove what SEO Pro Stack added, where it is still unused.
     *
     * @param string $slug Plugin folder.
     * @return array{removed:int,kept:string[]}|WP_Error Removed count and the names kept because they are in use.
     */
    public static function remove($slug) {
        $records = self::added($slug);
        if (!$records) {
            return new WP_Error('seoprostack_nothing_added', __('SEO Pro Stack has not added anything to this plugin.', 'seoprostack'));
        }
        if (!self::ready($slug)) {
            return new WP_Error('seoprostack_starter_inactive', __('Activate the plugin first.', 'seoprostack'));
        }
        $removed = 0;
        $kept    = array();
        $left    = array();
        try {
            foreach (array_reverse(self::TYPES) as $type) {
                foreach (isset($records[$type]) ? (array) $records[$type] : array() as $record) {
                    $result = self::delete($type, (array) $record);
                    if (true === $result) {
                        ++$removed;
                    } elseif (is_string($result)) {
                        $kept[]        = $result;
                        $left[$type][] = $record;
                    }
                    // false: already gone; forget it.
                }
            }
        } catch (Throwable $e) {
            return new WP_Error('seoprostack_starter_failed', $e->getMessage());
        }
        self::save_added($slug, $left);
        return array('removed' => $removed, 'kept' => $kept);
    }

    /**
     * A readable name for an item.
     *
     * @param string $type Item type.
     * @param array  $item Item.
     * @return string
     */
    private static function label($type, array $item) {
        switch ($type) {
            case 'fluentcrm_lists':
                return sprintf(/* translators: %s: list name */ __('List: %s', 'seoprostack'), (string) $item['title']);
            case 'fluentcrm_tags':
                return sprintf(/* translators: %s: tag name */ __('Tag: %s', 'seoprostack'), (string) $item['title']);
            case 'fluentcrm_contact_fields':
                return sprintf(/* translators: %s: field name */ __('Contact field: %s', 'seoprostack'), (string) $item['label']);
            case 'fluentcrm_settings':
                return sprintf(/* translators: %s: setting name */ __('Setting: %s', 'seoprostack'), (string) $item['option']);
            case 'fluentboards_boards':
                return sprintf(/* translators: %s: board name */ __('Board: %s', 'seoprostack'), (string) $item['title']);
        }
        return $type;
    }

    /**
     * Whether an item is already on the site.
     *
     * @param string $type Item type.
     * @param array  $item Item.
     * @return bool
     */
    private static function exists($type, array $item) {
        switch ($type) {
            case 'fluentcrm_lists':
                return null !== \FluentCrm\App\Models\Lists::where('slug', (string) $item['slug'])->first();
            case 'fluentcrm_tags':
                return null !== \FluentCrm\App\Models\Tag::where('slug', (string) $item['slug'])->first();
            case 'fluentcrm_contact_fields':
                foreach ((array) fluentcrm_get_option('contact_custom_fields', array()) as $field) {
                    if (is_array($field) && isset($field['slug']) && (string) $field['slug'] === (string) $item['slug']) {
                        return true;
                    }
                }
                return false;
            case 'fluentcrm_settings':
                $stored = fluentcrm_get_option((string) $item['option'], null);
                return null !== $stored && '' !== $stored && array() !== $stored;
            case 'fluentboards_boards':
                return null !== \FluentBoards\App\Models\Board::where('title', (string) $item['title'])->whereNull('archived_at')->first();
        }
        return true;
    }

    /**
     * Create an item.
     *
     * @param string $type Item type.
     * @param array  $item Item.
     * @return array|null What to record for removal, or null if nothing was made.
     */
    private static function create($type, array $item) {
        switch ($type) {
            case 'fluentcrm_lists':
                $list = \FluentCrm\App\Models\Lists::create(array(
                    'title'       => sanitize_text_field((string) $item['title']),
                    'slug'        => sanitize_title((string) $item['slug']),
                    'description' => '',
                ));
                do_action('fluentcrm_list_created', $list->id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCRM's own hook, as its list screen fires it.
                do_action('fluent_crm/list_created', $list); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCRM's own hook.
                return array('id' => (int) $list->id, 'name' => (string) $item['title']);

            case 'fluentcrm_tags':
                $tag = \FluentCrm\App\Models\Tag::create(array(
                    'title'       => sanitize_text_field((string) $item['title']),
                    'slug'        => sanitize_title((string) $item['slug']),
                    'description' => '',
                ));
                do_action('fluent_crm/tag_created', $tag); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCRM's own hook, as its tag screen fires it.
                return array('id' => (int) $tag->id, 'name' => (string) $item['title']);

            case 'fluentcrm_contact_fields':
                $fields   = array_values(array_filter((array) fluentcrm_get_option('contact_custom_fields', array()), 'is_array'));
                $fields[] = array(
                    'type'  => sanitize_key((string) $item['type']),
                    'label' => sanitize_text_field((string) $item['label']),
                    'slug'  => sanitize_key((string) $item['slug']),
                );
                fluentcrm_update_option('contact_custom_fields', $fields);
                return array('slug' => sanitize_key((string) $item['slug']), 'name' => (string) $item['label']);

            case 'fluentcrm_settings':
                $value = self::resolve_setting($item);
                fluentcrm_update_option((string) $item['option'], $value);
                return array('option' => (string) $item['option'], 'hash' => md5((string) wp_json_encode($value)), 'name' => (string) $item['option']);

            case 'fluentboards_boards':
                return self::create_board($item);
        }
        return null;
    }

    /**
     * A FluentCRM setting with tag and list slugs turned into this site's IDs.
     *
     * @param array $item Setting item.
     * @return array
     */
    private static function resolve_setting(array $item) {
        $value = isset($item['value']) && is_array($item['value']) ? $item['value'] : array();
        $ids   = array(
            'tags'  => \FluentCrm\App\Models\Tag::pluck('id', 'slug'),
            'lists' => \FluentCrm\App\Models\Lists::pluck('id', 'slug'),
        );
        foreach ($ids as $kind => $map) {
            $map = is_object($map) && method_exists($map, 'toArray') ? $map->toArray() : (array) $map;
            foreach (isset($item[$kind]) ? (array) $item[$kind] : array() as $path) {
                $value = self::map_path($value, explode('.', (string) $path), $map);
            }
        }
        if (isset($value['tag_mappings']) && is_array($value['tag_mappings'])) {
            foreach (array_keys($value['tag_mappings']) as $role) {
                if (!wp_roles()->is_role((string) $role)) {
                    unset($value['tag_mappings'][$role]);
                }
            }
        }
        return $value;
    }

    /**
     * Replace slugs with IDs at a path ("*" matches any key). Slugs the site
     * does not have are left out.
     *
     * @param mixed    $value Value.
     * @param string[] $path  Remaining path.
     * @param array    $map   Slug => ID.
     * @return mixed
     */
    private static function map_path($value, array $path, array $map) {
        if (!is_array($value)) {
            return $value;
        }
        if (!$path) {
            $ids = array();
            foreach ($value as $slug) {
                if (isset($map[$slug])) {
                    $ids[] = (string) $map[$slug];
                }
            }
            return $ids;
        }
        $key = array_shift($path);
        foreach ($value as $k => $v) {
            if ('*' === $key || (string) $k === $key) {
                $value[$k] = self::map_path($v, $path, $map);
            }
        }
        return $value;
    }

    /**
     * Create a Fluent Boards board with its stages and default labels, as its
     * own create-board screen does.
     *
     * @param array $item Board item.
     * @return array|null
     */
    private static function create_board(array $item) {
        $creator = get_current_user_id();
        if (!$creator) {
            $admins  = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID'));
            $creator = $admins ? (int) $admins[0] : 0;
        }
        $board = (new \FluentBoards\App\Services\BoardService())->createBoard(array(
            'title'       => sanitize_text_field((string) $item['title']),
            'type'        => isset($item['type']) ? sanitize_key((string) $item['type']) : 'to-do',
            'description' => isset($item['description']) ? sanitize_textarea_field((string) $item['description']) : '',
            'created_by'  => $creator,
        ));
        if (!$board) {
            return null;
        }
        (new \FluentBoards\App\Services\LabelService())->createDefaultLabel($board->id);
        $stages = array();
        foreach (isset($item['stages']) ? (array) $item['stages'] : array() as $stage) {
            if (is_array($stage) && !empty($stage['title'])) {
                $stages[] = array('title' => sanitize_text_field((string) $stage['title']));
            }
        }
        if ($stages) {
            (new \FluentBoards\App\Services\StageService())->createStages($board, $stages);
            foreach ((array) $item['stages'] as $stage) {
                if (is_array($stage) && !empty($stage['closed'])) {
                    $model = \FluentBoards\App\Models\Stage::where('board_id', $board->id)->where('title', sanitize_text_field((string) $stage['title']))->first();
                    if ($model) {
                        $model->settings = array('default_task_status' => 'closed');
                        $model->save();
                    }
                }
            }
        } else {
            (new \FluentBoards\App\Services\StageService())->createDefaultStages($board);
        }
        do_action('fluent_boards/board_created', $board); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Boards' own hook, as its create-board code fires it.
        return array('id' => (int) $board->id, 'name' => (string) $item['title']);
    }

    /**
     * Remove an added item if it is unused.
     *
     * @param string $type   Item type.
     * @param array  $record What was recorded when it was added.
     * @return true|false|string True when removed, false when already gone, its name when kept.
     */
    private static function delete($type, array $record) {
        $name = isset($record['name']) ? (string) $record['name'] : '';
        switch ($type) {
            case 'fluentcrm_lists':
            case 'fluentcrm_tags':
                $class = 'fluentcrm_lists' === $type ? '\FluentCrm\App\Models\Lists' : '\FluentCrm\App\Models\Tag';
                $model = $class::find((int) $record['id']);
                if (!$model) {
                    return false;
                }
                if ($model->totalCount() > 0) {
                    return $name;
                }
                $id = (int) $model->id;
                $class::where('id', $id)->delete();
                if ('fluentcrm_lists' === $type) {
                    // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCRM's own hooks, as its screens fire them.
                    do_action('fluent_crm/list_deleted', $id);
                    do_action('fluentcrm_list_deleted', $id);
                } else {
                    do_action('fluentcrm_tag_deleted', $id);
                    do_action('fluent_crm/tag_deleted', $id);
                    // phpcs:enable
                }
                return true;

            case 'fluentcrm_contact_fields':
                $slug   = (string) $record['slug'];
                $fields = array_values(array_filter((array) fluentcrm_get_option('contact_custom_fields', array()), 'is_array'));
                $left   = array();
                foreach ($fields as $field) {
                    if (!isset($field['slug']) || (string) $field['slug'] !== $slug) {
                        $left[] = $field;
                    }
                }
                if (count($left) === count($fields)) {
                    return false;
                }
                $used = fluentCrmDb()->table('fc_subscriber_meta')->where('object_type', 'custom_field')->where('key', $slug)->count();
                if ($used > 0) {
                    return $name;
                }
                fluentcrm_update_option('contact_custom_fields', $left);
                return true;

            case 'fluentcrm_settings':
                $option = (string) $record['option'];
                $stored = fluentcrm_get_option($option, null);
                if (null === $stored || '' === $stored) {
                    return false;
                }
                if (md5((string) wp_json_encode($stored)) !== (string) $record['hash']) {
                    return $name;
                }
                fluentcrm_delete_option($option);
                return true;

            case 'fluentboards_boards':
                $board = \FluentBoards\App\Models\Board::find((int) $record['id']);
                if (!$board) {
                    return false;
                }
                if ($board->tasks()->count() > 0) {
                    return $name;
                }
                (new \FluentBoards\App\Services\BoardService())->deleteBoard((int) $board->id);
                return true;
        }
        return false;
    }
}
