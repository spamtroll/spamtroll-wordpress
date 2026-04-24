<?php
/**
 * Spamtroll Scanner
 *
 * @package Spamtroll
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles spam scanning for comments and registrations.
 */
class Spamtroll_Scanner {

	/**
	 * Cached result from the last scan (used between preprocess_comment and pre_comment_approved).
	 *
	 * @var array|null
	 */
	private $last_scan_result = null;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		$settings = get_option( 'spamtroll_settings', array() );

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! empty( $settings['check_comments'] ) ) {
			add_filter( 'preprocess_comment', array( $this, 'check_comment' ) );
			add_filter( 'pre_comment_approved', array( $this, 'filter_comment_approved' ), 10, 2 );
		}

		if ( ! empty( $settings['check_registrations'] ) ) {
			add_filter( 'registration_errors', array( $this, 'check_registration' ), 10, 3 );
		}
	}

	/**
	 * Check a comment for spam via the API.
	 *
	 * Hooked to `preprocess_comment`. Scans the content and stores the result
	 * for use in `filter_comment_approved`.
	 *
	 * @param array $commentdata Comment data.
	 * @return array Unmodified comment data (fail-open).
	 */
	public function check_comment( $commentdata ) {
		$this->last_scan_result = null;

		if ( $this->should_bypass() ) {
			return $commentdata;
		}

		$content = isset( $commentdata['comment_content'] ) ? $commentdata['comment_content'] : '';
		if ( empty( trim( $content ) ) ) {
			return $commentdata;
		}

		try {
			$client   = Spamtroll_Sdk_Factory::client();
			$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$email    = isset( $commentdata['comment_author_email'] ) ? $commentdata['comment_author_email'] : '';
			$username = isset( $commentdata['comment_author'] ) ? $commentdata['comment_author'] : '';

			$response = $this->scan_with_cache( $client, $content, \Spamtroll\Sdk\Request\CheckSpamRequest::SOURCE_COMMENT, $ip, $username, $email );

			if ( ! $response->success ) {
				error_log( 'Spamtroll: API returned error for comment scan: ' . $response->error );
				return $commentdata;
			}

			$score  = $response->getSpamScore();
			$status = $this->determine_status( $score );
			$action = $this->determine_action( $score );

			$this->last_scan_result = array(
				'score'  => $score,
				'status' => $status,
				'action' => $action,
			);

			$user_id = get_current_user_id();

			Spamtroll_Logger::log(
				array(
					'user_id'           => $user_id ? $user_id : null,
					'content_type'      => 'comment',
					'content_id'        => null,
					'ip_address'        => $ip,
					'status'            => $status,
					'spam_score'        => $score,
					'raw_score'         => $response->getRawSpamScore(),
					'symbols'           => $response->getSymbols(),
					'threat_categories' => $response->getThreatCategories(),
					'action_taken'      => $action,
					'content_preview'   => $content,
				)
			);
		} catch ( \Spamtroll\Sdk\Exception\SpamtrollException $e ) {
			// Fail-open: allow the comment through on any API error.
			error_log( 'Spamtroll: API exception during comment scan: ' . $e->getMessage() );
		} catch ( Exception $e ) {
			error_log( 'Spamtroll: Unexpected error during comment scan: ' . $e->getMessage() );
		}

		return $commentdata;
	}

	/**
	 * Filter comment approval status based on scan result.
	 *
	 * Hooked to `pre_comment_approved`.
	 *
	 * @param int|string|WP_Error $approved    Current approval status.
	 * @param array               $commentdata Comment data.
	 * @return int|string|WP_Error Modified approval status.
	 */
	public function filter_comment_approved( $approved, $commentdata ) {
		if ( null === $this->last_scan_result ) {
			return $approved;
		}

		$result = $this->last_scan_result;
		$this->last_scan_result = null;

		switch ( $result['action'] ) {
			case 'block':
				return 'spam';

			case 'moderate':
				return 0;

			default:
				return $approved;
		}
	}

	/**
	 * Check registration for spam.
	 *
	 * Hooked to `registration_errors`.
	 *
	 * @param WP_Error $errors               Registration errors.
	 * @param string   $sanitized_user_login  Sanitized username.
	 * @param string   $user_email            User email.
	 * @return WP_Error Modified errors object.
	 */
	public function check_registration( $errors, $sanitized_user_login, $user_email ) {
		if ( $this->should_bypass() ) {
			return $errors;
		}

		$content = $sanitized_user_login . ' ' . $user_email;

		try {
			$client = Spamtroll_Sdk_Factory::client();
			$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

			$response = $this->scan_with_cache( $client, $content, \Spamtroll\Sdk\Request\CheckSpamRequest::SOURCE_REGISTRATION, $ip, $sanitized_user_login, $user_email );

			if ( ! $response->success ) {
				error_log( 'Spamtroll: API returned error for registration scan: ' . $response->error );
				return $errors;
			}

			$score  = $response->getSpamScore();
			$status = $this->determine_status( $score );
			$action = $this->determine_action( $score );

			Spamtroll_Logger::log(
				array(
					'user_id'           => null,
					'content_type'      => 'registration',
					'content_id'        => null,
					'ip_address'        => $ip,
					'status'            => $status,
					'spam_score'        => $score,
					'raw_score'         => $response->getRawSpamScore(),
					'symbols'           => $response->getSymbols(),
					'threat_categories' => $response->getThreatCategories(),
					'action_taken'      => $action,
					'content_preview'   => $content,
				)
			);

			if ( 'block' === $action ) {
				$errors->add( 'spamtroll_blocked', __( 'Registration blocked by spam filter. Please contact the site administrator if you believe this is an error.', 'spamtroll' ) );
			}
		} catch ( \Spamtroll\Sdk\Exception\SpamtrollException $e ) {
			// Fail-open: allow registration on any API error.
			error_log( 'Spamtroll: API exception during registration scan: ' . $e->getMessage() );
		} catch ( Exception $e ) {
			error_log( 'Spamtroll: Unexpected error during registration scan: ' . $e->getMessage() );
		}

		return $errors;
	}

	/**
	 * Call the API with a 1-hour transient cache keyed on the
	 * content + source + email. Identical repeated submissions (e.g. a
	 * bot hammering the same comment form with the same payload from
	 * different IPs) reuse the first verdict instead of burning through
	 * the user's API quota.
	 *
	 * Only caches successful API calls — errors fall straight through so
	 * we don't lock in a bad verdict.
	 */
	private function scan_with_cache( \Spamtroll\Sdk\Client $client, string $content, string $source, string $ip, string $username, string $email ): \Spamtroll\Sdk\Response\CheckSpamResponse {
		$cache_key = 'spamtroll_scan_' . md5( $source . '|' . $email . '|' . trim( $content ) );
		$cached    = get_transient( $cache_key );
		if ( $cached instanceof \Spamtroll\Sdk\Response\CheckSpamResponse && $cached->success ) {
			return $cached;
		}
		$response = $client->checkSpam( new \Spamtroll\Sdk\Request\CheckSpamRequest(
			$content,
			$source,
			$ip !== '' ? $ip : null,
			$username !== '' ? $username : null,
			$email !== '' ? $email : null
		) );
		if ( $response->success ) {
			set_transient( $cache_key, $response, HOUR_IN_SECONDS );
		}
		return $response;
	}

	/**
	 * Check if the current user should bypass spam checking.
	 *
	 * @return bool True if the user should bypass.
	 */
	private function should_bypass() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user     = wp_get_current_user();
		$settings = get_option( 'spamtroll_settings', array() );
		$bypass   = isset( $settings['bypass_roles'] ) ? (array) $settings['bypass_roles'] : array( 'administrator', 'editor' );

		foreach ( $bypass as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine action based on normalized spam score.
	 *
	 * @param float $score Normalized spam score (0-1).
	 * @return string Action to take (block, moderate, allow).
	 */
	private function determine_action( $score ) {
		$settings             = get_option( 'spamtroll_settings', array() );
		$spam_threshold       = isset( $settings['spam_threshold'] ) ? (float) $settings['spam_threshold'] : 0.70;
		$suspicious_threshold = isset( $settings['suspicious_threshold'] ) ? (float) $settings['suspicious_threshold'] : 0.40;
		$action_blocked       = isset( $settings['action_blocked'] ) ? $settings['action_blocked'] : 'block';
		$action_suspicious    = isset( $settings['action_suspicious'] ) ? $settings['action_suspicious'] : 'moderate';

		if ( $score >= $spam_threshold ) {
			return $action_blocked;
		}

		if ( $score >= $suspicious_threshold ) {
			return $action_suspicious;
		}

		return 'allow';
	}

	/**
	 * Determine status label based on normalized spam score.
	 *
	 * @param float $score Normalized spam score (0-1).
	 * @return string Status label (blocked, suspicious, safe).
	 */
	private function determine_status( $score ) {
		$settings             = get_option( 'spamtroll_settings', array() );
		$spam_threshold       = isset( $settings['spam_threshold'] ) ? (float) $settings['spam_threshold'] : 0.70;
		$suspicious_threshold = isset( $settings['suspicious_threshold'] ) ? (float) $settings['suspicious_threshold'] : 0.40;

		if ( $score >= $spam_threshold ) {
			return 'blocked';
		}

		if ( $score >= $suspicious_threshold ) {
			return 'suspicious';
		}

		return 'safe';
	}
}
