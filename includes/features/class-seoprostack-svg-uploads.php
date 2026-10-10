<?php
/**
 * SVG uploads.
 *
 * Lets chosen roles upload SVG files. Every SVG is cleaned before it is
 * stored: only known SVG elements and attributes are kept, and scripts,
 * event handlers, links to other files, style sheets that load other files
 * and HTML inside the SVG are removed. Files that cannot be read as SVG are
 * refused.
 *
 * Files are cleaned when uploaded (wp_handle_upload_prefilter and
 * wp_handle_sideload_prefilter, so the Media Library, the editors, REST and
 * sideloads are covered) and again when a new SVG attachment's metadata is
 * created, which also covers paths that write the file directly, such as
 * XML-RPC and importers.
 *
 * SVGs get a width and height in their metadata, and every image size points
 * at the same file, so they show and insert like other images.
 *
 * Replaces "Safe SVG". Its upload roles are imported; the Safe SVG block is
 * not replaced.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Svg_Uploads extends SEOProStack_Feature {

    const KEY = 'svg_uploads';

    const MIME = 'image/svg+xml';

    const NS_SVG = 'http://www.w3.org/2000/svg';

    const NS_XLINK = 'http://www.w3.org/1999/xlink';

    const NS_XML = 'http://www.w3.org/XML/1998/namespace';

    /** Largest SVG accepted, in bytes. */
    const MAX_BYTES = 10485760;

    /**
     * Most elements a file may draw once <use> references are expanded.
     * Detailed drawings have tens of thousands; nested <use> "bombs" reach
     * billions.
     */
    const MAX_EXPANDED = 250000;

    /**
     * MD5 of files cleaned in this request, so metadata creation does not
     * clean them twice.
     *
     * @var array<string,bool>
     */
    private static $cleaned = array();

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'media',
                'label'       => __('SVG uploads', 'seoprostack'),
                'description' => __('Let chosen roles upload SVG files. Each file is cleaned as it is uploaded: scripts, links to other files and anything that is not a drawing are removed. Files that cannot be cleaned are refused.', 'seoprostack'),
                'replaces'    => array('safe-svg' => 'Safe SVG'),
            ),
            'svg_uploads_roles' => array(
                'type'        => 'multi',
                'default'     => array('administrator', 'editor', 'author', 'contributor', 'shop_manager'),
                'parent'      => self::KEY,
                'label'       => __('Who can upload SVG files', 'seoprostack'),
                'description' => __('People also need permission to upload files. Network admins always can.', 'seoprostack'),
                'options'     => array(__CLASS__, 'role_options'),
            ),
        );
    }

    /**
     * Import Safe SVG's upload roles, and switch on while it is active.
     * Without roles, Safe SVG lets everyone who can upload files upload SVGs,
     * so those roles are imported.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $active = isset(self::active_plugins()['safe-svg']);
        if ($active) {
            $options = self::import_setting($options, self::KEY, true);
        }

        $roles = get_option('safe_svg_upload_roles');
        if (is_array($roles) && $roles) {
            return self::import_setting($options, 'svg_uploads_roles', array_values(array_map('strval', $roles)));
        }
        if ($active) {
            $uploaders = array();
            foreach (wp_roles()->roles as $role => $data) {
                if (!empty($data['capabilities']['upload_files'])) {
                    $uploaders[] = $role;
                }
            }
            $options = self::import_setting($options, 'svg_uploads_roles', $uploaders);
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('upload_mimes', array(__CLASS__, 'upload_mimes'), 10, 2);
        add_filter('wp_check_filetype_and_ext', array(__CLASS__, 'check_filetype'), 10, 4);
        add_filter('wp_handle_upload_prefilter', array(__CLASS__, 'prefilter'));
        add_filter('wp_handle_sideload_prefilter', array(__CLASS__, 'prefilter'));
        add_filter('wp_generate_attachment_metadata', array(__CLASS__, 'metadata'), 10, 3);
        add_filter('wp_calculate_image_srcset_meta', array(__CLASS__, 'no_srcset'), 10, 4);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_style'));
    }

    /* --------------------------------------------------------------------- */
    /* Who can upload                                                         */
    /* --------------------------------------------------------------------- */

    /**
     * Whether a user may upload SVG files.
     *
     * @param int|WP_User|null $user User; null for the current user.
     * @return bool
     */
    public static function user_can_upload($user = null) {
        if (null === $user) {
            $user = wp_get_current_user();
        } elseif (!$user instanceof WP_User) {
            $user = get_userdata((int) $user);
        }
        if (!$user || !$user->exists() || !user_can($user, 'upload_files')) {
            return false;
        }
        if (is_multisite() && is_super_admin($user->ID)) {
            return true;
        }
        return (bool) array_intersect((array) $user->roles, (array) SEOProStack_Settings::get('svg_uploads_roles'));
    }

    /**
     * Allow .svg for people who may upload SVGs. The uploaders read this list
     * when the page loads, so it has to include SVG for them everywhere.
     *
     * @param array            $mimes Extension => MIME type.
     * @param int|WP_User|null $user  User the list is for.
     * @return array
     */
    public static function upload_mimes($mimes, $user = null) {
        if (self::user_can_upload($user)) {
            $mimes['svg'] = self::MIME;
        }
        return $mimes;
    }

    /**
     * fileinfo reports SVGs without an XML declaration as text; accept an
     * allowed .svg file whose content is an SVG.
     *
     * @param array  $data     ext, type and proper_filename.
     * @param string $file     Full path to the file.
     * @param string $filename File name.
     * @param array  $mimes    Allowed MIME types.
     * @return array
     */
    public static function check_filetype($data, $file, $filename, $mimes) {
        if (!empty($data['type']) || 'svg' !== strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION))) {
            return $data;
        }
        $allowed = is_array($mimes) ? $mimes : get_allowed_mime_types();
        if (!isset($allowed['svg']) || !is_readable($file)) {
            return $data;
        }
        $head = (string) file_get_contents($file, false, null, 0, 4096); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        if (preg_match('/<svg[\s>]/i', $head)) {
            $data['ext']  = 'svg';
            $data['type'] = self::MIME;
        }
        return $data;
    }

    /* --------------------------------------------------------------------- */
    /* Cleaning                                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Clean uploaded SVGs before WordPress stores them.
     *
     * @param array $file Upload: name, tmp_name, error…
     * @return array
     */
    public static function prefilter($file) {
        if (!is_array($file) || !empty($file['error']) || empty($file['tmp_name']) || empty($file['name'])) {
            return $file;
        }
        if ('svg' !== strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION))) {
            return $file;
        }
        if (!self::user_can_upload()) {
            $file['error'] = __('Sorry, you are not allowed to upload SVG files.', 'seoprostack');
            return $file;
        }
        $result = self::clean_file($file['tmp_name']);
        if (is_wp_error($result)) {
            /* translators: %s: reason */
            $file['error'] = sprintf(__('This SVG file was not uploaded: %s', 'seoprostack'), $result->get_error_message());
        }
        return $file;
    }

    /**
     * Clean a file in place.
     *
     * @param string $path File path.
     * @return true|WP_Error
     */
    public static function clean_file($path) {
        if (!is_readable($path) || !wp_is_writable($path)) {
            return new WP_Error('svg_unreadable', __('the file could not be read.', 'seoprostack'));
        }
        if (filesize($path) > self::MAX_BYTES) {
            return new WP_Error('svg_too_large', __('it is larger than 10 MB.', 'seoprostack'));
        }
        $dirty = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        if (false === $dirty) {
            return new WP_Error('svg_unreadable', __('the file could not be read.', 'seoprostack'));
        }
        $clean = self::clean($dirty);
        if (is_wp_error($clean)) {
            return $clean;
        }
        if (false === file_put_contents($path, $clean)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary or uploaded file.
            return new WP_Error('svg_unwritable', __('the cleaned file could not be saved.', 'seoprostack'));
        }
        self::$cleaned[md5($clean)] = true; // NOSONAR: a cache or lock key, not security.
        return true;
    }

    /**
     * Clean SVG markup.
     *
     * @param string $dirty SVG markup.
     * @return string|WP_Error Clean markup.
     */
    public static function clean($dirty) {
        $invalid = new WP_Error('svg_invalid', __('it is not a valid SVG file.', 'seoprostack'));
        // NUL bytes mean UTF-16 or UTF-32, where the checks below cannot see.
        if (!is_string($dirty) || '' === trim($dirty) || strlen($dirty) > self::MAX_BYTES || false !== strpos($dirty, "\0")) {
            return $invalid;
        }
        // Other encodings (UTF-7 and the like) could hide what is checked next.
        if (preg_match('/<\?xml[^>]*encoding\s*=\s*["\']([^"\']*)/i', $dirty, $m) && !in_array(strtolower($m[1]), array('utf-8', 'us-ascii', 'ascii', 'iso-8859-1', 'windows-1252'), true)) {
            return $invalid;
        }
        // Entity definitions can expand without limit; SVGs never need them.
        if (false !== stripos($dirty, '<!ENTITY')) {
            return $invalid;
        }
        $dirty = preg_replace('/<!DOCTYPE[^>\[]*>/i', '', $dirty);
        if (!is_string($dirty) || false !== stripos($dirty, '<!DOCTYPE')) {
            return $invalid;
        }

        $dom      = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadXML($dirty, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $dom->documentElement : null;
        if (!$root || 'svg' !== self::local_name($root) || !in_array((string) $root->namespaceURI, array('', self::NS_SVG), true)) {
            return $invalid;
        }

        self::clean_children($dom, (string) $root->namespaceURI);
        self::clean_element($root, (string) $root->namespaceURI);

        if (!self::use_is_bounded($dom)) {
            return new WP_Error('svg_use', __('it repeats shapes too many times to be shown safely.', 'seoprostack'));
        }

        $xml = $dom->saveXML($root);
        if (!is_string($xml) || '' === $xml) {
            return $invalid;
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml . "\n";
    }

    /**
     * A node's name without its prefix, in lower case. Parsed XML always has
     * a local name; the node name is the fallback DOM allows for.
     *
     * @param DOMNode $node Element or attribute.
     * @return string
     */
    private static function local_name(DOMNode $node) {
        return strtolower($node->localName ?? $node->nodeName);
    }

    /**
     * Clean a node's children: keep allowed elements (cleaned) and text;
     * comments, processing instructions and entity references are removed,
     * and CDATA becomes text.
     *
     * @param DOMNode $node Parent.
     * @param string  $ns   Namespace of the root element.
     */
    private static function clean_children(DOMNode $node, $ns) {
        $doc = $node instanceof DOMDocument ? $node : $node->ownerDocument;
        if (!$doc instanceof DOMDocument) {
            return;
        }
        $children = iterator_to_array($node->childNodes, false);
        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                if ($child === $doc->documentElement) {
                    continue;
                }
                if (!self::keep_element($child, $ns)) {
                    $node->removeChild($child);
                    continue;
                }
                self::clean_element($child, $ns);
            } elseif ($child instanceof DOMCdataSection) {
                $node->replaceChild($doc->createTextNode($child->data), $child);
            } elseif (!$child instanceof DOMText) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * Whether an element may stay.
     *
     * @param DOMElement $el Element.
     * @param string     $ns Namespace of the root element.
     * @return bool
     */
    private static function keep_element(DOMElement $el, $ns) {
        $name = self::local_name($el);
        if ((string) $el->namespaceURI !== $ns || !isset(self::elements()[$name])) {
            return false;
        }
        if ('style' === $name) {
            // "<" in style text would end an inline <style> in HTML pages.
            return false === strpos($el->textContent, '<') && self::css_is_safe($el->textContent);
        }
        if ('use' === $name) {
            $href = self::href($el);
            return '' !== $href && '#' === $href[0];
        }
        return true;
    }

    /**
     * Clean an element's attributes, then its children.
     *
     * @param DOMElement $el Element.
     * @param string     $ns Namespace of the root element.
     */
    private static function clean_element(DOMElement $el, $ns) {
        $tag   = self::local_name($el);
        $attrs = iterator_to_array($el->attributes, false);
        foreach ($attrs as $attr) {
            if (!self::keep_attribute($attr, $tag)) {
                $el->removeAttributeNode($attr);
            }
        }
        self::clean_children($el, $ns);
    }

    /**
     * Whether an attribute may stay.
     *
     * @param DOMAttr $attr Attribute.
     * @param string  $tag  Element name, lower case.
     * @return bool
     */
    private static function keep_attribute(DOMAttr $attr, $tag) {
        $uri = (string) $attr->namespaceURI;
        if (self::NS_XLINK === $uri) {
            $name = 'xlink:' . self::local_name($attr);
        } elseif (self::NS_XML === $uri) {
            $name = 'xml:' . self::local_name($attr);
        } elseif ('' === $uri && false === strpos($attr->nodeName, ':')) {
            $name = self::local_name($attr);
        } else {
            return false;
        }

        // Not data-*: drawings do not need them, and scripts on a page that
        // shows the SVG inline may act on them.
        if (!isset(self::attributes()[$name]) && 0 !== strpos($name, 'aria-')) {
            return false;
        }

        $value   = (string) $attr->value;
        $compact = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $value));

        if ('href' === $name || 'xlink:href' === $name) {
            return self::href_is_safe($compact, $tag);
        }
        if (preg_match('/(javascript|vbscript|livescript):|data:text/', $compact)) {
            return false;
        }
        if ('style' === $name || false !== strpos($compact, 'url(')) {
            return self::css_is_safe($value);
        }
        return true;
    }

    /**
     * Whether a link target is safe: same-document references anywhere, web
     * and mail links on <a>, and embedded pictures on <image> (pictures from
     * other sites would let them see who views the drawing).
     *
     * @param string $href Target, lower case, without whitespace.
     * @param string $tag  Element name, lower case.
     * @return bool
     */
    private static function href_is_safe($href, $tag) {
        if ('' === $href || '#' === $href[0]) {
            return true;
        }
        if ('a' === $tag) {
            return (bool) preg_match('#^(https?://|mailto:)#', $href);
        }
        if ('image' === $tag) {
            return (bool) preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $href);
        }
        return false;
    }

    /**
     * Whether CSS is safe: no escapes (which can hide anything), no imports
     * or scripting, and url() only for same-document references and embedded
     * pictures.
     *
     * @param string $css CSS.
     * @return bool
     */
    private static function css_is_safe($css) {
        $css = strtolower((string) preg_replace('#/\*.*?\*/#s', '', (string) $css));
        if (false !== strpos($css, '\\') || false !== strpos($css, '/*')) {
            return false;
        }
        $compact = (string) preg_replace('/[\x00-\x20\x7f]+/', '', $css);
        if (preg_match('/@import|expression\(|behavior:|-moz-binding|(javascript|vbscript):|data:text/', $compact)) {
            return false;
        }
        if (preg_match_all('/url\(([^)]*)\)/', $compact, $matches)) {
            foreach ($matches[1] as $target) {
                $target = trim($target, '\'"');
                if ('' !== $target && '#' !== $target[0] && !preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $target)) {
                    return false;
                }
            }
        }
        // url( without a closing bracket, or an image-set() or src() that loads files.
        return substr_count($compact, 'url(') === count(isset($matches[1]) ? $matches[1] : array())
            && !preg_match('/(image-set|src)\(/', $compact);
    }

    /**
     * A <use> element's target.
     *
     * @param DOMElement $el Element.
     * @return string
     */
    private static function href(DOMElement $el) {
        $href = $el->getAttribute('href');
        if ('' === $href) {
            $href = $el->getAttributeNS(self::NS_XLINK, 'href');
        }
        return trim((string) $href);
    }

    /**
     * Whether <use> references stay within limits once expanded: no loops,
     * and not so many copies that a browser would hang drawing them.
     *
     * @param DOMDocument $dom Document.
     * @return bool
     */
    private static function use_is_bounded(DOMDocument $dom) {
        $ids   = array();
        $nodes = (new DOMXPath($dom))->query('//*[@id]');
        if (false === $nodes) {
            return false;
        }
        foreach ($nodes as $el) {
            if ($el instanceof DOMElement) {
                $ids[$el->getAttribute('id')] = $el;
            }
        }
        if (!$dom->documentElement) {
            return false;
        }
        $memo = array();
        $cost = self::expanded_count($dom->documentElement, $ids, $memo, array());
        return null !== $cost && $cost <= self::MAX_EXPANDED;
    }

    /**
     * Elements drawn for a subtree with <use> references expanded.
     *
     * @param DOMElement            $el   Element.
     * @param array<string,DOMElement> $ids Elements by id.
     * @param array<int,int|null>   $memo Counts by element.
     * @param array<int,bool>       $path Elements being expanded (loop check).
     * @return int|null Null for a loop or too many.
     */
    private static function expanded_count(DOMElement $el, array $ids, array &$memo, array $path) {
        $key = spl_object_id($el);
        if (isset($path[$key])) {
            return null;
        }
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }
        $path[$key] = true;
        $count      = 1;
        if ('use' === self::local_name($el)) {
            $target = substr(self::href($el), 1);
            if (isset($ids[$target])) {
                $sub = self::expanded_count($ids[$target], $ids, $memo, $path);
                if (null === $sub) {
                    return $memo[$key] = null;
                }
                $count += $sub;
            }
        }
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $sub = self::expanded_count($child, $ids, $memo, $path);
                if (null === $sub) {
                    return $memo[$key] = null;
                }
                $count += $sub;
            }
            if ($count > self::MAX_EXPANDED) {
                return $memo[$key] = null;
            }
        }
        return $memo[$key] = $count;
    }

    /**
     * Allowed elements (lower case).
     *
     * @return array<string,bool>
     */
    private static function elements() {
        static $list = null;
        if (null === $list) {
            $list = array_fill_keys(array(
                'svg', 'g', 'defs', 'desc', 'title', 'metadata', 'symbol', 'use', 'switch', 'view', 'a', 'image', 'style',
                'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
                'text', 'tspan', 'textpath',
                'marker', 'mask', 'pattern', 'clippath', 'lineargradient', 'radialgradient', 'stop',
                'filter', 'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite', 'feconvolvematrix',
                'fediffuselighting', 'fedisplacementmap', 'fedistantlight', 'fedropshadow', 'feflood', 'fefunca',
                'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur', 'femerge', 'femergenode', 'femorphology',
                'feoffset', 'fepointlight', 'fespecularlighting', 'fespotlight', 'fetile', 'feturbulence',
                'animatemotion', 'animatetransform', 'mpath',
            ), true);
        }
        return $list;
    }

    /**
     * Allowed attributes (lower case), besides aria-* and data-*.
     *
     * @return array<string,bool>
     */
    private static function attributes() {
        static $list = null;
        if (null === $list) {
            $list = array_fill_keys(array(
                // Core.
                'id', 'class', 'style', 'lang', 'tabindex', 'role', 'xml:space', 'xml:lang', 'href', 'xlink:href', 'xlink:title',
                'version', 'baseprofile', 'viewbox', 'preserveaspectratio', 'zoomandpan', 'transform', 'target',
                'requiredfeatures', 'requiredextensions', 'systemlanguage',
                // Geometry.
                'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr', 'width', 'height',
                'd', 'points', 'pathlength',
                // Presentation.
                'alignment-baseline', 'baseline-shift', 'clip', 'clip-path', 'clip-rule', 'color', 'color-interpolation',
                'color-interpolation-filters', 'color-rendering', 'direction', 'display', 'dominant-baseline',
                'enable-background', 'fill', 'fill-opacity', 'fill-rule', 'filter', 'flood-color', 'flood-opacity',
                'font-family', 'font-size', 'font-size-adjust', 'font-stretch', 'font-style', 'font-variant',
                'font-weight', 'image-rendering', 'isolation', 'kerning', 'letter-spacing', 'lighting-color',
                'marker-end', 'marker-mid', 'marker-start', 'mask', 'mask-type', 'mix-blend-mode', 'opacity',
                'overflow', 'paint-order', 'pointer-events', 'shape-rendering', 'stop-color', 'stop-opacity',
                'stroke', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-linecap', 'stroke-linejoin',
                'stroke-miterlimit', 'stroke-opacity', 'stroke-width', 'text-anchor', 'text-decoration',
                'text-rendering', 'transform-origin', 'unicode-bidi', 'vector-effect', 'visibility',
                'word-spacing', 'writing-mode',
                // Gradients, patterns, markers, masks and clipping.
                'gradientunits', 'gradienttransform', 'spreadmethod', 'offset', 'patternunits', 'patterncontentunits',
                'patterntransform', 'markerunits', 'markerwidth', 'markerheight', 'refx', 'refy', 'orient',
                'maskunits', 'maskcontentunits', 'clippathunits',
                // Text.
                'dx', 'dy', 'rotate', 'textlength', 'lengthadjust', 'startoffset', 'method', 'spacing', 'side',
                // Filters.
                'filterunits', 'primitiveunits', 'in', 'in2', 'result', 'mode', 'type', 'values', 'tablevalues',
                'slope', 'intercept', 'amplitude', 'exponent', 'k1', 'k2', 'k3', 'k4', 'operator', 'order',
                'kernelmatrix', 'divisor', 'bias', 'targetx', 'targety', 'edgemode', 'kernelunitlength',
                'preservealpha', 'surfacescale', 'diffuseconstant', 'specularconstant', 'specularexponent',
                'azimuth', 'elevation', 'pointsatx', 'pointsaty', 'pointsatz', 'limitingconeangle', 'z', 'scale',
                'xchannelselector', 'ychannelselector', 'stddeviation', 'basefrequency', 'numoctaves', 'seed',
                'stitchtiles', 'radius',
                // Movement (animateTransform and animateMotion only).
                'attributename', 'attributetype', 'begin', 'dur', 'end', 'min', 'max', 'restart', 'repeatcount',
                'repeatdur', 'calcmode', 'keytimes', 'keysplines', 'keypoints', 'from', 'to', 'by', 'additive',
                'accumulate', 'path',
            ), true);
        }
        return $list;
    }

    /* --------------------------------------------------------------------- */
    /* Showing SVGs as images                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Clean new SVG attachments (covers paths without an upload prefilter)
     * and give SVGs a size, with every image size pointing at the file.
     *
     * @param array  $metadata      Metadata.
     * @param int    $attachment_id Attachment ID.
     * @param string $context       create or update.
     * @return array
     */
    public static function metadata($metadata, $attachment_id, $context = 'create') {
        if (self::MIME !== get_post_mime_type($attachment_id)) {
            return $metadata;
        }
        $file = get_attached_file($attachment_id);
        if (!$file || !is_file($file)) {
            return $metadata;
        }
        $metadata = is_array($metadata) ? $metadata : array();

        if ('update' !== $context && !isset(self::$cleaned[(string) md5_file($file)])) {
            if (is_wp_error(self::clean_file($file))) {
                // Could not be cleaned: keep an empty drawing rather than the file.
                file_put_contents($file, '<svg xmlns="http://www.w3.org/2000/svg"/>'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- uploaded file.
            }
        }

        $size = self::dimensions($file);
        if (!$size) {
            return $metadata;
        }
        list($width, $height) = $size;

        $name     = wp_basename($file);
        $metadata = array_merge($metadata, array(
            'width'    => $width,
            'height'   => $height,
            'file'     => _wp_relative_upload_path($file),
            'filesize' => (int) filesize($file),
            'sizes'    => array(),
        ));
        foreach (wp_get_registered_image_subsizes() as $size_name => $box) {
            list($w, $h) = wp_constrain_dimensions($width, $height, (int) $box['width'], (int) $box['height']);
            $metadata['sizes'][$size_name] = array(
                'file'      => $name,
                'width'     => (int) $w,
                'height'    => (int) $h,
                'mime-type' => self::MIME,
            );
        }
        return $metadata;
    }

    /**
     * Width and height of an SVG: its width and height when both are
     * absolute, otherwise its viewBox.
     *
     * @param string $file File path.
     * @return int[]|null
     */
    public static function dimensions($file) {
        $dom      = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadXML((string) file_get_contents($file), LIBXML_NONET); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || !$dom->documentElement) {
            return null;
        }
        $root   = $dom->documentElement;
        $width  = self::length($root->getAttribute('width'));
        $height = self::length($root->getAttribute('height'));
        if (!$width || !$height) {
            $box = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
            if (!is_array($box) || 4 !== count($box) || (float) $box[2] <= 0 || (float) $box[3] <= 0) {
                return null;
            }
            $width  = (float) $box[2];
            $height = (float) $box[3];
        }
        return array(max(1, (int) round($width)), max(1, (int) round($height)));
    }

    /**
     * An absolute length in pixels, or 0.
     *
     * @param string $value Attribute value.
     * @return float
     */
    private static function length($value) {
        if (!preg_match('/^\s*([0-9]*\.?[0-9]+)\s*(px|pt|pc|mm|cm|in)?\s*$/i', (string) $value, $m)) {
            return 0.0;
        }
        $units = array('' => 1, 'px' => 1, 'pt' => 4 / 3, 'pc' => 16, 'mm' => 96 / 25.4, 'cm' => 96 / 2.54, 'in' => 96);
        $unit  = isset($m[2]) ? strtolower($m[2]) : '';
        return (float) $m[1] * $units[$unit];
    }

    /**
     * Every size is the same file, so srcset would only repeat it.
     *
     * @param array  $image_meta    Metadata.
     * @param int[]  $size_array    Width and height.
     * @param string $image_src     Image URL.
     * @param int    $attachment_id Attachment ID.
     * @return array
     */
    public static function no_srcset($image_meta, $size_array, $image_src, $attachment_id) {
        if ($attachment_id && self::MIME === get_post_mime_type($attachment_id) && is_array($image_meta)) {
            $image_meta['sizes'] = array();
        }
        return $image_meta;
    }

    /**
     * SVGs without a size of their own show at 0 × 0 in admin lists and the
     * featured image box; let them fill their box.
     */
    public static function admin_style() {
        wp_register_style('seoprostack-svg-uploads', false, array(), SEOPROSTACK_VERSION);
        wp_enqueue_style('seoprostack-svg-uploads');
        wp_add_inline_style('seoprostack-svg-uploads', 'table.media .column-title .media-icon img[src$=".svg"],#postimagediv .inside img[src$=".svg"]{width:100%;height:auto}');
    }
}
