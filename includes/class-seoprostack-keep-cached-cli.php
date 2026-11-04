<?php
/**
 * `wp seoprostack keep-cached`: fill the page cache now (see
 * SEOProStack_Keep_Pages_Cached).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.145
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Keep_Cached_CLI {

    /**
     * Register the command.
     */
    public static function register() {
        WP_CLI::add_command('seoprostack keep-cached', array(__CLASS__, 'run'), array(
            'shortdesc' => 'Fill the page cache now: open the pages Keep pages cached would, and show how each answered.',
            'synopsis'  => array(
                array(
                    'type'        => 'flag',
                    'name'        => 'network',
                    'optional'    => true,
                    'description' => 'Every site of the network.',
                ),
                array(
                    'type'        => 'flag',
                    'name'        => 'dry-run',
                    'optional'    => true,
                    'description' => 'List the pages without opening them.',
                ),
            ),
        ));
    }

    /**
     * `wp seoprostack keep-cached [--network] [--dry-run]`: open the pages
     * now, one at a time, and show each answer.
     *
     * @param array $args  Positional arguments (none).
     * @param array $assoc Flags.
     */
    public static function run($args, $assoc) {
        unset($args);
        $network = !empty($assoc['network']) && is_multisite();
        $urls    = SEOProStack_Keep_Cached_Pages::to_open($network);
        if (!empty($assoc['dry-run'])) {
            WP_CLI::log(implode("\n", $urls));
            WP_CLI::success(sprintf('%d pages.', count($urls)));
            return;
        }
        $mobile = SEOProStack_Keep_Cached_Requests::mobile_copy();
        foreach ($urls as $url) {
            $answers = array();
            foreach (SEOProStack_Keep_Cached_Requests::open($url, $mobile) as $answer) {
                $answers[] = trim($answer['code'] . ' ' . $answer['cache']);
            }
            WP_CLI::log($url . ' ' . implode(' | ', $answers));
        }
        if (!$network && SEOProStack_Keep_Cached_Requests::litespeed_crawler() && class_exists('SEOProStack_Litespeed_Crawler')) {
            if (SEOProStack_Litespeed_Crawler::start()) {
                WP_CLI::log('LiteSpeed Cache crawler started.');
            } else {
                WP_CLI::warning('LiteSpeed Cache crawler not started: LiteSpeed Cache is not loaded in this run.');
            }
        }
        WP_CLI::success(sprintf('%d pages opened.', count($urls)));
    }
}
