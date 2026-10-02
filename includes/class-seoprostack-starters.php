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
 *   Fields may be compact: an element with only the keys that differ
 *   (attributes, settings, "required": true, "options": value => label, or
 *   for payment items, label => price) is completed from Fluent Forms' own
 *   element defaults, as its editor would; columns of a "container" too.
 *   "layout": "checkout" lays the fields out as a checkout: "intro" (HTML
 *   about the service) and the fields in a two-thirds column, and the order
 *   summary, discount code and form button in a one-third column beside it
 *   (below it on phones), styled by the form's own Custom CSS.
 * - fluentboards_boards: title, type, description, stages (title, closed),
 *   labels (title, color: a Fluent Boards colour such as "blue-bold"; without
 *   labels the board gets Fluent Boards' default ones). Matched by title among
 *   boards that are not archived.
 * - fluentsupport_products: title, description. Matched by title.
 * - fluentbooking_events: title, description, duration, crm_list (a list
 *   slug), location (phone_guest, phone_organizer, online_meeting,
 *   in_person_organizer or custom; default none), color. Matched by title in
 *   the adding user's host calendar. Creates that calendar only if missing;
 *   Remove keeps events with bookings and shared calendars.
 * - fluentcommunity_spaces: title, slug, description, privacy (public,
 *   private or secret). Matched by slug.
 * - tutor_courses: title, content, excerpt, topics (title, summary, lessons:
 *   title, content). A free, published course. Matched by title.
 * - pages: title, slug, content. A draft page. Matched by title.
 * - seoprostack_settings: key (an SEO Pro Stack setting), value. Set only
 *   while the setting is at its default.
 *
 * Text in forms, pages and settings can name other items: {form:Title},
 * {board:Title}, {product:Title}, {event:Title}, {space:slug},
 * {course:Title} and {page:Title} become their IDs on this site (a value that is only a token
 * becomes a number), so starters added in any order link up. {url:Page title}
 * becomes a page's address, for links between pages.
 *
 * Any item can have "when": a plugin folder that must be active, so shop
 * lists only appear on shops, and "requires": "payments" when it needs
 * Fluent Forms' payments turned on with a payment method (such as Stripe)
 * set up. Compact fields Fluent Forms does not offer on the site are left
 * out. A starter can include another plugin's
 * items it relies on (the lists its forms feed) with "when"; they are
 * matched as usual, so the order starters are added in does not matter.
 *
 * A starter with "set": true is not tied to one plugin (starters/agency.json):
 * it covers several, adds what it can for the plugins that are active, and
 * is offered where it belongs (the Agency tab) instead of in a plugin's row.
 *
 * Adding never changes or removes anything already there: items that exist
 * are skipped. What was added is recorded, and Remove takes away only those
 * items, and only while unused (no contacts, no tasks, no contact values,
 * no tickets, no members or posts, no enrolments, pages and settings
 * unchanged). Everything runs through the plugin's own models and fires its
 * own created and deleted actions.
 *
 * @package SEOProStack
 * @since 0.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Starters use SEOProStack_Presets::slug_of(); callers such as the Agency tab load only this file.
require_once __DIR__ . '/class-seoprostack-presets.php';

final class SEOProStack_Starters {

    /** Option recording what was added, per plugin folder. Not autoloaded. */
    const ADDED = 'seoprostack_starters_added';

    /** Item types, in the order they are added. Removal runs in reverse. */
    const TYPES = array(
        'fluentcrm_lists',
        'fluentcrm_tags',
        'fluentcrm_contact_fields',
        'fluentcrm_settings',
        'fluentform_forms',
        'fluentboards_boards',
        'fluentsupport_products',
        'fluentbooking_events',
        'fluentcommunity_spaces',
        'tutor_courses',
        'pages',
        'seoprostack_settings',
    );

    /**
     * Custom CSS for checkout forms: the summary column as a panel that stays
     * in view while the wide column scrolls. Neutral, translucent colours,
     * so it suits light and dark palettes.
     */
    const CHECKOUT_CSS = '.fluentform .sps-checkout .ff-t-column-2 { padding: 1.25rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 8px; background: rgba(127, 127, 127, 0.06); }
.fluentform .sps-checkout .ff-t-column-2 h3 { margin: 0 0 0.75rem; }
.fluentform .sps-checkout .ff-t-column-2 .ff_submit_btn_wrapper_custom, .fluentform .sps-checkout .ff-t-column-2 .ff-btn-submit { width: 100%; }
.fluentform .sps-checkout .ff-t-column-2 .ff_submit_btn_wrapper_custom button { margin-bottom: 0; }
.fluentform .sps-checkout .ff-t-column-2 table.ffp_table, .fluentform .sps-checkout .ff-t-column-2 table.ffp_table tbody, .fluentform .sps-checkout .ff-t-column-2 table.ffp_table tfoot { display: block; width: 100%; margin: 0; border: 0; background: none; }
.fluentform .sps-checkout .ff-t-column-2 table.ffp_table thead, .fluentform .sps-checkout .ff-t-column-2 table.ffp_table td:nth-child(2), .fluentform .sps-checkout .ff-t-column-2 table.ffp_table td:nth-child(3) { display: none; }
.fluentform .sps-checkout .ff-t-column-2 table.ffp_table tr { display: flex; justify-content: space-between; gap: 1rem; padding: 0.5rem 0; border-bottom: 1px solid rgba(127, 127, 127, 0.25); background: none; }
.fluentform .sps-checkout .ff-t-column-2 table.ffp_table th, .fluentform .sps-checkout .ff-t-column-2 table.ffp_table td { display: block; padding: 0; border: 0; background: none; color: inherit; text-align: left; }
.fluentform .sps-checkout .ff-t-column-2 table.ffp_table tr > :last-child { white-space: nowrap; text-align: right; }
.fluentform .sps-checkout .ff-t-column-2 table.ffp_table tfoot tr:last-child { border-bottom: 0; font-weight: 700; }
@media (min-width: 768px) {
    .fluentform .sps-checkout { gap: 2rem; }
    .fluentform .sps-checkout .ff-t-column-2 { align-self: flex-start; position: sticky; top: 2rem; }
}';

    /** Item types that link items by name: token kind => item type. */
    const TOKENS = array(
        'form'    => 'fluentform_forms',
        'board'   => 'fluentboards_boards',
        'product' => 'fluentsupport_products',
        'event'   => 'fluentbooking_events',
        'space'   => 'fluentcommunity_spaces',
        'course'  => 'tutor_courses',
        'page'    => 'pages',
    );

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
                    'set'     => !empty($starter['set']),
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
        if ($starter['set']) {
            // A set adds what it can: ready when any of its plugins is.
            foreach (array_keys($starter['items']) as $type) {
                if ('pages' !== $type && 'seoprostack_settings' !== $type && self::type_ready($type)) {
                    return true;
                }
            }
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
        if (0 === strpos($type, 'fluentsupport_')) {
            return class_exists('FluentSupport\App\Models\Product') && class_exists('FluentSupport\App\Models\Ticket');
        }
        if (0 === strpos($type, 'fluentcommunity_')) {
            return class_exists('FluentCommunity\App\Models\Space') && class_exists('FluentCommunity\App\Services\CustomSanitizer');
        }
        if (0 === strpos($type, 'fluentbooking_')) {
            return class_exists('FluentBooking\App\Http\Controllers\CalendarController') && class_exists('FluentBooking\App\Models\CalendarSlot') && class_exists('FluentBooking\App\Models\Booking');
        }
        if (0 === strpos($type, 'tutor_')) {
            return function_exists('tutor') && post_type_exists(self::course_type());
        }
        return 'pages' === $type || 'seoprostack_settings' === $type;
    }

    /**
     * Tutor LMS's course post type.
     *
     * @return string
     */
    private static function course_type() {
        return function_exists('tutor') && !empty(tutor()->course_post_type) ? (string) tutor()->course_post_type : 'courses';
    }

    /**
     * Whether an item's "requires" condition holds.
     *
     * @param array $item Item.
     * @return bool
     */
    private static function requirement_met(array $item) {
        if (empty($item['requires'])) {
            return true;
        }
        if ('payments' === $item['requires']) {
            return self::payments_on();
        }
        return false;
    }

    /**
     * Whether Fluent Forms can take payments: payments turned on (Fluent
     * Forms → Global Settings → Payments) and a payment method, such as
     * Stripe, set up, so its Payment Method field exists.
     *
     * @return bool
     */
    public static function payments_on() {
        $settings = get_option('__fluentform_payment_module_settings');
        if (!is_array($settings) || !isset($settings['status']) || 'yes' !== $settings['status']) {
            return false;
        }
        $methods = apply_filters('fluentform/available_payment_methods', array()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Forms' own filter, as its Payment Method field reads it.
        return !empty($methods);
    }

    /**
     * Items of a starter that wait for a condition (payments turned on),
     * among those whose plugin is active, as names.
     *
     * @param string $slug Starter.
     * @return string[]
     */
    public static function waiting($slug) {
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
                if ((empty($item['when']) || isset($active[(string) $item['when']])) && !self::requirement_met($item) && !self::exists($type, $item)) {
                    $out[] = self::label($type, $item);
                }
            }
        }
        return $out;
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
                if ((!empty($item['when']) && !isset($active[(string) $item['when']])) || !self::requirement_met($item)) {
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
                        // A setting added earlier, before every item it names
                        // existed (forms waiting for payments), catches up.
                        if ('seoprostack_settings' === $type && !empty($records[$type]) && self::refresh_setting($item, $records[$type])) {
                            self::save_added($slug, $records);
                            ++$count;
                        }
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
            case 'fluentsupport_products':
                return sprintf(/* translators: %s: product name */ __('Support product: %s', 'seoprostack'), (string) $item['title']);
            case 'fluentbooking_events':
                return sprintf(/* translators: %s: event name */ __('Booking event: %s', 'seoprostack'), (string) $item['title']);
            case 'fluentcommunity_spaces':
                return sprintf(/* translators: %s: space name */ __('Community space: %s', 'seoprostack'), (string) $item['title']);
            case 'tutor_courses':
                return sprintf(/* translators: %s: course name */ __('Course: %s', 'seoprostack'), (string) $item['title']);
            case 'pages':
                return sprintf(/* translators: %s: page title */ __('Draft page: %s', 'seoprostack'), (string) $item['title']);
            case 'seoprostack_settings':
                $schema = SEOProStack_Settings::schema();
                $key    = (string) $item['key'];
                return sprintf(/* translators: %s: setting name */ __('SEO Pro Stack setting: %s', 'seoprostack'), isset($schema[$key]['label']) ? (string) $schema[$key]['label'] : $key);
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
            case 'fluentsupport_products':
            case 'fluentcommunity_spaces':
            case 'tutor_courses':
            case 'pages':
                return 0 !== self::find_id($type, 'fluentcommunity_spaces' === $type ? (string) $item['slug'] : (string) $item['title']);
            case 'seoprostack_settings':
                $schema = SEOProStack_Settings::schema();
                $key    = (string) $item['key'];
                if (!isset($schema[$key])) {
                    return true; // Unknown here: nothing to add.
                }
                $default = isset($schema[$key]['default']) ? $schema[$key]['default'] : null;
                if (SEOProStack_Settings::get($key) !== $default) {
                    return true;
                }
                // Nothing to set yet (it lists only items the site does not have).
                return SEOProStack_Settings::sanitize_value(self::setting_value($item), $schema[$key]) === $default;
        }
        return true;
    }

    /**
     * ID of an item on this site, by the name the starter gives it.
     *
     * @param string $type Item type.
     * @param string $name Title (slug for spaces).
     * @return int 0 when not found.
     */
    public static function find_id($type, $name) {
        if ('' === $name || !self::type_ready($type)) {
            return 0;
        }
        switch ($type) {
            case 'fluentform_forms':
                $id = \FluentForm\App\Models\Form::where('title', $name)->orderBy('id', 'DESC')->value('id');
                break;
            case 'fluentboards_boards':
                $id = \FluentBoards\App\Models\Board::where('title', $name)->whereNull('archived_at')->orderBy('id', 'DESC')->value('id');
                break;
            case 'fluentsupport_products':
                $id = \FluentSupport\App\Models\Product::where('title', $name)->orderBy('id', 'DESC')->value('id');
                break;
            case 'fluentbooking_events':
                $id = \FluentBooking\App\Models\CalendarSlot::where('title', $name)->where('status', '!=', 'expired')->orderBy('id', 'DESC')->value('id');
                break;
            case 'fluentcommunity_spaces':
                $id = \FluentCommunity\App\Models\Space::where('slug', sanitize_title($name))->value('id');
                if (!$id) {
                    $id = \FluentCommunity\App\Models\Space::where('title', $name)->value('id');
                }
                break;
            case 'tutor_courses':
            case 'pages':
                $ids = get_posts(array(
                    'post_type'              => 'pages' === $type ? 'page' : self::course_type(),
                    'post_status'            => array('publish', 'draft', 'pending', 'private', 'future'),
                    'title'                  => $name,
                    'fields'                 => 'ids',
                    'posts_per_page'         => 1,
                    'orderby'                => 'ID',
                    'order'                  => 'DESC',
                    'no_found_rows'          => true,
                    'update_post_term_cache' => false,
                    'update_post_meta_cache' => false,
                ));
                $id = $ids ? $ids[0] : 0;
                break;
            default:
                $id = 0;
        }
        return (int) $id;
    }

    /**
     * Put item IDs in for {kind:Name} tokens. A value that is only a token
     * becomes a number; tokens inside text become the ID as text. Names the
     * site does not have become 0. {url:Page title} becomes the page's
     * address (?page_id= while it is a draft, which still works once it is
     * published), for links between pages; list the linked page first.
     *
     * @param mixed $value Value.
     * @return mixed
     */
    public static function resolve_tokens($value) {
        if (is_array($value)) {
            return array_map(array(__CLASS__, 'resolve_tokens'), $value);
        }
        if (!is_string($value) || false === strpos($value, '{')) {
            return $value;
        }
        $value = preg_replace_callback('/\{url:([^{}]+)\}/', function ($m) {
            $id = self::find_id('pages', $m[1]);
            return $id ? esc_url((string) get_permalink($id)) : '#';
        }, $value);
        $pattern = '/\{(' . implode('|', array_keys(self::TOKENS)) . '):([^{}]+)\}/';
        if (preg_match('/^' . trim($pattern, '/') . '$/', $value, $m)) {
            return self::find_id(self::TOKENS[$m[1]], $m[2]);
        }
        return preg_replace_callback($pattern, function ($m) {
            return (string) self::find_id(self::TOKENS[$m[1]], $m[2]);
        }, $value);
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

            case 'fluentsupport_products':
                $product = \FluentSupport\App\Models\Product::create(array(
                    'title'       => sanitize_text_field((string) $item['title']),
                    'description' => isset($item['description']) ? sanitize_textarea_field((string) $item['description']) : '',
                    'created_by'  => self::creator(),
                ));
                return $product ? array('id' => (int) $product->id, 'name' => (string) $item['title']) : null;

            case 'fluentbooking_events':
                return self::create_booking_event($item, $slug);

            case 'fluentcommunity_spaces':
                return self::create_space($item);

            case 'tutor_courses':
                return self::create_course($item);

            case 'pages':
                $content = isset($item['content']) ? (string) self::resolve_tokens((string) $item['content']) : '';
                $id      = wp_insert_post(wp_slash(array(
                    'post_type'    => 'page',
                    'post_status'  => 'draft',
                    'post_title'   => sanitize_text_field((string) $item['title']),
                    'post_name'    => isset($item['slug']) ? sanitize_title((string) $item['slug']) : '',
                    'post_content' => $content,
                    'post_author'  => self::creator(),
                )), true);
                if (is_wp_error($id) || !$id) {
                    return null;
                }
                return array('id' => (int) $id, 'hash' => md5((string) get_post_field('post_content', $id, 'raw')), 'name' => (string) $item['title']);

            case 'seoprostack_settings':
                $key   = (string) $item['key'];
                $value = SEOProStack_Settings::set($key, self::setting_value($item));
                if (is_wp_error($value)) {
                    return null;
                }
                return array('key' => $key, 'hash' => md5((string) wp_json_encode($value)), 'name' => $key);
        }
        return null;
    }

    /**
     * Who items are made by: the current user, or the first administrator
     * (WP-CLI without --user).
     *
     * @return int
     */
    private static function creator() {
        $creator = get_current_user_id();
        if (!$creator) {
            $admins  = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID'));
            $creator = $admins ? (int) $admins[0] : 0;
        }
        return (int) $creator;
    }

    /**
     * An SEO Pro Stack setting's value from a starter, with item IDs put in.
     * In lists, items the site does not have (yet) are left out.
     *
     * @param array $item Setting item.
     * @return mixed
     */
    private static function setting_value(array $item) {
        $raw   = isset($item['value']) ? $item['value'] : null;
        $value = self::resolve_tokens($raw);
        if (0 === $value && is_string($raw)) {
            return null; // Names one item the site does not have (yet).
        }
        if (is_array($value)) {
            $value = array_values(array_filter($value, function ($v) {
                return 0 !== $v && '' !== $v && '0' !== $v;
            }));
        }
        return $value;
    }

    /**
     * Bring a setting SEO Pro Stack added up to date with the items that
     * exist now, while it is as SEO Pro Stack left it.
     *
     * @param array $item    Setting item.
     * @param array $records Records of added settings; the matching one is updated.
     * @return bool Whether it changed.
     */
    private static function refresh_setting(array $item, array &$records) {
        $key = (string) $item['key'];
        foreach ($records as $i => $record) {
            if (!is_array($record) || !isset($record['key'], $record['hash']) || $key !== $record['key']) {
                continue;
            }
            $current = SEOProStack_Settings::get($key);
            if (md5((string) wp_json_encode($current)) !== $record['hash']) {
                return false; // Changed by someone since: leave it.
            }
            $wanted = self::setting_value($item);
            if ($wanted === $current) {
                return false;
            }
            $value = SEOProStack_Settings::set($key, $wanted);
            if (is_wp_error($value) || $value === $current) {
                return false;
            }
            $records[$i]['hash'] = md5((string) wp_json_encode($value));
            return true;
        }
        return false;
    }

    /**
     * Create a FluentCommunity space as its own create-space screen does,
     * with the person adding it as the space's admin.
     *
     * @param array $item Space item.
     * @return array|null
     */
    private static function create_space(array $item) {
        $privacy = isset($item['privacy']) && in_array($item['privacy'], array('public', 'private', 'secret'), true) ? $item['privacy'] : 'private';
        $settings = \FluentCommunity\App\Services\CustomSanitizer::santizeSpaceSettings(array(), $privacy);
        if (is_wp_error($settings)) {
            return null;
        }
        $serial = (int) \FluentCommunity\App\Models\BaseSpace::query()->withoutGlobalScopes()->max('serial') + 1;
        $space  = \FluentCommunity\App\Models\Space::create(apply_filters('fluent_community/space/create_data', array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCommunity's own filter, as its screen applies it.
            'title'       => sanitize_text_field((string) $item['title']),
            'slug'        => sanitize_title(isset($item['slug']) ? (string) $item['slug'] : (string) $item['title']),
            'privacy'     => $privacy,
            'description' => isset($item['description']) ? sanitize_textarea_field((string) $item['description']) : '',
            'settings'    => $settings,
            'parent_id'   => null,
            'serial'      => $serial,
        )));
        if (!$space || empty($space->id)) {
            return null;
        }
        $creator = self::creator();
        if ($creator) {
            $space->members()->attach($creator, array('role' => 'admin'));
        }
        do_action('fluent_community/space/created', $space, array()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCommunity's own hook.
        return array('id' => (int) $space->id, 'name' => (string) $item['title']);
    }

    /**
     * Create a free, published Tutor LMS course with its topics and lessons.
     *
     * @param array $item Course item.
     * @return array|null
     */
    private static function create_course(array $item) {
        $author = self::creator();
        $course = wp_insert_post(wp_slash(array(
            'post_type'    => self::course_type(),
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field((string) $item['title']),
            'post_content' => isset($item['content']) ? wp_kses_post((string) $item['content']) : '',
            'post_excerpt' => isset($item['excerpt']) ? sanitize_textarea_field((string) $item['excerpt']) : '',
            'post_author'  => $author,
        )), true);
        if (is_wp_error($course) || !$course) {
            return null;
        }
        update_post_meta($course, '_tutor_course_price_type', 'free');
        $children = array();
        foreach (isset($item['topics']) ? array_values((array) $item['topics']) : array() as $t => $topic) {
            if (!is_array($topic) || empty($topic['title'])) {
                continue;
            }
            $topic_id = wp_insert_post(wp_slash(array(
                'post_type'    => 'topics',
                'post_status'  => 'publish',
                'post_title'   => sanitize_text_field((string) $topic['title']),
                'post_content' => isset($topic['summary']) ? sanitize_textarea_field((string) $topic['summary']) : '',
                'post_parent'  => $course,
                'menu_order'   => $t + 1,
                'post_author'  => $author,
            )));
            if (!$topic_id) {
                continue;
            }
            $children[] = (int) $topic_id;
            foreach (isset($topic['lessons']) ? array_values((array) $topic['lessons']) : array() as $l => $lesson) {
                if (!is_array($lesson) || empty($lesson['title'])) {
                    continue;
                }
                $lesson_id = wp_insert_post(wp_slash(array(
                    'post_type'    => 'lesson',
                    'post_status'  => 'publish',
                    'post_title'   => sanitize_text_field((string) $lesson['title']),
                    'post_content' => isset($lesson['content']) ? wp_kses_post((string) $lesson['content']) : '',
                    'post_parent'  => $topic_id,
                    'menu_order'   => $l + 1,
                    'post_author'  => $author,
                )));
                if ($lesson_id) {
                    $children[] = (int) $lesson_id;
                }
            }
        }
        return array('id' => (int) $course, 'children' => $children, 'hash' => md5((string) get_post_field('post_content', $course, 'raw')), 'name' => (string) $item['title']);
    }

    /**
     * Host for Booking: the adding user, or the first administrator for WP-CLI.
     *
     * @return int
     */
    private static function booking_user() {
        return self::creator();
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
            // Only locations that need no details from the host (a number, address or link).
            $location = isset($item['location']) && in_array($item['location'], array('phone_guest', 'online_meeting'), true) ? (string) $item['location'] : '';
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
                'location_type' => $location,
                'location_settings' => $location ? array(array('type' => $location, 'title' => '', 'display_on_booking' => '')) : array(),
                'color_schema' => isset($item['color']) && sanitize_hex_color((string) $item['color']) ? (string) $item['color'] : '#0099ff',
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
        $id       = (int) $form->id;
        $fields   = $item['form_fields'];
        $checkout = isset($item['layout']) && 'checkout' === $item['layout'] && isset($fields['fields']) && is_array($fields['fields']);
        if ($checkout) {
            $text             = isset($fields['submitButton']['settings']['button_ui']['text']) ? (string) $fields['submitButton']['settings']['button_ui']['text'] : __('Complete purchase', 'seoprostack');
            $fields['fields'] = self::checkout_fields($fields['fields'], isset($item['intro']) ? (string) $item['intro'] : '', $text);
        }
        if (isset($fields['fields']) && is_array($fields['fields'])) {
            $fields['fields'] = self::usable_fields(self::expand_fields($fields['fields']), self::active_folders());
        }
        if (isset($fields['submitButton']) && is_array($fields['submitButton']) && empty($fields['submitButton']['editor_options'])) {
            // Compact: only the button text; the rest from the template's own button.
            $stored = \FluentForm\App\Models\Form::find($id);
            $own    = $stored ? json_decode((string) $stored->form_fields, true) : null;
            if (is_array($own) && isset($own['submitButton']) && is_array($own['submitButton'])) {
                $fields['submitButton'] = self::merge_deep($own['submitButton'], $fields['submitButton']);
            }
        }
        $fields = self::resolve_tokens($fields);
        (new \FluentForm\App\Services\Form\Updater())->update(array(
            'form_id'    => $id,
            'title'      => $title,
            'status'     => 'published',
            'formFields' => wp_json_encode($fields),
        ));
        if ($checkout) {
            // In the form's own Custom CSS, so it travels with copies of the form.
            \FluentForm\App\Models\FormMeta::persist($id, '_custom_form_css', self::CHECKOUT_CSS);
        }

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
     * A checkout layout: the service and its questions in a wide column, and
     * the order summary, discount code and button in a narrow one beside it
     * (below it on phones).
     *
     * @param array  $fields Compact fields.
     * @param string $intro  HTML describing the service, shown first.
     * @param string $button Button text.
     * @return array One two-column container.
     */
    private static function checkout_fields(array $fields, $intro, $button) {
        $main = array();
        $side = array(
            array('element' => 'custom_html', 'settings' => array('html_codes' => '<h3>' . esc_html__('Your order', 'seoprostack') . '</h3>')),
        );
        if ('' !== $intro) {
            $main[] = array('element' => 'custom_html', 'settings' => array('html_codes' => $intro));
        }
        $pay = array();
        foreach ($fields as $field) {
            $element = is_array($field) && isset($field['element']) ? $field['element'] : '';
            if (in_array($element, array('payment_coupon', 'payment_summary_component'), true)) {
                $side[] = $field;
            } elseif ('payment_method' === $element) {
                $pay[] = $field;
            } else {
                $main[] = $field;
            }
        }
        // Payment details last, next to the button.
        $main = array_merge($main, $pay);
        // Fluent Forms hides its own button when a form has this one.
        $side[] = array(
            'element'  => 'custom_submit_button',
            'settings' => array('button_ui' => array('text' => $button, 'type' => 'default', 'img_url' => '')),
        );
        return array(
            array(
                'element'  => 'container',
                'settings' => array('container_class' => 'sps-checkout'),
                'columns'  => array(
                    array('width' => 66.67, 'fields' => $main),
                    array('width' => 33.33, 'fields' => $side),
                ),
            ),
        );
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
        $creator = self::creator();
        $board   = (new \FluentBoards\App\Services\BoardService())->createBoard(array(
            'title'       => sanitize_text_field((string) $item['title']),
            'type'        => isset($item['type']) ? sanitize_key((string) $item['type']) : 'to-do',
            'description' => isset($item['description']) ? sanitize_textarea_field((string) $item['description']) : '',
            'created_by'  => $creator,
        ));
        if (!$board) {
            return null;
        }
        if (!self::create_labels($board->id, isset($item['labels']) ? (array) $item['labels'] : array())) {
            (new \FluentBoards\App\Services\LabelService())->createDefaultLabel($board->id);
        }
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
     * Add a board's labels the way Fluent Boards adds its default ones: a
     * colour preset (light colours stored, dark ones picked when shown).
     *
     * @param int   $board_id Board ID.
     * @param array $labels   Labels: title, color (preset ID).
     * @return bool Whether any were added.
     */
    private static function create_labels($board_id, array $labels) {
        if (!$labels || !class_exists('FluentBoards\App\Models\Label') || !method_exists('FluentBoards\App\Services\Constant', 'getLabelColorPreset')) {
            return false;
        }
        $rows = array();
        foreach ($labels as $label) {
            if (!is_array($label) || empty($label['title'])) {
                continue;
            }
            $preset_id = isset($label['color']) ? (string) $label['color'] : 'gray-bold';
            $preset    = \FluentBoards\App\Services\Constant::getLabelColorPreset($preset_id);
            if (!$preset) {
                $preset_id = 'gray-bold';
                $preset    = \FluentBoards\App\Services\Constant::getLabelColorPreset($preset_id);
            }
            $title  = sanitize_text_field((string) $label['title']);
            $rows[] = array(
                'board_id'   => (int) $board_id,
                'title'      => $title,
                'slug'       => sanitize_title($title),
                'type'       => 'label',
                'bg_color'   => $preset ? $preset['light_bg_color'] : '',
                'color'      => $preset ? $preset['light_text_color'] : '',
                'settings'   => maybe_serialize(array(\FluentBoards\App\Services\Constant::LABEL_COLOR_PRESET_SETTING => $preset_id)),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            );
        }
        if (!$rows) {
            return false;
        }
        \FluentBoards\App\Models\Label::insert($rows);
        return true;
    }

    /**
     * Fluent Forms' element defaults, keyed by element (the first of each
     * name: text input before mask input, dropdown before multiple choice),
     * and its container layouts keyed by number of columns.
     *
     * @return array{elements:array<string,array>,containers:array<int,array>}
     */
    private static function form_elements() {
        static $out = null;
        if (null !== $out) {
            return $out;
        }
        $out = array('elements' => array(), 'containers' => array());
        if (!function_exists('wpFluentForm')) {
            return $out;
        }
        $components = wpFluentForm('components');
        do_action('fluentform/editor_init', $components); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Forms' own hook, as its editor fires it to build the element list.
        $groups = (array) $components->toArray();
        // Payment and other extra elements arrive through this filter (true: keep fields without a form).
        foreach ((array) apply_filters('fluentform/editor_components', array(), true) as $group => $elements) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Forms' own filter.
            $groups[$group] = array_merge(isset($groups[$group]) ? (array) $groups[$group] : array(), (array) $elements);
        }
        foreach ($groups as $group => $elements) {
            foreach ((array) $elements as $element) {
                if (!is_array($element) || empty($element['element'])) {
                    continue;
                }
                if ('container' === $element['element']) {
                    $count = isset($element['columns']) ? count((array) $element['columns']) : 0;
                    if ($count && !isset($out['containers'][$count])) {
                        $out['containers'][$count] = $element;
                    }
                } elseif (!isset($out['elements'][$element['element']])) {
                    $out['elements'][$element['element']] = $element;
                }
            }
        }
        return $out;
    }

    /**
     * Complete compact form fields from Fluent Forms' element defaults.
     * Fields that already carry editor_options (a full export) stay as they are.
     *
     * @param array $fields Fields.
     * @return array
     */
    private static function expand_fields(array $fields) {
        $defaults = self::form_elements();
        $out      = array();
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['element'])) {
                continue;
            }
            $element = (string) $field['element'];
            if ('container' === $element && isset($field['columns']) && is_array($field['columns'])) {
                $count = count($field['columns']);
                $base  = isset($defaults['containers'][$count]) ? $defaults['containers'][$count] : array('element' => 'container', 'attributes' => array(), 'settings' => array('container_class' => '', 'conditional_logics' => array()), 'columns' => array());
                unset($base['index']);
                if (!empty($field['settings']) && is_array($field['settings'])) {
                    $base['settings'] = self::merge_deep(isset($base['settings']) ? (array) $base['settings'] : array(), $field['settings']);
                }
                foreach (array_values($field['columns']) as $i => $column) {
                    $base['columns'][$i] = array(
                        'width'  => isset($column['width']) ? $column['width'] : (isset($base['columns'][$i]['width']) ? $base['columns'][$i]['width'] : round(100 / $count, 2)),
                        'fields' => self::expand_fields(isset($column['fields']) ? (array) $column['fields'] : array()),
                    );
                }
                $base['uniqElKey'] = 'el_' . uniqid();
                if (!empty($field['when'])) {
                    $base['when'] = $field['when'];
                }
                $out[] = $base;
                continue;
            }
            if (!empty($field['editor_options'])) {
                $out[] = $field;
                continue;
            }
            if (!isset($defaults['elements'][$element])) {
                // A compact field this site's Fluent Forms does not offer: leave it out.
                continue;
            }
            $base = $defaults['elements'][$element];
            unset($base['index']);
            foreach (array('attributes', 'settings') as $part) {
                if (!empty($field[$part]) && is_array($field[$part])) {
                    $base[$part] = self::merge_deep(isset($base[$part]) ? (array) $base[$part] : array(), $field[$part]);
                }
            }
            if (!empty($field['fields']) && is_array($field['fields']) && isset($base['fields']) && is_array($base['fields'])) {
                // Name and address parts: first_name, last_name, address_line_1…
                foreach ($field['fields'] as $name => $part) {
                    if (isset($base['fields'][$name]) && is_array($part)) {
                        $base['fields'][$name] = self::merge_deep($base['fields'][$name], $part);
                    }
                }
            }
            if (!empty($field['required'])) {
                $base['settings']['validation_rules']['required']['value'] = true;
            }
            if (isset($field['options']) && is_array($field['options'])) {
                $base = self::field_options($base, $field['options']);
            }
            $base['uniqElKey'] = 'el_' . uniqid();
            if (!empty($field['when'])) {
                $base['when'] = $field['when'];
            }
            $out[] = $base;
        }
        return $out;
    }

    /**
     * Put a compact field's options in: choices (value => label) for
     * dropdowns, radios and checkboxes; label => price for payment items;
     * name => [amount, interval, setup fee] for subscription plans.
     *
     * @param array $field   Field.
     * @param array $options Options.
     * @return array
     */
    private static function field_options(array $field, array $options) {
        $element = $field['element'];
        if ('multi_payment_component' === $element) {
            $list = array();
            // Fluent Forms shows the price of a single item only, so choices
            // carry theirs in the label, in this site's currency.
            $priced = 'single' !== $field['attributes']['type'];
            foreach ($options as $label => $price) {
                $label  = (string) $label;
                $list[] = array('label' => $priced ? $label . ' – ' . self::money((float) $price) : $label, 'value' => (float) $price, 'image' => '');
            }
            $field['settings']['pricing_options'] = $list;
            if (1 === count($list) && 'single' === $field['attributes']['type']) {
                $field['attributes']['value'] = (string) $list[0]['value'];
            }
            return $field;
        }
        if ('subscription_payment_component' === $element) {
            $template = isset($field['settings']['subscription_options'][0]) ? (array) $field['settings']['subscription_options'][0] : array();
            $list     = array();
            foreach ($options as $name => $plan) {
                $plan   = array_values((array) $plan);
                $fee    = isset($plan[2]) ? (float) $plan[2] : 0;
                $list[] = array_merge($template, array(
                    'name'                => (string) $name,
                    'subscription_amount' => isset($plan[0]) ? (float) $plan[0] : 0,
                    'billing_interval'    => isset($plan[1]) ? sanitize_key((string) $plan[1]) : 'month',
                    'has_signup_fee'      => $fee > 0 ? 'yes' : 'no',
                    'signup_fee'          => $fee,
                    'is_default'          => $list ? 'no' : 'yes',
                ));
            }
            $field['settings']['subscription_options'] = $list;
            // One plan shows as a single item; more as a choice (settings.selection_type).
            $field['attributes']['type'] = count($list) > 1 ? 'multiple' : 'single';
            return $field;
        }
        if (isset($field['settings']['advanced_options'])) {
            $list = array();
            foreach ($options as $value => $label) {
                $list[] = array('label' => (string) $label, 'value' => (string) $value, 'calc_value' => '', 'image' => '');
            }
            $field['settings']['advanced_options'] = $list;
        }
        return $field;
    }

    /**
     * A price as Fluent Forms shows it, in its payment currency.
     *
     * @param float $amount Amount.
     * @return string
     */
    private static function money($amount) {
        $helper = '\FluentForm\App\Modules\Payments\PaymentHelper';
        if (class_exists($helper)) {
            $settings = $helper::getPaymentSettings();
            if (!empty($settings['currency'])) {
                $money = $helper::formatMoney((int) round($amount * 100), $settings['currency']);
                // Whole amounts without the zero pence: £90, not £90.00.
                return floor($amount) == $amount ? (string) preg_replace('/[.,]00(?=\D*$)/', '', $money) : $money;
            }
        }
        return number_format_i18n($amount, floor($amount) == $amount ? 0 : 2);
    }

    /**
     * Merge arrays key by key; lists are replaced whole.
     *
     * @param array $base Base.
     * @param array $ours Ours.
     * @return array
     */
    private static function merge_deep(array $base, array $ours) {
        foreach ($ours as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !wp_is_numeric_array($value) && !wp_is_numeric_array($base[$key])) {
                $base[$key] = self::merge_deep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
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

            case 'fluentsupport_products':
                $product = \FluentSupport\App\Models\Product::find((int) $record['id']);
                if (!$product) {
                    return false;
                }
                if (\FluentSupport\App\Models\Ticket::where('product_id', (int) $product->id)->count() > 0) {
                    return $name;
                }
                \FluentSupport\App\Models\Product::where('id', (int) $product->id)->delete();
                return true;

            case 'fluentcommunity_spaces':
                $space = \FluentCommunity\App\Models\Space::find((int) $record['id']);
                if (!$space) {
                    return false;
                }
                $id      = (int) $space->id;
                $members = \FluentCommunity\App\Models\SpaceUserPivot::where('space_id', $id)->where('role', '!=', 'admin')->count();
                $posts   = \FluentCommunity\App\Models\Feed::where('space_id', $id)->count();
                if ($members > 0 || $posts > 0) {
                    return $name;
                }
                do_action('fluent_community/space/before_delete', $space); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCommunity's own hook, as its delete-space code fires it.
                \FluentCommunity\App\Models\SpaceUserPivot::where('space_id', $id)->delete();
                $space->delete();
                do_action('fluent_community/space/deleted', $id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCommunity's own hook.
                return true;

            case 'tutor_courses':
                $course = get_post((int) $record['id']);
                if (!$course || self::course_type() !== $course->post_type) {
                    return false;
                }
                $enrolled = get_posts(array(
                    'post_type'        => 'tutor_enrolled',
                    'post_parent'      => (int) $course->ID,
                    'post_status'      => 'any',
                    'fields'           => 'ids',
                    'posts_per_page'   => 1,
                    'no_found_rows'    => true,
                ));
                if ($enrolled || md5((string) $course->post_content) !== (string) $record['hash']) {
                    return $name;
                }
                foreach (array_reverse(isset($record['children']) ? (array) $record['children'] : array()) as $child) {
                    wp_delete_post((int) $child, true);
                }
                wp_delete_post((int) $course->ID, true);
                return true;

            case 'pages':
                $page = get_post((int) $record['id']);
                if (!$page || 'page' !== $page->post_type || 'trash' === $page->post_status) {
                    return false;
                }
                if ('draft' !== $page->post_status || md5((string) $page->post_content) !== (string) $record['hash']) {
                    return $name;
                }
                wp_delete_post((int) $page->ID, true);
                return true;

            case 'seoprostack_settings':
                $key    = (string) $record['key'];
                $schema = SEOProStack_Settings::schema();
                if (!isset($schema[$key])) {
                    return false;
                }
                if (md5((string) wp_json_encode(SEOProStack_Settings::get($key))) !== (string) $record['hash']) {
                    return $name;
                }
                SEOProStack_Settings::set($key, isset($schema[$key]['default']) ? $schema[$key]['default'] : null);
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
