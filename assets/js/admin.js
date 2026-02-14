/**
 * Spamtroll Admin JavaScript
 *
 * @package Spamtroll
 * @since   0.1.0
 */

/* global jQuery, spamtrollAdmin */
(function ($) {
	'use strict';

	$(document).ready(function () {
		var $button = $('#spamtroll-test-connection');
		var $result = $('#spamtroll-test-result');

		$button.on('click', function () {
			$button.prop('disabled', true);
			$result
				.text(spamtrollAdmin.i18n.testing)
				.removeClass('spamtroll-success spamtroll-error')
				.addClass('spamtroll-testing');

			$.ajax({
				url: spamtrollAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'spamtroll_test_connection',
					nonce: spamtrollAdmin.nonce,
				},
				success: function (response) {
					$result.removeClass('spamtroll-testing');
					if (response.success) {
						$result
							.text(response.data.message)
							.addClass('spamtroll-success');
					} else {
						$result
							.text(
								spamtrollAdmin.i18n.error +
									(response.data
										? response.data.message
										: '')
							)
							.addClass('spamtroll-error');
					}
				},
				error: function () {
					$result
						.removeClass('spamtroll-testing')
						.text(spamtrollAdmin.i18n.ajaxError)
						.addClass('spamtroll-error');
				},
				complete: function () {
					$button.prop('disabled', false);
				},
			});
		});
	});
})(jQuery);
