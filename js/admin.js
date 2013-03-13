(function($, window) {
	var liftAdmin = liftAdmin || {};

	liftAdmin.App = Backbone.Router.extend({
		el: '#lift-status-page',
		initialize: function() {
			Backbone.emulateHTTP = true;
			Backbone.emulateJSON = true;
			that = this;

			this.settingsCollection = new liftAdmin.settingsCollection();

			$.when(this.settingsCollection.fetch())
							.then(function() {
				var state = that.determine_state();
				that.render_state(state);
			});
		},
		determine_state: function() {
			var state;
			if (this.settingsCollection.getValue('setup_complete')) {
				state = 'dashboard';
			} else {
				if (!this.settingsCollection.getValue('accessKey') || this.settingsCollection.getValue('secretKey') || this.models.credentials.get('error')) {
					state = 'set_credentials';
				} else if (!this.settingsCollection.getValue('domainname')) {
					state = 'set_domainname';
				} else {
					state = 'processing_setup';
				}
			}
			return state;
		},
		render_state: function(state) {
			var state_views = {
				set_credentials: {view: 'setCredentialsView', args: {model: this.settingsCollection}},
				set_domainname: {view: 'setDomainView', args: {model: this.settingsCollection}},
				processing_setup: {view: 'setupProcessingView', args: {}},
				dashboard: {view: 'dashboardView', args: {model: this.settingsCollection}}
			};

			var new_view = state_views[state];
			if (!typeof new_view)
				return;

			// only process if setting a new view
			if (this.currentView && this.currentView instanceof new_view.view)
				return;

			// clean up the old view
			this.currentView && this.currentView.undelegateEvents();

			this.currentView = new liftAdmin[new_view.view](new_view.args);
			this.currentView.setElement(this.el);
			this.currentView.render();
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
		initialize: function() {
			this.deferred = this.fetch();
		}
	});

	liftAdmin.settingModel = Backbone.Model.extend({
		url: function() {
			return window.ajaxurl + '?action=lift_setting&setting=' + this.get('id') + '&nonce=' + this.collection.getValue('nonce');
		},
		parse: function(res, options) {
			if (res.id)
				return res;
			return res.data || null;
		}
	});

	liftAdmin.settingsCollection = Backbone.Collection.extend({
		model: liftAdmin.settingModel,
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

	liftAdmin.domainModel = liftAdmin.Model.extend({
		action: 'domain'
	});

	liftAdmin.setCredentialsView = Backbone.View.extend({
		initialize: function() {
			this.template = _.template(liftAdmin.templateLoader.getTemplate('set-credentials'));
		},
		events: {
			'click #save_credentials': 'update_credentials'
		},
		render: function() {
			this.el.innerHTML = this.template(this.model.toJSONObject());
		},
		before_save: function() {
			$('#errors').hide();
			$('#save_credentials').disable();
		},
		after_save: function() {
			$('#save_credentials').enable();
		},
		update_credentials: function() {
			var credentials = {
				accessKey: $('#accessKey').val(),
				secretKey: $('#secretKey').val()
			};

			this.model.get('credentials')
							.save({value: credentials}, {
				success: function(model, xhr, options) {
					var success = xhr;
				},
				error: function(model, xhr, options) {
					var foo = xhr;
				}
			});
		}
	});


	liftAdmin.dashboardView = Backbone.View.extend({
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

	liftAdmin.setDomainView = Backbone.View.extend({
		initialize: function() {
			this.template = _.template(liftAdmin.templateLoader.getTemplate('set-domain'));
		},
		events: {
		},
		render: function() {
			this.el.innerHTML = this.template(this.model.toJSON());
		}

	});

	liftAdmin.setupProcessingView = Backbone.View.extend({
		initialize: function() {
			this.template = _.template(liftAdmin.templateLoader.getTemplate('setup-processing'));
		},
		events: {
		},
		render: function() {
			this.el.innerHTML = this.template(this.model.toJSON());
		}

	});

	new liftAdmin.App();

})(jQuery, window);