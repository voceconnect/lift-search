(function($, window) {
  var liftAdmin = liftAdmin || {};

  liftAdmin.App = Backbone.Router.extend({
    el: '#lift-status-page',
    initialize: function() {
      var _this = this;

      Backbone.emulateHTTP = true;
      Backbone.emulateJSON = true;

      this.settings = new liftAdmin.SettingsCollection();
      this.domains = new liftAdmin.DomainsCollection();
      this.bind('sync_error', this.domainSyncError, this);

      $.when(this.settings.fetch()).then(function() {
        _this.render()
      });
    },
    render: function() {
      var state = this.determinState();
      state && this.renderState(state);
    },
    determinState: function() {
      var _this = this,
          state,
          domainname,
          domain,
          credentials;

      credentials = this.settings.getValue('credentials');
      if (!(credentials.accessKey && credentials.secretKey)) {
        state = 'set_credentials';
      } else {
        if (typeof this.domains.deferred === 'object' && 'then' in this.domains.deferred) {
          //rerender after domains have completely loaded
          $.when(this.domains.deferred).then(function() {
            _this.render();
          });
        } else {
          domainname = this.settings.getValue('domainname');
          domain = domainname && this.domains.get(domainname);
          if (!(domain && !domain.get('Deleted'))) {
            state = 'setDomainname';
          } else if (!this.settings.getValue('setupComplete')) {
            state = 'processing_setup';
          } else {
            state = 'dashboard';
          }
        }
      }

      return state;
    },
    renderState: function(state) {
      var new_view,
          stat_views;

      state_views = {
        set_credentials: {view: liftAdmin.SetCredentialsView, args: {model: this.settings}},
        setDomainname: {view: liftAdmin.SetDomainView, args: {model: this.settings, domains: this.domains}},
        processing_setup: {view: liftAdmin.SetupProcessingView, args: {model: this.domains.get(this.settings.getValue('domainname'))}},
        dashboard: {view: liftAdmin.DashboardView, args: {model: this.settings}}
      };

      new_view = state_views[state];

      // only process if setting a new view
      if (this.currentView && (this.currentView instanceof new_view.view)) {
        return;
      }
      // clean up the old view
      this.currentView && this.currentView.close();

      this.currentView = new new_view.view(new_view.args);
      
      this.currentView.setElement($('<div></div>').appendTo(this.el));
      this.currentView.render();
    },
    domainSyncError: function(collection, error, options) {
      var modal;
      if (error.code == 'invalidCredentials') {
        modal = new ModalSetCredentialsView({_template: 'modal-error-set-credentials', model: this.settings});
      }
    },
    open_modal: function(view) {
      view.setElement($('#modal_content'));
      view.render();
      $('#lift_modal').show();
    },
    closeModal: function(view) {
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
      var tmpUrl = window.liftData.templateDir + name + '.html';
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

  liftAdmin.SettingModel = Backbone.Model.extend({
    url: function() {
      return window.ajaxurl + '?action=lift_setting&setting=' + this.get('id') + '&nonce=' + this.collection.getValue('nonce');
    },
    parse: function(res) {
      //if came from collection
      if (res.id) {
        return res;
      }
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

  liftAdmin.DomainModel = Backbone.Model.extend({
    url: function() {
      return window.ajaxurl + '?action=lift_domain&nonce=' + this.getNonce();
    },
    idAttribute: 'DomainName',
    getNonce: function() {
      return (this.collection && this.collection.nonce) || this.nonce;
    }
  });

  liftAdmin.DomainsCollection = Backbone.Collection.extend({
    model: liftAdmin.DomainModel,
    initialize: function() {
      var _this = this;
      this.bind('sync', this.syncCheck, this);
      this.fetchWithDeferred();
    },
    fetchWithDeferred: function() {
      var _this = this,
          intervalUpdate = function() {
            _this.fetchWithDeferred();
          };
      
      this.deferred = this.fetch({
        success: function(model) {
          delete model.deferred;
          _this.updateTimeout = setTimeout(intervalUpdate, 60000);
        }
      });
      return this;
    },
    url: function() {
      return window.ajaxurl + '?action=lift_domains';
    },
    parse: function(resp) {
      this.nonce = resp.nonce;
      return resp.domains;
    },
    syncCheck: function(collection, resp, options) {
      if (resp.error) {
        this.sync_error = error;
        collection.trigger('sync_error', collection, resp.error, options);
      }
    }
  });

  Backbone.View.prototype.close = function() {
    this.remove();
    this.unbind();
    this.undelegateEvents();
    if (this.onClose) {
      this.onClose();
    }
  }

  liftAdmin.SetCredentialsView = Backbone.View.extend({
    _template: 'set-credentials',
    initialize: function() {
      var _this = this;
      this.template = _.template(liftAdmin.templateLoader.getTemplate(this._template));
      this.model.get('credentials').bind('validated:invalid', this.invalidCredentials, this);
    },
    events: {
      'click #save_credentials': 'updateCredentials'
    },
    render: function() {
      this.el.innerHTML = this.template(this.model.toJSONObject());
    },
    beforeSave: function() {
      $('#errors').hide();
      $('#save_credentials').attr('disabled', 'disabled');
    },
    afterSave: function() {
      $('#save_credentials').removeAttr('disabled');
    },
    updateCredentials: function() {
      var credentials = {
        accessKey: $('#accessKey').val(),
        secretKey: $('#secretKey').val()
      };
      this.beforeSave();

      this.model.get('credentials').save({value: credentials}, {
        success: this.saveSuccess,
        error: this.saveError
      }).always(this.afterSave);
    },
    saveSuccess: function() {
      adminApp.render();
    },
    saveError: function(model, xhr, options) {
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
    saveSuccess: function() {
      adminApp.closeModal(this);
    }
  });

  liftAdmin.DashboardView = Backbone.View.extend({
    initialize: function() {
      this.template = _.template(liftAdmin.templateLoader.getTemplate('dashboard'));
    },
    events: {
      'click #batch_interval_update': 'updateBatchInterval'
    },
    render: function() {
      this.el.innerHTML = this.template({settings: this.model.toJSONObject()});
      $('#batch_interval_unit').val(this.model.getValue('batch_interval').unit);

    },
    updateBatchInterval: function() {
      var _this = this,
          batchInterval = {
      value: $('#batch_interval').val(),
          unit: $('#batch_interval_unit').val()
      };
      this.beforeSave();
      this.model.get('batch_interval')
          .save({value: batchInterval}, {
        success: function() {
          _this.afterSave();
        }
      });
    },
    beforeSave: function() {
      $(this.el).find('input').attr('disabled', true);
    },
    afterSave: function() {
      $(this.el).find('input').attr('disabled', false);
    }

  });

  liftAdmin.SetDomainView = Backbone.View.extend({
    initialize: function() {
      var _this = this;
      this.domains = this.options.domains;
      this.template = _.template(liftAdmin.templateLoader.getTemplate('set-domain'));
      this.model.get('domainname').bind('validated:invalid', this.invalidDomainName, this);
    },
    events: {
      'click #save_domainname': 'setDomainname',
      'click #cancel': 'goBack'
    },
    render: function() {
      this.el.innerHTML = this.template(this.model.toJSONObject());
    },
    beforeSave: function() {
      $('#errors').hide();
      $('#save_domainname').attr('disabled', 'disabled');
    },
    afterSave: function() {
      $('#save_domainname').removeAttr('disabled');
    },
    setDomainname: function() {
      var _this = this,
          domainname,
          domain,
          modalView;
      this.beforeSave();
      domainname = $('#domainname').val();
      domain = this.domains.get(domainname);

      if (!domain) {
        //if domain doesn't exist, create it
        this.createDomain(domainname);
      } else {
        //have user confirm to override the existing domain
        modalView = new liftAdmin.ModalConfirmDomainView({model: this.domains.get(domainname)});
        modalView.bind('cancelled', this.modalCancelled, this);
        modalView.bind('confirmed', this.modalConfirmed, this);
        adminApp.open_modal(modalView);
      }
      //this.model.get('domainname').set({value:domainname});

    },
    modalCancelled: function(view, model) {
      $('#save_domainname').removeAttr('disabled');
      adminApp.closeModal(view);
    },
    modalConfirmed: function(view, domain) {
      adminApp.closeModal(view);
      this.useDomain(domain);
    },
    createDomain: function(domainname) {
      var domain, _this = this;
      domain = new liftAdmin.DomainModel({DomainName: domainname});
      domain.nonce = this.domains.nonce;
      domain.save(null, {
        success: function(model, xhr, options) {
          var domain = new liftAdmin.DomainModel(xhr.data);
          _this.domains.add(domain);
          _this.useDomain(domain);
        },
        error: function(model, xhr, options) {
          var errors = $.parseJSON(xhr.responseText).errors;
          _this.invalidDomainname(model, errors);
          _this.afterSave();
        }
      });
    },
    invalidDomainname: function(model, errors) {
      var template = liftAdmin.templateLoader.getTemplate('errors');
      $('#errors').html(_.template(template, {errors: errors})).show();
    },
    goBack: function() {
      adminApp.renderState('set_credentials');
    },
    useDomain: function(domain) {
      var _this = this;
      adminApp.settings.get('domainname').save({value: domain.get('DomainName')}, {
        success: function() {
          _this.domains.fetchWithDeferred();
          adminApp.render();
        }

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
      var _this = this;
      this.template = _.template(liftAdmin.templateLoader.getTemplate('setup-processing'));
      this.model.bind('change', this.render, this);
    },
    events: {
      'click #skip_status': 'fakeStatusComplete'
    },
    render: function() {
      console.log(this.model);
      this.el.innerHTML = this.template(this.model.toJSON());
    },
    fakeStatusComplete: function() {
      adminApp.renderState('dashboard');
    },
    onClose: function() {
      this.model.unbind("all", this.render);
    }

  });

  var adminApp = new liftAdmin.App();

})(jQuery, window);
