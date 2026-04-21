<?php
/**
 * Spamtroll Admin
 *
 * @package Spamtroll
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page, logs viewer, and AJAX handlers.
 */
class Spamtroll_Admin {

	/**
	 * Initialize admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_spamtroll_test_connection', array( $this, 'ajax_test_connection' ) );
		add_filter( 'plugin_action_links_' . SPAMTROLL_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Add admin menu pages.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Spamtroll', 'spamtroll' ),
			__( 'Spamtroll', 'spamtroll' ),
			'manage_options',
			'spamtroll',
			array( $this, 'render_settings_page' ),
			'dashicons-shield',
			80
		);

		add_submenu_page(
			'spamtroll',
			__( 'Settings', 'spamtroll' ),
			__( 'Settings', 'spamtroll' ),
			'manage_options',
			'spamtroll',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'spamtroll',
			__( 'Logs', 'spamtroll' ),
			__( 'Logs', 'spamtroll' ),
			'manage_options',
			'spamtroll-logs',
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'spamtroll_settings_group', 'spamtroll_settings', array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );

		// API Configuration section.
		add_settings_section(
			'spamtroll_api',
			__( 'API Configuration', 'spamtroll' ),
			array( $this, 'render_section_api' ),
			'spamtroll'
		);

		add_settings_field( 'enabled', __( 'Enable Plugin', 'spamtroll' ), array( $this, 'render_field_enabled' ), 'spamtroll', 'spamtroll_api' );
		add_settings_field( 'api_key', __( 'API Key', 'spamtroll' ), array( $this, 'render_field_api_key' ), 'spamtroll', 'spamtroll_api' );

		// Detection Settings section. Kept small: what to scan +
		// one sensitivity preset. Numeric thresholds and per-status
		// action matrix are pinned to safe defaults in
		// sanitize_settings() so typical admins never see them.
		add_settings_section(
			'spamtroll_detection',
			__( 'Detection Settings', 'spamtroll' ),
			array( $this, 'render_section_detection' ),
			'spamtroll'
		);

		add_settings_field( 'check_comments', __( 'Check Comments', 'spamtroll' ), array( $this, 'render_field_check_comments' ), 'spamtroll', 'spamtroll_detection' );
		add_settings_field( 'check_registrations', __( 'Check Registrations', 'spamtroll' ), array( $this, 'render_field_check_registrations' ), 'spamtroll', 'spamtroll_detection' );
		add_settings_field( 'sensitivity', __( 'Sensitivity', 'spamtroll' ), array( $this, 'render_field_sensitivity' ), 'spamtroll', 'spamtroll_detection' );

		// Bypass Settings section.
		add_settings_section(
			'spamtroll_bypass',
			__( 'Bypass Settings', 'spamtroll' ),
			array( $this, 'render_section_bypass' ),
			'spamtroll'
		);

		add_settings_field( 'bypass_roles', __( 'Bypass Roles', 'spamtroll' ), array( $this, 'render_field_bypass_roles' ), 'spamtroll', 'spamtroll_bypass' );
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		$sanitized['enabled']             = ! empty( $input['enabled'] ) ? 1 : 0;
		$sanitized['api_key']             = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
		$sanitized['check_comments']      = ! empty( $input['check_comments'] ) ? 1 : 0;
		$sanitized['check_registrations'] = ! empty( $input['check_registrations'] ) ? 1 : 0;

		// Sensitivity preset replaces the two numeric thresholds. Map
		// to the underlying 0.0-1.0 values the scanner uses internally
		// so we don't have to touch Spamtroll_Scanner::determine_*.
		$sensitivity = isset( $input['sensitivity'] ) ? $input['sensitivity'] : 'balanced';
		if ( ! in_array( $sensitivity, array( 'lenient', 'balanced', 'strict' ), true ) ) {
			$sensitivity = 'balanced';
		}
		$sanitized['sensitivity'] = $sensitivity;
		switch ( $sensitivity ) {
			case 'strict':
				$sanitized['spam_threshold']       = 0.50;
				$sanitized['suspicious_threshold'] = 0.30;
				break;
			case 'lenient':
				$sanitized['spam_threshold']       = 0.85;
				$sanitized['suspicious_threshold'] = 0.60;
				break;
			case 'balanced':
			default:
				$sanitized['spam_threshold']       = 0.70;
				$sanitized['suspicious_threshold'] = 0.40;
				break;
		}

		// Pin the things nobody asked to customize.
		$sanitized['api_url']            = Spamtroll_Api_Client::API_BASE_URL;
		$sanitized['timeout']            = Spamtroll_Api_Client::DEFAULT_TIMEOUT;
		$sanitized['action_blocked']     = 'block';
		$sanitized['action_suspicious']  = 'moderate';
		$sanitized['log_retention_days'] = 30;

		// Bypass roles — only allow valid WordPress roles.
		$valid_roles = array_keys( wp_roles()->roles );
		if ( isset( $input['bypass_roles'] ) && is_array( $input['bypass_roles'] ) ) {
			$sanitized['bypass_roles'] = array_values( array_intersect( $input['bypass_roles'], $valid_roles ) );
		} else {
			$sanitized['bypass_roles'] = array( 'administrator', 'editor' );
		}

		return $sanitized;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'spamtroll' ) ) {
			return;
		}

		wp_enqueue_style( 'spamtroll-admin', SPAMTROLL_PLUGIN_URL . 'assets/css/admin.css', array(), SPAMTROLL_VERSION );
		wp_enqueue_script( 'spamtroll-admin', SPAMTROLL_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), SPAMTROLL_VERSION, true );
		wp_localize_script( 'spamtroll-admin', 'spamtrollAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'spamtroll_test_connection' ),
			'i18n'    => array(
				'testing'    => __( 'Testing connection...', 'spamtroll' ),
				'success'    => __( 'Connection successful!', 'spamtroll' ),
				'error'      => __( 'Connection failed: ', 'spamtroll' ),
				'ajaxError'  => __( 'Request failed. Please try again.', 'spamtroll' ),
			),
		) );
	}

	/**
	 * AJAX handler for testing API connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'spamtroll_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'spamtroll' ) ) );
		}

		try {
			$client   = new Spamtroll_Api_Client();
			$response = $client->test_connection();

			if ( $response->is_connection_valid() ) {
				wp_send_json_success( array( 'message' => __( 'Connection successful! API is reachable.', 'spamtroll' ) ) );
			} else {
				wp_send_json_error( array( 'message' => $response->error ? $response->error : __( 'API returned an unexpected response.', 'spamtroll' ) ) );
			}
		} catch ( Spamtroll_Api_Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Add "Settings" link to the plugins list page.
	 *
	 * @param array $links Existing action links.
	 * @return array Modified links.
	 */
	public function plugin_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=spamtroll' ) ) . '">' . __( 'Settings', 'spamtroll' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	// -------------------------------------------------------------------------
	// Section descriptions
	// -------------------------------------------------------------------------

