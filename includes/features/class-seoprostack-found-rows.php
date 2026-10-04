<?php
/**
 * Exact page counts without SQL_CALC_FOUND_ROWS.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Found_Rows extends SEOProStack_Feature {

    const KEY = 'found_rows';

    /** @var array<int,array{query:WeakReference<WP_Query>,request:string,count:string}> Pending counts. */
    private static $pending = array();

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
                'tab'         => 'server',
                'label'       => __('Faster page counts on long lists', 'seoprostack'),
                'description' => __('WordPress counts all matching posts in the same query as the page. This counts them separately, which databases can do faster on long lists. Page numbers stay the same.', 'seoprostack'),
            ),
        );
    }

    /** Register hooks only when requested. */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('posts_request', array(__CLASS__, 'rewrite'), PHP_INT_MAX, 2);
        add_filter('found_posts_query', array(__CLASS__, 'count_query'), PHP_INT_MAX, 2);
        add_filter('the_posts', array(__CLASS__, 'forget'), PHP_INT_MAX, 2);
    }

    /**
     * Remove the keyword only after constructing a safe count.
     *
     * @param string   $sql   Page SQL.
     * @param WP_Query $query Query being executed.
     * @return string
     */
    public static function rewrite($sql, $query) {
        self::prune();
        unset(self::$pending[spl_object_id($query)]);
        if ($query->get('no_found_rows') || $query->get('nopaging') || !preg_match('/^\s*SELECT\s+SQL_CALC_FOUND_ROWS\s+/i', $sql, $match)) {
            return $sql;
        }
        $request = 'SELECT ' . substr($sql, strlen($match[0]));
        $count = self::build_count($request);
        if ($count === '') {
            return $sql;
        }
        self::$pending[spl_object_id($query)] = array('query' => WeakReference::create($query), 'request' => $request, 'count' => $count);
        return $request;
    }

    /**
     * Supply core's count SQL, without changing its pagination calculation.
     *
     * @param string   $sql   Core's count SQL.
     * @param WP_Query $query Query being counted.
     * @return string
     */
    public static function count_query($sql, $query) {
        $id = spl_object_id($query);
        $entry = self::$pending[$id] ?? null;
        unset(self::$pending[$id]);
        if ($entry !== null && $entry['query']->get() === $query && $entry['request'] === $query->request) {
            return $entry['count'];
        }
        return $sql;
    }

    /**
     * Cached, empty and short-circuited results may never ask for a count.
     *
     * @param array    $posts Returned posts.
     * @param WP_Query $query Query being finished.
     * @return array
     */
    public static function forget($posts, $query) {
        unset(self::$pending[spl_object_id($query)]);
        self::prune();
        return $posts;
    }

    /**
     * Empty or cached fields=ids queries return before the_posts in core.
     * Weak references (PHP 7.4) do not retain them; reap finished entries on
     * subsequent queries without discarding an outer query still executing.
     */
    private static function prune() {
        foreach (self::$pending as $id => $entry) {
            $query = $entry['query']->get();
            if (!$query instanceof WP_Query || $query->posts === array() || $query->found_posts > 0) {
                unset(self::$pending[$id]);
            }
        }
    }

    /**
     * Mask literals and nested expressions, preserving byte offsets.
     * Comments, multiple statements and unbalanced SQL are deliberately skipped.
     *
     * @param string $sql SQL to inspect.
     * @return string Empty when unsafe to rewrite.
     */
    private static function top_level($sql) {
        $mask = $sql;
        $depth = 0;
        $quote = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];
            if ($quote !== '') {
                $mask[$i] = ' ';
                if ($char === '\\' && $quote !== '`') {
                    ++$i;
                    if ($i >= $length) {
                        return '';
                    }
                    $mask[$i] = ' ';
                } elseif ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $mask[++$i] = ' ';
                    } else {
                        $quote = '';
                    }
                }
                continue;
            }
            if ($char === ';' || $char === '#' || substr($sql, $i, 2) === '--' || substr($sql, $i, 2) === '/*') {
                return '';
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $mask[$i] = ' ';
            } elseif ($char === '(') {
                ++$depth;
                $mask[$i] = ' ';
            } elseif ($char === ')') {
                --$depth;
                if ($depth < 0) {
                    return '';
                }
                $mask[$i] = ' ';
            } elseif ($depth > 0) {
                $mask[$i] = ' ';
            }
        }
        return $depth === 0 && $quote === '' ? $mask : '';
    }

    /**
     * Count rows (including DISTINCT rows and groups), not joined post IDs.
     * Only core's unique-column projections are accepted: arbitrary plugin
     * fields can produce duplicate column names in a derived table.
     *
     * @param string $sql Page SQL without the keyword.
     * @return string Count SQL, or empty to keep core's original query.
     */
    private static function build_count($sql) {
        global $wpdb;
        $mask = self::top_level($sql);
        if ($mask === '' || preg_match('/\b(?:UNION|INTERSECT|EXCEPT|INTO|PROCEDURE|FOR|LOCK)\b/i', $mask)) {
            return '';
        }
        if (!preg_match('/\bLIMIT\s+\d+\s*(?:(?:,\s*\d+)|(?:OFFSET\s+\d+))?\s*$/i', $mask, $limit, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $end = $limit[0][1];
        $mask = substr($mask, 0, $end);
        if (preg_match('/\bORDER\s+BY\b/i', $mask, $order, PREG_OFFSET_CAPTURE)) {
            $suffix = substr($mask, $order[0][1] + strlen($order[0][0]));
            if (preg_match('/\b(?:GROUP|HAVING|LIMIT|ORDER|WHERE)\b/i', $suffix)) {
                return '';
            }
            $end = $order[0][1];
        }
        $inner = rtrim(substr($sql, 0, $end));
        $table = preg_quote($wpdb->posts, '/');
        if (!preg_match('/^SELECT\s+(DISTINCT\s+)?(' . $table . '\.(?:\*|ID))\s+FROM\s+/i', $inner, $fields)) {
            return '';
        }
        if (empty($fields[1]) && !preg_match('/\b(?:GROUP\s+BY|HAVING)\b/i', $mask)) {
            $inner = 'SELECT ' . $wpdb->posts . '.ID FROM ' . substr($inner, strlen($fields[0]));
        }
        return 'SELECT COUNT(*) FROM ( ' . $inner . ' ) AS sps_found';
    }
}
