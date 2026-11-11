<?php
/**
 * The pages to refresh once their pictures' 480 pixel copies are made
 * (GH#1542), for SEOProStack_Picture_Sizes.
 *
 * A picture waiting for its 480 copy alone does not purge its page when
 * the copy is made, and pages are cached for a week, so the page would
 * keep offering the 768 copy, and pictures it shows that found the queue
 * full would not be looked at again until then. The posts such pages show
 * are remembered here (a few, the first seen) and taken when no picture
 * waits for that copy, to be purged and opened again.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.202
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Picture_Pages {

    /** Option: IDs of the posts remembered. */
    const OPTION = 'seoprostack_picture_pages';

    /** Posts remembered, at most. */
    const MAX = 20;

    /** @var bool This request's page is remembered already, or cannot be. */
    private static $noted = false;

    /**
     * Remember the post this page shows, once a request: singular pages
     * only, as archives have no post to purge.
     */
    public static function note() {
        if (self::$noted) {
            return;
        }
        self::$noted = true;
        $post_id     = is_singular() ? (int) get_queried_object_id() : 0;
        $pages       = self::all();
        if ($post_id < 1 || in_array($post_id, $pages, true) || count($pages) >= self::MAX) {
            return;
        }
        $pages[] = $post_id;
        update_option(self::OPTION, $pages, false);
    }

    /**
     * The posts remembered, forgotten now.
     *
     * @return int[]
     */
    public static function take() {
        $pages = self::all();
        if ($pages) {
            delete_option(self::OPTION);
        }
        return $pages;
    }

    /**
     * The posts remembered.
     *
     * @return int[]
     */
    private static function all() {
        $pages = get_option(self::OPTION, array());
        return array_values(array_filter(array_map('intval', is_array($pages) ? $pages : array()), static function ($id) {
            return $id > 0;
        }));
    }
}
