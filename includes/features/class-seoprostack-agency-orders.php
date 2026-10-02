<?php
/**
 * Order flow: from a paid order form to a task, a support conversation and
 * client access.
 *
 * Fluent Forms takes the order (payment fields with Stripe, or a form
 * without payment). When an order form is paid, or sent if it takes no
 * payment, this:
 * - links or adds the FluentCRM contact (as transactional, so buying does
 *   not subscribe anyone to newsletters);
 * - makes the client a WordPress login (core's set-password email) when it
 *   has something to open: the client dashboard, a course or a space;
 * - adds a task to the chosen Fluent Boards board, first stage, with the
 *   order's details, the contact, and the board label named in the form's
 *   title (an "SEO" label for "SEO audit");
 * - opens a Fluent Support conversation for it (product named in the form's
 *   title), so Fluent Support emails the client a link;
 * - enrols the client in a Tutor LMS course and adds them to a
 *   FluentCommunity space, when chosen and the client has a login.
 * When the task moves to another stage, the client gets a reply in that
 * conversation (filter: `seoprostack_agency_update_message`). A project brief sent later is
 * added to the client's latest order: a comment on the task and the client's
 * reply in the conversation. Lead forms (quote requests, referrals) add a
 * task to a sales board; support request forms open a conversation under
 * the product named in the form's title or answers.
 *
 * Each step runs only if its plugin is active and is logged on the form
 * entry. Links are kept in the entry's meta (_seoprostack_order) and the
 * task's meta (seoprostack_order, seoprostack_client), which the client
 * dashboard reads.
 *
 * Hook: `seoprostack_agency_order_started` (entry ID, task ID, ticket ID,
 * user ID) after an order starts.
 *
 * @package SEOProStack
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Agency_Orders extends SEOProStack_Feature {

    const KEY = 'agency_orders';

    /** Fluent Forms entry meta: the order's task, ticket and user. */
    const ENTRY_META = '_seoprostack_order';

    /** Fluent Boards task meta: the order's entry, form, ticket and user (JSON). */
    const TASK_META = 'seoprostack_order';

    /** Fluent Boards task meta: the client's email address, lower case. */
    const CLIENT_META = 'seoprostack_client';

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
                'label'       => __('Order flow', 'seoprostack'),
                'description' => __('Paid orders go on your Client Orders board with a support conversation, and clients are told by email as you move them along. Quotes and referrals go on your Sales Pipeline. Add example data below to set it all up.', 'seoprostack'),
            ),
            // The options below are wiring, filled in by the agency example
            // data (starters/agency.json) and not shown: there is nothing to decide.
            'agency_orders_forms' => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'hidden'      => true,
                'label'       => __('Order forms', 'seoprostack'),
                'description' => __('Forms that start an order: when paid, or when sent if they take no payment.', 'seoprostack'),
                'options'     => array(__CLASS__, 'form_options'),
                'open'        => true,
            ),
            'agency_orders_board' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'hidden'      => true,
                'label'       => __('Board', 'seoprostack'),
                'description' => __('Fluent Boards board each order is added to, in its first stage.', 'seoprostack'),
                'options'     => array(__CLASS__, 'board_options'),
                'open'        => true,
            ),
            'agency_orders_briefs' => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'hidden'      => true,
                'label'       => __('Project brief forms', 'seoprostack'),
                'description' => __('A brief sent after ordering is added to the client’s latest order.', 'seoprostack'),
                'options'     => array(__CLASS__, 'form_options'),
                'open'        => true,
            ),
            'agency_orders_requests' => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'hidden'      => true,
                'label'       => __('Support request forms', 'seoprostack'),
                'description' => __('Each opens a support conversation, under the product named in the form’s title or answers.', 'seoprostack'),
                'options'     => array(__CLASS__, 'form_options'),
                'open'        => true,
            ),
            'agency_orders_leads' => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'hidden'      => true,
                'label'       => __('Lead forms', 'seoprostack'),
                'description' => __('Quote requests, referrals and other enquiries, added to the sales board.', 'seoprostack'),
                'options'     => array(__CLASS__, 'form_options'),
                'open'        => true,
            ),
            'agency_orders_leads_board' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'hidden'      => true,
                'label'       => __('Sales board', 'seoprostack'),
                'description' => __('Fluent Boards board each lead is added to, in its first stage.', 'seoprostack'),
                'options'     => array(__CLASS__, 'board_options'),
                'open'        => true,
            ),
            'agency_orders_course' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'hidden'      => true,
                'label'       => __('Enrol in course', 'seoprostack'),
                'description' => __('A Tutor LMS course, such as how you work with clients.', 'seoprostack'),
                'options'     => array(__CLASS__, 'course_options'),
                'open'        => true,
            ),
            'agency_orders_space' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'hidden'      => true,
                'label'       => __('Add to community space', 'seoprostack'),
                'description' => __('A FluentCommunity space for your clients.', 'seoprostack'),
                'options'     => array(__CLASS__, 'space_options'),
                'open'        => true,
            ),
        );
    }

    /**
     * Whether new clients get a WordPress login: only when there is something
     * for it to open (the client dashboard, a course or a community space).
     *
     * @return bool
     */
    private static function wants_login() {
        return SEOProStack_Settings::get('agency_dashboard') || SEOProStack_Settings::get('agency_orders_course') || SEOProStack_Settings::get('agency_orders_space');
    }

    /**
     * Fluent Forms forms.
     *
     * @return array<string,string>
     */
    public static function form_options() {
        if (!class_exists('FluentForm\App\Models\Form')) {
            return array();
        }
        $out = array();
        foreach (\FluentForm\App\Models\Form::select(array('id', 'title'))->orderBy('title', 'ASC')->get() as $form) {
            $out[(string) $form->id] = (string) $form->title;
        }
        return $out;
    }

    /**
     * Fluent Boards boards that are not archived.
     *
     * @return array<string,string>
     */
    public static function board_options() {
        $out = array('' => __('None', 'seoprostack'));
        if (class_exists('FluentBoards\App\Models\Board')) {
            foreach (\FluentBoards\App\Models\Board::whereNull('archived_at')->orderBy('title', 'ASC')->get() as $board) {
                $out[(string) $board->id] = (string) $board->title;
            }
        }
        return $out;
    }

    /**
     * Tutor LMS courses.
     *
     * @return array<string,string>
     */
    public static function course_options() {
        $out = array('' => __('None', 'seoprostack'));
        if (function_exists('tutor')) {
            $courses = get_posts(array(
                'post_type'      => tutor()->course_post_type,
                'post_status'    => 'publish',
                'posts_per_page' => 200,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ));
            foreach ($courses as $course) {
                $out[(string) $course->ID] = $course->post_title;
            }
        }
        return $out;
    }

    /**
     * FluentCommunity spaces.
     *
     * @return array<string,string>
     */
    public static function space_options() {
        $out = array('' => __('None', 'seoprostack'));
        if (class_exists('FluentCommunity\App\Models\Space')) {
            foreach (\FluentCommunity\App\Models\Space::orderBy('title', 'ASC')->get() as $space) {
                $out[(string) $space->id] = (string) $space->title;
            }
        }
        return $out;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !class_exists('FluentForm\App\Models\Submission')) {
            return;
        }
        // Button layouts for order form choices (Advanced > Layout in the editor).
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-form-buttons.php';
        SEOProStack_Form_Buttons::boot();
        // After Fluent Forms' own integrations (FluentCRM feeds), which run at 10.
        add_action('fluentform/submission_inserted', array(__CLASS__, 'submitted'), 20, 3);
        add_action('fluentform/after_payment_status_change', array(__CLASS__, 'payment_changed'), 20, 2);
        add_action('fluent_boards/task_stage_updated', array(__CLASS__, 'stage_changed'), 20, 2);
    }

    /**
     * Form IDs in a setting.
     *
     * @param string $key Setting key.
     * @return int[]
     */
    private static function form_ids($key) {
        return array_map('intval', (array) SEOProStack_Settings::get($key));
    }

    /**
     * A form was sent (for payment forms: after the payment, when Fluent
     * Forms runs its form actions).
     *
     * @param int    $entry_id  Entry ID.
     * @param array  $form_data Submitted data.
     * @param object $form      Form.
     */
    public static function submitted($entry_id, $form_data, $form) {
        $form_id = isset($form->id) ? (int) $form->id : 0;
        if (self::is_order_form($form)) {
            $entry = \FluentForm\App\Models\Submission::find((int) $entry_id);
            // A form that takes payment waits for it (payment_changed()).
            if ($entry && !self::unpaid($entry)) {
                self::start($entry, $form);
            }
            return;
        }
        $entry = null;
        foreach (array('agency_orders_briefs', 'agency_orders_leads', 'agency_orders_requests') as $key) {
            if (in_array($form_id, self::form_ids($key), true)) {
                $entry = \FluentForm\App\Models\Submission::find((int) $entry_id);
                break;
            }
        }
        if (!$entry) {
            return;
        }
        if ('agency_orders_briefs' === $key) {
            self::add_brief($entry, $form);
        } elseif ('agency_orders_leads' === $key) {
            self::add_lead($entry, $form);
        } else {
            self::open_request($entry, $form);
        }
    }

    /**
     * A lead (quote request, referral): a task on the sales board, linked to
     * the FluentCRM contact the form's own feed added.
     *
     * @param object $entry Entry.
     * @param object $form  Form.
     */
    private static function add_lead($entry, $form) {
        $client = self::client($entry);
        $lines  = self::details($entry, $form);
        $title  = sprintf(/* translators: 1: form, such as Quote Request, 2: name or email */ __('%1$s: %2$s', 'seoprostack'), self::service_name((string) $form->title), '' !== $client['name'] ? $client['name'] : $client['email']);
        $order  = array('task' => 0, 'ticket' => 0, 'user' => (int) $entry->user_id, 'contact' => 0);
        $order['contact'] = self::step($entry, $form, 'FluentCRM', function () use ($client, $order) {
            return self::contact_for($client, $order['user']);
        });
        $order['task'] = self::step($entry, $form, 'Fluent Boards', function () use ($entry, $form, $client, $order, $title, $lines) {
            return self::add_task($entry, $form, $client, $order, $title, $lines, 'agency_orders_leads_board');
        });
        \FluentForm\App\Helpers\Helper::setSubmissionMeta((int) $entry->id, self::ENTRY_META, wp_json_encode($order), (int) $form->id);
    }

    /**
     * A support request: a Fluent Support conversation from the client.
     *
     * @param object $entry Entry.
     * @param object $form  Form.
     */
    private static function open_request($entry, $form) {
        $client = self::client($entry);
        $lines  = self::details($entry, $form);
        $title  = sprintf(/* translators: 1: form, such as Support Request, 2: name or email */ __('%1$s: %2$s', 'seoprostack'), self::service_name((string) $form->title), '' !== $client['name'] ? $client['name'] : $client['email']);
        $ticket = self::step($entry, $form, 'Fluent Support', function () use ($entry, $form, $client, $title, $lines) {
            return self::open_ticket($entry, $form, $client, $title, $lines);
        });
        \FluentForm\App\Helpers\Helper::setSubmissionMeta((int) $entry->id, self::ENTRY_META, wp_json_encode(array('task' => 0, 'ticket' => $ticket, 'user' => (int) $entry->user_id, 'contact' => 0)), (int) $form->id);
    }

    /**
     * A payment status changed: start a waiting order once it is paid.
     *
     * @param string $status     New status.
     * @param object $submission Entry.
     */
    public static function payment_changed($status, $submission) {
        if ('paid' !== $status || !is_object($submission) || empty($submission->id)) {
            return;
        }
        $entry = \FluentForm\App\Models\Submission::find((int) $submission->id);
        $form  = $entry ? \FluentForm\App\Models\Form::find((int) $entry->form_id) : null;
        if ($entry && $form && self::is_order_form($form)) {
            self::start($entry, $form);
        }
    }

    /**
     * Whether a form starts orders: one the example data set up, or any form
     * named "… Order Form", so a copied order form for a new service works
     * with nothing to set.
     *
     * @param object $form Form.
     * @return bool
     */
    private static function is_order_form($form) {
        $form_id = isset($form->id) ? (int) $form->id : 0;
        if (in_array($form_id, self::form_ids('agency_orders_forms'), true)) {
            return true;
        }
        foreach (array('agency_orders_briefs', 'agency_orders_leads', 'agency_orders_requests') as $key) {
            if (in_array($form_id, self::form_ids($key), true)) {
                return false;
            }
        }
        return (bool) preg_match('/\sorder\s+form$/iu', trim(isset($form->title) ? (string) $form->title : ''));
    }

    /**
     * Whether an entry has an amount to pay that is not paid yet.
     *
     * @param object $entry Entry.
     * @return bool
     */
    private static function unpaid($entry) {
        return (float) $entry->payment_total > 0 && 'paid' !== (string) $entry->payment_status;
    }

    /**
     * Start an order once: contact, login, task, conversation, course, space.
     *
     * @param object $entry Entry.
     * @param object $form  Form.
     */
    public static function start($entry, $form) {
        $entry_id = (int) $entry->id;
        if (\FluentForm\App\Helpers\Helper::getSubmissionMeta($entry_id, self::ENTRY_META)) {
            return;
        }
        // Claim it first, so a payment webhook and the form actions cannot both start it.
        \FluentForm\App\Helpers\Helper::setSubmissionMeta($entry_id, self::ENTRY_META, wp_json_encode(array('started' => time())), (int) $form->id);

        $client  = self::client($entry);
        $order   = array('task' => 0, 'ticket' => 0, 'user' => 0, 'contact' => 0);
        $service = self::service_name((string) $form->title);
        $title   = sprintf(/* translators: 1: service, 2: client name or email */ __('%1$s: %2$s', 'seoprostack'), $service, '' !== $client['name'] ? $client['name'] : $client['email']);
        $lines  = self::details($entry, $form);

        $order['user'] = self::step($entry, $form, __('Client login', 'seoprostack'), function () use ($entry, $client) {
            return self::user_for($entry, $client);
        });
        $order['contact'] = self::step($entry, $form, 'FluentCRM', function () use ($client, $order) {
            return self::contact_for($client, $order['user']);
        });
        $order['task'] = self::step($entry, $form, 'Fluent Boards', function () use ($entry, $form, $client, $order, $title, $lines) {
            return self::add_task($entry, $form, $client, $order, $title, $lines);
        });
        $order['ticket'] = self::step($entry, $form, 'Fluent Support', function () use ($entry, $form, $client, $title, $lines) {
            return self::open_ticket($entry, $form, $client, $title, $lines);
        });
        if ($order['user']) {
            self::step($entry, $form, 'Tutor LMS', function () use ($order) {
                return self::enrol($order['user']);
            });
            self::step($entry, $form, 'FluentCommunity', function () use ($order) {
                return self::join_space($order['user']);
            });
        }

        \FluentForm\App\Helpers\Helper::setSubmissionMeta($entry_id, self::ENTRY_META, wp_json_encode($order), (int) $form->id);
        if ($order['task'] && class_exists('FluentBoards\App\Models\Task')) {
            $task = \FluentBoards\App\Models\Task::find($order['task']);
            if ($task) {
                $task->updateMeta(self::TASK_META, wp_json_encode(array(
                    'service' => $service,
                    'entry'   => $entry_id,
                    'form'    => (int) $form->id,
                    'ticket'  => $order['ticket'],
                    'user'    => $order['user'],
                    'email'   => $client['email'],
                    'name'    => $client['name'],
                )));
                if ('' !== $client['email']) {
                    $task->updateMeta(self::CLIENT_META, strtolower($client['email']));
                }
            }
        }

        /**
         * Fires after an order has started.
         *
         * @param int $entry_id  Fluent Forms entry ID.
         * @param int $task_id   Fluent Boards task ID, or 0.
         * @param int $ticket_id Fluent Support ticket ID, or 0.
         * @param int $user_id   Client's user ID, or 0.
         */
        do_action('seoprostack_agency_order_started', $entry_id, $order['task'], $order['ticket'], $order['user']);
    }

    /**
     * What the client ordered, from the form's name: "SEO Audit Order Form"
     * becomes "SEO Audit".
     *
     * @param string $title Form title.
     * @return string
     */
    public static function service_name($title) {
        $name = trim((string) preg_replace('/\s+(order\s+)?form$/iu', '', trim($title)));
        return '' !== $name ? $name : trim($title);
    }

    /**
     * Run one step, log the outcome on the entry and keep going on failure.
     *
     * @param object   $entry    Entry.
     * @param object   $form     Form.
     * @param string   $name     Step name.
     * @param callable $callback Returns an ID (0 when skipped) or a WP_Error.
     * @return int
     */
    private static function step($entry, $form, $name, $callback) {
        try {
            $result = call_user_func($callback);
        } catch (Throwable $e) {
            $result = new WP_Error('seoprostack_order_step', $e->getMessage());
        }
        if (is_wp_error($result)) {
            self::log($entry, $form, 'failed', $name, $result->get_error_message());
            return 0;
        }
        if ($result) {
            /* translators: %d: ID of what was made or linked */
            self::log($entry, $form, 'success', $name, sprintf(__('Done (ID %d).', 'seoprostack'), (int) $result));
        }
        return (int) $result;
    }

    /**
     * Add a line to the entry's log in Fluent Forms.
     *
     * @param object $entry  Entry.
     * @param object $form   Form.
     * @param string $status success or failed.
     * @param string $title  Step.
     * @param string $text   What happened.
     */
    private static function log($entry, $form, $status, $title, $text) {
        do_action('fluentform/log_data', array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Forms' own logging hook.
            'parent_source_id' => (int) $form->id,
            'source_type'      => 'submission_item',
            'source_id'        => (int) $entry->id,
            'component'        => 'SEO Pro Stack',
            'status'           => $status,
            /* translators: %s: step, such as Fluent Boards */
            'title'            => sprintf(__('Order flow: %s', 'seoprostack'), $title),
            'description'      => $text,
        ));
    }

    /**
     * The client's email address and name from an entry.
     *
     * @param object $entry Entry.
     * @return array{email:string,name:string,first:string,last:string}
     */
    private static function client($entry) {
        $data  = json_decode((string) $entry->response, true);
        $data  = is_array($data) ? $data : array();
        $email = '';
        $first = '';
        $last  = '';
        foreach ($data as $key => $value) {
            if ('' === $email && is_string($value) && is_email($value)) {
                $email = sanitize_email($value);
            }
            if ('' === $first && is_array($value) && (isset($value['first_name']) || isset($value['last_name']))) {
                $first = isset($value['first_name']) ? sanitize_text_field((string) $value['first_name']) : '';
                $last  = isset($value['last_name']) ? sanitize_text_field((string) $value['last_name']) : '';
            }
        }
        if ('' === $email && $entry->user_id) {
            $user  = get_userdata((int) $entry->user_id);
            $email = $user ? (string) $user->user_email : '';
        }
        return array('email' => $email, 'name' => trim($first . ' ' . $last), 'first' => $first, 'last' => $last);
    }

    /**
     * The entry's answers as "label: value" pairs, plus the amount paid.
     *
     * @param object $entry Entry.
     * @param object $form  Form.
     * @return array<string,string>
     */
    private static function details($entry, $form) {
        $lines  = array();
        $labels = array();
        if (class_exists('FluentForm\App\Modules\Form\FormFieldsParser')) {
            foreach ((array) \FluentForm\App\Modules\Form\FormFieldsParser::getEntryInputs($form, array('admin_label')) as $name => $input) {
                $labels[$name] = isset($input['admin_label']) ? (string) $input['admin_label'] : (string) $name;
            }
        }
        $parsed = class_exists('FluentForm\App\Modules\Form\FormDataParser') ? \FluentForm\App\Modules\Form\FormDataParser::parseFormEntry(clone $entry, $form, null, false) : $entry;
        $inputs = isset($parsed->user_inputs) && is_array($parsed->user_inputs) ? $parsed->user_inputs : (array) json_decode((string) $entry->response, true);
        foreach ($inputs as $name => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', array_filter($value, 'is_scalar'))));
            }
            $value = trim(wp_strip_all_tags((string) $value));
            if ('' === $value || 0 === strpos((string) $name, '_')) {
                continue;
            }
            $lines[isset($labels[$name]) && '' !== $labels[$name] ? $labels[$name] : (string) $name] = $value;
        }
        if ((float) $entry->payment_total > 0) {
            $lines[__('Total', 'seoprostack')] = self::money($entry->payment_total, $entry->currency) . ' (' . (string) $entry->payment_status . ')';
        }
        return $lines;
    }

    /**
     * An amount Fluent Forms stores in cents, with its currency.
     *
     * @param int|float $cents    Amount in cents.
     * @param string    $currency Currency code.
     * @return string
     */
    public static function money($cents, $currency) {
        return trim(strtoupper((string) $currency) . ' ' . number_format_i18n(((float) $cents) / 100, 2));
    }

    /**
     * The client's user ID: who sent the form, a user with that email, or a
     * new login when that setting is on.
     *
     * @param object $entry  Entry.
     * @param array  $client Client.
     * @return int|WP_Error
     */
    private static function user_for($entry, array $client) {
        if ($entry->user_id) {
            return (int) $entry->user_id;
        }
        if ('' === $client['email']) {
            return 0;
        }
        $existing = get_user_by('email', $client['email']);
        if ($existing) {
            return (int) $existing->ID;
        }
        if (!self::wants_login()) {
            return 0;
        }
        $base  = sanitize_user(current(explode('@', $client['email'])), true);
        $base  = '' !== $base ? $base : 'client';
        $login = $base;
        for ($i = 2; username_exists($login); $i++) {
            $login = $base . $i;
        }
        try {
            $user_id = wp_insert_user(array(
                'user_login' => $login,
                'user_email' => $client['email'],
                'user_pass'  => wp_generate_password(24),
                'first_name' => $client['first'],
                'last_name'  => $client['last'],
                'role'       => get_option('default_role', 'subscriber'),
            ));
        } catch (Throwable $e) {
            // Another plugin's user_register code failed after the user was made.
            $made = get_user_by('email', $client['email']);
            if (!$made) {
                throw $e;
            }
            $user_id = (int) $made->ID;
        }
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        wp_new_user_notification($user_id, null, 'user');
        return (int) $user_id;
    }

    /**
     * The client's FluentCRM contact: linked if there is one, otherwise
     * added as transactional (buying is not a newsletter sign-up).
     *
     * @param array $client  Client.
     * @param int   $user_id User ID.
     * @return int
     */
    private static function contact_for(array $client, $user_id) {
        if ('' === $client['email'] || !function_exists('FluentCrmApi')) {
            return 0;
        }
        $contact = FluentCrmApi('contacts')->getContact($client['email']);
        if (!$contact) {
            $data = array(
                'email'      => $client['email'],
                'first_name' => $client['first'],
                'last_name'  => $client['last'],
                'status'     => 'transactional',
            );
            if ($user_id) {
                $data['user_id'] = (int) $user_id;
            }
            $contact = FluentCrmApi('contacts')->createOrUpdate($data);
        }
        return $contact ? (int) $contact->id : 0;
    }

    /**
     * Words in a form's title, for matching labels and products ("SEO audit").
     *
     * @param string $title     Form title.
     * @param string $candidate Label or product name.
     * @return bool
     */
    private static function named_in($title, $candidate) {
        $candidate = trim((string) $candidate);
        return '' !== $candidate && (bool) preg_match('/(^|\W)' . preg_quote($candidate, '/') . '(\W|$)/iu', (string) $title);
    }

    /**
     * Add the order's task to the chosen board, in its first stage.
     *
     * @param object $entry  Entry.
     * @param object $form   Form.
     * @param array  $client Client.
     * @param array  $order  Order so far.
     * @param string $title  Task title.
     * @param array  $lines  Details.
     * @param string $board  Setting that holds the board.
     * @return int|WP_Error
     */
    private static function add_task($entry, $form, array $client, array $order, $title, array $lines, $board = 'agency_orders_board') {
        $board_id = (int) SEOProStack_Settings::get($board);
        if (!$board_id || !class_exists('FluentBoards\App\Models\Task')) {
            return 0;
        }
        if (!\FluentBoards\App\Models\Board::find($board_id)) {
            return new WP_Error('seoprostack_no_board', __('The chosen board no longer exists.', 'seoprostack'));
        }
        $stage = \FluentBoards\App\Models\Stage::where('board_id', $board_id)->whereNull('archived_at')->orderBy('position', 'ASC')->first();
        if (!$stage) {
            return new WP_Error('seoprostack_no_stage', __('The chosen board has no stages.', 'seoprostack'));
        }
        $description = '';
        foreach ($lines as $label => $value) {
            $description .= '**' . $label . ':** ' . $value . "\n\n";
        }
        $description .= sprintf(/* translators: %s: link to the form entry */ __('Entry: %s', 'seoprostack'), admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . (int) $form->id . '#/entries/' . (int) $entry->id));

        $data = array(
            'title'          => $title,
            'board_id'       => $board_id,
            'stage_id'       => (int) $stage->id,
            'description'    => $description,
            'source'         => 'FluentForm',
            'source_id'      => (string) $entry->id,
            'position'       => (int) \FluentBoards\App\Models\Task::where('stage_id', $stage->id)->whereNull('parent_id')->max('position') + 1,
            'crm_contact_id' => $order['contact'] ? (int) $order['contact'] : null,
        );
        if ($order['user']) {
            $data['created_by'] = (int) $order['user'];
        } else {
            // As Fluent Boards' own Fluent Forms feed records a visitor.
            $data['settings'] = array('author' => array('name' => $client['name'], 'email' => $client['email'], 'cover' => array('backgroundColor' => ''), 'subtask_count' => 0));
        }
        $labels = array();
        foreach (\FluentBoards\App\Models\Label::where('board_id', $board_id)->whereNull('archived_at')->get() as $label) {
            if (self::named_in($form->title, $label->title)) {
                $labels[] = (int) $label->id;
            }
        }
        $data['labels'] = $labels;
        if (class_exists('FluentBoards\App\Services\Helper') && method_exists('FluentBoards\App\Services\Helper', 'sanitizeTask')) {
            $data = \FluentBoards\App\Services\Helper::sanitizeTask($data) + array('labels' => $labels);
        }
        $task = (new \FluentBoards\App\Models\Task())->createTask($data);
        if (!$task || empty($task->id)) {
            return new WP_Error('seoprostack_task_failed', __('Fluent Boards did not add the task.', 'seoprostack'));
        }
        do_action('fluent_boards/task_added_from_fluent_form', $task); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Boards' own hook: records "added from Fluent Forms" in the task's activity.
        return (int) $task->id;
    }

    /**
     * Open a Fluent Support conversation for the order, from the client.
     *
     * @param object $entry  Entry.
     * @param object $form   Form.
     * @param array  $client Client.
     * @param string $title  Title.
     * @param array  $lines  Details.
     * @return int|WP_Error
     */
    private static function open_ticket($entry, $form, array $client, $title, array $lines) {
        if ('' === $client['email'] || !function_exists('FluentSupportApi') || !class_exists('FluentSupport\App\Models\Customer')) {
            return 0;
        }
        $customer = \FluentSupport\App\Models\Customer::maybeCreateCustomer(array(
            'email'           => $client['email'],
            'first_name'      => $client['first'],
            'last_name'       => $client['last'],
            'last_ip_address' => (string) $entry->ip,
        ));
        if (!$customer) {
            return new WP_Error('seoprostack_no_customer', __('Fluent Support did not add the client.', 'seoprostack'));
        }
        $content = '<p>' . esc_html__('Details:', 'seoprostack') . '</p><ul>';
        foreach ($lines as $label => $value) {
            $content .= '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '</li>';
        }
        $content .= '</ul>';
        $data = array(
            'customer_id' => (int) $customer->id,
            'title'       => $title,
            'content'     => $content,
            'source'      => 'web',
        );
        if (class_exists('FluentSupport\App\Models\Product')) {
            // Named in the form's title ("SEO Audit Order Form"), else an
            // answer that is exactly a product's name ("Which service?").
            $answers  = array_map('strtolower', array_map('trim', array_values($lines)));
            $products = \FluentSupport\App\Models\Product::orderBy('id', 'ASC')->get();
            foreach ($products as $product) {
                if (self::named_in($form->title, $product->title)) {
                    $data['product_id'] = (int) $product->id;
                    break;
                }
            }
            if (empty($data['product_id'])) {
                foreach ($products as $product) {
                    if (in_array(strtolower(trim((string) $product->title)), $answers, true)) {
                        $data['product_id'] = (int) $product->id;
                        break;
                    }
                }
            }
        }
        $ticket = FluentSupportApi('tickets')->createTicket($data);
        if (!$ticket || empty($ticket->id)) {
            return new WP_Error('seoprostack_ticket_failed', __('Fluent Support did not open the conversation.', 'seoprostack'));
        }
        return (int) $ticket->id;
    }

    /**
     * Enrol the client in the chosen Tutor LMS course.
     *
     * @param int $user_id User ID.
     * @return int Enrolment ID, or 0.
     */
    private static function enrol($user_id) {
        $course = (int) SEOProStack_Settings::get('agency_orders_course');
        if (!$course || !function_exists('tutor_utils')) {
            return 0;
        }
        if (class_exists('Tutor\Models\EnrollmentModel')) {
            // A client ordering again is already enrolled.
            if (\Tutor\Models\EnrollmentModel::is_enrolled($course, (int) $user_id)) {
                return 0;
            }
            return (int) \Tutor\Models\EnrollmentModel::do_enroll($course, 0, (int) $user_id);
        }
        return method_exists(tutor_utils(), 'do_enroll') ? (int) tutor_utils()->do_enroll($course, 0, (int) $user_id) : 0;
    }

    /**
     * Add the client to the chosen FluentCommunity space.
     *
     * @param int $user_id User ID.
     * @return int Space ID, or 0.
     */
    private static function join_space($user_id) {
        $space = (int) SEOProStack_Settings::get('agency_orders_space');
        if (!$space || !class_exists('FluentCommunity\App\Services\Helper')) {
            return 0;
        }
        return \FluentCommunity\App\Services\Helper::addToSpace($space, (int) $user_id, 'member', 'by_admin') ? $space : 0;
    }

    /**
     * The order an entry of a task belongs to.
     *
     * @param object $task Task.
     * @return array|null
     */
    public static function task_order($task) {
        $raw   = $task->getMeta(self::TASK_META);
        $order = is_array($raw) ? $raw : json_decode((string) $raw, true);
        return is_array($order) ? $order : null;
    }

    /**
     * A task moved stage: tell the client in the order's conversation.
     *
     * @param object $task         Task.
     * @param int    $old_stage_id Previous stage.
     */
    public static function stage_changed($task, $old_stage_id) {
        if (!is_object($task) || empty($task->id) || (int) $task->stage_id === (int) $old_stage_id) {
            return;
        }
        if (!function_exists('FluentSupportApi')) {
            return;
        }
        $order = self::task_order($task);
        if (!$order || empty($order['ticket'])) {
            return;
        }
        $stage = \FluentBoards\App\Models\Stage::find((int) $task->stage_id);
        if (!$stage) {
            return;
        }
        $agent = self::agent();
        $entry = !empty($order['entry']) ? \FluentForm\App\Models\Submission::find((int) $order['entry']) : null;
        $form  = $entry ? \FluentForm\App\Models\Form::find((int) $entry->form_id) : null;
        if (!$agent) {
            if ($entry && $form) {
                self::log($entry, $form, 'failed', __('Client update', 'seoprostack'), __('Fluent Support has no agent to send it. Open Fluent Support once.', 'seoprostack'));
            }
            return;
        }
        $service = !empty($order['service']) ? (string) $order['service'] : (string) $task->title;
        /* translators: 1: what was ordered, such as SEO Audit, 2: stage, such as In progress */
        $message = sprintf(__('Your order “%1$s” has moved to: %2$s.', 'seoprostack'), $service, (string) $stage->title);
        /**
         * Filters the reply that tells a client their order moved stage.
         *
         * @param string $message Plain text.
         * @param object $task    Fluent Boards task.
         * @param object $stage   Its new stage.
         * @param array  $order   The order: service, entry, form, ticket, user, email, name.
         */
        $message = (string) apply_filters('seoprostack_agency_update_message', $message, $task, $stage, $order);
        try {
            FluentSupportApi('tickets')->addResponse(array('content' => wpautop(esc_html($message)), 'conversation_type' => 'response'), (int) $agent->id, (int) $order['ticket']);
        } catch (Throwable $e) {
            if ($entry && $form) {
                self::log($entry, $form, 'failed', __('Client update', 'seoprostack'), $e->getMessage());
            }
        }
    }

    /**
     * Fluent Support agent who replies: the person moving the task if they
     * are an agent (an administrator becomes one, as when they first open
     * Fluent Support), else the first agent.
     *
     * @return object|null
     */
    private static function agent() {
        if (!class_exists('FluentSupport\App\Models\Agent')) {
            return null;
        }
        $user_id = get_current_user_id();
        $agent   = $user_id ? \FluentSupport\App\Models\Agent::where('user_id', $user_id)->first() : null;
        if (!$agent && $user_id && current_user_can('manage_options')) {
            $user  = wp_get_current_user();
            $agent = \FluentSupport\App\Models\Agent::create(array(
                'email'      => $user->user_email,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'user_id'    => $user->ID,
            ));
        }
        return $agent ? $agent : \FluentSupport\App\Models\Agent::orderBy('id', 'ASC')->first();
    }

    /**
     * A project brief: add it to the client's latest order.
     *
     * @param object $entry Entry.
     * @param object $form  Form.
     */
    private static function add_brief($entry, $form) {
        $client = self::client($entry);
        if ('' === $client['email'] || !class_exists('FluentBoards\App\Models\TaskMeta')) {
            return;
        }
        $meta = \FluentBoards\App\Models\TaskMeta::where('key', self::CLIENT_META)->where('value', strtolower($client['email']))->orderBy('task_id', 'DESC')->first();
        $task = $meta ? \FluentBoards\App\Models\Task::find((int) $meta->task_id) : null;
        if (!$task) {
            self::log($entry, $form, 'failed', __('Project brief', 'seoprostack'), __('No order found for this email address.', 'seoprostack'));
            return;
        }
        $lines = self::details($entry, $form);
        $html  = '<p><strong>' . esc_html((string) $form->title) . '</strong></p>';
        foreach ($lines as $label => $value) {
            $html .= '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '</p>';
        }
        self::step($entry, $form, 'Fluent Boards', function () use ($task, $client, $html, $entry) {
            $comment = \FluentBoards\App\Models\Comment::create(array(
                'board_id'     => (int) $task->board_id,
                'task_id'      => (int) $task->id,
                'type'         => 'comment',
                'privacy'      => 'public',
                'status'       => 'published',
                'author_name'  => '' !== $client['name'] ? $client['name'] : $client['email'],
                'author_email' => $client['email'],
                'author_ip'    => (string) $entry->ip,
                'description'  => $html,
                'created_by'   => (int) $entry->user_id,
            ));
            do_action('fluent_boards/comment_created', $comment); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Boards' own hook, as its comment code fires it.
            return (int) $comment->id;
        });
        $order = self::task_order($task);
        if ($order && !empty($order['ticket']) && class_exists('FluentSupport\App\Services\Tickets\ResponseService')) {
            self::step($entry, $form, 'Fluent Support', function () use ($order, $client, $html) {
                $ticket   = \FluentSupport\App\Models\Ticket::find((int) $order['ticket']);
                $customer = \FluentSupport\App\Models\Customer::where('email', $client['email'])->first();
                if (!$ticket || !$customer) {
                    return 0;
                }
                $result = (new \FluentSupport\App\Services\Tickets\ResponseService())->createResponse(array('content' => $html, 'conversation_type' => 'response'), $customer, $ticket);
                return is_array($result) && isset($result['response']->id) ? (int) $result['response']->id : 0;
            });
        }
    }
}
