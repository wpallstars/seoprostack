<?php
/**
 * Site templates: block parts of a page or element.
 *
 * A part is a top-level core/group block with the class
 * sps-template-part-{key}. Its SHA-256 as written decides ownership: an
 * unchanged part takes the template's new version, an edited or removed one
 * stays as the site has it.
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

class SEOProStack_Site_Template_Parts {

    const PREFIX = 'sps-template-part-';

    /**
     * Merge the manifest's parts into the post's blocks.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Post resource key.
     * @param array                         $item Manifest post.
     * @param array                         $blocks Current blocks, updated in place.
     * @param array                         $hashes Owned part hashes.
     * @return array Owned part hashes after the merge.
     */
    public static function merge($run, $path, $item, &$blocks, $hashes) {
        $indices = self::indices($blocks);
        foreach ($item['parts'] as $part) {
            $key = $part['key'];
            $new = self::block($run, $path . '/' . $key, $key, $part['markup']);
            if ($new) {
                self::merge_part($run, $path . '/' . $key, $key, $new, $blocks, $indices[$key] ?? array(), $hashes);
            }
        }
        foreach (array_diff(array_keys($hashes), array_column($item['parts'], 'key')) as $key) {
            $run->report($path . '/' . $key, 'removed-from-template');
        }
        return $hashes;
    }

    /**
     * Own exactly what reached storage for the parts this run wrote.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $path Post resource key.
     * @param string                        $content Saved content.
     * @param array                         $hashes Hashes this run intended.
     * @param array                         $old_parts Hashes before this run.
     */
    public static function own_saved($run, $path, $content, $hashes, $old_parts) {
        $blocks = parse_blocks($content);
        foreach (self::indices($blocks) as $key => $list) {
            if (isset($hashes[$key]) && ($old_parts[$key] ?? null) !== $hashes[$key]) {
                $run->record['posts'][$path]['parts'][$key] = SEOProStack_Site_Template_Run::fingerprint(serialize_block($blocks[end($list)]));
            }
        }
    }

    /** @param array $blocks Parsed blocks. @return array Part key => block indices. */
    private static function indices($blocks) {
        $indices = array();
        foreach ($blocks as $index => $block) {
            $class = $block['attrs']['className'] ?? '';
            if ('core/group' === $block['blockName'] && 0 === strpos($class, self::PREFIX)) {
                $indices[substr($class, 18)][] = $index;
            }
        }
        return $indices;
    }

    /**
     * The manifest part as a wrapped block, or null when it cannot be placed.
     *
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $label Part resource key.
     * @param string                        $key Part key.
     * @param string                        $markup Manifest markup.
     * @return array|null
     */
    private static function block($run, $label, $key, $markup) {
        $markup = SEOProStack_Site_Template_Media::tokens($run, $markup);
        if (null === $markup) {
            $run->report($label, 'skipped: image unavailable');
            return null;
        }
        $form = false !== strpos($markup, 'seoprostack/form') || false !== strpos($markup, '[seoprostack_form');
        if ($form && !shortcode_exists('seoprostack_form')) {
            $run->report($label, 'skipped: Fluent Forms inactive');
            return null;
        }
        $class = self::PREFIX . $key;
        $wrapped = '<!-- wp:group {"className":"' . $class . '","layout":{"type":"default"}} -->' . "\n" . '<div class="wp-block-group ' . $class . '">' . $markup . '</div>' . "\n" . '<!-- /wp:group -->';
        return parse_blocks($wrapped)[0];
    }

    /**
     * @param SEOProStack_Site_Template_Run $run Run.
     * @param string                        $label Part resource key.
     * @param string                        $key Part key.
     * @param array                         $new Template block.
     * @param array                         $blocks Current blocks, updated in place.
     * @param array                         $found Indices of this part in $blocks.
     * @param array                         $hashes Owned hashes, updated in place.
     */
    private static function merge_part($run, $label, $key, $new, &$blocks, $found, &$hashes) {
        $hash = SEOProStack_Site_Template_Run::fingerprint(serialize_block($new));
        $owned = $hashes[$key] ?? null;
        if (null !== $owned && !$found) {
            $run->report($label, 'removed-on-site');
            return;
        }
        if (self::edited($blocks, $found, $owned)) {
            $run->report($label, 'edited-on-site');
            return;
        }
        $run->report($label, !$found ? 'added' : ($hash === $owned ? 'same' : 'updated'));
        if ($found) {
            $blocks[$found[0]] = $new;
        } else {
            $blocks[] = $new;
        }
        $hashes[$key] = $hash;
    }

    /** @param array $blocks Blocks. @param array $found Part indices. @param string|null $owned Owned hash. @return bool */
    private static function edited($blocks, $found, $owned) {
        if (count($found) > 1) {
            return true;
        }
        return $found && (null === $owned || SEOProStack_Site_Template_Run::fingerprint(serialize_block($blocks[$found[0]])) !== $owned);
    }
}
