(function($) {

	liftAdminApp = Backbone.View.extend({
		el: '#lift-status-page',
		initialize: function() {
			this.subViews = {
				setKeysView: new setKeysView({el: this.el}),
				setDomainView: new setDomainView({el: this.el}),
				setupProgressView: new setupProgressView({el: this.el}),
				stateView: new stateView({el: this.el})
			};
			this.render();
		},
		render: function() {
			this.subViews.stateView.render();
			this.subViews.setDomainView.render();
		}

	});

	var stateView = Backbone.View.extend({
		template: _.template($('#lift-state').html()),
		events: {
			'click': 'test'
		},
		render: function() {
			this.el.innerHTML = this.template();
		},
		test: function() {
			alert("STILL HERE");
		}

	});

	var setKeysView = Backbone.View.extend({
		template: _.template('<p>Set Keys View</p>'),
		events: {
		},
		render: function() {
			this.el.innerHTML = this.template();
		}

	});

	var setDomainView = Backbone.View.extend({
		template: _.template('<p>Set Domain View</p>'),
		events: {
		},
		render: function() {
			this.el.innerHTML = this.template();
			alert("HELLO");
		}

	});

	var setupProgressView = Backbone.View.extend({
		template: _.template('<p>Initialization Progress View</p>'),
		events: {
		},
		render: function() {
			this.el.innerHTML = this.template();
		}

	});

	new liftAdminApp();

})(jQuery);