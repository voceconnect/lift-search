/*global window, $, jQuery, document, ajaxurl*/
jQuery(document).ready(function($) {
	"use strict";
	var lift_ajax;

	/**
	 * Replace the Next/Last time labels with the updated values
	 * @method updateCronTimeLabels
	 * @param resp Object
	 **/
	function updateCronTimeLabels(resp) {
		var last_cron = resp.last_cron || "",
		next_crom = resp.next_cron || "";
		$('#last-cron').html(last_cron);
		$('#next-cron').html(next_crom);
	}

	/**
	 * Capture the event for any disabled anchor tab
	 * and prevent the default action
	 **/
	$('a[disabled="disabled"]').live('click', function(e){
		e.preventDefault();
	});

	/**
	 *  Using this like a singleton class to make AJAX calls simpler
	 *  @property lift_ajax
	 *  @type Object
	 */
	lift_ajax = {
		
		/**
		 * Flag to see if we already have a request running
		 * @property processing
		 * @type boolean
		 */
		processing : false,
		
		/**
		 * Make the ajax request
		 * @method makeRequest
		 * @param data
		 * @return request|false
		 */
		makeRequest: function makeRequest(data){
			if(lift_ajax.processing === false){
				lift_ajax.processing = true;
				$('#update-ajax-loader').removeClass('hidden');
				var request = $.post(ajaxurl, data);
				request.complete(function(){
					lift_ajax.processing = false;
					$('#update-ajax-loader').addClass('hidden');
				});
				return request;
			}
			return false;
		},

		parseJSON: function parseJSON(thing) {
			try {
				var json = $.parseJSON(thing);
				return json;
			} catch (e) {
				return false;
			}
		}, 


		/**
		 * @method test_connection
		 * @param data object
		 * @return request|false
		 */
		test_connection: function test_connection(data){
			data = $.extend({
				action: 'lift_test_access',
				id: "",
				secret: ""
			}, data);
			return lift_ajax.makeRequest(data);
		},

		/**
		 * @method test_domain
		 * @param data object
		 * @return request|false
		 */
		test_domain: function test_domain(data){
			data = $.extend({
				action: 'lift_test_domain',
				domain: ""
			}, data);
			return lift_ajax.makeRequest(data);
		},
		
		/**
		 * @method create_domain
		 * @param data object
		 * @return request|false
		 */
		create_domain: function create_domain(data){
			data = $.extend({
				action: 'lift_create_domain',
				domain: ""
			}, data);
			return lift_ajax.makeRequest(data);
		},
		
		/**
		 * @method update_cron_status
		 * @param data object
		 * @return request|false
		 */
		update_cron_status: function update_cron_status(data){
			data = $.extend({
				action: 'lift_set_cron_status',
				cron: 0
			}, data);
			return lift_ajax.makeRequest(data);
		},
		
		/**
		 * @method update_cron_interval
		 * @param data object
		 * @return request|false
		 */
		update_cron_interval: function update_cron_interval(data){
			data = $.extend({
				action: 'lift_update_cron_interval',
				cron_interval: 1,
				cron_interval_units: "m"
			}, data);
			return lift_ajax.makeRequest(data);
		},
		
		/**
		 * @method delete_error_logs
		 * @return request|false
		 */
		delete_error_logs: function delete_error_logs(){
			return lift_ajax.makeRequest({
				action: 'lift_delete_error_logs'
			});
		}
	};

	/**
	 * @event
	 */
	$('#lift-test-access').click(function(e){
		e.preventDefault();
		if(lift_ajax.processing === false){
			var next, prev, data, request;
			next = $(this).parents('.lift-step').find('.lift-next-step');
			prev = $(this).parents('.lift-step').find('.lift-prev-step');
			next.attr('disabled', 'disabled');
			prev.attr('disabled', 'disabled');
			$('.lift-step-4 .lift-admin-panel').attr('disabled', 'disabled');

			$('#access-status-message').html('').addClass('success-message').removeClass('error-message');
			data = {
				id: $('input[name="access-key-id"]').val(),
				secret: $('input[name="secret-access-key"]').val()
			};
			if(data.id.length < 1 || data.secret.length < 1){
				$('#access-status-message').addClass('error-message')
				.html('Please enter your AWS access key and secret');
				prev.removeAttr("disabled");
				return;
			}
			$('#lift-test-access, input[name="access-key-id"], input[name="secret-access-key"]').attr("disabled", "disabled");
			$('#access-ajax-loader').removeClass('hidden');
			request = lift_ajax.test_connection(data);
			request.success(function(response){
				var resp_parsed, msg, msg_text;
				resp_parsed = lift_ajax.parseJSON(response);
				msg = $('#access-status-message');
				msg_text = '';

				if (!resp_parsed || resp_parsed.error) {
					if (!resp_parsed) {
						msg_text = 'An error occurred. Response could not be parsed.';
					}
					msg.addClass('error-message').removeClass('success-message');
					$('.lift-step-4 .lift-admin-panel').attr('disabled', 'disabled');
					next.attr('disabled', 'disabled');
				}
				else {
					$('.lift-step-4 .lift-admin-panel').removeAttr('disabled');
					next.removeAttr('disabled');
				}
				if (!msg_text) {
					msg_text = resp_parsed.message;
				}
				msg.html(msg_text);
				$('#access-ajax-loader').addClass('hidden');
				$('#lift-test-access, input[name="access-key-id"], input[name="secret-access-key"]').removeAttr("disabled");
				prev.removeAttr("disabled");
			});
		}
	});

	/**
	 * @event
	 */
	$('#lift-test-domain').click(function(e){
		e.preventDefault();
		if(lift_ajax.processing === false){
			var next, prev, data, request;
			next = $(this).parents('.lift-step').find('.lift-next-step');
			prev = $(this).parents('.lift-step').find('.lift-prev-step');
			next.attr('disabled', 'disabled');
			prev.attr('disabled', 'disabled');
			$('.lift-step-4 .lift-admin-panel').attr('disabled', 'disabled');
			$('#domain-status-message').html('').addClass('success-message').removeClass('error-message');
			data = {
				domain: $('input[name="search-domain"]').val()
			};
			if(data.domain.length < 1){
				$('#domain-status-message').addClass('error-message')
				.html('Please enter your AWS domain name');
				prev.removeAttr("disabled");
				return;
			}
			$('#domain-ajax-loader').removeClass('hidden');
			$('#lift-test-domain, input[name="search-domain"]').attr("disabled", "disabled");
			request = lift_ajax.test_domain(data);
			request.success(function(response){
				var resp_parsed, msg, msg_text;
				resp_parsed = lift_ajax.parseJSON(response);
				msg = $('#domain-status-message');
				msg_text = '';
				
				if (!resp_parsed || resp_parsed.error) {
					if (!resp_parsed) {
						msg_text = 'An error occurred. Response could not be parsed.';
					}
					msg.addClass('error-message').removeClass('success-message');
					$('.lift-step-4 .lift-admin-panel').attr('disabled', 'disabled');
					next.attr('disabled', 'disabled');
				}
				else {
					$('.lift-step-4 .lift-admin-panel').removeAttr('disabled');
					next.removeAttr('disabled');
				}
				if (!msg_text) {
					msg_text = resp_parsed.message;
				}
				msg.html(msg_text);
				$('#domain-ajax-loader').addClass('hidden');
				$('#lift-test-domain, input[name="search-domain"]').removeAttr("disabled");
				prev.removeAttr("disabled");
			});
		}
	});

	/**
	 * @event
	 */
	$('#domain-status-message').delegate('#lift-create-domain', 'click', function(e) {

		e.preventDefault();

		if (false === lift_ajax.processing) {

			var next, prev, data, request, msg, loader, input, msg_text;
			next = $(this).parents('.lift-step').find('.lift-next-step');
			prev = $(this).parents('.lift-step').find('.lift-prev-step');
			next.attr('disabled', 'disabled');
			prev.attr('disabled', 'disabled');

			msg = $('#domain-status-message').html('').addClass('success-message').removeClass('error-message');
			loader = $('#domain-ajax-loader').removeClass('hidden');
			msg_text = '';

			data = {
				domain: $(this).data('domain')
			};

			input = $('#lift-test-domain, input[name="search-domain"]').attr("disabled", "disabled");

			request = lift_ajax.create_domain(data);
			request.success(function(response){
				var resp_parsed = lift_ajax.parseJSON(response);
				if (!resp_parsed || resp_parsed.error) {
					if ( resp_parsed.error ) {
						msg_text = resp_parsed.error;
					} else {
						msg_text = 'An error occurred. Response could not be parsed.';
					}
					msg.addClass('error-message').removeClass('success-message');
					$('.lift-step-4 .lift-admin-panel').attr('disabled', 'disabled');
					next.attr('disabled', 'disabled');
				}
				else {
					$('.lift-step-4 .lift-admin-panel').removeAttr('disabled');
					next.removeAttr('disabled');
				}
				if (!msg_text) {
					msg_text = resp_parsed.message;
				}
				msg.html(msg_text);
				loader.addClass('hidden');
				input.removeAttr("disabled");
				prev.removeAttr("disabled");
			});
		}

	});

	/**
	 * @event
	 */
	$('input[type="text"]').keyup(function(e){
		if(e.keyCode === 13){
			switch ($(this).attr("name")) {
				case 'access-key-id':
					$('input[name="secret-access-key"]').focus();
					break;
				case 'secret-access-key':
					$('#lift-test-access').click();
					break;
				case 'search-domain':
					$('#lift-test-domain').click();
					break;
				case 'batch-interval':
					$('#update-cron-interval').click();
					break;
			}
		}
	});

	/**
	 * @event
	 */
	$('#set-cron-status').click(function(e){
		e.preventDefault();
		if(lift_ajax.processing === false){
			var container, slider, response;

			container = $(this);
			container.addClass('disabled');

			slider = container.find(".slider-button");

			if(slider.hasClass('on')){
				slider.removeClass('button-primary on').addClass('button off').html('OFF');
			} else {
				slider.removeClass('button off').addClass('button-primary on').html('ON');
			}

			response = lift_ajax.update_cron_status({
				cron: slider.hasClass('on') ? 1 : 0
			});
			response.success(function(response){
				var resp_parsed = lift_ajax.parseJSON(response);
				if (resp_parsed) {
					updateCronTimeLabels(resp_parsed);
				}
				

				container.removeClass('disabled');
			});
		}
		
	});

	/**
	 * @event
	 */
	$('#update-cron-interval').click(function(e){
		e.preventDefault();
		if(lift_ajax.processing === false){
			var t, buttons, data, response;
			t = $(this);
			buttons = $('input[name="batch-interval"], select[name="batch-interval-units"]');
			//disable fields while sending
			t.attr("disabled", "disabled");
			buttons.attr("disabled", "disabled");

			data = {
				cron_interval: $('input[name="batch-interval"]').val(),
				cron_interval_units: $('select[name="batch-interval-units"]').val()
			};
			response = lift_ajax.update_cron_interval(data);
			response.success(function(response){
				var resp_parsed = $.parseJSON(response);
				updateCronTimeLabels(resp_parsed);
				t.removeAttr("disabled");
				buttons.removeAttr("disabled");
			});
		}
	});
	
	/**
	 * @event
	 */
	$('#voce-lift-admin-settings-clear-status-logs').click(function(e){
		e.preventDefault();
		if(lift_ajax.processing === false){
			var request, $this;
			$this = $(this);
			$('#clear-log-status-message').html('');
			$('#clear-logs-loader').removeClass('hidden');
			$this.attr('disabled', 'disabled');
			request = lift_ajax.delete_error_logs();
			request.success(function(response){
				var resp_parsed, msg_span, msg, row, cell, empty;
				resp_parsed = $.parseJSON(response);
				msg_span = $('<span>');
				if (resp_parsed.success === true){
					row = $('<tr>');
					cell = $('<td colspan="3">');
					empty = row.html(cell.html('No Recent Logs'));
					msg = msg_span.addClass('success-message')
					.html('Purged all logs.')
					.delay(3000)
					.fadeOut('slow', function(){
						$(this).html('').show();
					});
					$('#clear-logs-loader').addClass('hidden');
					$('#clear-log-status-message').html(msg);
					$this.removeAttr('disabled');
					$('#lift-recent-logs-table tbody').html(empty);
				} else {
					msg = msg_span.addClass('error-message').html('Error clearing logs.');
					$('#clear-logs-loader').addClass('hidden');
					$('#clear-log-status-message').html(msg);
					$this.removeAttr('disabled');
				}
			});
		}
	});

	/**
	 * Step through the setup form
	 * @event
	 * @todo Do we need a .on/.live/.delegate call here? Are the elements
	 * dynamically created or can we just use .click?
	 */
	$('.lift-setup').delegate('input[data-lift_step]', 'click', function(e){
		e.preventDefault();
		if($(this).data('lift_step') === "next"){
			$(this).parents('.lift-step').hide().next('.lift-step').fadeIn('fast');
		}
		if($(this).data('lift_step') === "prev"){
			$(this).parents('.lift-step').hide().prev('.lift-step').fadeIn('fast');
		}
	});

});