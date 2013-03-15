(function($, window) {
	var liftAdmin = liftAdmin || {};

	liftAdmin.App = Backbone.Router.extend({
		el: '#lift-status-page',
		initialize: function() {
			var that = this;

			Backbone.emulateHTTP = true;
			Backbone.emulateJSON = true;

			this.settings = new liftAdmin.SettingsCollection();
			this.domains = new liftAdmin.DomainsCollection();
			this.bind('sync_error', function(){that.domain_sync_error()});

			$.when(this.settings.fetch()).then(function(){that.render()});
		},
		render: function() {
			var state = this.determine_state();
			state && this.render_state(state);
		},
		determine_state: function() {
			var state, credentials, that = this;
			
			credentials = this.settings.getValue('credentials');
			if (!(credentials.accessKey && credentials.secretKey)) {
				state = 'set_credentials';
			} else {
				var foo = typeof this.domains.deferred;
				if ( typeof this.domains.deferred === 'object' && 'then' in this.domains.deferred ) {
					//rerender after domains have completely loaded
					$.when(this.domains.deferred).then(function() {
						that.render();
					});
				} else {
					if (!this.settings.getValue('domainname')) {
						state = 'set_domainname';
					} else if (!this.settings.getValue('setup_complete')) {
						state = 'processing_setup';
					} else {
						state = 'dashboard';
					}
				}
			}

			return state;
		},
		render_state: function(state) {
			var state_views = {
				set_credentials: {view: liftAdmin.SetCredentialsView, args: {model: this.settings}},
				set_domainname: {view: liftAdmin.SetDomainView, args: {model: this.settings, domains: this.domains}},
				processing_setup: {view: liftAdmin.SetupProcessingView, args: {model: this.domains.get(this.settings.getValue('domainname'))}},
				dashboard: {view: liftAdmin.DashboardView, args: {model: this.settings}}
			};

			var new_view = state_views[state];

			// only process if setting a new view
			if (this.currentView && (this.currentView instanceof new_view.view))
				return;

			// clean up the old view
			this.currentView && this.currentView.undelegateEvents();

			this.currentView = new new_view.view(new_view.args);
			this.currentView.setElement(this.el);
			this.currentView.render();
		},
		domain_sync_error: function(collection, error, options) {
			var modal;
			if (error.code == 'invalid_credentials') {
				modal = new ModalSetCredentialsView({_template: 'modal-error-set-credentials', model: this.settings});
			}
		},
		open_modal: function(view) {
			view.setElement($('#modal_content'));
			view.render();
			$('#lift_modal').show();
		},
		close_modal: function(view) {
			view.undelegateEvents();
			$('#modal_content').html('');
			$('#lift_modal').hide();
		}
	});

	liftAdmin.templateLoader = {
		templates: {},
		getTemplate: function(name) {
			return this.templates[name] || this.loadTemplate(name);
		},
		loadTemplate: function(name) {
			var tmpUrl = window.lift_data.template_dir + name + '.html';
			$.ajax({
				url: tmpUrl,
				type: 'get',
				dataType: 'html',
				async: false,
				success: function(data) {
					liftAdmin.templateLoader.templates[name] = data;
				}
			});
			return this.templates[name] || false;
		}
	};

	liftAdmin.Model = Backbone.Model.extend({
		url: function() {
			return window.ajaxurl + '?action=lift_' + this.action;
		},
	});

	liftAdmin.SettingModel = Backbone.Model.extend({
		url: function() {
			return window.ajaxurl + '?action=lift_setting&setting=' + this.get('id') + '&nonce=' + this.collection.getValue('nonce');
		},
		parse: function(res) {
			//if came from collection
			if (res.id)
				return res;
			return res.data || null;
		}
	});

	liftAdmin.SettingsCollection = Backbone.Collection.extend({
		model: liftAdmin.SettingModel,
		url: function() {
			return window.ajaxurl + '?action=lift_settings';
		},
		getValue: function(id) {
			return this.get(id) && this.get(id).get('value');
		},
		setValue: function(id, value) {
			return this.get(id) && this.get(id).set('value', value);
		},
		toJSONObject: function() {
			return _.object(this.map(function(model) {
				return [model.get('id'), model.get('value')];
			}));
		}
	});

	liftAdmin.DomainModel = liftAdmin.Model.extend({
		action: 'domain',
		idAttribute: 'DomainName',
	});

	liftAdmin.DomainsCollection = Backbone.Collection.extend({
		model: liftAdmin.DomainModel,
		initialize: function() {
			var that = this;
			this.bind('sync', function(){that.syncCheck()});
			this.deferred = this.fetch({
				success: function(model) {
					delete model.deferred;
				}
			});
		},
		url: function() {
			return window.ajaxurl + '?action=lift_domains';
		},
		parse: function(resp) {
			return resp.domains;
		},
		syncCheck: function(collection, resp, options) {
			if (resp.error) {
				this.sync_error = error;
				collection.trigger('sync_error', collection, resp.error, options);
			}
		}
	});

	liftAdmin.SetCredentialsView = Backbone.View.extend({
		_template: 'set-credentials',
		initialize: function() {
			var that = this;
			this.template = _.template(liftAdmin.templateLoader.getTemplate(this._template));
			this.model.get('credentials').bind('validated:invalid', function(){that.invalidCredentials()});
		},
		events: {
			'click #save_credentials': 'update_credentials'
		},
		render: function() {
			this.el.innerHTML = this.template(this.model.toJSONObject());
		},
		before_save: function() {
			$('#errors').hide();
			$('#save_credentials').attr('disabled', 'disabled');
		},
		after_save: function() {
			$('#save_credentials').removeAttr('disabled');
		},
		update_credentials: function() {
			this.before_save();
			var credentials = {
				accessKey: $('#accessKey').val(),
				secretKey: $('#secretKey').val()
			};
			this.model.get('credentials').save({value: credentials}, {
				success: this.save_success,
				error: this.save_error
			}).always(this.after_save);
		},
		save_success: function() {
			adminApp.render();
		},
		save_error: function(model, xhr, options) {
			var errors = $.parseJSON(xhr.responseText).errors;
			model.trigger('validated:invalid', model, errors, options || {});
		},
		invalidCredentials: function(model, errors) {
			var template = liftAdmin.templateLoader.getTemplate('errors');
			$('#errors').html(_.template(template, {errors: errors})).show();
		}

	});

	liftAdmin.ModalSetCredentialsView = liftAdmin.SetCredentialsView.extend({
		_template: 'modal-set-credentials',
		save_success: function() {
			adminApp.closeModal(this);
		}
	});

	liftAdmin.DashboardView = Backbone.View.extend({
		initialize: function() {
			this.template = _.template(liftAdmin.templateLoader.getTemplate('dashboard'));
		},
		events: {
			'click #batch_interval_update': 'update_batch_interval'
		},
		render: function() {
			this.el.innerHTML = this.template({settings: this.model.toJSONObject()});
			$('#batch_interval_unit').val(this.model.getValue('batch_interval').unit);

		},
		update_batch_interval: function() {
			var that = this;
			var batch_interval = {
				value: $('#batch_interval').val(),
				unit: $('#batch_interval_unit').val()
			};
			this.before_save();
			this.model.get('batch_interval')
							.save({value: batch_interval}, {
				success: function() {
					that.after_save();
				}
			});
		},
		before_save: function() {
			$(this.el).find('input').attr('disabled', true);
		},
		after_save: function() {
			$(this.el).find('input').attr('disabled', false);
		}

	});

	liftAdmin.SetDomainView = Backbone.View.extend({
		initialize: function() {
			var that = this;
			this.domains = this.options.domains;
			this.template = _.template(liftAdmin.templateLoader.getTemplate('set-domain'));
			this.model.get('domainname').bind('validated:invalid', function(){that.invalidDomainName()});
		},
		events: {
			'click #save_domainname': 'set_domainname',
			'click #cancel': 'go_back'
		},
		render: function() {
			this.el.innerHTML = this.template(this.model.toJSONObject());
		},
		before_save: function() {
			$('#errors').hide();
			$('#save_domainname').attr('disabled', 'disabled');
		},
		after_save: function() {
			$('#save_domainname').removeAttr('disabled');
		},
		set_domainname: function() {
			var domainname, domain, modalView, that = this;
			this.before_save();
			domainname = $('#domainname').val();
			domain = this.domains.get(domainname);

			if (!domain) {
				//if domain doesn't exist, create it
				this.create_domain(domainname);
			} else {
				//have user confirm to override the existing domain
				modalView = new liftAdmin.ModalConfirmDomainView({model: this.domains.get(domainname)});
				modalView.bind('cancelled', function(view, model){that.modal_cancelled(view, model)});
				modalView.bind('confirmed', function(view, model){that.modal_confirmed(view, model)});
				adminApp.open_modal(modalView);
			}
			//this.model.get('domainname').set({value:domainname});

		},
		modal_cancelled: function(view, model) {
			$('#save_domainname').removeAttr('disabled');
			adminApp.close_modal(view);
		},
		modal_confirmed: function(view, domain) {
			adminApp.close_modal(view);
			this.use_domain(domain);
		},
		create_domain: function(domainname) {
			var domain, that = this;
			domain = new liftAdmin.DomainModel({DomainName: domainname});
			this.domains.add(domain);
			domain.save(null, {success: function() {that.use_domain(domain)} });
		},
		invalid_domainname: function(model, errors) {
			var template = liftAdmin.templateLoader.getTemplate('errors');
			$('#errors').html(_.template(template, {errors: errors})).show();
		},
		go_back: function() {
			adminApp.render_state('set_credentials');
		},
		use_domain: function(domain) {
			adminApp.settings.get('domainname').save({value: domain.get('DomainName')}, {
				success: function(){adminApp.render()}
			});
		}
	});

	liftAdmin.ModalConfirmDomainView = Backbone.View.extend({
		initialize: function() {
			this.template = _.template(liftAdmin.templateLoader.getTemplate('modal-confirm-domain'));
		},
		events: {
			'click #confirm_domain': 'confirm',
			'click #cancel_domain': 'cancel'
		},
		render: function() {
			this.el.innerHTML = this.template(this.model.toJSON());
		},
		confirm: function() {
			this.trigger('confirmed', this, this.model);
		},
		cancel: function() {
			this.trigger('cancelled', this, this.model);
		}

	});

	liftAdmin.SetupProcessingView = Backbone.View.extend({
		initialize: function() {
			this.template = _.template(liftAdmin.templateLoader.getTemplate('setup-processing'));
		},
		events: {
			'click #skip_status': 'fake_status_complete'
		},
		render: function() {
			this.el.innerHTML = this.template(this.model.toJSON());
		},
		fake_status_complete: function() {
			adminApp.render_state('dashboard');
		}

	});

	var adminApp = new liftAdmin.App();

})(jQuery, window);