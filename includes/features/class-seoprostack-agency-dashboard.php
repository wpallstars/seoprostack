<?php
/**
 * Client dashboard: a logged-in client's orders, conversations, payments,
 * calls, report, courses and community spaces on one page.
 *
 * The Client dashboard block and the [seoprostack_client_dashboard]
 * shortcode show the orders the order flow (SEOProStack_Agency_Orders)
 * started for the client's email address: each with its stage on the board
 * (as "stage 2 of 5"), when it was ordered and a link to its Fluent Support
 * conversation. Below, a link to the client's report (the address in a
 * FluentCRM contact field), their upcoming FluentBooking calls, their Fluent
 * Forms payments, Tutor LMS courses with progress and FluentCommunity spaces,
 * when those plugins are active. Visitors who are not logged in see a link
 * to log in. Nothing is shown for other people, and nothing is cached
 * across users.
 *
 * Shortcode attributes: order_page and call_page (a page ID or address for
 * "Order something new" and "Book a call"), show (any of
 * orders,report,calls,payments,courses,spaces: what to list).
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Agency_Dashboard extends SEOProStack_Feature {

    const KEY = 'agency_dashboard';

    const SHORTCODE = 'seoprostack_client_dashboard';

    /** What the dashboard can show, in order. */
    const PARTS = array('orders', 'report', 'calls', 'payments', 'courses', 'spaces');

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
                'tab'         => 'agency',
                'label'       => __('Client dashboard', 'seoprostack'),
                'description' => __('A Client dashboard block and [seoprostack_client_dashboard] shortcode that show logged-in clients their orders and how far along each is, with links to their conversations, plus their payments, upcoming calls, report, courses and community spaces.', 'seoprostack'),
            ),
            'agency_dashboard_order_page' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Order page', 'seoprostack'),
                'description' => __('Linked as “Order something new”.', 'seoprostack'),
                'options'     => array(__CLASS__, 'page_options'),
                'open'        => true,
            ),
            'agency_dashboard_call_page' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Call booking page', 'seoprostack'),
                'description' => __('Linked as “Book a call”, under the client’s upcoming FluentBooking calls.', 'seoprostack'),
                'options'     => array(__CLASS__, 'page_options'),
                'open'        => true,
            ),
            'agency_dashboard_report_field' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Report field', 'seoprostack'),
                'description' => __('A FluentCRM contact field where you keep the address of each client’s report. The dashboard links to it.', 'seoprostack'),
                'options'     => array(__CLASS__, 'field_options'),
                'open'        => true,
            ),
        );
    }

    /**
     * FluentCRM custom contact fields.
     *
     * @return array<string,string>
     */
    public static function field_options() {
        $out = array('' => __('None', 'seoprostack'));
        if (function_exists('fluentcrm_get_option')) {
            foreach ((array) fluentcrm_get_option('contact_custom_fields', array()) as $field) {
                if (is_array($field) && !empty($field['slug'])) {
                    $out[(string) $field['slug']] = !empty($field['label']) ? (string) $field['label'] : (string) $field['slug'];
                }
            }
        }
        return $out;
    }

    /**
     * Published and draft pages.
     *
     * @return array<string,string>
     */
    public static function page_options() {
        $out   = array('' => __('None', 'seoprostack'));
        $pages = get_pages(array('post_status' => 'publish,draft', 'sort_column' => 'post_title'));
        foreach (is_array($pages) ? $pages : array() as $page) {
            $out[(string) $page->ID] = '' !== $page->post_title ? $page->post_title : '#' . $page->ID;
        }
        return $out;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_action('init', array(__CLASS__, 'register'));
    }

    /**
     * Register the block and shortcode.
     */
    public static function register() {
        register_block_type(SEOPROSTACK_DIR . 'blocks/client-dashboard');
        if (!shortcode_exists(self::SHORTCODE)) {
            add_shortcode(self::SHORTCODE, array(__CLASS__, 'shortcode'));
        }
    }

    /**
     * Shortcode.
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function shortcode($atts) {
        $atts = shortcode_atts(array('order_page' => '', 'call_page' => '', 'show' => implode(',', self::PARTS)), $atts, self::SHORTCODE);
        wp_enqueue_style('seoprostack-client-dashboard-style');
        return self::render(array(
            'orderPage' => (string) $atts['order_page'],
            'callPage'  => (string) $atts['call_page'],
            'show'      => array_map('trim', explode(',', (string) $atts['show'])),
        ));
    }

    /**
     * The dashboard's HTML.
     *
     * @param array $args orderPage and callPage (ID or address), show (parts, as in PARTS), wrapper (attributes).
     * @return string
     */
    public static function render(array $args) {
        $show    = isset($args['show']) ? (array) $args['show'] : self::PARTS;
        $wrapper = isset($args['wrapper']) ? (string) $args['wrapper'] : 'class="wp-block-seoprostack-client-dashboard"';
        $out     = '<div ' . $wrapper . '>';
        if (!is_user_logged_in()) {
            $here = is_singular() ? get_permalink() : home_url('/');
            $out .= '<p class="sps-client__login">' . sprintf(
                /* translators: %s: log in link */
                esc_html__('%s to see your orders.', 'seoprostack'),
                '<a href="' . esc_url(wp_login_url($here ? $here : home_url('/'))) . '">' . esc_html__('Log in', 'seoprostack') . '</a>'
            ) . '</p>';
            return $out . '</div>';
        }
        $user = wp_get_current_user();
        if (in_array('orders', $show, true)) {
            $out .= self::orders_html($user, self::page_url(isset($args['orderPage']) ? (string) $args['orderPage'] : '', 'agency_dashboard_order_page'));
        }
        if (in_array('report', $show, true)) {
            $out .= self::report_html($user);
        }
        if (in_array('calls', $show, true)) {
            $out .= self::calls_html($user, self::page_url(isset($args['callPage']) ? (string) $args['callPage'] : '', 'agency_dashboard_call_page'));
        }
        if (in_array('payments', $show, true)) {
            $out .= self::payments_html($user);
        }
        if (in_array('courses', $show, true)) {
            $out .= self::courses_html((int) $user->ID);
        }
        if (in_array('spaces', $show, true)) {
            $out .= self::spaces_html((int) $user->ID);
        }
        return $out . '</div>';
    }

    /**
     * Address of a linked page: the block's or shortcode's choice, else the setting.
     *
     * @param string $choice  Page ID or address.
     * @param string $setting Setting with the default page.
     * @return string
     */
    private static function page_url($choice, $setting) {
        $choice = '' !== trim($choice) ? trim($choice) : (string) SEOProStack_Settings::get($setting);
        if ('' === $choice) {
            return '';
        }
        if (ctype_digit($choice)) {
            return 'publish' === get_post_status((int) $choice) ? (string) get_permalink((int) $choice) : '';
        }
        return esc_url_raw($choice);
    }

    /**
     * The client's orders: tasks the order flow linked to their email address.
     *
     * @param WP_User $user      User.
     * @param string  $order_url Order page address.
     * @return string
     */
    private static function orders_html($user, $order_url) {
        $html  = '<section class="sps-client__section sps-client__orders"><h2 class="sps-client__title">' . esc_html__('Your orders', 'seoprostack') . '</h2>';
        $tasks = self::tasks_for((string) $user->user_email);
        if (!$tasks) {
            $html .= '<p class="sps-client__empty">' . esc_html__('No orders yet.', 'seoprostack') . '</p>';
        } else {
            $html .= '<ul class="sps-client__list">';
            foreach ($tasks as $task) {
                $html .= self::order_html($task);
            }
            $html .= '</ul>';
        }
        if ('' !== $order_url) {
            $html .= '<p class="sps-client__more"><a href="' . esc_url($order_url) . '">' . esc_html__('Order something new', 'seoprostack') . '</a></p>';
        }
        return $html . '</section>';
    }

    /**
     * Fluent Boards tasks for an email address, newest first.
     *
     * @param string $email Email address.
     * @return object[]
     */
    private static function tasks_for($email) {
        if ('' === $email || !class_exists('FluentBoards\App\Models\TaskMeta') || !class_exists('SEOProStack_Agency_Orders')) {
            return array();
        }
        $ids = \FluentBoards\App\Models\TaskMeta::where('key', SEOProStack_Agency_Orders::CLIENT_META)
            ->where('value', strtolower($email))
            ->orderBy('task_id', 'DESC')
            ->limit(50)
            ->pluck('task_id')
            ->toArray();
        if (!$ids) {
            return array();
        }
        $tasks = \FluentBoards\App\Models\Task::whereIn('id', array_map('intval', $ids))->whereNull('archived_at')->orderBy('id', 'DESC')->get();
        $out   = array();
        foreach ($tasks as $task) {
            $out[] = $task;
        }
        return $out;
    }

    /**
     * One order: name, stage as a step of the board's stages, date and conversation link.
     *
     * @param object $task Task.
     * @return string
     */
    private static function order_html($task) {
        $stages = \FluentBoards\App\Models\Stage::where('board_id', (int) $task->board_id)->whereNull('archived_at')->orderBy('position', 'ASC')->get();
        $total  = 0;
        $step   = 0;
        $name   = '';
        foreach ($stages as $stage) {
            ++$total;
            if ((int) $stage->id === (int) $task->stage_id) {
                $step = $total;
                $name = (string) $stage->title;
            }
        }
        $done  = 'closed' === (string) $task->status || ($step && $step === $total);
        $order = SEOProStack_Agency_Orders::task_order($task);
        // The task is named "Service: client"; clients only need the service.
        $title = $order && !empty($order['service']) ? (string) $order['service'] : (string) $task->title;

        $html  = '<li class="sps-client__item' . ($done ? ' is-done' : '') . '">';
        $html .= '<span class="sps-client__name">' . esc_html($title) . '</span>';
        if ($total) {
            $html .= '<span class="sps-client__stage">' . esc_html($done ? __('Done', 'seoprostack') : $name) . '</span>';
            $html .= sprintf(
                '<progress class="sps-client__progress" max="%1$d" value="%2$d" aria-label="%3$s">%2$d / %1$d</progress>',
                (int) $total,
                (int) $step,
                /* translators: 1: step, 2: number of steps */
                esc_attr(sprintf(__('Step %1$d of %2$d', 'seoprostack'), (int) $step, (int) $total))
            );
        }
        $meta = array();
        if (!empty($task->created_at)) {
            /* translators: %s: date */
            $meta[] = esc_html(sprintf(__('Ordered %s', 'seoprostack'), mysql2date(get_option('date_format'), (string) $task->created_at)));
        }
        $link = $order && !empty($order['ticket']) ? self::ticket_url((int) $order['ticket']) : '';
        if ('' !== $link) {
            $meta[] = '<a href="' . esc_url($link) . '">' . esc_html__('Messages', 'seoprostack') . '</a>';
        }
        if ($meta) {
            $html .= '<span class="sps-client__meta">' . implode(' · ', $meta) . '</span>';
        }
        return $html . '</li>';
    }

    /**
     * Address of a Fluent Support conversation in its customer portal.
     *
     * @param int $ticket_id Ticket ID.
     * @return string
     */
    private static function ticket_url($ticket_id) {
        if (!class_exists('FluentSupport\App\Models\Ticket') || !class_exists('FluentSupport\App\Services\Helper')) {
            return '';
        }
        $ticket = \FluentSupport\App\Models\Ticket::find($ticket_id);
        if (!$ticket) {
            return '';
        }
        try {
            // Without a customer portal page, Fluent Support has nowhere to show it.
            if (method_exists('FluentSupport\App\Services\Helper', 'getPortalBaseUrl') && '' === (string) \FluentSupport\App\Services\Helper::getPortalBaseUrl()) {
                return '';
            }
            return (string) \FluentSupport\App\Services\Helper::getTicketViewUrl($ticket);
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * A link to the client's report: the address in their FluentCRM contact
     * field chosen in the settings.
     *
     * @param WP_User $user User.
     * @return string
     */
    private static function report_html($user) {
        $field = (string) SEOProStack_Settings::get('agency_dashboard_report_field');
        if ('' === $field || !function_exists('FluentCrmApi')) {
            return '';
        }
        $contact = FluentCrmApi('contacts')->getContactByUserRef((int) $user->ID);
        $values  = $contact ? (array) $contact->custom_fields() : array();
        $url     = isset($values[$field]) && is_string($values[$field]) ? esc_url_raw(trim($values[$field]), array('http', 'https')) : '';
        if ('' === $url) {
            return '';
        }
        return '<section class="sps-client__section sps-client__report"><h2 class="sps-client__title">' . esc_html__('Your report', 'seoprostack') . '</h2>'
            . '<p class="sps-client__more"><a href="' . esc_url($url) . '">' . esc_html__('Open your latest report', 'seoprostack') . '</a></p></section>';
    }

    /**
     * The client's upcoming FluentBooking calls, and a link to book one.
     *
     * @param WP_User $user     User.
     * @param string  $call_url Call booking page address.
     * @return string
     */
    private static function calls_html($user, $call_url) {
        if (!class_exists('FluentBooking\App\Models\Booking')) {
            return '';
        }
        $bookings = \FluentBooking\App\Models\Booking::where('email', (string) $user->user_email)
            ->whereIn('status', array('scheduled', 'pending'))
            ->where('end_time', '>=', gmdate('Y-m-d H:i:s'))
            ->orderBy('start_time', 'ASC')
            ->limit(10)
            ->get();
        $items = '';
        foreach ($bookings as $booking) {
            $event = \FluentBooking\App\Models\CalendarSlot::find((int) $booking->event_id);
            try {
                $zone = new DateTimeZone('' !== (string) $booking->person_time_zone ? (string) $booking->person_time_zone : wp_timezone_string());
            } catch (Exception $e) {
                $zone = wp_timezone();
            }
            $when  = wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) strtotime((string) $booking->start_time . ' UTC'), $zone);
            $items .= '<li class="sps-client__item">';
            $items .= '<span class="sps-client__name">' . esc_html($event ? (string) $event->title : __('Call', 'seoprostack')) . '</span>';
            $items .= '<span class="sps-client__stage">' . esc_html('pending' === (string) $booking->status ? __('Waiting for confirmation', 'seoprostack') : __('Booked', 'seoprostack')) . '</span>';
            $meta   = array(esc_html($when . ' (' . $zone->getName() . ')'));
            if (method_exists($booking, 'getConfirmationUrl')) {
                $meta[] = '<a href="' . esc_url((string) $booking->getConfirmationUrl()) . '">' . esc_html__('Details', 'seoprostack') . '</a>';
            }
            $items .= '<span class="sps-client__meta">' . implode(' · ', $meta) . '</span></li>';
        }
        if ('' === $items && '' === $call_url) {
            return '';
        }
        $html = '<section class="sps-client__section sps-client__calls"><h2 class="sps-client__title">' . esc_html__('Your calls', 'seoprostack') . '</h2>';
        $html .= '' !== $items ? '<ul class="sps-client__list">' . $items . '</ul>' : '<p class="sps-client__empty">' . esc_html__('No calls booked.', 'seoprostack') . '</p>';
        if ('' !== $call_url) {
            $html .= '<p class="sps-client__more"><a href="' . esc_url($call_url) . '">' . esc_html__('Book a call', 'seoprostack') . '</a></p>';
        }
        return $html . '</section>';
    }

    /**
     * The client's Fluent Forms payments, newest first.
     *
     * @param WP_User $user User.
     * @return string
     */
    private static function payments_html($user) {
        if (!class_exists('FluentForm\App\Models\Transaction') || !class_exists('SEOProStack_Agency_Orders')) {
            return '';
        }
        $email   = (string) $user->user_email;
        $user_id = (int) $user->ID;
        try {
            $payments = \FluentForm\App\Models\Transaction::whereIn('status', array('paid', 'processing', 'partially-refunded', 'refunded'))
                ->where(function ($query) use ($email, $user_id) {
                    $query->where('payer_email', $email)->orWhere('user_id', $user_id);
                })
                ->orderBy('id', 'DESC')
                ->limit(20)
                ->get();
        } catch (Throwable $e) {
            // No payments table until Fluent Forms' payments are set up.
            return '';
        }
        $status = array(
            'paid'               => __('Paid', 'seoprostack'),
            'processing'         => __('Processing', 'seoprostack'),
            'partially-refunded' => __('Partly refunded', 'seoprostack'),
            'refunded'           => __('Refunded', 'seoprostack'),
        );
        $titles = array();
        $items  = '';
        foreach ($payments as $payment) {
            $form_id = (int) $payment->form_id;
            if (!isset($titles[$form_id])) {
                $form             = \FluentForm\App\Models\Form::select(array('id', 'title'))->find($form_id);
                $titles[$form_id] = $form ? SEOProStack_Agency_Orders::service_name((string) $form->title) : '';
            }
            $items .= '<li class="sps-client__item' . ('refunded' === (string) $payment->status ? ' is-done' : '') . '">';
            $items .= '<span class="sps-client__name">' . esc_html('' !== $titles[$form_id] ? $titles[$form_id] : __('Payment', 'seoprostack')) . '</span>';
            $items .= '<span class="sps-client__stage">' . esc_html(SEOProStack_Agency_Orders::money($payment->payment_total, (string) $payment->currency)) . '</span>';
            $meta   = array();
            if (!empty($payment->created_at)) {
                $meta[] = esc_html(mysql2date(get_option('date_format'), (string) $payment->created_at));
            }
            $meta[] = esc_html(isset($status[(string) $payment->status]) ? $status[(string) $payment->status] : (string) $payment->status);
            if ('subscription' === (string) $payment->transaction_type) {
                $meta[] = esc_html__('Subscription', 'seoprostack');
            }
            $items .= '<span class="sps-client__meta">' . implode(' · ', $meta) . '</span></li>';
        }
        if ('' === $items) {
            return '';
        }
        return '<section class="sps-client__section sps-client__payments"><h2 class="sps-client__title">' . esc_html__('Your payments', 'seoprostack') . '</h2><ul class="sps-client__list">' . $items . '</ul></section>';
    }

    /**
     * The client's Tutor LMS courses with progress.
     *
     * @param int $user_id User ID.
     * @return string
     */
    private static function courses_html($user_id) {
        if (!function_exists('tutor_utils')) {
            return '';
        }
        $ids = array_slice(array_map('intval', (array) tutor_utils()->get_enrolled_courses_ids_by_user($user_id)), 0, 50);
        if (!$ids) {
            return '';
        }
        $html = '<section class="sps-client__section sps-client__courses"><h2 class="sps-client__title">' . esc_html__('Your courses', 'seoprostack') . '</h2><ul class="sps-client__list">';
        foreach ($ids as $id) {
            if ('publish' !== get_post_status($id)) {
                continue;
            }
            $percent = (int) tutor_utils()->get_course_completed_percent($id, $user_id);
            $html   .= '<li class="sps-client__item' . (100 <= $percent ? ' is-done' : '') . '">';
            $html   .= '<a class="sps-client__name" href="' . esc_url(get_permalink($id)) . '">' . esc_html(get_the_title($id)) . '</a>';
            /* translators: %d: percentage */
            $html .= '<span class="sps-client__stage">' . esc_html(sprintf(__('%d%% done', 'seoprostack'), $percent)) . '</span>';
            $html .= sprintf('<progress class="sps-client__progress" max="100" value="%1$d">%1$d%%</progress>', $percent);
            $html .= '</li>';
        }
        return $html . '</ul></section>';
    }

    /**
     * The client's FluentCommunity spaces.
     *
     * @param int $user_id User ID.
     * @return string
     */
    private static function spaces_html($user_id) {
        if (!class_exists('FluentCommunity\App\Models\SpaceUserPivot') || !class_exists('FluentCommunity\App\Models\Space')) {
            return '';
        }
        $ids = \FluentCommunity\App\Models\SpaceUserPivot::where('user_id', $user_id)->where('status', 'active')->limit(50)->pluck('space_id')->toArray();
        if (!$ids) {
            return '';
        }
        $spaces = \FluentCommunity\App\Models\Space::whereIn('id', array_map('intval', $ids))->orderBy('title', 'ASC')->get();
        $items  = '';
        foreach ($spaces as $space) {
            $items .= '<li class="sps-client__item"><a class="sps-client__name" href="' . esc_url((string) $space->getPermalink()) . '">' . esc_html((string) $space->title) . '</a></li>';
        }
        if ('' === $items) {
            return '';
        }
        return '<section class="sps-client__section sps-client__spaces"><h2 class="sps-client__title">' . esc_html__('Your community', 'seoprostack') . '</h2><ul class="sps-client__list">' . $items . '</ul></section>';
    }
}
