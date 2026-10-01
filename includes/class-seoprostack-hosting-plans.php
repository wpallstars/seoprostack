<?php
/**
 * Hosting plans for Hosting needs: what to buy for low, medium and high
 * traffic, and for the traffic the site has now.
 *
 * The site's own numbers come in (memory per PHP worker, time per request
 * that reaches PHP, OPcache and database needs); traffic is the only
 * assumption, and every assumption is a constant here so it can be stated.
 *
 * PHP workers follow from Little's law: requests per second that reach PHP
 * in a burst, times the seconds each takes, is how many run at once. One
 * more is kept for cron and the admin, and each level has a minimum,
 * because slow outside calls and imports hold workers for longer.
 *
 * @package SEOProStack
 * @since 0.4.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Hosting_Plans {

    /** Visits a month for each traffic level. */
    const VISITS = array(
        'low'    => 10000,
        'medium' => 100000,
        'high'   => 1000000,
    );

    /** Pages each visit opens. */
    const PAGES = 2.5;

    /** Busiest hour against an average hour. */
    const PEAK_HOUR = 3;

    /** Bursts within the busiest hour (a post shared, a sale, a crawler). */
    const BURST = 3;

    /** Requests besides page views that reach PHP: REST API, AJAX, cron, bots on uncached addresses. */
    const BACKGROUND = 1.25;

    /** Share of page views a page cache cannot serve: logged-in visitors, carts, searches. */
    const UNCACHED = 0.15;

    /** The same for shops, membership and course sites, where more visitors are logged in. */
    const UNCACHED_DYNAMIC = 0.4;

    /** Seconds per request that reaches PHP, until measured. */
    const SECONDS = 0.5;

    /** The same for shops, membership and course sites. */
    const SECONDS_DYNAMIC = 1.0;

    /** Memory per PHP worker until measured, in MB. */
    const WORKER_MB = 128;

    /** Fewest PHP workers per level. */
    const MIN_WORKERS = array(
        'now'    => 2,
        'low'    => 2,
        'medium' => 4,
        'high'   => 8,
    );

    /** Fewest CPU cores per level. */
    const MIN_CPU = array(
        'now'    => 1,
        'low'    => 1,
        'medium' => 2,
        'high'   => 4,
    );

    /** Memory for the system, web server and database server itself, in MB. */
    const RESERVE_MB = array(
        'now'    => 512,
        'low'    => 512,
        'medium' => 1024,
        'high'   => 1536,
    );

    /** Memory for a persistent object cache (Redis or Memcached), in MB. */
    const OBJECT_CACHE_MB = array(
        'now'    => 0,
        'low'    => 0,
        'medium' => 128,
        'high'   => 256,
    );

    /** Extra memory kept free for bursts. */
    const HEADROOM = 1.25;

    /** Share of a CPU core a busy PHP worker uses (the rest is waiting for the database and disk). */
    const CPU_SHARE = 0.7;

    /**
     * Requests per second that reach PHP in a burst, for a traffic level.
     *
     * @param int  $visits     Visits a month.
     * @param bool $page_cache A page cache serves most page views.
     * @param bool $dynamic    Shop, membership or course site.
     * @return float
     */
    public static function burst_rps($visits, $page_cache, $dynamic) {
        $per_second = $visits * self::PAGES / (30 * DAY_IN_SECONDS);
        $uncached   = $page_cache ? ($dynamic ? self::UNCACHED_DYNAMIC : self::UNCACHED) : 1;
        return $per_second * self::PEAK_HOUR * self::BURST * $uncached * self::BACKGROUND;
    }

    /**
     * One plan.
     *
     * @param string $level Level: now, low, medium or high.
     * @param float  $rps   Requests per second that reach PHP in a burst.
     * @param array  $site  seconds (per request), worker (bytes), opcache (MB), db (bytes),
     *                      object_cache (bool: needed whatever the traffic).
     * @return array workers, ram (GB), cpu, object_cache (bool), type (text), busy (workers at once).
     */
    public static function plan($level, $rps, array $site) {
        $busy      = $rps * $site['seconds'];
        $workers   = max(self::MIN_WORKERS[$level], (int) ceil($busy) + 1); // One more for cron and the admin.
        $worker_mb = $site['worker'] ? $site['worker'] / MB_IN_BYTES : self::WORKER_MB;
        // The database keeps its tables and indexes in memory when it can.
        $db_mb     = min(4096, max(128, $site['db'] / MB_IN_BYTES * 1.2));
        $cache     = $site['object_cache'] || self::OBJECT_CACHE_MB[$level] > 0;
        $ram_mb    = ($workers * $worker_mb + $site['opcache'] + $db_mb + self::RESERVE_MB[$level]
            + ($cache ? max(128, self::OBJECT_CACHE_MB[$level]) : 0)) * self::HEADROOM;
        $cpu       = self::step(max(self::MIN_CPU[$level], $busy * self::CPU_SHARE + 0.5), array(1, 2, 4, 8, 16, 32, 64));
        // Plans come with at least 1 GB per CPU core.
        $ram       = self::step(max($ram_mb / 1024, $cpu), array(1, 2, 4, 8, 16, 32, 64, 128));

        if ($ram <= 2 && $workers <= 2) {
            $type = __('Shared or entry-level managed WordPress hosting', 'seoprostack');
        } elseif ($ram <= 8 && $workers <= 8) {
            $type = __('Managed WordPress hosting or a cloud server (VPS)', 'seoprostack');
        } else {
            $type = __('A larger cloud or dedicated server, with a CDN', 'seoprostack');
        }

        return array(
            'workers'      => $workers,
            'ram'          => $ram,
            'cpu'          => $cpu,
            'object_cache' => $cache,
            'type'         => $type,
            'busy'         => $busy,
        );
    }

    /**
     * Plans for low, medium and high traffic, and for now when traffic has
     * been measured.
     *
     * @param array      $site    See plan(), plus page_cache and dynamic (bool).
     * @param float|null $now_rps Measured requests per second in a burst, or null.
     * @return array level => plan, with visits for the traffic levels.
     */
    public static function plans(array $site, $now_rps) {
        $plans = array();
        if (null !== $now_rps) {
            $plans['now'] = self::plan('now', $now_rps, $site);
        }
        foreach (self::VISITS as $level => $visits) {
            $plans[$level]           = self::plan($level, self::burst_rps($visits, $site['page_cache'], $site['dynamic']), $site);
            $plans[$level]['visits'] = $visits;
        }
        return $plans;
    }

    /**
     * Names of the plans.
     *
     * @return array<string,string>
     */
    public static function labels() {
        return array(
            'now'    => __('Now', 'seoprostack'),
            'low'    => __('Low traffic', 'seoprostack'),
            'medium' => __('Medium traffic', 'seoprostack'),
            'high'   => __('High traffic', 'seoprostack'),
        );
    }

    /**
     * The assumptions, as a sentence.
     *
     * @param bool $page_cache A page cache was found.
     * @param bool $dynamic    Shop, membership or course site.
     * @param bool $measured   Seconds per request were measured.
     * @return string
     */
    public static function assumptions($page_cache, $dynamic, $measured) {
        if (!$page_cache) {
            $cached = __('every page view runs PHP, because no page cache was found', 'seoprostack');
        } else {
            $cached = sprintf(
                /* translators: %s: percentage. */
                __('a page cache serves all but %s of page views', 'seoprostack'),
                number_format_i18n(100 * ($dynamic ? self::UNCACHED_DYNAMIC : self::UNCACHED)) . '%'
            );
        }
        return sprintf(
            /* translators: 1: pages per visit, 2: busiest hour factor, 3: burst factor, 4: what a page cache serves, 5: where the time per request comes from. */
            __('Assumes %1$s pages a visit, a busiest hour %2$s times the average with bursts %3$s times that, and that %4$s. Time per request: %5$s. RAM includes OPcache, the database and the system, with a quarter extra for bursts.', 'seoprostack'),
            number_format_i18n(self::PAGES, 1),
            number_format_i18n(self::PEAK_HOUR),
            number_format_i18n(self::BURST),
            $cached,
            $measured ? __('measured from this site’s pages', 'seoprostack') : __('typical for this kind of site, until enough pages are timed', 'seoprostack')
        );
    }

    /**
     * Smallest step at or above a value.
     *
     * @param float $value Wanted value.
     * @param int[] $steps Steps, smallest first.
     * @return int
     */
    public static function step($value, array $steps) {
        foreach ($steps as $step) {
            if ($step >= $value) {
                return $step;
            }
        }
        return end($steps);
    }
}
