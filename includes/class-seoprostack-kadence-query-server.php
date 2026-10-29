<?php
/**
 * A REST server holding only Kadence Blocks Pro's query loop route, for
 * Fixes for other plugins (SEOProStack_Plugin_Fixes::kadence_query_server()).
 *
 * Kadence Blocks Pro's query blocks run their query through rest_do_request()
 * while the page renders. The first rest_get_server() of a request fires
 * rest_api_init, so every plugin registers all its routes for a page that
 * uses one. While a query block renders, this server stands in as the
 * global one: Kadence's own request is dispatched here, with WordPress's
 * usual checks and filters. It stops standing in as soon as a dispatch
 * starts, so a request for any other route, and any REST use later in the
 * page, gets the full server as before.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.99
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Query_Server extends WP_REST_Server {

    /**
     * Dispatch Kadence's query request here; send any other to the full
     * server.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function dispatch($request) {
        SEOProStack_Plugin_Fixes::kadence_query_server_off();
        if (SEOProStack_Plugin_Fixes::KADENCE_QUERY_ROUTE !== $request->get_route() || 'GET' !== $request->get_method()) {
            return rest_get_server()->dispatch($request);
        }
        SEOProStack_Plugin_Fixes::kadence_query_dispatching(true);
        try {
            return parent::dispatch($request);
        } finally {
            SEOProStack_Plugin_Fixes::kadence_query_dispatching(false);
        }
    }
}
