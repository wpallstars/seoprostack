<?php
/**
 * WordPress's scheduled tasks list (the cron option), for Fixes for other
 * plugins (SEOProStack_Plugin_Fixes).
 *
 * WordPress keeps every scheduled task of a site in one autoloaded option,
 * cron. Each request reads it once, and every change it makes
 * (wp_schedule_event(), wp_unschedule_event(), a cron run moving a task to
 * its next time) writes the whole list back from that copy. A request that
 * runs long, as a cron run does (90 seconds on one site, writing the list
 * about 30 times), writes the list it read at the start back over every
 * change made meanwhile: removed tasks come back, other plugins' changes are
 * lost, and the next removal of a task that came back fails with "The cron
 * event list could not be saved" (could_not_set). WordPress refuses a second
 * copy of a single event, but not of a recurring one, so the copies stay.
 *
 * write() applies what the request changed (the tasks it added, changed or
 * removed since it read the list) to the list as stored now, so changes made
 * by other requests meanwhile are kept. dedupe() runs once at the start of
 * each cron run and removes extra copies of recurring tasks: the same task
 * (hook and arguments) on the same schedule at more than one time.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.152
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Cron_List {

    /**
     * Register the hooks. write() runs on every write of the list; dedupe()
     * only in WordPress cron runs (wp-cron.php), before they read which tasks
     * are due.
     */
    public static function init() {
        add_filter('pre_update_option_cron', array(__CLASS__, 'write'), PHP_INT_MAX, 2);
        if (wp_doing_cron()) {
            add_action('wp_loaded', array(__CLASS__, 'dedupe'), 0);
        }
    }

    /**
     * Write what this request changed in the list onto the list as stored
     * now, instead of this request's copy of it.
     *
     * The tasks in $old_value (this request's copy) that are not in $value
     * were removed by this request; those in $value that are not in
     * $old_value, or differ, were added or changed by it. Only those are
     * applied to the stored list; tasks this request did not touch stay as
     * stored, whatever its copy says. When the stored list is this request's
     * copy, as it usually is, $value is written unchanged.
     *
     * @param mixed $value     The list to write.
     * @param mixed $old_value This request's copy of the stored list.
     * @return mixed
     */
    public static function write($value, $old_value) {
        if (!is_array($value) || !is_array($old_value)) {
            return $value;
        }
        $stored = self::stored();
        if (null === $stored || $stored === $old_value) {
            return $value;
        }
        return self::merge($stored, $old_value, $value);
    }

    /**
     * The stored list with one request's changes applied: what is in its
     * copy but not in its new list removed, what is new or different in its
     * new list added, in time order.
     *
     * @param array<mixed> $stored The list as stored now.
     * @param array<mixed> $before The request's copy.
     * @param array<mixed> $after  The request's new list.
     * @return array<mixed>
     */
    private static function merge(array $stored, array $before, array $after) {
        $merged = $stored;
        unset($merged['version']);
        foreach (self::events($before) as list($time, $hook, $key)) {
            if (!isset($after[$time][$hook][$key])) {
                self::remove($merged, $time, $hook, $key);
            }
        }
        foreach (self::events($after) as list($time, $hook, $key, $event)) {
            if (!isset($before[$time][$hook][$key]) || $before[$time][$hook][$key] !== $event) {
                self::put($merged, $time, $hook, $key, $event);
            }
        }
        uksort($merged, 'strnatcasecmp');
        if (isset($after['version'])) {
            $merged['version'] = $after['version'];
        }
        return $merged;
    }

    /**
     * Remove extra copies of recurring tasks: the same hook and arguments on
     * the same schedule at more than one time. The latest copy is kept: a
     * cron run moving a task writes its next time before removing the one
     * that ran, so in between the copy that is about to go is the earlier
     * one. Single events, and copies whose schedule differs, are left alone.
     * The list is written once, and only when a copy is removed.
     */
    public static function dedupe() {
        if (!function_exists('_get_cron_array') || !function_exists('_set_cron_array')) {
            return;
        }
        $crons = _get_cron_array();
        $extra = self::extra_copies($crons);
        if (!$extra) {
            return;
        }
        foreach ($extra as list($time, $hook, $key)) {
            self::remove($crons, $time, $hook, $key);
        }
        _set_cron_array($crons);
    }

    /**
     * Every copy of a recurring task but its latest: time, hook and key.
     *
     * @param array<mixed> $crons A cron list.
     * @return array<int, array{0:int|string,1:string,2:string}>
     */
    private static function extra_copies(array $crons) {
        $latest = array();
        $extra  = array();
        foreach (self::events($crons) as list($time, $hook, $key, $event)) {
            $schedule = is_array($event) && isset($event['schedule']) && is_string($event['schedule']) ? $event['schedule'] : '';
            if ('' === $schedule) {
                continue;
            }
            $task = $hook . "\0" . $key . "\0" . $schedule;
            if (!isset($latest[$task])) {
                $latest[$task] = $time;
                continue;
            }
            if ((int) $time > (int) $latest[$task]) {
                $extra[]       = array($latest[$task], $hook, $key);
                $latest[$task] = $time;
            } else {
                $extra[] = array($time, $hook, $key);
            }
        }
        return $extra;
    }

    /**
     * Add or replace one task in a list.
     *
     * @param array<mixed> $crons A cron list.
     * @param int|string   $time  Time.
     * @param string       $hook  Hook.
     * @param string       $key   Hash of its arguments.
     * @param mixed        $event The task.
     */
    private static function put(array &$crons, $time, $hook, $key, $event) {
        if (!isset($crons[$time]) || !is_array($crons[$time])) {
            $crons[$time] = array();
        }
        if (!isset($crons[$time][$hook]) || !is_array($crons[$time][$hook])) {
            $crons[$time][$hook] = array();
        }
        $crons[$time][$hook][$key] = $event;
    }

    /**
     * Remove one task from a list, and its hook and time when nothing else
     * is left there.
     *
     * @param array<mixed> $crons A cron list.
     * @param int|string   $time  Time.
     * @param string       $hook  Hook.
     * @param string       $key   Hash of its arguments.
     */
    private static function remove(array &$crons, $time, $hook, $key) {
        if (!isset($crons[$time][$hook]) || !is_array($crons[$time]) || !is_array($crons[$time][$hook])) {
            return;
        }
        unset($crons[$time][$hook][$key]);
        if (!$crons[$time][$hook]) {
            unset($crons[$time][$hook]);
        }
        if (!$crons[$time]) {
            unset($crons[$time]);
        }
    }

    /**
     * Each task in a list: time, hook, key (the hash of its arguments) and
     * the task, in the list's order. The version entry is skipped.
     *
     * @param array<mixed> $crons A cron list.
     * @return array<int, array{0:int|string,1:string,2:string,3:mixed}>
     */
    private static function events(array $crons) {
        $events = array();
        foreach ($crons as $time => $hooks) {
            if ('version' === $time || !is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook => $tasks) {
                if (!is_array($tasks)) {
                    continue;
                }
                foreach ($tasks as $key => $event) {
                    $events[] = array($time, (string) $hook, (string) $key, $event);
                }
            }
        }
        return $events;
    }

    /**
     * The list as stored now, read from the database past every cache
     * (another request may have written it since this one read it); null
     * when it cannot be read.
     *
     * @return array<mixed>|null
     */
    private static function stored() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the stored value now, past the option caches, once per write of the list.
        $row = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'cron'));
        if (!is_string($row)) {
            return null;
        }
        $stored = maybe_unserialize($row);
        return is_array($stored) ? $stored : null;
    }
}
