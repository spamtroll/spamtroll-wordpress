<?php

declare(strict_types=1);
/**
 * Spamtroll Admin
 *
 * @package Spamtroll
 *
 * @since   0.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin settings page, logs viewer, and AJAX handlers.
 */
class Spamtroll_Admin
{
    /**
     * Prefix of the placeholder shown in place of a saved API key.
     */
    public const MASK_MARKER = '••••';

    /**
     * A saved API key as something safe to render.
     *
     * Returns an empty string when nothing is stored, so a first-time setup
     * still gets an empty box to type into.
     */
    public static function mask(string $key): string
    {
        if ('' === $key) {
            return '';
        }
        return self::MASK_MARKER . substr($key, -4);
    }

    /**
     * Initialize admin hooks.
     */
    public function init(): void
    {
        add_action('admin_menu', [ $this, 'add_menu' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);
        add_action('wp_ajax_spamtroll_test_connection', [ $this, 'ajax_test_connection' ]);
        add_filter('plugin_action_links_' . SPAMTROLL_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ]);

        // A plugin that has quietly stopped protecting the site has to say
        // so somewhere the administrator actually looks.
        (new Spamtroll_Health())->init();
    }

    /**
     * Add admin menu pages.
     */
    public function add_menu(): void
    {
        add_menu_page(
            __('Spamtroll', 'spamtroll'),
            __('Spamtroll', 'spamtroll'),
            'manage_options',
            'spamtroll',
            [ $this, 'render_settings_page' ],
            'dashicons-shield',
            80,
        );

        add_submenu_page(
            'spamtroll',
            __('Settings', 'spamtroll'),
            __('Settings', 'spamtroll'),
            'manage_options',
            'spamtroll',
            [ $this, 'render_settings_page' ],
        );

        add_submenu_page(
            'spamtroll',
            __('Logs', 'spamtroll'),
            __('Logs', 'spamtroll'),
            'manage_options',
            'spamtroll-logs',
            [ $this, 'render_logs_page' ],
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings(): void
    {
        register_setting('spamtroll_settings_group', 'spamtroll_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ]);

        // API Configuration section.
        add_settings_section(
            'spamtroll_api',
            __('API Configuration', 'spamtroll'),
            [ $this, 'render_section_api' ],
            'spamtroll',
        );

        add_settings_field('enabled', __('Enable Plugin', 'spamtroll'), [ $this, 'render_field_enabled' ], 'spamtroll', 'spamtroll_api');
        add_settings_field('api_key', __('API Key', 'spamtroll'), [ $this, 'render_field_api_key' ], 'spamtroll', 'spamtroll_api');

        // Detection Settings section. Kept small: what to scan +
        // one sensitivity preset. Numeric thresholds and per-status
        // action matrix are pinned to safe defaults in
        // sanitize_settings() so typical admins never see them.
        add_settings_section(
            'spamtroll_detection',
            __('Detection Settings', 'spamtroll'),
            [ $this, 'render_section_detection' ],
            'spamtroll',
        );

        add_settings_field('check_comments', __('Check Comments', 'spamtroll'), [ $this, 'render_field_check_comments' ], 'spamtroll', 'spamtroll_detection');
        add_settings_field('check_registrations', __('Check Registrations', 'spamtroll'), [ $this, 'render_field_check_registrations' ], 'spamtroll', 'spamtroll_detection');
        add_settings_field('sensitivity', __('Sensitivity', 'spamtroll'), [ $this, 'render_field_sensitivity' ], 'spamtroll', 'spamtroll_detection');

        // Bypass Settings section.
        add_settings_section(
            'spamtroll_bypass',
            __('Bypass Settings', 'spamtroll'),
            [ $this, 'render_section_bypass' ],
            'spamtroll',
        );

        add_settings_field('bypass_roles', __('Bypass Roles', 'spamtroll'), [ $this, 'render_field_bypass_roles' ], 'spamtroll', 'spamtroll_bypass');

        // Advanced section. Small, but every field here was previously
        // rendered nowhere and pinned to a constant on save — which meant a
        // self-hosted or staging instance could not be pointed at, and an
        // operator who had once set a custom URL had it wiped the first time
        // they pressed Save.
        add_settings_section(
            'spamtroll_advanced',
            __('Advanced', 'spamtroll'),
            [ $this, 'render_section_advanced' ],
            'spamtroll',
        );

        add_settings_field('api_url', __('API URL', 'spamtroll'), [ $this, 'render_field_api_url' ], 'spamtroll', 'spamtroll_advanced');
        add_settings_field('timeout', __('Latency budget (seconds)', 'spamtroll'), [ $this, 'render_field_timeout' ], 'spamtroll', 'spamtroll_advanced');
        add_settings_field('trust_proxy', __('Behind a proxy or CDN', 'spamtroll'), [ $this, 'render_field_trust_proxy' ], 'spamtroll', 'spamtroll_advanced');
        add_settings_field('send_feedback', __('Send moderator feedback', 'spamtroll'), [ $this, 'render_field_send_feedback' ], 'spamtroll', 'spamtroll_advanced');
        add_settings_field('log_retention_days', __('Log Retention (days)', 'spamtroll'), [ $this, 'render_field_log_retention_days' ], 'spamtroll', 'spamtroll_advanced');
    }

    /**
     * Sanitize settings on save.
     *
     * @param array<string, mixed>|mixed $input Raw input.
     *
     * @return array<string, mixed> Sanitized settings.
     */
    public function sanitize_settings($input): array
    {
        if (! is_array($input)) {
            $input = [];
        }
        $sanitized = [];

        $stored = Spamtroll_Settings::all();

        $sanitized['enabled'] = ! empty($input['enabled']) ? 1 : 0;
        $sanitized['check_comments'] = ! empty($input['check_comments']) ? 1 : 0;
        $sanitized['check_registrations'] = ! empty($input['check_registrations']) ? 1 : 0;
        $sanitized['send_feedback'] = ! empty($input['send_feedback']) ? 1 : 0;
        $sanitized['trust_proxy'] = ! empty($input['trust_proxy']) ? 1 : 0;

        // The key field renders as a mask once one is saved, so an untouched
        // form posts the mask back. Treat that — and an empty box — as "leave
        // what is stored alone"; only a real value replaces the key.
        $submitted_key = isset($input['api_key']) && is_scalar($input['api_key'])
            ? sanitize_text_field((string) $input['api_key'])
            : '';
        $stored_key = isset($stored['api_key']) && is_scalar($stored['api_key']) ? (string) $stored['api_key'] : '';
        $sanitized['api_key'] = ('' === $submitted_key || self::mask($stored_key) === $submitted_key)
            ? $stored_key
            : $submitted_key;

        // Sensitivity selects a column of Spamtroll_Action_Map's table. It no
        // longer maps to numeric thresholds, because the plugin no longer
        // re-derives the verdict — the backend's `status` decides, and this
        // only says how borderline content is treated.
        $sensitivity = isset($input['sensitivity']) && is_string($input['sensitivity'])
            ? $input['sensitivity']
            : Spamtroll_Action_Map::PRESET_BALANCED;
        $sanitized['sensitivity'] = in_array($sensitivity, Spamtroll_Action_Map::presets(), true)
            ? $sensitivity
            : Spamtroll_Action_Map::PRESET_BALANCED;

        $sanitized['api_url'] = isset($input['api_url']) && is_scalar($input['api_url'])
            ? esc_url_raw(trim((string) $input['api_url']))
            : \Spamtroll\Sdk\ClientConfig::DEFAULT_BASE_URL;
        if ('' === $sanitized['api_url']) {
            $sanitized['api_url'] = \Spamtroll\Sdk\ClientConfig::DEFAULT_BASE_URL;
        }

        $sanitized['timeout'] = max(
            Spamtroll_Settings::MIN_TIMEOUT,
            min(
                Spamtroll_Settings::MAX_TIMEOUT,
                isset($input['timeout']) && is_numeric($input['timeout'])
                    ? (int) $input['timeout']
                    : Spamtroll_Settings::DEFAULT_TIMEOUT,
            ),
        );

        $sanitized['log_retention_days'] = max(
            Spamtroll_Settings::MIN_RETENTION_DAYS,
            min(
                Spamtroll_Settings::MAX_RETENTION_DAYS,
                isset($input['log_retention_days']) && is_numeric($input['log_retention_days'])
                    ? (int) $input['log_retention_days']
                    : Spamtroll_Settings::DEFAULT_RETENTION_DAYS,
            ),
        );

        // Bypass roles. An administrator who unticks every role means it:
        // the submitted-but-empty case has to survive, so the key is always
        // written and the defaults only apply on a first save.
        $valid_roles = array_keys(wp_roles()->roles);
        if (isset($input['bypass_roles']) && is_array($input['bypass_roles'])) {
            $bypass = array_values(array_filter($input['bypass_roles'], 'is_string'));
            $sanitized['bypass_roles'] = array_values(array_intersect($bypass, $valid_roles));
        } elseif (array_key_exists('bypass_roles', $stored)) {
            // The checkboxes are on the form; a POST without them means every
            // box was cleared, not that the section was never rendered.
            $sanitized['bypass_roles'] = [];
        } else {
            $sanitized['bypass_roles'] = Spamtroll_Settings::DEFAULT_BYPASS_ROLES;
        }

        return $sanitized;
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        if (!str_contains($hook_suffix, 'spamtroll')) {
            return;
        }

        wp_enqueue_style('spamtroll-admin', SPAMTROLL_PLUGIN_URL . 'assets/css/admin.css', [], SPAMTROLL_VERSION);
        wp_enqueue_script('spamtroll-admin', SPAMTROLL_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], SPAMTROLL_VERSION, true);
        wp_localize_script('spamtroll-admin', 'spamtrollAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('spamtroll_test_connection'),
            'i18n' => [
                'testing' => __('Testing connection...', 'spamtroll'),
                'success' => __('Connection successful!', 'spamtroll'),
                'error' => __('Connection failed: ', 'spamtroll'),
                'ajaxError' => __('Request failed. Please try again.', 'spamtroll'),
            ],
        ]);
    }

    /**
     * AJAX handler for testing API connection.
     */
    public function ajax_test_connection(): void
    {
        check_ajax_referer('spamtroll_test_connection', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error([ 'message' => __('Permission denied.', 'spamtroll') ]);
        }

        // Test the key in the form, not the one in the database. Pasting a
        // fresh key and pressing the button used to report on the old one,
        // which is the one answer the button must never give.
        $posted = isset($_POST['api_key']) && is_scalar($_POST['api_key'])
            ? sanitize_text_field(wp_unslash((string) $_POST['api_key']))
            : '';
        $override = ('' !== $posted && self::MASK_MARKER !== substr($posted, 0, strlen(self::MASK_MARKER))) ? $posted : null;

        try {
            $response = Spamtroll_Sdk_Factory::client($override)->testConnection();

            if ($response->isConnectionValid()) {
                // An operator getting an answer is the clearest possible
                // signal that whatever was wrong has stopped being wrong.
                (new Spamtroll_Circuit_Breaker())->reset();
                Spamtroll_Health::record_success();
                wp_send_json_success([ 'message' => __('Connection successful! API is reachable.', 'spamtroll') ]);
            }

            wp_send_json_error([ 'message' => $response->error ? $response->error : __('API returned an unexpected response.', 'spamtroll') ]);
        } catch (\Spamtroll\Sdk\Exception\SpamtrollException $e) {
            wp_send_json_error([ 'message' => $e->getMessage() ]);
        } catch (\Throwable $e) {
            // Without this the AJAX handler answers an unexpected error with
            // an empty 500 and the button spins forever.
            wp_send_json_error([ 'message' => __('Unexpected error while testing the connection.', 'spamtroll') ]);
        }
    }

    /**
     * Add "Settings" link to the plugins list page.
     *
     * @param array<int|string, string> $links Existing action links.
     *
     * @return array<int|string, string> Modified links.
     */
    public function plugin_action_links(array $links): array
    {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=spamtroll')) . '">' . __('Settings', 'spamtroll') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    // -------------------------------------------------------------------------
    // Section descriptions
    // -------------------------------------------------------------------------

    /**
     * Render API section description.
     */
    public function render_section_api(): void
    {
        echo '<p>' . esc_html__('Configure your Spamtroll API credentials. Get your API key at spamtroll.io.', 'spamtroll') . '</p>';
    }

    /**
     * Render detection section description.
     */
    public function render_section_detection(): void
    {
        echo '<p>' . esc_html__('Choose what to scan, and how borderline content is treated. The spam verdict itself is decided by the Spamtroll API using the thresholds configured for this platform in your dashboard.', 'spamtroll') . '</p>';
    }

    /**
     * Render bypass section description.
     */
    public function render_section_bypass(): void
    {
        echo '<p>' . esc_html__('Select user roles that bypass spam checks entirely.', 'spamtroll') . '</p>';
    }

    /**
     * Render advanced section description.
     */
    public function render_section_advanced(): void
    {
        echo '<p>' . esc_html__('Defaults suit almost every site. Change these only if you run a self-hosted Spamtroll instance, sit behind a proxy, or need a different log retention period.', 'spamtroll') . '</p>';
    }

    // -------------------------------------------------------------------------
    // Field renderers
    // -------------------------------------------------------------------------

    /**
     * Render enabled checkbox.
     */
    public function render_field_enabled(): void
    {
        $value = Spamtroll_Settings::int('enabled', 0);
        echo '<label><input type="checkbox" name="spamtroll_settings[enabled]" value="1" ' . checked(1, $value, false) . ' /> '
            . esc_html__('Enable Spamtroll spam detection', 'spamtroll') . '</label>';
    }

    /**
     * Render API key field.
     */
    public function render_field_api_key(): void
    {
        // The key is never printed in full. `type="password"` hides it from
        // a shoulder, not from View Source, a browser extension, or anything
        // else that can read the DOM of an admin page.
        $value = self::mask(Spamtroll_Settings::string('api_key'));
        echo '<input type="password" id="spamtroll-api-key" name="spamtroll_settings[api_key]" value="' . esc_attr($value) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description">' . esc_html__('Your Spamtroll API key.', 'spamtroll') . '</p>';
        echo '<p><button type="button" class="button" id="spamtroll-test-connection">' . esc_html__('Test Connection', 'spamtroll') . '</button>';
        echo ' <span id="spamtroll-test-result"></span></p>';
    }

    /**
     * Render API URL field.
     */
    public function render_field_api_url(): void
    {
        $value = Spamtroll_Settings::api_url();
        echo '<input type="url" name="spamtroll_settings[api_url]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Spamtroll API endpoint URL. Change only if using a self-hosted instance.', 'spamtroll') . '</p>';
    }

    /**
     * Render timeout field.
     */
    public function render_field_timeout(): void
    {
        $value = Spamtroll_Settings::timeout();
        echo '<input type="number" name="spamtroll_settings[timeout]" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) Spamtroll_Settings::MIN_TIMEOUT) . '" max="' . esc_attr((string) Spamtroll_Settings::MAX_TIMEOUT) . '" step="1" class="small-text" />';
        echo '<p class="description">' . esc_html__('Longest a visitor may wait for the whole spam check, retries included. When the budget runs out the content is allowed through unscanned.', 'spamtroll') . '</p>';
    }

    /**
     * Render the trusted-proxy checkbox.
     */
    public function render_field_trust_proxy(): void
    {
        $value = Spamtroll_Settings::int('trust_proxy', 0);
        echo '<label><input type="checkbox" name="spamtroll_settings[trust_proxy]" value="1" ' . checked(1, $value, false) . ' /> '
            . esc_html__('Read the visitor IP from X-Forwarded-For, X-Real-IP or CF-Connecting-IP', 'spamtroll') . '</label>';
        echo '<p class="description">' . esc_html__('Only tick this if the site really is behind Cloudflare, a load balancer or a reverse proxy. Anyone can send those headers, so on a directly exposed site this would let a spammer choose their own reputation.', 'spamtroll') . '</p>';
    }

    /**
     * Render the moderator-feedback checkbox.
     */
    public function render_field_send_feedback(): void
    {
        $value = Spamtroll_Settings::int('send_feedback', 1);
        echo '<label><input type="checkbox" name="spamtroll_settings[send_feedback]" value="1" ' . checked(1, $value, false) . ' /> '
            . esc_html__('Tell Spamtroll when a moderator marks a comment as spam or restores it', 'spamtroll') . '</label>';
        echo '<p class="description">' . esc_html__('Sends the comment identifier and your verdict — not the comment text — so detection improves for this platform.', 'spamtroll') . '</p>';
    }

    /**
     * Render check comments checkbox.
     */
    public function render_field_check_comments(): void
    {
        $value = Spamtroll_Settings::int('check_comments', 1);
        echo '<label><input type="checkbox" name="spamtroll_settings[check_comments]" value="1" ' . checked(1, $value, false) . ' /> '
            . esc_html__('Scan comments for spam', 'spamtroll') . '</label>';
    }

    /**
     * Render check registrations checkbox.
     */
    public function render_field_check_registrations(): void
    {
        $value = Spamtroll_Settings::int('check_registrations', 1);
        echo '<label><input type="checkbox" name="spamtroll_settings[check_registrations]" value="1" ' . checked(1, $value, false) . ' /> '
            . esc_html__('Scan user registrations for spam', 'spamtroll') . '</label>';
    }

    /**
     * Render sensitivity preset dropdown.
     */
    public function render_field_sensitivity(): void
    {
        $value = (new Spamtroll_Action_Map())->preset();
        $options = Spamtroll_Action_Map::describe();
        echo '<select name="spamtroll_settings[sensitivity]">';
        foreach ($options as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('How aggressively to treat borderline content.', 'spamtroll') . '</p>';
    }

    /**
     * Render bypass roles checkboxes.
     */
    public function render_field_bypass_roles(): void
    {
        $current = Spamtroll_Settings::bypass_roles();
        $roles = wp_roles()->roles;

        foreach ($roles as $slug => $role) {
            $checked = in_array($slug, $current, true);
            $name = is_array($role) && isset($role['name']) && is_string($role['name']) ? $role['name'] : (string) $slug;
            echo '<label style="display:block;margin-bottom:4px;">';
            echo '<input type="checkbox" name="spamtroll_settings[bypass_roles][]" value="' . esc_attr((string) $slug) . '" ' . checked(true, $checked, false) . ' /> ';
            echo esc_html(translate_user_role($name));
            echo '</label>';
        }
        echo '<p class="description">' . esc_html__('Users with selected roles will bypass spam checks.', 'spamtroll') . '</p>';
    }

    /**
     * Render log retention field.
     */
    public function render_field_log_retention_days(): void
    {
        $value = Spamtroll_Settings::retention_days();
        echo '<input type="number" name="spamtroll_settings[log_retention_days]" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) Spamtroll_Settings::MIN_RETENTION_DAYS) . '" max="' . esc_attr((string) Spamtroll_Settings::MAX_RETENTION_DAYS) . '" step="1" class="small-text" />';
        echo '<p class="description">' . esc_html__('How long scan results — including IP and email addresses — are kept before the daily cleanup deletes them (1-365).', 'spamtroll') . '</p>';
    }

    // -------------------------------------------------------------------------
    // Page renderers
    // -------------------------------------------------------------------------

    /**
     * Render the settings page.
     */
    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // WordPress only auto-renders settings_errors() on the
        // options-*.php pages (Settings → ...). Custom top-level admin
        // pages have to print them manually, otherwise the user gets
        // no "Settings saved." feedback after submitting the form.
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'spamtroll_settings_group',
                'spamtroll_settings_saved',
                __('Settings saved.', 'spamtroll'),
                'updated',
            );
        }
        settings_errors('spamtroll_settings_group');
        ?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
			<?php $this->render_health_panel(); ?>
			<?php $this->render_quota_skipped_panel(); ?>
			<form method="post" action="options.php">
				<?php
                settings_fields('spamtroll_settings_group');
        do_settings_sections('spamtroll');
        submit_button();
        ?>
			</form>
		</div>
		<?php
    }

    /**
     * Render the current API fault, if any, at the top of the settings screen.
     *
     * The site-wide notice covers the loud states; this one also shows the
     * quieter ones — a rate limit, an odd answer — to whoever came looking.
     */
    private function render_health_panel(): void
    {
        $described = Spamtroll_Health::describe();
        if (null === $described) {
            return;
        }

        $status = Spamtroll_Health::status();
        $seen = $status['at'] > 0
            ? sprintf(
                /* translators: %s: human-readable time difference, e.g. "5 mins" */
                __('Last seen %s ago.', 'spamtroll'),
                human_time_diff($status['at']),
            )
            : '';

        printf(
            '<div class="notice notice-%1$s"><p><strong>%2$s</strong></p><p>%3$s</p><p><em>%4$s</em></p></div>',
            esc_attr($described['severity']),
            esc_html($described['title']),
            esc_html($described['body']),
            esc_html(trim($seen . ' ' . $status['message'])),
        );
    }

    /**
     * Render the "messages skipped due to quota" callout. Only shown
     * when there's at least one skipped scan in the trailing 7 days,
     * so users on a healthy plan don't see noise. Sources its data
     * from the rolling local log Spamtroll_Scanner writes on every
     * 402 response from the API — no extra HTTP needed.
     */
    private function render_quota_skipped_panel(): void
    {
        $stats = Spamtroll_Scanner::get_skipped_quota_stats(7);
        if (0 === $stats['total']) {
            return;
        }

        $usage = $stats['last_usage'];
        $current = isset($usage['current']) && is_numeric($usage['current']) ? (int) $usage['current'] : 0;
        $limit = isset($usage['limit']) && is_numeric($usage['limit']) ? (int) $usage['limit'] : 0;
        $plan = isset($usage['plan']) && is_string($usage['plan']) ? $usage['plan'] : 'free';

        $summary = sprintf(
            /* translators: %1$d: count of skipped scans, %2$d: window in days */
            esc_html__('In the last %2$d days, %1$d incoming messages were allowed through without spam scanning because your Spamtroll daily quota was exhausted. They were not blocked — but they were not checked either.', 'spamtroll'),
            (int) $stats['total'],
            7,
        );

        $reading = $limit > 0
            ? sprintf(
                /* translators: %1$d current, %2$d limit, %3$s plan name */
                esc_html__('Last reading from API: %1$d / %2$d scans on the %3$s plan.', 'spamtroll'),
                $current,
                $limit,
                esc_html($plan),
            )
            : '';

        $breakdown = '';
        if ([] !== $stats['days']) {
            $items = '';
            foreach ($stats['days'] as $day => $count) {
                $items .= '<li>' . esc_html($day . ' — ' . $count) . '</li>';
            }
            $breakdown = '<details style="margin-top:8px;"><summary>'
                . esc_html__('Per-day breakdown', 'spamtroll')
                . '</summary><ul style="margin:8px 0 0 24px;">' . $items . '</ul></details>';
        }

        printf(
            '<div class="notice notice-warning" style="margin:16px 0;padding:16px;">'
                . '<h3 style="margin-top:0;">%1$s</h3><p>%2$s</p>%3$s'
                . '<p><a class="button button-primary" href="%4$s" target="_blank" rel="noopener">%5$s</a></p>%6$s</div>',
            esc_html__('Some messages were not scanned — daily quota reached', 'spamtroll'),
            $summary,
            '' !== $reading ? '<p>' . $reading . '</p>' : '',
            esc_url('https://spamtroll.io/dashboard/billing'),
            esc_html__('Upgrade your plan', 'spamtroll'),
            $breakdown,
        );
    }

    /**
     * Render the logs page.
     */
    public function render_logs_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $status = isset($_GET['status']) && is_string($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        // `?paged=0` used to survive absint() as 0 and reach the query as
        // OFFSET -20 — a MySQL syntax error, an empty table, and a raw
        // database message on screen when WP_DEBUG_DISPLAY is on.
        $paged = isset($_GET['paged']) && is_numeric($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 20;

        $result = Spamtroll_Logger::get_recent_logs([
            'status' => $status,
            'per_page' => $per_page,
            'page' => $paged,
        ]);

        $logs = $result['logs'];
        $total = $result['total'];
        $total_pages = (int) ceil($total / $per_page);

        // One GROUP BY for all four filter tabs, where there used to be four
        // separate COUNT(*) queries on every render.
        $counts = Spamtroll_Logger::count_by_status();
        ?>
		<div class="wrap">
			<h1><?php esc_html_e('Spamtroll Logs', 'spamtroll'); ?></h1>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=spamtroll-logs')); ?>" <?php echo empty($status) ? 'class="current"' : ''; ?>><?php esc_html_e('All', 'spamtroll'); ?> <span class="count">(<?php echo esc_html((string) $counts['_all']); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=spamtroll-logs&status=blocked')); ?>" <?php echo 'blocked' === $status ? 'class="current"' : ''; ?>><?php esc_html_e('Blocked', 'spamtroll'); ?> <span class="count">(<?php echo esc_html((string) ($counts['blocked'] ?? 0)); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=spamtroll-logs&status=suspicious')); ?>" <?php echo 'suspicious' === $status ? 'class="current"' : ''; ?>><?php esc_html_e('Suspicious', 'spamtroll'); ?> <span class="count">(<?php echo esc_html((string) ($counts['suspicious'] ?? 0)); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=spamtroll-logs&status=safe')); ?>" <?php echo 'safe' === $status ? 'class="current"' : ''; ?>><?php esc_html_e('Safe', 'spamtroll'); ?> <span class="count">(<?php echo esc_html((string) ($counts['safe'] ?? 0)); ?>)</span></a></li>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:50px;"><?php esc_html_e('ID', 'spamtroll'); ?></th>
						<th><?php esc_html_e('Date', 'spamtroll'); ?></th>
						<th><?php esc_html_e('Type', 'spamtroll'); ?></th>
						<th><?php esc_html_e('IP', 'spamtroll'); ?></th>
						<th><?php esc_html_e('Status', 'spamtroll'); ?></th>
						<th><?php esc_html_e('Score', 'spamtroll'); ?></th>
						<th><?php esc_html_e('Action', 'spamtroll'); ?></th>
						<th><?php esc_html_e('Preview', 'spamtroll'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($logs)) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e('No log entries found.', 'spamtroll'); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ($logs as $log) :
						    $preview = isset($log['content_preview']) && is_string($log['content_preview']) ? $log['content_preview'] : '';
						    $cell = static fn (string $key): string => isset($log[$key]) && is_scalar($log[$key]) ? (string) $log[$key] : '';
						    ?>
							<tr>
								<td><?php echo esc_html($cell('id')); ?></td>
								<td><?php echo esc_html($cell('created_at')); ?></td>
								<td><?php echo esc_html($cell('content_type')); ?></td>
								<td><?php echo esc_html($cell('ip_address')); ?></td>
								<td><span class="spamtroll-badge spamtroll-badge--<?php echo esc_attr($cell('status')); ?>"><?php echo esc_html($cell('status')); ?></span></td>
								<td><?php echo esc_html(number_format(is_numeric($log['spam_score'] ?? null) ? (float) $log['spam_score'] : 0.0, 4)); ?> <small>(<?php echo esc_html(number_format(is_numeric($log['raw_score'] ?? null) ? (float) $log['raw_score'] : 0.0, 2)); ?>)</small></td>
								<td><?php echo esc_html($cell('action_taken')); ?></td>
								<td class="spamtroll-preview"><?php echo esc_html(mb_substr($preview, 0, 80)); ?><?php echo mb_strlen($preview) > 80 ? '&hellip;' : ''; ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ($total_pages > 1) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
                        $pagination = paginate_links([
						    'base' => add_query_arg('paged', '%#%', admin_url('admin.php?page=spamtroll-logs')),
						    'format' => '',
						    'current' => $paged,
						    'total' => $total_pages,
						    'type' => 'plain',
                        ]);
			    echo wp_kses_post($pagination);
			    ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
    }
}
