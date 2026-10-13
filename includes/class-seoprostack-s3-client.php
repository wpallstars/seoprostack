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
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- local file; concurrent deletion is handled as WP_Error below.
        $body = @file_get_contents($path);
        if (false === $body) {
            return new WP_Error('storage_read', __('The local picture could not be read.', 'seoprostack'));
        }
        if ($digest && !hash_equals($digest, hash('sha256', $body))) {
            return new WP_Error('storage_changed', __('The local picture changed while being copied. It will be retried.', 'seoprostack'));
        }
        return $this->put($key, $body, $content_type, $cache_control);
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
     * Inspect an object.
     *
     * @param string $key Object key.
     * @return array{status:int,size:int,etag:string}|WP_Error
     */
    public function head($key) {
        $response = $this->request('HEAD', $key);
        if (is_wp_error($response)) {
            return $response;
        }
        $etag = wp_remote_retrieve_header($response, 'etag');
        return array('status' => (int) wp_remote_retrieve_response_code($response), 'size' => (int) wp_remote_retrieve_header($response, 'content-length'), 'etag' => is_string($etag) ? $etag : '');
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
        $config = $this->config;
        $parts  = wp_parse_url($config['endpoint']);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme']) || !in_array($parts['scheme'], array('http', 'https'), true) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return new WP_Error('storage_endpoint', __('Check the storage endpoint address.', 'seoprostack'));
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
        // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- explicit probe or background batch, never a page view.
        $response = wp_remote_request($parts['scheme'] . '://' . $host . $path, array('method' => $method, 'headers' => $headers, 'body' => $body, 'timeout' => 20, 'redirection' => 0));
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
        if (preg_match('/<Code>([A-Za-z0-9]{1,64})<\/Code>/', wp_remote_retrieve_body($response), $match)) {
            $code = str_replace(array($config['key_id'], $config['secret']), '[redacted]', $match[1]);
        }
        return new WP_Error('storage_http', sprintf('Storage HTTP %d%s', $status, $code ? ' (' . $code . ')' : ''));
    }
}