	/**
	 * Render API section description.
	 */
	public function render_section_api() {
		echo '<p>' . esc_html__( 'Configure your Spamtroll API credentials. Get your API key at spamtroll.io.', 'spamtroll' ) . '</p>';
	}

	/**
	 * Render detection section description.
	 */
	public function render_section_detection() {
		echo '<p>' . esc_html__( 'Choose what to scan and configure detection thresholds (0-1 scale, where 1 = definitely spam).', 'spamtroll' ) . '</p>';
	}

	/**
	 * Render actions section description.
	 */
	public function render_section_actions() {
		echo '<p>' . esc_html__( 'Define what happens when spam or suspicious content is detected.', 'spamtroll' ) . '</p>';
	}

	/**
	 * Render bypass section description.
	 */
	public function render_section_bypass() {
		echo '<p>' . esc_html__( 'Select user roles that bypass spam checks entirely.', 'spamtroll' ) . '</p>';
	}

	/**
	 * Render maintenance section description.
	 */
	public function render_section_maintenance() {
		echo '<p>' . esc_html__( 'Configure log retention and cleanup settings.', 'spamtroll' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/**
	 * Helper to get a setting value with default.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private function get_setting( $key, $default = '' ) {
		$settings = get_option( 'spamtroll_settings', array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Render enabled checkbox.
	 */
	public function render_field_enabled() {
		$value = $this->get_setting( 'enabled', 0 );
		echo '<label><input type="checkbox" name="spamtroll_settings[enabled]" value="1" ' . checked( 1, $value, false ) . ' /> '
			. esc_html__( 'Enable Spamtroll spam detection', 'spamtroll' ) . '</label>';
	}

	/**
	 * Render API key field.
	 */
	public function render_field_api_key() {
		$value = $this->get_setting( 'api_key', '' );
		echo '<input type="password" name="spamtroll_settings[api_key]" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'Your Spamtroll API key.', 'spamtroll' ) . '</p>';
		echo '<p><button type="button" class="button" id="spamtroll-test-connection">' . esc_html__( 'Test Connection', 'spamtroll' ) . '</button>';
		echo ' <span id="spamtroll-test-result"></span></p>';
	}

	/**
	 * Render API URL field.
	 */
	public function render_field_api_url() {
		$value = $this->get_setting( 'api_url', 'https://api.spamtroll.io/api/v1' );
		echo '<input type="url" name="spamtroll_settings[api_url]" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Spamtroll API endpoint URL. Change only if using a self-hosted instance.', 'spamtroll' ) . '</p>';
	}

	/**
	 * Render timeout field.
	 */
	public function render_field_timeout() {
		$value = $this->get_setting( 'timeout', 5 );
		echo '<input type="number" name="spamtroll_settings[timeout]" value="' . esc_attr( $value ) . '" min="1" max="30" step="1" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'API request timeout in seconds (1-30).', 'spamtroll' ) . '</p>';
	}

	/**
	 * Render check comments checkbox.
	 */
	public function render_field_check_comments() {
		$value = $this->get_setting( 'check_comments', 1 );
		echo '<label><input type="checkbox" name="spamtroll_settings[check_comments]" value="1" ' . checked( 1, $value, false ) . ' /> '
			. esc_html__( 'Scan comments for spam', 'spamtroll' ) . '</label>';
	}

	/**
	 * Render check registrations checkbox.
	 */
	public function render_field_check_registrations() {
		$value = $this->get_setting( 'check_registrations', 1 );
		echo '<label><input type="checkbox" name="spamtroll_settings[check_registrations]" value="1" ' . checked( 1, $value, false ) . ' /> '
			. esc_html__( 'Scan user registrations for spam', 'spamtroll' ) . '</label>';
	}

	/**
	 * Render sensitivity preset dropdown.
	 */
	public function render_field_sensitivity() {
		$value   = $this->get_setting( 'sensitivity', 'balanced' );
		$options = array(
			'lenient'  => __( 'Lenient — fewer false positives, lets more spam through', 'spamtroll' ),
			'balanced' => __( 'Balanced (recommended)', 'spamtroll' ),
			'strict'   => __( 'Strict — blocks aggressively, more false positives', 'spamtroll' ),
		);
		echo '<select name="spamtroll_settings[sensitivity]">';
		foreach ( $options as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'How aggressively to treat borderline content.', 'spamtroll' ) . '</p>';
	}

	/**
	 * Render spam threshold field.
	 */
	public function render_field_spam_threshold() {
		$value = $this->get_setting( 'spam_threshold', 0.70 );
		echo '<input type="number" name="spamtroll_settings[spam_threshold]" value="' . esc_attr( $value ) . '" min="0" max="1" step="0.01" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Score at or above this threshold triggers the spam action (0-1).', 'spamtroll' ) . '</p>';
	}

