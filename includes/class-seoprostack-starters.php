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
 * - fluentform_forms: title, template (a Fluent Forms template the form starts
 *   from, for its default settings and notifications), form_fields (as in a
 *   Fluent Forms export), settings (merged into its form settings) and
 *   crm_feed: a FluentCRM feed with list and tags as slugs and tag_routers
 *   (tag, field, value), added only with FluentCRM active. Matched by title.
 * - fluentboards_boards: title, type, description, stages (title, closed).
 *   Matched by title among boards that are not archived.
 * - fluentbooking_events: title, description, duration, crm_list (a list slug).
 *   Matched by title in the adding user's host calendar. Creates that calendar
 *   only if missing; Remove keeps events with bookings and shared calendars.
 *
 * Any item can have "when": a plugin folder that must be active, so shop
 * lists only appear on shops. A starter can include another plugin's items
 * it relies on (the lists its forms feed) with "when"; they are matched as
 * usual, so the order starters are added in does not matter.
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
    const TYPES = array('fluentcrm_lists', 'fluentcrm_tags', 'fluentcrm_contact_fields', 'fluentcrm_settings', 'fluentform_forms', 'fluentboards_boards', 'fluentbooking_events');

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
        foreach ($starter['items'] as $type => $items) {
            // Types whose items all name a "when" plugin are optional.
            $optional = !in_array(true, array_map(function ($item) {
                return empty($item['when']);
            }, $items), true);
            if (!$optional && !self::type_ready($type)) {
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
        if (0 === strpos($type, 'fluentform_')) {
            return class_exists('FluentForm\App\Services\Form\FormService') && class_exists('FluentForm\App\Models\Form');
        }
        if ('fluentbooking_events' === $type) {
            return class_exists('FluentBooking\App\Http\Controllers\CalendarController') && class_exists('FluentBooking\App\Models\Booking');
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
            if (!self::type_ready($type)) {
                continue;
            }
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
                        if ('fluentbooking_events' === $type && !empty($records[$type])) {
                            foreach ($records[$type] as $key => $owned) {
                                if (empty($owned['pending_crm'])) {
                                    continue;
                                }
                                $event = \FluentBooking\App\Models\CalendarSlot::find((int) $owned['id']);
                                if ($event && (int) $event->user_id === self::booking_user()
                                    && (int) $event->calendar_id === (int) $owned['calendar_id']
                                    && (string) $event->title === (string) $item['title']
                                    && self::booking_feed($event, $item)) {
                                    unset($records[$type][$key]['pending_crm']);
                                    self::save_added($slug, $records);
                                }
                            }
                        }
                        continue;
                    }
                    $record = self::create($type, $item, $slug);
                    if (null !== $record) {
                        $records[$type][] = $record;
                        self::save_added($slug, $records);
                        ++$count;
                    }
                }
            }
        } catch (Throwable $e) {
            // Each addition is saved immediately, including a Booking event
            // whose later integration save failed. Do not overwrite that record.
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
        $in_use  = null;
        try {
            foreach (array_reverse(self::TYPES) as $type) {
                if (!empty($records[$type]) && !self::type_ready($type)) {
                    // Its plugin is inactive (FluentCRM lists a form starter added): keep them for later.
                    $left[$type] = $records[$type];
                    continue;
                }
                $by_id = in_array($type, array('fluentcrm_tags', 'fluentcrm_lists'), true);
                if ($by_id && null === $in_use) {
                    // Settings and forms go first; tags and lists the remaining ones still point at stay.
                    $in_use = self::setting_ids($slug) + self::feed_ids();
                }
                foreach (isset($records[$type]) ? (array) $records[$type] : array() as $record) {
                    $record = (array) $record;
                    if ($by_id && isset($record['id'], $in_use[(int) $record['id']])) {
                        $kept[]        = isset($record['name']) ? (string) $record['name'] : '';
                        $left[$type][] = $record;
                        continue;
                    }
                    $result = self::delete($type, $record);
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
     * Numbers stored in the FluentCRM settings a starter covers, as tag or
     * list IDs those settings may still use. Over-matching only keeps more.
     *
     * @param string $slug Plugin folder.
     * @return array<int,true>
     */
    private static function setting_ids($slug) {
        $starter = self::get($slug);
        $out     = array();
        if (!$starter || empty($starter['items']['fluentcrm_settings']) || !function_exists('fluentcrm_get_option')) {
            return $out;
        }
        foreach ((array) $starter['items']['fluentcrm_settings'] as $item) {
            if (!is_array($item) || empty($item['option'])) {
                continue;
            }
            $stored = fluentcrm_get_option((string) $item['option'], null);
            if (!is_array($stored)) {
                continue;
            }
            $out += self::numbers($stored);
        }
        return $out;
    }

    /**
     * List and tag IDs used by any Fluent Forms FluentCRM feed on the site,
     * whoever made the form. Over-matching only keeps more.
     *
     * @return array<int,true>
     */
    private static function feed_ids() {
        $out = array();
        $values = class_exists('FluentForm\App\Models\FormMeta') ? \FluentForm\App\Models\FormMeta::where('meta_key', 'fluentcrm_feeds')->pluck('value') : array();
        foreach ($values as $value) {
            $feed = json_decode((string) $value, true);
            if (is_array($feed)) {
                $out += self::numbers(array(
                    isset($feed['list_id']) ? $feed['list_id'] : null,
                    isset($feed['tag_ids']) ? $feed['tag_ids'] : null,
                    isset($feed['tag_routers']) ? array_column((array) $feed['tag_routers'], 'input_value') : null,
                    isset($feed['remove_tags']) ? $feed['remove_tags'] : null,
                ));
            }
        }
        if (class_exists('FluentBooking\App\Models\Meta')) {
            foreach (\FluentBooking\App\Models\Meta::where('object_type', 'integration')->where('key', 'fluentcrm_feeds')->pluck('value') as $feed) {
                if (is_array($feed)) {
                    $out += self::numbers(isset($feed['list_ids']) ? $feed['list_ids'] : array());
                }
            }
        }
        return $out;
    }

    /**
     * Whole numbers anywhere in a value.
     *
     * @param mixed $value Value.
     * @return array<int,true>
     */
    private static function numbers($value) {
        $out = array();
        if (!is_array($value)) {
            $value = array($value);
        }
        array_walk_recursive(
            $value,
            function ($v) use (&$out) {
                if (is_int($v) || (is_string($v) && '' !== $v && ctype_digit($v))) {
                    $out[(int) $v] = true;
                }
            }
        );
        return $out;
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
            case 'fluentform_forms':
                return sprintf(/* translators: %s: form name */ __('Form: %s', 'seoprostack'), (string) $item['title']);
            case 'fluentboards_boards':
                return sprintf(/* translators: %s: board name */ __('Board: %s', 'seoprostack'), (string) $item['title']);
            case 'fluentbooking_events':
                return sprintf(/* translators: %s: event name */ __('Booking event: %s', 'seoprostack'), (string) $item['title']);
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
            case 'fluentform_forms':
                return null !== \FluentForm\App\Models\Form::where('title', (string) $item['title'])->first();
            case 'fluentboards_boards':
                return null !== \FluentBoards\App\Models\Board::where('title', (string) $item['title'])->whereNull('archived_at')->first();
            case 'fluentbooking_events':
                $calendar = \FluentBooking\App\Models\Calendar::where('user_id', self::booking_user())->where('type', 'simple')->first();
                return $calendar && $calendar->events()->where('title', (string) $item['title'])->exists();
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
    private static function create($type, array $item, $slug) {
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

            case 'fluentform_forms':
                return self::create_form($item);

            case 'fluentboards_boards':
                return self::create_board($item);
            case 'fluentbooking_events':
                return self::create_booking_event($item, $slug);
        }
        return null;
    }

    /**
     * Host for Booking: the adding user, or the first administrator for WP-CLI.
     *
     * @return int
     */
    private static function booking_user() {
        $id = get_current_user_id();
        if (!$id) {
            $admins = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID'));
            $id = $admins ? (int) $admins[0] : 0;
        }
        return $id;
    }

    /**
     * Create through Booking's admin handlers, including its default schedule.
     * Existing host calendars and events are never renamed or reconfigured.
     *
     * @param array  $item Event item.
     * @param string $slug Starter folder, for preserving a partial addition.
     * @return array
     */
    private static function create_booking_event(array $item, $slug) {
        $user = get_user_by('ID', self::booking_user());
        if (!$user) {
            throw new RuntimeException(esc_html__('No administrator is available to host the event.', 'seoprostack'));
        }
        $original_user = get_current_user_id();
        $record = null;
        try {
            wp_set_current_user($user->ID);
            $calendar = \FluentBooking\App\Models\Calendar::where('user_id', $user->ID)->where('type', 'simple')->first();
            $created_calendar = !$calendar;
            $app = \FluentBooking\Framework\Foundation\App::getInstance();
            $controller = new \FluentBooking\App\Http\Controllers\CalendarController($app);
            $weekly = \FluentBooking\App\Services\Helper::getWeeklyScheduleSchema();
            $slot = array(
                'title' => sanitize_text_field((string) $item['title']),
                'description' => isset($item['description']) ? wp_kses_post((string) $item['description']) : '',
                'duration' => isset($item['duration']) ? max(5, (int) $item['duration']) : 30,
                'status' => 'active',
                'event_type' => 'single',
                'availability_type' => 'existing_schedule',
                'schedule_type' => 'weekly_schedules',
                'weekly_schedules' => $weekly,
                'location_heading' => '',
                'location_type' => '',
                'location_settings' => array(),
                'settings' => array('schedule_type' => 'weekly_schedules', 'weekly_schedules' => $weekly, 'range_type' => 'range_days'),
            );
            if ($created_calendar) {
                $request = new \FluentBooking\Framework\Http\Request\Request($app, array(), array('calendar' => array(
                    'user_id' => $user->ID, 'type' => 'simple', 'author_timezone' => wp_timezone_string(), 'slot' => $slot,
                )));
                $result = $controller->createCalendar($request);
                $calendar = $result['calendar'];
            } else {
                // Ensure a schedule exists before the event handler looks it up.
                \FluentBooking\App\Services\AvailabilityService::maybeCreateAvailability($calendar, $weekly);
                $request = new \FluentBooking\Framework\Http\Request\Request($app, array(), $slot);
                $result = $controller->createCalendarEvent($request, $calendar->id);
            }
            $event = $result['slot'];
            $record = array('id' => (int) $event->id, 'calendar_id' => (int) $calendar->id, 'created_calendar' => $created_calendar, 'name' => (string) $item['title']);
            if (!empty($item['crm_list']) && self::type_ready('fluentcrm_lists')) {
                $record['pending_crm'] = true;
            }
            if ($created_calendar) {
                $calendar->title = $user->display_name;
                $calendar->save();
                \FluentBooking\App\Services\LandingPage\LandingPageHelper::updateSettings($calendar, array(
                    'enabled' => 'yes', 'show_type' => 'selected', 'enabled_slots' => array((int) $event->id),
                ));
            }
            if (!empty($record['pending_crm']) && self::booking_feed($event, $item)) {
                unset($record['pending_crm']);
            }
            return $record;
        } catch (Throwable $e) {
            if ($record) {
                $records = self::added($slug);
                $records['fluentbooking_events'][] = $record;
                self::save_added($slug, $records);
            }
            throw $e;
        } finally {
            wp_set_current_user($original_user);
        }
    }

    /**
     * Finish a starter-owned event's CRM feed, without replacing an owner's feed.
     * A failed save can be retried by Add; pre-existing events never reach here.
     *
     * @param object $event Booking event.
     * @param array  $item  Starter item.
     * @return bool Whether setup is complete.
     */
    private static function booking_feed($event, array $item) {
        if (empty($item['crm_list']) || !self::type_ready('fluentcrm_lists')) {
            return false;
        }
        if (\FluentBooking\App\Models\Meta::where('object_type', 'integration')->where('object_id', $event->id)->where('key', 'fluentcrm_feeds')->exists()) {
            return true;
        }
        $list = \FluentCrm\App\Models\Lists::where('slug', (string) $item['crm_list'])->first();
        if (!$list) {
            throw new RuntimeException(esc_html__('The booking contact list is missing.', 'seoprostack'));
        }
        $feed = apply_filters('fluent_booking/get_integration_defaults_fluentcrm', array(), $event->id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Booking's integration defaults.
        $feed['name'] = __('Website Booking Form', 'seoprostack');
        $feed['list_ids'] = array((string) $list->id);
        $feed['event_trigger'] = array('after_booking_scheduled');
        (new \FluentBooking\App\Services\Integrations\CalendarIntegrationService())->update(array('slot_id' => $event->id, 'integration_name' => 'fluentcrm', 'integration' => $feed));
        return true;
    }

    /**
     * Create a Fluent Forms form as its own screens do: from a template (its
     * default settings and notifications), then saved with our fields through
     * its editor's save code, which sanitises them and sets the primary email.
     *
     * @param array $item Form item.
     * @return array|null
     */
    private static function create_form(array $item) {
        if (empty($item['title']) || empty($item['form_fields']) || !is_array($item['form_fields'])) {
            return null;
        }
        $title    = sanitize_text_field((string) $item['title']);
        $template = isset($item['template']) ? sanitize_key((string) $item['template']) : 'blank_form';
        $form     = (new \FluentForm\App\Services\Form\FormService())->store(array('predefined' => $template, 'type' => 'form'));
        if (!$form || empty($form->id)) {
            return null;
        }
        $id     = (int) $form->id;
        $fields = $item['form_fields'];
        if (isset($fields['fields']) && is_array($fields['fields'])) {
            $fields['fields'] = self::usable_fields($fields['fields'], self::active_folders());
        }
        (new \FluentForm\App\Services\Form\Updater())->update(array(
            'form_id'    => $id,
            'title'      => $title,
            'status'     => 'published',
            'formFields' => wp_json_encode($fields),
        ));

        if (!empty($item['settings']) && is_array($item['settings'])) {
            $settings = \FluentForm\App\Models\FormMeta::retrieve('formSettings', $id);
            $settings = is_array($settings) ? $settings : \FluentForm\App\Models\Form::getFormsDefaultSettings();
            \FluentForm\App\Models\FormMeta::persist($id, 'formSettings', self::merge_settings($settings, $item['settings']));
        }

        if (!empty($item['crm_feed']) && is_array($item['crm_feed']) && self::type_ready('fluentcrm_lists')) {
            \FluentForm\App\Models\FormMeta::insert(array(
                'form_id'  => $id,
                'meta_key' => 'fluentcrm_feeds', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fluent Forms' own table, one row per feed.
                'value'    => wp_json_encode(self::crm_feed($item['crm_feed'])),
            ));
        }

        $stored = \FluentForm\App\Models\Form::find($id);
        return array('id' => $id, 'hash' => md5((string) ($stored ? $stored->form_fields : '')), 'name' => $title);
    }

    /**
     * Form fields without those whose "when" plugin is inactive (Fluent Forms
     * Pro fields such as file uploads, which the free plugin does not show but
     * would still require), and without the "when" keys themselves.
     *
     * @param array               $fields Fields, columns included.
     * @param array<string,true>  $active Active plugin folders.
     * @return array
     */
    private static function usable_fields(array $fields, array $active) {
        $out = array();
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            if (!empty($field['when']) && !isset($active[(string) $field['when']])) {
                continue;
            }
            unset($field['when']);
            if (isset($field['columns']) && is_array($field['columns'])) {
                foreach ($field['columns'] as $i => $column) {
                    if (is_array($column) && isset($column['fields']) && is_array($column['fields'])) {
                        $field['columns'][$i]['fields'] = self::usable_fields($column['fields'], $active);
                    }
                }
            }
            $out[] = $field;
        }
        return $out;
    }

    /**
     * Settings with our values put in, key by key; lists are replaced whole.
     *
     * @param array $settings Current.
     * @param array $ours     Ours.
     * @return array
     */
    private static function merge_settings(array $settings, array $ours) {
        foreach ($ours as $key => $value) {
            if (is_array($value) && isset($settings[$key]) && is_array($settings[$key]) && !wp_is_numeric_array($value)) {
                $settings[$key] = self::merge_settings($settings[$key], $value);
            } else {
                $settings[$key] = is_string($value) ? wp_kses_post($value) : $value;
            }
        }
        return $settings;
    }

    /**
     * A FluentCRM feed for Fluent Forms, with list and tag slugs turned into
     * this site's IDs, in the shape Fluent Forms' integration screen saves.
     *
     * @param array $feed Feed item.
     * @return array
     */
    private static function crm_feed(array $feed) {
        $lists = \FluentCrm\App\Models\Lists::pluck('id', 'slug');
        $tags  = \FluentCrm\App\Models\Tag::pluck('id', 'slug');
        $lists = is_object($lists) && method_exists($lists, 'toArray') ? $lists->toArray() : (array) $lists;
        $tags  = is_object($tags) && method_exists($tags, 'toArray') ? $tags->toArray() : (array) $tags;

        $tag_ids = array();
        foreach (isset($feed['tags']) ? (array) $feed['tags'] : array() as $slug) {
            if (isset($tags[$slug])) {
                $tag_ids[] = (string) $tags[$slug];
            }
        }
        $routers = array();
        foreach (isset($feed['tag_routers']) ? (array) $feed['tag_routers'] : array() as $router) {
            if (is_array($router) && isset($router['tag'], $tags[$router['tag']])) {
                $routers[] = array(
                    'input_value' => (string) $tags[$router['tag']],
                    // Fluent Forms' editor save (Updater) passes field names through sanitize_key(), and routing is case-sensitive.
                    'field'       => isset($router['field']) ? sanitize_key((string) $router['field']) : '',
                    'operator'    => '=',
                    'value'       => isset($router['value']) ? sanitize_text_field((string) $router['value']) : '',
                );
            }
        }
        $text = function ($key) use ($feed) {
            return isset($feed[$key]) ? sanitize_text_field((string) $feed[$key]) : '';
        };
        $other = array();
        foreach (isset($feed['other_fields']) ? (array) $feed['other_fields'] : array() as $field) {
            if (is_array($field) && isset($field['item_value'], $field['label'])) {
                $other[] = array('item_value' => sanitize_text_field((string) $field['item_value']), 'label' => sanitize_key((string) $field['label']));
            }
        }
        $list = isset($feed['list'], $lists[$feed['list']]) ? (string) $lists[$feed['list']] : '';

        return array(
            'name'                   => __('FluentCRM Integration Feed', 'seoprostack'),
            'first_name'             => $text('first_name'),
            'last_name'              => $text('last_name'),
            'full_name'              => $text('full_name'),
            'email'                  => $text('email'),
            'other_fields'           => $other,
            'list_id'                => $list,
            'tag_ids'                => $routers ? array() : $tag_ids,
            'tag_ids_selection_type' => $routers ? 'routing' : 'simple',
            'tag_routers'            => $routers,
            'skip_if_exists'         => false,
            'double_opt_in'          => !empty($feed['double_opt_in']),
            'force_subscribe'        => !empty($feed['force_subscribe']),
            'skip_primary_data'      => false,
            'conditionals'           => array(
                'conditions' => array(array('field' => null, 'operator' => '=', 'value' => null)),
                'status'     => false,
                'type'       => 'all',
            ),
            'run_events_only'        => array(),
            'remove_tags'            => array(),
            'enabled'                => true,
            'CustomFields'           => array(),
        );
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
            case 'fluentbooking_events':
                $event = \FluentBooking\App\Models\CalendarSlot::find((int) $record['id']);
                $calendar = \FluentBooking\App\Models\Calendar::find((int) $record['calendar_id']);
                if (\FluentBooking\App\Models\Booking::where('event_id', (int) $record['id'])->exists()) {
                    return $name;
                }
                $controller = new \FluentBooking\App\Http\Controllers\CalendarController();
                $app = \FluentBooking\Framework\Foundation\App::getInstance();
                $request = new \FluentBooking\Framework\Http\Request\Request($app, array(), array());
                if ($event && (!$calendar || (int) $event->calendar_id !== (int) $calendar->id)) {
                    return $name;
                }
                if ($event) {
                    $controller->deleteCalendarEvent($request, $calendar->id, $event->id);
                    // Booking's cleaner does not delete integration feeds.
                    \FluentBooking\App\Models\Meta::where('object_type', 'integration')->where('object_id', (int) $record['id'])->delete();
                }
                if ($calendar && !empty($record['created_calendar'])) {
                    // Keep the calendar if the owner added events, bookings or team use.
                    if ($calendar->events()->exists() || $calendar->bookings()->exists()
                        || \FluentBooking\App\Models\CalendarSlot::where('user_id', $calendar->user_id)->where('calendar_id', '!=', $calendar->id)->exists()
                        || \FluentBooking\App\Models\CalendarSlot::whereIn('event_type', array('collective', 'round_robin'))->exists()) {
                        return $name;
                    }
                    $controller->deleteCalendar($request, $calendar->id);
                }
                return $event || $calendar ? true : false;

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

            case 'fluentform_forms':
                $form = \FluentForm\App\Models\Form::find((int) $record['id']);
                if (!$form) {
                    return false;
                }
                $id = (int) $form->id;
                if (\FluentForm\App\Models\Submission::where('form_id', $id)->count() > 0
                    || md5((string) $form->form_fields) !== (string) $record['hash']
                    || self::form_embedded($id)) {
                    return $name;
                }
                \FluentForm\App\Models\Form::remove($id);
                return true;
        }
        return false;
    }

    /**
     * Whether a form is placed in any post, page or block (shortcode or block).
     *
     * @param int $id Form ID.
     * @return bool
     */
    private static function form_embedded($id) {
        global $wpdb;
        $like = array(
            '%' . $wpdb->esc_like('fluentform id="' . $id . '"') . '%',
            '%' . $wpdb->esc_like("fluentform id='" . $id . "'") . '%',
            '%' . $wpdb->esc_like('"formId":"' . $id . '"') . '%',
            '%' . $wpdb->esc_like('"formId":' . $id . ',') . '%',
            '%' . $wpdb->esc_like('"formId":' . $id . '}') . '%',
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one check before deleting, on request.
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft') AND post_type <> 'revision' AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s) LIMIT 1",
            $like[0],
            $like[1],
            $like[2],
            $like[3],
            $like[4]
        ));
        return null !== $found;
    }
}
