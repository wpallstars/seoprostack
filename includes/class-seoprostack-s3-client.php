<?php
/**
 * Small path-style S3 client. Credentials never leave the configured endpoint.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_S3_Client {
    /** Connection, held only in memory. @var array<string,string> */
    private $config;

    /** Curl multi handle kept for this client's groups, so connections are reused (an object on PHP 8). @var resource|null */
    private $multi = null;

    /**
     * Set the trusted connection.
     *
     * @param string $endpoint API address.
     * @param string $region Signing region.
     * @param string $bucket Bucket name.
     * @param string $key_id Access key.
     * @param string $secret Signing secret.
     */
    public function __construct($endpoint, $region, $bucket, $key_id, $secret) {
        $this->config = compact('endpoint', 'region', 'bucket', 'key_id', 'secret');
    }

    /**
     * Copy a bounded local file.
     *
     * @param string $key Object key.
     * @param string $path Local file.
     * @param string $content_type MIME type.
     * @param string $cache_control Cache policy.
     * @param string $digest Expected content digest, when copying an attachment.
     * @return true|WP_Error
     */
    public function put_file($key, $path, $content_type, $cache_control, $digest = '') {
        $body = $this->read_file($path);
        if (false === $body) {
            return new WP_Error('storage_read', __('The local picture could not be read.', 'seoprostack'));
        }
        if ($digest && !hash_equals($digest, hash('sha256', $body))) {
            return new WP_Error('storage_changed', __('The local picture changed while being copied. It will be retried.', 'seoprostack'));
        }
        return $this->put($key, $body, $content_type, $cache_control);
    }

    /**
     * Read locally through core, which handles a concurrent deletion quietly.
     *
     * @param string $path Local file.
     * @return string|false Bytes, or a failed read.
     */
    private function read_file($path) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        return (new WP_Filesystem_Direct(null))->get_contents($path);
    }

    /**
     * Put bytes (also used for the connection probe).
     *
     * @param string $key Object key.
     * @param string $body Bytes.
     * @param string $content_type MIME type.
     * @param string $cache_control Cache policy.
     * @return true|WP_Error
     */
    public function put($key, $body, $content_type, $cache_control) {
        $response = $this->request('PUT', $key, $body, array('content-type' => $content_type, 'cache-control' => $cache_control));
        return is_wp_error($response) ? $response : true;
    }

    /**
     * Partition file metadata without loading bodies. An oversized file goes alone.
     *
     * @param array $files Object keys mapped to file information with size.
     * @param int $concurrency Maximum requests, bounded to 1–128.
     * @param int $byte_cap Maximum payload bytes per group.
     * @return Generator Groups preserving object keys and order.
     */
    public static function file_groups($files, $concurrency, $byte_cap) {
        $concurrency = max(1, min(128, (int) $concurrency));
        $byte_cap = max(1, (int) $byte_cap);
        $group = array();
        $bytes = 0;
        foreach ($files as $key => $info) {
            $size = max(0, (int) $info['size']);
            if ($group && (count($group) >= $concurrency || $bytes + $size > $byte_cap)) {
                yield $group;
                $group = array();
                $bytes = 0;
            }
            $group[$key] = $info;
            $bytes += $size;
        }
        if ($group) {
            yield $group;
        }
    }

    /**
     * Copy bounded groups, checking every digest before its signed PUT.
     *
     * @param array $files Object keys mapped to path, type and digest.
     * @param string $cache_control Cache policy.
     * @param int $concurrency Maximum requests per group.
     * @param int $byte_cap Maximum payload bytes per group.
     * @param float $deadline End of the batch, or zero for the usual timeout.
     * @return array Object keys mapped to true or a redacted WP_Error.
     */
    public function put_files($files, $cache_control, $concurrency = 20, $byte_cap = 33554432, $deadline = 0) {
        $results = array();
        foreach (self::file_groups($files, $concurrency, $byte_cap) as $group) {
            $requests = array();
            foreach ($group as $key => $info) {
                if ($deadline && microtime(true) >= $deadline) {
                    $results[$key] = new WP_Error('storage_budget', __('The storage batch will continue on its next turn.', 'seoprostack'));
                    continue;
                }
                $body = $this->read_file($info['path']);
                if (false === $body || empty($info['digest'])) {
                    $results[$key] = new WP_Error('storage_read', __('The local picture could not be read.', 'seoprostack'));
                } elseif (!hash_equals($info['digest'], hash('sha256', $body))) {
                    $results[$key] = new WP_Error('storage_changed', __('The local picture changed while being copied. It will be retried.', 'seoprostack'));
                } else {
                    $requests[$key] = $this->signed_request('PUT', $key, $body, array('content-type' => $info['type'], 'cache-control' => $cache_control));
                }
            }
            $remaining = $deadline ? $deadline - microtime(true) : 20;
            if ($remaining <= 0) {
                foreach ($requests as $key => $request) {
                    $results[$key] = is_wp_error($request) ? $request : new WP_Error('storage_budget', __('The storage batch will continue on its next turn.', 'seoprostack'));
                }
                unset($requests, $request, $body);
                continue;
            }
            $timeout = min(20, $remaining);
            $responses = $this->request_multiple($requests, $timeout);
            foreach ($responses as $key => $response) {
                // In a group given less than the usual 20 seconds, a request
                // still running when the time ran out was stopped by the batch
                // deadline, not the bucket: it continues on the next turn, as
                // remove_local() treats its HEADs. Earlier failures stay errors.
                if ($timeout < 20 && is_wp_error($response) && 'storage_transport' === $response->get_error_code() && !empty($response->get_error_data()['at_deadline'])) {
                    $response = new WP_Error('storage_budget', __('The storage batch will continue on its next turn.', 'seoprostack'));
                }
                $results[$key] = is_wp_error($response) ? $response : true;
            }
            // Release the last body's reference as well as the signed payloads
            // before allocating the following group.
            unset($requests, $responses, $body);
        }
        return $results;
    }

    /**
     * Inspect a bounded group before any local file is removed.
     *
     * @param string[] $keys Object keys.
     * @param float $deadline Latest completion time, or zero for the usual timeout.
     * @param int $concurrency Maximum requests per group.
     * @return array Object keys mapped to object information or WP_Error.
     */
    public function heads($keys, $deadline = 0, $concurrency = 20) {
        $results = array();
        foreach (array_chunk($keys, max(1, min(128, (int) $concurrency))) as $group) {
            $requests = array();
            foreach ($group as $key) {
                $requests[$key] = $this->signed_request('HEAD', $key);
            }
            $remaining = $deadline ? $deadline - microtime(true) : 20;
            if ($remaining <= 0) {
                foreach ($group as $key) {
                    $results[$key] = new WP_Error('storage_budget', __('The storage batch will continue on its next turn.', 'seoprostack'));
                }
                continue;
            }
            $timeout = min(20, $remaining);
            foreach ($this->request_multiple($requests, $timeout) as $key => $response) {
                // As in put_files(): a HEAD still running when a group given
                // less than the usual 20 seconds ran out was stopped by the
                // caller's deadline, not the bucket, so it waits for the next
                // turn instead of counting as a service failure.
                if ($timeout < 20 && is_wp_error($response) && 'storage_transport' === $response->get_error_code() && !empty($response->get_error_data()['at_deadline'])) {
                    $response = new WP_Error('storage_budget', __('The storage batch will continue on its next turn.', 'seoprostack'));
                }
                $results[$key] = $this->head_info($response);
            }
        }
        return $results;
    }

    /**
     * Read objects back in bounded groups with signed GETs and compare each
     * byte for byte with its record; bodies are dropped after each group.
     *
     * @param array $objects Object keys mapped to size and sha256 digest.
     * @param int $concurrency Maximum requests per group.
     * @param int $byte_cap Maximum response bytes per group.
     * @return array Object keys mapped to true, a storage_mismatch WP_Error, or the request's WP_Error.
     */
    public function confirm_files($objects, $concurrency = 20, $byte_cap = 33554432) {
        $results = array();
        foreach (self::file_groups($objects, $concurrency, $byte_cap) as $group) {
            $requests = array();
            foreach ($group as $key => $info) {
                $requests[$key] = $this->signed_request('GET', $key);
            }
            foreach ($this->request_multiple($requests) as $key => $response) {
                if (is_wp_error($response)) {
                    $results[$key] = $response;
                    continue;
                }
                $body = (string) wp_remote_retrieve_body($response);
                $same = strlen($body) === (int) $group[$key]['size'] && hash_equals((string) $group[$key]['digest'], hash('sha256', $body));
                $results[$key] = $same ? true : new WP_Error('storage_mismatch', __('A stored file did not match its record.', 'seoprostack'));
            }
            unset($requests, $response, $body);
        }
        return $results;
    }

    /**
     * Use core's bundled transport, without redirects or raw transport errors.
     * Proxy-configured sites retain the WordPress HTTP API path.
     *
     * @param array $requests Signed request arrays or WP_Error values.
     * @param float $timeout Most seconds for this group.
     * @return array WordPress-shaped responses or WP_Error values, by key.
     */
    private function request_multiple($requests, $timeout = 20) {
        $deadline = microtime(true) + $timeout;
        $results = array();
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $responses = $this->request_multiple_once($requests, max(0, $deadline - microtime(true)));
            $pending = array();
            foreach ($responses as $key => $response) {
                // A retry cut short by the budget must not turn an earlier
                // service failure into a harmless upload deferral.
                $at_deadline = is_wp_error($response) && ('storage_budget' === $response->get_error_code() || ('storage_transport' === $response->get_error_code() && !empty($response->get_error_data()['at_deadline'])));
                if (!$at_deadline || !self::retryable($results[$key] ?? null)) {
                    $results[$key] = $response;
                }
                if (self::retryable($response)) {
                    $pending[$key] = $requests[$key];
                }
            }
            if (!$pending || !$this->retry_pause($attempt, $deadline)) {
                break;
            }
            // Retry only failures, retaining the same digest-checked PUT bodies
            // and the successful peers without allocating a second payload group.
            $requests = $pending;
        }
        return $results;
    }

    /**
     * Send one attempt within the remaining group budget.
     *
     * @param array $requests Signed request arrays or WP_Error values.
     * @param float $timeout Remaining seconds for this group.
     * @return array Checked responses by key.
     */
    private function request_multiple_once($requests, $timeout) {
        $class = class_exists('WpOrg\\Requests\\Requests') ? 'WpOrg\\Requests\\Requests' : 'Requests';
        $deadline = microtime(true) + $timeout;
        $batch = $this->prepare_parallel($requests, $class, $deadline);
        if (!$batch['parallel']) {
            return $batch['results'];
        }
        if (self::can_wait()) {
            $sent = $this->curl_multiple($batch['parallel'], $timeout, $deadline);
            foreach ($batch['parallel'] as $key => $request) {
                $batch['results'][$key] = $this->at_deadline($this->multiple_response($request['type'], $sent['responses'][$key]), $sent['finished'][$key], $deadline);
            }
            return $batch['results'];
        }
        // When each request finished, so a transport error can be told apart
        // from one stopped by the group's time limit.
        $finished = array();
        $complete = static function ($response, $key) use (&$finished) {
            if (!isset($finished[$key])) {
                $finished[$key] = microtime(true);
            }
        };
        try {
            // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- background batch, never a page view.
            $responses = $class::request_multiple($batch['parallel'], array('timeout' => $timeout, 'connect_timeout' => min(10, $timeout), 'follow_redirects' => false, 'redirects' => 0, 'data_format' => 'body', 'verify' => ABSPATH . WPINC . '/certificates/ca-bundle.crt', 'max_bytes' => 16 * MB_IN_BYTES + 1, 'complete' => $complete));
        } catch (Throwable $error) {
            // Never persist exception messages: they can contain credentials.
            $responses = array();
        }
        $ended = microtime(true);
        foreach ($batch['parallel'] as $key => $request) {
            $batch['results'][$key] = $this->at_deadline($this->multiple_response($request['type'], $responses[$key] ?? null), $finished[$key] ?? $ended, $deadline);
        }
        return $batch['results'];
    }

    /** @return bool Whether PHP's curl can wait on its sockets (curl_multi_select). */
    private static function can_wait() {
        return function_exists('curl_multi_init') && function_exists('curl_multi_select') && function_exists('curl_multi_info_read') && defined('CURLOPT_PROTOCOLS');
    }

    // phpcs:disable WordPress.WP.AlternativeFunctions -- core's bundled Requests transport loops on curl_multi_exec() without curl_multi_select(), keeping a CPU core busy for the whole network wait (#1184). The same request arrays, signatures, CA bundle, timeouts, redirect policy and size limit apply; proxy-configured sites still use the WordPress HTTP API (prepare_parallel()).

    /**
     * Send signed requests together on one curl multi handle, waiting in the
     * kernel (curl_multi_select) for the network instead of spinning. The
     * handle stays open for this client, so later groups reuse connections.
     *
     * @param array $requests Signed request arrays by key.
     * @param float $timeout Most seconds for this group.
     * @param float $deadline End of the group's time.
     * @return array{responses:array,finished:array} Response objects (null for a transport failure) and end times, by key.
     */
    private function curl_multiple($requests, $timeout, $deadline) {
        if (!$this->multi) {
            $this->multi = curl_multi_init();
        }
        $handles = array();
        $bodies = array();
        $headers = array();
        foreach ($requests as $key => $request) {
            $bodies[$key] = '';
            $headers[$key] = array();
            $handles[$key] = $this->curl_handle($request, $timeout, $bodies[$key], $headers[$key]);
            curl_multi_add_handle($this->multi, $handles[$key]);
        }
        $codes = array();
        $finished = array();
        $this->curl_wait($handles, $deadline, $codes, $finished);
        $ended = microtime(true);
        $responses = array();
        foreach ($handles as $key => $handle) {
            $ok = isset($codes[$key]) && CURLE_OK === $codes[$key];
            $responses[$key] = $ok ? (object) array('status_code' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'headers' => $headers[$key], 'body' => $bodies[$key]) : null;
            $finished[$key] = $finished[$key] ?? $ended;
            curl_multi_remove_handle($this->multi, $handle);
        }
        return array('responses' => $responses, 'finished' => $finished);
    }

    /**
     * Drive the multi handle until every transfer ends, sleeping in the
     * kernel (curl_multi_select) while the network is busy.
     *
     * @param array $handles Easy handles by key.
     * @param float $deadline End of the group's time.
     * @param array $codes Receives curl result codes by key.
     * @param array $finished Receives end times by key.
     */
    private function curl_wait($handles, $deadline, &$codes, &$finished) {
        do {
            do {
                $status = curl_multi_exec($this->multi, $running);
            } while (CURLM_CALL_MULTI_PERFORM === $status);
            $this->curl_read_done($handles, $codes, $finished);
            // Each handle has its own time limit; the margin only guards the loop.
            if (!$running || CURLM_OK !== $status || microtime(true) > $deadline + 5) {
                return;
            }
            // -1: nothing to wait on yet (libcurl is resolving or connecting).
            if (-1 === curl_multi_select($this->multi, min(1.0, max(0.01, $deadline - microtime(true))))) {
                usleep(10000);
            }
        } while (true);
    }

    /**
     * Record each transfer that has ended since the last call.
     *
     * @param array $handles Easy handles by key.
     * @param array $codes Receives curl result codes by key.
     * @param array $finished Receives end times by key.
     */
    private function curl_read_done($handles, &$codes, &$finished) {
        while (is_array($done = curl_multi_info_read($this->multi))) {
            $key = array_search($done['handle'], $handles, true);
            if (false !== $key) {
                $codes[$key] = (int) $done['result'];
                $finished[$key] = microtime(true);
            }
        }
    }

    /**
     * One request's easy handle: no redirects, http(s) only, peers verified
     * with core's CA bundle, and a response body of at most 16 MB plus a byte.
     *
     * @param array $request Signed request.
     * @param float $timeout Most seconds.
     * @param string $body Receives the response body.
     * @param array $headers Receives lower-case response headers.
     * @return resource Easy handle (an object on PHP 8).
     */
    private function curl_handle($request, $timeout, &$body, &$headers) {
        $lines = array('Expect:'); // No 100-continue round trip before each PUT body.
        foreach ($request['headers'] as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $handle = curl_init();
        $options = array(
            CURLOPT_URL => $request['url'],
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) (min(10, $timeout) * 1000)),
            CURLOPT_USERAGENT => 'WordPress/' . get_bloginfo('version') . '; SEO Pro Stack',
            CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$headers) {
                $text = trim($line);
                if (0 === stripos($text, 'HTTP/')) {
                    // A new status line (after 100 Continue): only the last response counts.
                    $headers = array();
                } elseif (strpos($text, ':')) {
                    list($name, $value) = explode(':', $text, 2);
                    $name = strtolower(trim($name));
                    $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . trim($value) : trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, $data) use (&$body) {
                // Returning less than given stops the transfer: a transport error.
                if (strlen($body) + strlen($data) > 16 * MB_IN_BYTES + 1) {
                    return 0;
                }
                $body .= $data;
                return strlen($data);
            },
        );
        if ('HEAD' === $request['type']) {
            $options[CURLOPT_NOBODY] = true;
        } elseif ('GET' === $request['type']) {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $request['type'];
            $options[CURLOPT_POSTFIELDS] = (string) $request['data'];
        }
        curl_setopt_array($handle, $options);
        return $handle;
    }

    // phpcs:enable WordPress.WP.AlternativeFunctions

    /**
     * Mark a transport error that ended when the group's time ran out.
     *
     * @param array|WP_Error $result Checked result.
     * @param float $finished When the request ended.
     * @param float $deadline End of the group's time.
     * @return array|WP_Error The result, a transport error carrying at_deadline.
     */
    private function at_deadline($result, $finished, $deadline) {
        if (is_wp_error($result) && 'storage_transport' === $result->get_error_code()) {
            $result->add_data(array('at_deadline' => $finished >= $deadline - 0.25));
        }
        return $result;
    }

    /**
     * Separate allowed parallel requests from invalid or proxy-routed ones.
     *
     * @param array $requests Signed requests or errors.
     * @param string $class Bundled Requests class.
     * @param float $deadline End of the group's time.
     * @return array{results:array,parallel:array} Completed results and pending requests.
     */
    private function prepare_parallel($requests, $class, $deadline) {
        $results = array();
        $parallel = array();
        $fallback = (new WP_HTTP_Proxy())->is_enabled() || !class_exists($class);
        foreach ($requests as $key => $request) {
            if (is_wp_error($request)) {
                $results[$key] = $request;
            } elseif ($fallback) {
                $results[$key] = $this->at_deadline($this->fallback_request($request, $deadline), microtime(true), $deadline);
            } elseif ((new WP_Http())->block_request($request['url'])) {
                $results[$key] = new WP_Error('storage_transport', __('The storage service could not be reached.', 'seoprostack'));
            } else {
                $parallel[$key] = $request;
            }
        }
        return array('results' => $results, 'parallel' => $parallel);
    }

    /**
     * Keep sequential proxy requests inside the same group deadline.
     *
     * @param array $request Signed request.
     * @param float $deadline End of group time.
     * @return array|WP_Error Checked HTTP response.
     */
    private function fallback_request($request, $deadline) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return new WP_Error('storage_budget', __('The storage batch will continue on its next turn.', 'seoprostack'));
        }
        // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- background batch, never a page view.
        $response = wp_remote_request($request['url'], array('method' => $request['type'], 'headers' => $request['headers'], 'body' => $request['data'], 'timeout' => $remaining, 'redirection' => 0, 'limit_response_size' => 16 * MB_IN_BYTES + 1));
        return $this->checked_response($request['type'], $response);
    }

    /**
     * Convert a bundled Requests result without retaining raw exceptions.
     *
     * @param string $method HTTP method.
     * @param mixed $response Response or transport exception.
     * @return array|WP_Error Checked WordPress-shaped result.
     */
    private function multiple_response($method, $response) {
        if (!is_object($response) || !isset($response->status_code, $response->headers, $response->body)) {
            return new WP_Error('storage_transport', __('The storage service could not be reached.', 'seoprostack'));
        }
        return $this->checked_response($method, array('response' => array('code' => (int) $response->status_code), 'headers' => $response->headers, 'body' => $response->body));
    }

    /**
     * Inspect an object.
     *
     * @param string $key Object key.
     * @return array{status:int,size:int,etag:string}|WP_Error
     */
    public function head($key) {
        return $this->head_info($this->request('HEAD', $key));
    }

    /**
     * Read the same confirmation fields from single or concurrent HEADs.
     *
     * @param array|WP_Error $response Checked HTTP result.
     * @return array{status:int,size:int,etag:string}|WP_Error Confirmation.
     */
    private function head_info($response) {
        if (is_wp_error($response)) {
            return $response;
        }
        $etag = wp_remote_retrieve_header($response, 'etag');
        return array('status' => (int) wp_remote_retrieve_response_code($response), 'size' => (int) wp_remote_retrieve_header($response, 'content-length'), 'etag' => is_string($etag) ? $etag : '');
    }

    /**
     * Read an object's bytes, signed, so a private bucket can bring files back.
     *
     * @param string $key Object key.
     * @return string|WP_Error
     */
    public function get($key) {
        $response = $this->request('GET', $key);
        return is_wp_error($response) ? $response : (string) wp_remote_retrieve_body($response);
    }

    /**
     * Remove an object; absent objects count as done.
     *
     * @param string $key Object key.
     * @return true|WP_Error
     */
    public function delete($key) {
        $response = $this->request('DELETE', $key);
        return is_wp_error($response) ? $response : true;
    }

    /**
     * Sign and send one request. Never follow redirects with credentials.
     *
     * @param string $method HTTP method.
     * @param string $key Object key.
     * @param string $body Payload.
     * @param array<string,string> $headers Object headers.
     * @return array|WP_Error
     */
    private function request($method, $key, $body = '', $headers = array()) {
        $request = $this->signed_request($method, $key, $body, $headers);
        if (is_wp_error($request)) {
            return $request;
        }
        $deadline = microtime(true) + 20;
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $response = $this->fallback_request($request, $deadline);
            if (!self::retryable($response) || !$this->retry_pause($attempt, $deadline)) {
                return $response;
            }
        }
        return $response;
    }

    /**
     * Recognise only service responses, never credentials or transport failures.
     *
     * @param mixed $response Checked response.
     * @return bool Whether a short retry can help.
     */
    public static function retryable($response) {
        return is_wp_error($response) && 'storage_http' === $response->get_error_code() && !empty($response->get_error_data()['retryable']);
    }

    /**
     * Back off twice with jitter, leaving time to start the next attempt.
     *
     * @param int $attempt Attempt just completed (three at most).
     * @param float $deadline End of the caller's budget.
     * @return bool Whether another attempt fits.
     */
    private function retry_pause($attempt, $deadline) {
        if ($attempt >= 3) {
            return false;
        }
        $delay = (1 === $attempt ? 0.5 : 1.5) + wp_rand(0, 250) / 1000;
        if (microtime(true) + $delay + 0.05 >= $deadline) {
            return false;
        }
        usleep((int) ($delay * 1000000));
        return microtime(true) < $deadline;
    }

    /**
     * Build the same signature for single and concurrent requests.
     *
     * @param string $method HTTP method.
     * @param string $key Object key.
     * @param string $body Payload.
     * @param array $headers Object headers.
     * @return array|WP_Error Signed request or invalid endpoint.
     */
    private function signed_request($method, $key, $body = '', $headers = array()) {
        $config = $this->config;
        $parts = $this->endpoint_parts();
        if (is_wp_error($parts)) {
            return $parts;
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = (isset($parts['path']) ? rtrim($parts['path'], '/') : '') . '/' . $config['bucket'] . '/' . $key;
        $path = implode('/', array_map('rawurlencode', explode('/', $path)));
        $date = gmdate('Ymd\THis\Z');
        $day  = substr($date, 0, 8);
        $hash = hash('sha256', $body);
        $headers['host']                 = $host;
        $headers['x-amz-content-sha256'] = $hash;
        $headers['x-amz-date']           = $date;
        ksort($headers);
        $canonical = '';
        foreach ($headers as $name => $value) {
            $canonical .= $name . ':' . trim($value) . "\n";
        }
        $signed = implode(';', array_keys($headers));
        $scope  = $day . '/' . $config['region'] . '/s3/aws4_request';
        $request = $method . "\n" . $path . "\n\n" . $canonical . "\n" . $signed . "\n" . $hash;
        $string  = "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash('sha256', $request);
        $signing = hash_hmac('sha256', $day, 'AWS4' . $config['secret'], true);
        foreach (array($config['region'], 's3', 'aws4_request') as $step) {
            $signing = hash_hmac('sha256', $step, $signing, true);
        }
        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $config['key_id'] . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . hash_hmac('sha256', $string, $signing);
        return array('url' => $parts['scheme'] . '://' . $host . $path, 'type' => $method, 'headers' => $headers, 'data' => $body);
    }

    /** @return array|WP_Error Parsed trusted endpoint, never URL credentials. */
    private function endpoint_parts() {
        $parts = wp_parse_url($this->config['endpoint']);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme']) || !in_array($parts['scheme'], array('http', 'https'), true) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return new WP_Error('storage_endpoint', __('Check the storage endpoint address.', 'seoprostack'));
        }
        return $parts;
    }

    /**
     * Apply one success policy and redact every service error.
     *
     * @param string $method HTTP method.
     * @param array|WP_Error $response HTTP result.
     * @return array|WP_Error Checked result.
     */
    private function checked_response($method, $response) {
        if (is_wp_error($response)) {
            // Transport errors may contain the URL or sensitive server text.
            return new WP_Error('storage_transport', __('The storage service could not be reached.', 'seoprostack'));
        }
        $status = wp_remote_retrieve_response_code($response);
        if (($status >= 200 && $status < 300) || ('DELETE' === $method && 404 === $status)) {
            return $response;
        }
        // Only allow a short machine code, never the service's echoed request.
        $code = '';
        $retryable = 429 === $status || ($status >= 500 && $status < 600);
        if (preg_match('/<Code>([A-Za-z0-9]{1,64})<\/Code>/', wp_remote_retrieve_body($response), $match)) {
            $retryable = $retryable || in_array($match[1], array('SlowDown', 'InternalError', 'ServiceUnavailable'), true);
            $code = str_replace(array($this->config['key_id'], $this->config['secret']), '[redacted]', $match[1]);
        }
        return new WP_Error('storage_http', sprintf('Storage HTTP %d%s', $status, $code ? ' (' . $code . ')' : ''), array('status' => (int) $status, 'retryable' => $retryable));
    }
}