	/**
	 * Render suspicious threshold field.
	 */
	public function render_field_suspicious_threshold() {
		$value = $this->get_setting( 'suspicious_threshold', 0.40 );
		echo '<input type="number" name="spamtroll_settings[suspicious_threshold]" value="' . esc_attr( $value ) . '" min="0" max="1" step="0.01" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Score at or above this threshold (but below spam) triggers the suspicious action (0-1).', 'spamtroll' ) . '</p>';
	}

	/**
	 * Render action for blocked content.
	 */
	public function render_field_action_blocked() {
		$value = $this->get_setting( 'action_blocked', 'block' );
		echo '<select name="spamtroll_settings[action_blocked]">';
		echo '<option value="block" ' . selected( 'block', $value, false ) . '>' . esc_html__( 'Block (mark as spam)', 'spamtroll' ) . '</option>';
		echo '<option value="moderate" ' . selected( 'moderate', $value, false ) . '>' . esc_html__( 'Send to moderation', 'spamtroll' ) . '</option>';
		echo '</select>';
	}

	/**
	 * Render action for suspicious content.
	 */
	public function render_field_action_suspicious() {
		$value = $this->get_setting( 'action_suspicious', 'moderate' );
		echo '<select name="spamtroll_settings[action_suspicious]">';
		echo '<option value="moderate" ' . selected( 'moderate', $value, false ) . '>' . esc_html__( 'Send to moderation', 'spamtroll' ) . '</option>';
		echo '<option value="allow" ' . selected( 'allow', $value, false ) . '>' . esc_html__( 'Allow (log only)', 'spamtroll' ) . '</option>';
		echo '</select>';
	}

	/**
	 * Render bypass roles checkboxes.
	 */
	public function render_field_bypass_roles() {
		$current = $this->get_setting( 'bypass_roles', array( 'administrator', 'editor' ) );
		$roles   = wp_roles()->roles;

		foreach ( $roles as $slug => $role ) {
			$checked = in_array( $slug, (array) $current, true );
			echo '<label style="display:block;margin-bottom:4px;">';
			echo '<input type="checkbox" name="spamtroll_settings[bypass_roles][]" value="' . esc_attr( $slug ) . '" ' . checked( true, $checked, false ) . ' /> ';
			echo esc_html( translate_user_role( $role['name'] ) );
			echo '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Users with selected roles will bypass spam checks.', 'spamtroll' ) . '</p>';
	}

	/**
	 * Render log retention field.
	 */
	public function render_field_log_retention_days() {
		$value = $this->get_setting( 'log_retention_days', 30 );
		echo '<input type="number" name="spamtroll_settings[log_retention_days]" value="' . esc_attr( $value ) . '" min="1" max="365" step="1" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Number of days to keep scan logs (1-365).', 'spamtroll' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'spamtroll_settings_group' );
				do_settings_sections( 'spamtroll' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the logs page.
	 *
	 * @return void
	 */
	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$per_page = 20;

		$result = Spamtroll_Logger::get_recent_logs( array(
			'status'   => $status,
			'per_page' => $per_page,
			'page'     => $paged,
		) );

		$logs        = $result['logs'];
		$total       = $result['total'];
		$total_pages = ceil( $total / $per_page );

		// Count per status for filter tabs.
		$all_result        = Spamtroll_Logger::get_recent_logs( array( 'per_page' => 1, 'page' => 1 ) );
		$blocked_result    = Spamtroll_Logger::get_recent_logs( array( 'status' => 'blocked', 'per_page' => 1, 'page' => 1 ) );
		$suspicious_result = Spamtroll_Logger::get_recent_logs( array( 'status' => 'suspicious', 'per_page' => 1, 'page' => 1 ) );
		$safe_result       = Spamtroll_Logger::get_recent_logs( array( 'status' => 'safe', 'per_page' => 1, 'page' => 1 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spamtroll Logs', 'spamtroll' ); ?></h1>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=spamtroll-logs' ) ); ?>" <?php echo empty( $status ) ? 'class="current"' : ''; ?>><?php esc_html_e( 'All', 'spamtroll' ); ?> <span class="count">(<?php echo esc_html( $all_result['total'] ); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=spamtroll-logs&status=blocked' ) ); ?>" <?php echo 'blocked' === $status ? 'class="current"' : ''; ?>><?php esc_html_e( 'Blocked', 'spamtroll' ); ?> <span class="count">(<?php echo esc_html( $blocked_result['total'] ); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=spamtroll-logs&status=suspicious' ) ); ?>" <?php echo 'suspicious' === $status ? 'class="current"' : ''; ?>><?php esc_html_e( 'Suspicious', 'spamtroll' ); ?> <span class="count">(<?php echo esc_html( $suspicious_result['total'] ); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=spamtroll-logs&status=safe' ) ); ?>" <?php echo 'safe' === $status ? 'class="current"' : ''; ?>><?php esc_html_e( 'Safe', 'spamtroll' ); ?> <span class="count">(<?php echo esc_html( $safe_result['total'] ); ?>)</span></a></li>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:50px;"><?php esc_html_e( 'ID', 'spamtroll' ); ?></th>
						<th><?php esc_html_e( 'Date', 'spamtroll' ); ?></th>
						<th><?php esc_html_e( 'Type', 'spamtroll' ); ?></th>
						<th><?php esc_html_e( 'IP', 'spamtroll' ); ?></th>
						<th><?php esc_html_e( 'Status', 'spamtroll' ); ?></th>
						<th><?php esc_html_e( 'Score', 'spamtroll' ); ?></th>
						<th><?php esc_html_e( 'Action', 'spamtroll' ); ?></th>
						<th><?php esc_html_e( 'Preview', 'spamtroll' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No log entries found.', 'spamtroll' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log['id'] ); ?></td>
								<td><?php echo esc_html( $log['created_at'] ); ?></td>
								<td><?php echo esc_html( $log['content_type'] ); ?></td>
								<td><?php echo esc_html( $log['ip_address'] ); ?></td>
								<td><span class="spamtroll-badge spamtroll-badge--<?php echo esc_attr( $log['status'] ); ?>"><?php echo esc_html( $log['status'] ); ?></span></td>
								<td><?php echo esc_html( number_format( (float) $log['spam_score'], 4 ) ); ?> <small>(<?php echo esc_html( number_format( (float) $log['raw_score'], 2 ) ); ?>)</small></td>
								<td><?php echo esc_html( $log['action_taken'] ); ?></td>
								<td class="spamtroll-preview"><?php echo esc_html( mb_substr( $log['content_preview'], 0, 80 ) ); ?><?php echo mb_strlen( $log['content_preview'] ) > 80 ? '&hellip;' : ''; ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						$pagination = paginate_links( array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $total_pages,
							'type'    => 'plain',
						) );
						echo wp_kses_post( $pagination );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
