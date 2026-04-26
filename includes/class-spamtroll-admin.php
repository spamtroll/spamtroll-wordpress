<?php
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
     * Initialize admin hooks.
     */
    public function init(): void
    {
        add_action('admin_menu', [ $this, 'add_menu' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);
        add_action('wp_ajax_spamtroll_test_connection', [ $this, 'ajax_test_connection' ]);
        add_filter('plugin_action_links_' . SPAMTROLL_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ]);
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

        $sanitized['enabled'] = ! empty($input['enabled']) ? 1 : 0;
        $sanitized['api_key'] = isset($input['api_key']) && is_scalar($input['api_key']) ? sanitize_text_field((string) $input['api_key']) : '';
        $sanitized['check_comments'] = ! empty($input['check_comments']) ? 1 : 0;
        $sanitized['check_registrations'] = ! empty($input['check_registrations']) ? 1 : 0;

        // Sensitivity preset replaces the two numeric thresholds. Map
        // to the underlying 0.0-1.0 values the scanner uses internally
        // so we don't have to touch Spamtroll_Scanner::determine_*.
        $sensitivity = isset($input['sensitivity']) && is_string($input['sensitivity'])
            ? $input['sensitivity']
            : 'balanced';
        if (! in_array($sensitivity, [ 'lenient', 'balanced', 'strict' ], true)) {
            $sensitivity = 'balanced';
        }
        $sanitized['sensitivity'] = $sensitivity;
        switch ($sensitivity) {
            case 'strict':
                $sanitized['spam_threshold'] = 0.50;
                $sanitized['suspicious_threshold'] = 0.30;
                break;
            case 'lenient':
                $sanitized['spam_threshold'] = 0.85;
                $sanitized['suspicious_threshold'] = 0.60;
                break;
            case 'balanced':
            default:
                $sanitized['spam_threshold'] = 0.70;
                $sanitized['suspicious_threshold'] = 0.40;
                break;
        }

        // Pin the things nobody asked to customize.
        $sanitized['api_url'] = \Spamtroll\Sdk\ClientConfig::DEFAULT_BASE_URL;
        $sanitized['timeout'] = \Spamtroll\Sdk\ClientConfig::DEFAULT_TIMEOUT;
        $sanitized['action_blocked'] = 'block';
        $sanitized['action_suspicious'] = 'moderate';
        $sanitized['log_retention_days'] = 30;

        // Bypass roles — only allow valid WordPress roles.
        $valid_roles = array_keys(wp_roles()->roles);
        if (isset($input['bypass_roles']) && is_array($input['bypass_roles'])) {
            $bypass = array_values(array_filter($input['bypass_roles'], 'is_string'));
            $sanitized['bypass_roles'] = array_values(array_intersect($bypass, $valid_roles));
        } else {
            $sanitized['bypass_roles'] = [ 'administrator', 'editor' ];
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

        try {
            $client = Spamtroll_Sdk_Factory::client();
            $response = $client->testConnection();

            if ($response->isConnectionValid()) {
                wp_send_json_success([ 'message' => __('Connection successful! API is reachable.', 'spamtroll') ]);
            } else {
                wp_send_json_error([ 'message' => $response->error ? $response->error : __('API returned an unexpected response.', 'spamtroll') ]);
            }
        } catch (\Spamtroll\Sdk\Exception\SpamtrollException $e) {
            wp_send_json_error([ 'message' => $e->getMessage() ]);
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
        echo '<p>' . esc_html__('Choose what to scan and configure detection thresholds (0-1 scale, where 1 = definitely spam).', 'spamtroll') . '</p>';
    }

    /**
     * Render actions section description.
     */
    public function render_section_actions(): void
    {
        echo '<p>' . esc_html__('Define what happens when spam or suspicious content is detected.', 'spamtroll') . '</p>';
    }

    /**
     * Render bypass section description.
     */
    public function render_section_bypass(): void
    {
        echo '<p>' . esc_html__('Select user roles that bypass spam checks entirely.', 'spamtroll') . '</p>';
    }

    /**
     * Render maintenance section description.
     */
    public function render_section_maintenance(): void
    {
        echo '<p>' . esc_html__('Configure log retention and cleanup settings.', 'spamtroll') . '</p>';
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
        $value = Spamtroll_Settings::string('api_key');
        echo '<input type="password" name="spamtroll_settings[api_key]" value="' . esc_attr($value) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description">' . esc_html__('Your Spamtroll API key.', 'spamtroll') . '</p>';
        echo '<p><button type="button" class="button" id="spamtroll-test-connection">' . esc_html__('Test Connection', 'spamtroll') . '</button>';
        echo ' <span id="spamtroll-test-result"></span></p>';
    }

    /**
     * Render API URL field.
     */
    public function render_field_api_url(): void
    {
        $value = Spamtroll_Settings::string('api_url', 'https://api.spamtroll.io/api/v1');
        echo '<input type="url" name="spamtroll_settings[api_url]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Spamtroll API endpoint URL. Change only if using a self-hosted instance.', 'spamtroll') . '</p>';
    }

    /**
     * Render timeout field.
     */
    public function render_field_timeout(): void
    {
        $value = Spamtroll_Settings::int('timeout', 5);
        echo '<input type="number" name="spamtroll_settings[timeout]" value="' . esc_attr((string) $value) . '" min="1" max="30" step="1" class="small-text" />';
        echo '<p class="description">' . esc_html__('API request timeout in seconds (1-30).', 'spamtroll') . '</p>';
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
        $value = Spamtroll_Settings::string('sensitivity', 'balanced');
        $options = [
            'lenient' => __('Lenient — fewer false positives, lets more spam through', 'spamtroll'),
            'balanced' => __('Balanced (recommended)', 'spamtroll'),
            'strict' => __('Strict — blocks aggressively, more false positives', 'spamtroll'),
        ];
        echo '<select name="spamtroll_settings[sensitivity]">';
        foreach ($options as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('How aggressively to treat borderline content.', 'spamtroll') . '</p>';
    }

    /**
     * Render spam threshold field.
     */
    public function render_field_spam_threshold(): void
    {
        $value = Spamtroll_Settings::float('spam_threshold', 0.70);
        echo '<input type="number" name="spamtroll_settings[spam_threshold]" value="' . esc_attr((string) $value) . '" min="0" max="1" step="0.01" class="small-text" />';
        echo '<p class="description">' . esc_html__('Score at or above this threshold triggers the spam action (0-1).', 'spamtroll') . '</p>';
    }

    /**
     * Render suspicious threshold field.
     */
    public function render_field_suspicious_threshold(): void
    {
        $value = Spamtroll_Settings::float('suspicious_threshold', 0.40);
        echo '<input type="number" name="spamtroll_settings[suspicious_threshold]" value="' . esc_attr((string) $value) . '" min="0" max="1" step="0.01" class="small-text" />';
        echo '<p class="description">' . esc_html__('Score at or above this threshold (but below spam) triggers the suspicious action (0-1).', 'spamtroll') . '</p>';
    }

    /**
     * Render action for blocked content.
     */
    public function render_field_action_blocked(): void
    {
        $value = Spamtroll_Settings::string('action_blocked', 'block');
        echo '<select name="spamtroll_settings[action_blocked]">';
        echo '<option value="block" ' . selected('block', $value, false) . '>' . esc_html__('Block (mark as spam)', 'spamtroll') . '</option>';
        echo '<option value="moderate" ' . selected('moderate', $value, false) . '>' . esc_html__('Send to moderation', 'spamtroll') . '</option>';
        echo '</select>';
    }

    /**
     * Render action for suspicious content.
     */
    public function render_field_action_suspicious(): void
    {
        $value = Spamtroll_Settings::string('action_suspicious', 'moderate');
        echo '<select name="spamtroll_settings[action_suspicious]">';
        echo '<option value="moderate" ' . selected('moderate', $value, false) . '>' . esc_html__('Send to moderation', 'spamtroll') . '</option>';
        echo '<option value="allow" ' . selected('allow', $value, false) . '>' . esc_html__('Allow (log only)', 'spamtroll') . '</option>';
        echo '</select>';
    }

    /**
     * Render bypass roles checkboxes.
     */
    public function render_field_bypass_roles(): void
    {
        $current = Spamtroll_Settings::stringList('bypass_roles');
        if ($current === []) {
            $current = [ 'administrator', 'editor' ];
        }
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
        $value = Spamtroll_Settings::int('log_retention_days', 30);
        echo '<input type="number" name="spamtroll_settings[log_retention_days]" value="' . esc_attr((string) $value) . '" min="1" max="365" step="1" class="small-text" />';
        echo '<p class="description">' . esc_html__('Number of days to keep scan logs (1-365).', 'spamtroll') . '</p>';
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
     * Render the "messages skipped due to quota" callout. Only shown
     * when there's at least one skipped scan in the trailing 7 days,
     * so users on a healthy plan don't see noise. Sources its data
     * from the rolling local log Spamtroll_Scanner writes on every
     * 402 response from the API — no extra HTTP needed.
     */
    private function render_quota_skipped_panel(): void
    {
        $stats = Spamtroll_Scanner::get_skipped_quota_stats(7);
        if ($stats['total'] === 0) {
            return;
        }

        $usage = $stats['last_usage'];
        $current = isset($usage['current']) && is_numeric($usage['current']) ? (int) $usage['current'] : 0;
        $limit = isset($usage['limit']) && is_numeric($usage['limit']) ? (int) $usage['limit'] : 0;
        $plan = isset($usage['plan']) && is_string($usage['plan']) ? $usage['plan'] : 'free';

        $upgrade_url = 'https://spamtroll.io/dashboard/billing';

        ?>
		<div class="notice notice-warning" style="margin: 16px 0; padding: 16px;">
			<h3 style="margin-top: 0;">
				<?php esc_html_e('Some messages were not scanned — daily quota reached', 'spamtroll'); ?>
			</h3>
			<p>
				<?php
                /* translators: %1$d: count of skipped scans, %2$d: window in days */
                printf(
                    esc_html__(
                        'In the last %2$d days, %1$d incoming messages were allowed through without spam scanning because your Spamtroll daily quota was exhausted. They were not blocked — but they were not checked either.',
                        'spamtroll',
                    ),
                    (int) $stats['total'],
                    7,
                );
                ?>
			</p>
			<?php if ($limit > 0) : ?>
				<p>
					<?php
                    /* translators: %1$d current, %2$d limit, %3$s plan name */
                    printf(
                        esc_html__('Last reading from API: %1$d / %2$d scans on the %3$s plan.', 'spamtroll'),
                        $current,
                        $limit,
                        esc_html($plan),
                    );
                    ?>
				</p>
			<?php endif; ?>
			<p>
				<a class="button button-primary" href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener">
					<?php esc_html_e('Upgrade your plan', 'spamtroll'); ?>
				</a>
			</p>
			<?php if ($stats['days'] !== []) : ?>
				<details style="margin-top: 8px;">
					<summary><?php esc_html_e('Per-day breakdown', 'spamtroll'); ?></summary>
					<ul style="margin: 8px 0 0 24px;">
						<?php foreach ($stats['days'] as $day => $count) : ?>
							<li><?php echo esc_html($day . ' — ' . $count); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<?php
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
        $paged = isset($_GET['paged']) && is_numeric($_GET['paged']) ? absint($_GET['paged']) : 1;
        $per_page = 20;

        $result = Spamtroll_Logger::get_recent_logs([
            'status' => $status,
            'per_page' => $per_page,
            'page' => $paged,
        ]);

        $logs = $result['logs'];
        $total = $result['total'];
        $total_pages = (int) ceil($total / $per_page);

        // Count per status for filter tabs.
        $all_result = Spamtroll_Logger::get_recent_logs([ 'per_page' => 1, 'page' => 1 ]);
        $blocked_result = Spamtroll_Logger::get_recent_logs([ 'status' => 'blocked', 'per_page' => 1, 'page' => 1 ]);
        $suspicious_result = Spamtroll_Logger::get_recent_logs([ 'status' => 'suspicious', 'per_page' => 1, 'page' => 1 ]);
        $safe_result = Spamtroll_Logger::get_recent_logs([ 'status' => 'safe', 'per_page' => 1, 'page' => 1 ]);
        ?>
		<div class="wrap">
			<h1><?php esc_html_e('Spamtroll Logs', 'spamtroll'); ?></h1>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=spamtroll-logs')); ?>" <?php echo empty($status) ? 'class="current"' : ''; ?>><?php esc_html_e('All', 'spamtroll'); ?> <span class="count">(<?php echo esc_html((string) $all_result['total']); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=spamtroll-logs&status=blocked')); ?>" <?php echo 'blocked' === $status ? 'class="current"' : ''; ?>><?php esc_html_e('Blocked', 'spamtroll'); ?> <span class="count">(<?php echo esc_html((string) $blocked_result['total']); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=spamtroll-logs&status=suspicious')); ?>" <?php echo 'suspicious' === $status ? 'class="current"' : ''; ?>><?php esc_html_e('Suspicious', 'spamtroll'); ?> <span class="count">(<?php echo esc_html((string) $suspicious_result['total']); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=spamtroll-logs&status=safe')); ?>" <?php echo 'safe' === $status ? 'class="current"' : ''; ?>><?php esc_html_e('Safe', 'spamtroll'); ?> <span class="count">(<?php echo esc_html((string) $safe_result['total']); ?>)</span></a></li>
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
						    'base' => add_query_arg('paged', '%#%'),
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
