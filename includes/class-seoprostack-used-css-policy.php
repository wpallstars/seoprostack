<?php
/**
 * Used CSS: whether the page's content security policy lets the rewrite
 * work. It needs an inline `<style>` and an inline onload attribute; where a
 * policy blocks either, the page is left as it is, as a blocked `<style>`
 * would show the page unstyled and a blocked onload would never apply the
 * full stylesheet.
 *
 * Reads every enforced policy PHP sends (any plugin's, SEO Pro Stack's
 * Security headers too) and the page's `<meta http-equiv>` policies. One
 * the web server adds itself (an nginx or .htaccess rule) cannot be seen.
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

final class SEOProStack_Used_Css_Policy {

    /**
     * What the rewrite adds, each with the directives that decide it, in the
     * order browsers look for them.
     */
    const CHAINS = array(
        'style'  => array('style-src-elem', 'style-src', 'default-src'),
        'onload' => array('script-src-attr', 'script-src', 'default-src'),
        'script' => array('script-src-elem', 'script-src', 'default-src'),
    );

    /**
     * Whether every enforced policy allows an inline `<style>` and onload (and
     * with $script, an inline `<script>` element).
     *
     * @param string $html   Page.
     * @param bool   $script Also check inline scripts.
     * @return bool
     */
    public static function allows($html, $script = false) {
        foreach (self::policies($html) as $policy) {
            $directives = self::directives($policy);
            foreach (self::CHAINS as $name => $chain) {
                if ('script' === $name && !$script) {
                    continue;
                }
                if (!self::inline_allowed($directives, $chain)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * The enforced policies: headers sent from PHP, then `<meta>` tags in the
     * head. Report-only policies block nothing.
     *
     * @param string $html Page.
     * @return string[]
     */
    private static function policies($html) {
        $policies = array();
        foreach (headers_list() as $header) {
            $parts = explode(':', $header, 2);
            if (isset($parts[1]) && 'content-security-policy' === strtolower(trim($parts[0]))) {
                // One header can hold several policies, separated by commas.
                $policies = array_merge($policies, explode(',', $parts[1]));
            }
        }
        $end  = stripos($html, '</head>');
        $head = false === $end ? '' : substr($html, 0, $end);
        if (false === stripos($head, 'content-security-policy') || !preg_match_all('/<meta\b[^>]*>/i', $head, $metas)) {
            return $policies;
        }
        foreach ($metas[0] as $meta) {
            $attrs = SEOProStack_Used_Css_Links::attributes($meta);
            if (isset($attrs['http-equiv'], $attrs['content']) && 'content-security-policy' === strtolower(trim($attrs['http-equiv']))) {
                $policies[] = $attrs['content'];
            }
        }
        return $policies;
    }

    /**
     * A policy's directives: lower-case name => sources (the first of a
     * repeated name applies).
     *
     * @param string $policy Policy.
     * @return array<string,string>
     */
    private static function directives($policy) {
        $directives = array();
        foreach (explode(';', strtolower($policy)) as $directive) {
            $parts = preg_split('/\s+/', trim($directive));
            if ($parts && '' !== $parts[0] && !isset($directives[$parts[0]])) {
                // Spaced at both ends, so each source can be found as " 'name' ".
                $directives[$parts[0]] = ' ' . implode(' ', array_slice($parts, 1)) . ' ';
            }
        }
        return $directives;
    }

    /**
     * Whether the first directive of a chain that the policy has allows
     * inline code. 'unsafe-inline' is ignored next to a nonce, a hash or
     * 'strict-dynamic', and the rewrite's code has none of their approval.
     *
     * @param array<string,string> $directives Directives.
     * @param string[]             $chain      Directive names, in order.
     * @return bool True when none of them is set.
     */
    private static function inline_allowed(array $directives, array $chain) {
        foreach ($chain as $name) {
            if (isset($directives[$name])) {
                $sources = $directives[$name];
                return false !== strpos($sources, " 'unsafe-inline' ") && !preg_match("/ '(?:nonce-|sha256-|sha384-|sha512-|strict-dynamic)/", $sources);
            }
        }
        return true;
    }
}
