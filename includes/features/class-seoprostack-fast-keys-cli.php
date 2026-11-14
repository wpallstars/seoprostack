<?php
/**
 * Faster database keys: `wp seoprostack fast-keys`.
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

final class SEOProStack_Fast_Keys_Cli {

    /**
     * Shows, applies or undoes faster database keys.
     *
     * ## OPTIONS
     *
     * [<command>]
     * : status (default), apply or undo.
     *
     * @param array $args Positional arguments.
     */
    public static function run($args) {
        $command = isset($args[0]) ? (string) $args[0] : 'status';
        $items   = array();
        foreach (self::results($command) as $suffix => $result) {
            $items[] = array('table' => SEOProStack_Fast_Keys_Schema::table($suffix), 'status' => $result['status'], 'note' => $result['note']);
        }
        WP_CLI\Utils\format_items('table', $items, array('table', 'status', 'note'));
    }

    /**
     * Results per table for a command.
     *
     * @param string $command status, apply or undo.
     * @return array<string,array>
     */
    private static function results($command) {
        if ('status' === $command) {
            return self::status();
        }
        if ('apply' === $command && !SEOProStack_Fast_Keys::enabled()) {
            WP_CLI::error('Turn on Faster database keys (Server tab) and deactivate Index WP MySQL For Speed first.');
        }
        if ('undo' === $command && SEOProStack_Fast_Keys::switched_on()) {
            WP_CLI::error('Turn off Faster database keys (Server tab) first, or it adds them again.');
        }
        if (in_array($command, array('apply', 'undo'), true)) {
            return SEOProStack_Fast_Keys_Run::run(true, $command);
        }
        WP_CLI::error('Usage: wp seoprostack fast-keys [status|apply|undo]');
        return array();
    }

    /**
     * The changes each table needs to get the faster keys.
     *
     * @return array<string,array{status:string,note:string}>
     */
    private static function status() {
        $results = array();
        $state   = SEOProStack_Fast_Keys::state();
        foreach (SEOProStack_Fast_Keys_Schema::targets() as $suffix => $target) {
            $current = SEOProStack_Fast_Keys_Table::inspect($suffix);
            if (null === $current || is_wp_error($current)) {
                continue;
            }
            $base = SEOProStack_Fast_Keys_Schema::standard_for($suffix);
            $plan = SEOProStack_Fast_Keys_Plan::plan($current, SEOProStack_Fast_Keys_Schema::combined($target, $base), $base);
            if (!$plan) {
                $results[$suffix] = array('status' => 'done', 'note' => isset($state['owned'][$suffix]) ? 'kept' : 'not kept');
                continue;
            }
            $note             = empty($state['tables'][$suffix]['note']) ? '' : ' (' . $state['tables'][$suffix]['note'] . ')';
            $results[$suffix] = array('status' => 'to change', 'note' => implode(', ', $plan) . $note);
        }
        return $results;
    }
}
