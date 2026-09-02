/**
 * GFSMS Admin Script (Enterprise Edition)
 *
 * @since 3.3.0
 */

const GFSMSAdmin = ((config) => {
	'use strict';

	// -----------------------------------------------------------------
	// Guards
	// -----------------------------------------------------------------
	if (!config || !config.ajaxUrl) {
		console.error('GFSMS: Missing config');
		return {};
	}

	// -----------------------------------------------------------------
	// Utilities
	// -----------------------------------------------------------------
	const Utils = {
		debounce(fn, delay = 150) {
			let t;
			return (...args) => {
				clearTimeout(t);
				t = setTimeout(() => fn(...args), delay);
			};
		},

		sanitizePhone(value) {
			return String(value || '').replace(/[^\d+]/g, '');
		},

		sanitizeText(value) {
			return String(value || '').trim();
		},

		escapeRegex(str) {
			return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
		}
	};

	// -----------------------------------------------------------------
	// State
	// -----------------------------------------------------------------
	let currentRequest = null;

	const state = {
		$previewBox: null,
		$testResult: null,
		$sendersResult: null
	};

	// -----------------------------------------------------------------
	// UI
	// -----------------------------------------------------------------
	const UI = {
		/**
		 * Set a result message with success/error styling.
		 */
		setResult($el, ok, msg) {
			if (!$el?.length) return;

			$el
				.removeClass('notice-success notice-error')
				.addClass(ok ? 'notice-success' : 'notice-error')
				.text(msg);
		},

		/**
		 * Toggle busy state on a button.
		 */
		toggleBusy($btn, busy) {
			if (!$btn?.length) return;

			$btn.prop('disabled', busy);
			$btn.find('.spinner').toggleClass('is-active', busy);
			$btn.attr('aria-busy', busy ? 'true' : 'false');
		}
	};

	// -----------------------------------------------------------------
	// Preview
	// -----------------------------------------------------------------
	const Preview = (() => {
		const vars = {
			workflow_step_name: 'Approval Step',
			assignee_name: 'John Doe',
			approval_comment: 'Looks good!',
			entry_id: '123'
		};

		const build = () => {
			let sample =
				jQuery('textarea[name="gfsms_settings[template_approved]"]').val() ||
				jQuery('textarea[name="gfsms_settings[template_rejected]"]').val() ||
				jQuery('textarea[name="gfsms_settings[template_workflow]"]').val() ||
				'';

			if (!sample) return '';

			Object.entries(vars).forEach(([key, val]) => {
				const safeKey = Utils.escapeRegex(key);
				sample = sample.replace(new RegExp(`\\{${safeKey}\\}`, 'g'), val);
			});

			return sample;
		};

		const update = Utils.debounce(() => {
			if (!state.$previewBox) return;
			state.$previewBox.text(build());
		});

		return { update };
	})();

	// -----------------------------------------------------------------
	// Validation
	// -----------------------------------------------------------------
	const Validation = {
		validateJson($field) {
			const raw = Utils.sanitizeText($field.val());

			if (!raw) return { valid: true };

			try {
				const parsed = JSON.parse(raw);

				if (typeof parsed !== 'object' || Array.isArray(parsed)) {
					throw new Error('Must be JSON object');
				}

				Object.keys(parsed).forEach(k => {
					if (typeof k !== 'string') {
						throw new Error('Invalid key');
					}
				});

				return { valid: true };
			} catch (e) {
				return { valid: false, message: e.message };
			}
		},

		handleField($field) {
			const $card = $field.closest('[data-gfsms="pattern-card"]');
			const $error = $card.find('[data-gfsms="json-error"]');

			const result = Validation.validateJson($field);

			if (result.valid) {
				$error.hide().text('');
				$field.removeClass('gfsms-invalid');
			} else {
				$error.show().text(`${config.strings.invalid}: ${result.message}`);
				$field.addClass('gfsms-invalid');
			}
		}
	};

	// -----------------------------------------------------------------
	// AJAX
	// -----------------------------------------------------------------
	const Ajax = {
		request(action, data = {}, nonce) {
			if (!nonce) {
				console.error('Missing nonce');
				return jQuery.Deferred().reject().promise();
			}

			if (currentRequest) {
				currentRequest.abort();
			}

			currentRequest = jQuery.ajax({
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				timeout: 15000,
				data: { action, nonce, ...data }
			});

			return currentRequest;
		},

		handleError(jqXHR) {
			if (jqXHR.status === 403) {
				return 'Security check failed';
			}
			if (jqXHR.statusText === 'timeout') {
				return 'Request timed out';
			}
			return config.strings.error;
		}
	};

	// -----------------------------------------------------------------
	// Actions (map action names to handlers)
	// -----------------------------------------------------------------
	const Actions = {
		test_connection($btn) {
			UI.toggleBusy($btn, true);

			const phone = Utils.sanitizePhone(jQuery('#gfsms_test_phone').val());
			const message = Utils.sanitizeText(jQuery('#gfsms_test_message').val());

			Ajax.request('gfsms_test_connection', { phone, message }, config.nonce)
				.done(resp => {
					UI.setResult(
						state.$testResult,
						resp?.success,
						resp?.data?.message || config.strings.error
					);
				})
				.fail(jqXHR => {
					UI.setResult(state.$testResult, false, Ajax.handleError(jqXHR));
				})
				.always(() => UI.toggleBusy($btn, false));
		},

		fetch_senders($btn) {
			UI.toggleBusy($btn, true);

			Ajax.request('gfsms_fetch_senders', {}, config.fetchNonce)
				.done(resp => {
					UI.setResult(
						state.$sendersResult,
						resp?.success,
						resp?.data?.message || config.strings.error
					);
					if (Array.isArray(resp.data?.senders)) {
						Senders.populateSelects(resp.data.senders);
					}
				})
				.fail(jqXHR => {
					UI.setResult(state.$sendersResult, false, Ajax.handleError(jqXHR));
				})
				.always(() => UI.toggleBusy($btn, false));
		}
	};

	// -----------------------------------------------------------------
	// Sender select populator
	// -----------------------------------------------------------------
	const Senders = {
		populateSelects(senders) {
			if (!Array.isArray(senders)) return;

			const $selects = jQuery(
				'select[name="gfsms_settings[default_sender_number]"], ' +
				'select[name="gfsms_settings[gf_sender_number]"], ' +
				'select[name="gfsms_settings[secondary_sender_number]"], ' +
				'select[name^="gfsms_settings[gf_rules]"][name$="[sender_number]"], ' +
				'select[name^="gfsms_settings[recipient_rules]"][name$="[sender_number]"]'
			);

			$selects.each(function () {
				const $sel = jQuery(this);
				const current = $sel.val();
				$sel.empty();
				senders.forEach(num => {
					$sel.append(jQuery('<option>', { value: num, text: num }));
				});
				if (senders.includes(current)) {
					$sel.val(current);
				}
			});
		}
	};

	// -----------------------------------------------------------------
	// Rule Builder (no toggle — pattern row always visible)
	// -----------------------------------------------------------------
	const RuleBuilder = {
		getTemplateRow($wrap) {
			return $wrap
				.find('.gfsms-rule-template')
				.clone()
				.removeClass('gfsms-rule-template')
				.show();
		},

		getTemplatePatternRow($wrap) {
			return $wrap
				.find('.gfsms-pattern-row')
				.first()
				.clone()
				.show();
		},

		/**
		 * Reindex all rule rows and their associated pattern rows.
		 */
		reindexRows($wrap) {
			const $mainRows = $wrap.find('tbody tr.gfsms-rule-main');
			$mainRows.each(function (i) {
				const $main = jQuery(this);
				// Update main row inputs
				$main.find('select, input, textarea').each(function () {
					const name = jQuery(this).attr('name');
					if (name) {
						const newName = name.replace(/\[\d+\]/, '[' + i + ']');
						jQuery(this).attr('name', newName);
					}
				});

				// Update associated pattern row inputs
				const $pattern = $main.next('.gfsms-pattern-row');
				if ($pattern.length) {
					$pattern.find('select, input, textarea').each(function () {
						const name = jQuery(this).attr('name');
						if (name) {
							const newName = name.replace(/\[\d+\]/, '[' + i + ']');
							jQuery(this).attr('name', newName);
						}
					});
				}
			});
		},

		addRow(e) {
			const $wrap = jQuery(e.currentTarget).closest('.gfsms-rule-builder');
			const $tbody = $wrap.find('tbody');
			const $newMain = RuleBuilder.getTemplateRow($wrap);
			const $newPattern = RuleBuilder.getTemplatePatternRow($wrap);
			$tbody.append($newMain);
			$tbody.append($newPattern);
			RuleBuilder.reindexRows($wrap);
		},

		removeRow(e) {
			const $btn = jQuery(e.currentTarget);
			const $mainRow = $btn.closest('tr.gfsms-rule-main');
			const $patternRow = $mainRow.next('.gfsms-pattern-row');
			const $wrap = $mainRow.closest('.gfsms-rule-builder');
			$mainRow.remove();
			if ($patternRow.length) {
				$patternRow.remove();
			}
			RuleBuilder.reindexRows($wrap);
		}
		// toggleAdvanced removed — pattern row is always visible
	};

	// -----------------------------------------------------------------
	// Init
	// -----------------------------------------------------------------
	const init = () => {
		state.$previewBox = jQuery('#gfsms_preview_box');
		state.$testResult = jQuery('#gfsms_test_message_result');
		state.$sendersResult = jQuery('#gfsms_fetch_senders_result');

		jQuery(document)
			.off('.gfsms')
			// Preview
			.on('input.gfsms', 'textarea[name^="gfsms_settings[template_"]', Preview.update)
			// JSON validation for pattern_map fields and rule variable_map fields
			.on('blur.gfsms', '.gfsms-json-field', function () {
				Validation.handleField(jQuery(this));
			})
			// Unified click handler for all action buttons
			.on('click.gfsms', '.gfsms-action-btn', function (e) {
				e.preventDefault();
				const $btn = jQuery(this);
				const action = $btn.data('action');

				if (typeof Actions[action] === 'function') {
					Actions[action]($btn);
				} else {
					console.warn('GFSMS: Unknown action', action);
				}
			})
			// Rule builder
			.on('click.gfsms', '.gfsms-add-rule', e => {
				e.preventDefault();
				RuleBuilder.addRow(e);
			})
			.on('click.gfsms', '.gfsms-remove-rule', e => {
				e.preventDefault();
				RuleBuilder.removeRow(e);
			});

		Preview.update();
	};

	return { init };

})(window.gfsmsAdmin || {});

// Boot
jQuery(document).ready(() => {
	GFSMSAdmin.init();
});