(function($, window) {
  "use strict";
  var liftAdmin = liftAdmin || {};

  liftAdmin.App = Backbone.Router.extend({
    el: '#lift-status-page',
    initialize: function() {
      var _this = this;

      Backbone.emulateHTTP = true;
      Backbone.emulateJSON = true;

      this.settings = new liftAdmin.SettingsCollection();
      this.domains = new liftAdmin.DomainsCollection();

      this.on('resetLift', this.render, this)
          .on('unsetDomainName', this.render, this);
      this.settings.on('sync reset', function() {
        var credentials = this.settings.getValue('credentials');
        if ('' == credentials.accessKey && '' == credentials.secretKey) {
          this.domains.disablePolling();
        } else {
          this.domains.enablePolling();
        }
        this.render();
      }, this)
          .fetch();

      this.domains.on('sync_error', this.handleDomainSyncError, this);

    },
    render: function() {
      var state = this.getState();
      state && this.renderState(state);
      return this;
    },
    getState: function() {
      var _this = this,
          errorModal,
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
          if (!domainname) {
            state = 'set_domainname';
          } else if (domain && !(domain.get('DocService') && domain.get('DocService').Endpoint)) {
            state = 'processing_setup';
          } else {
            if (!domain) {
              errorModal = new liftAdmin.ModalMissingDomain({model: {settings: this.settings, domains: this.domains}});
              this.openModal(errorModal);
            }
            state = 'dashboard';
          }
        }
      }

      return state;
    },
    renderState: function(state) {
      var new_view,
          state_views;

      state_views = {
        set_credentials: {view: liftAdmin.SetCredentialsView, args: {model: {settings: this.settings}}},
        set_domainname: {view: liftAdmin.SetDomainView, args: {model: {settings: this.settings, domains: this.domains}}},
        processing_setup: {view: liftAdmin.SetupProcessingView, args: {model: {settings: this.settings, domains: this.domains}}},
        dashboard: {view: liftAdmin.DashboardView, args: {model: {settings: this.settings, domains: this.domains}}}
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
      return this;
    },
    handleDomainSyncError: function(unused, error, options) {
      var modal;
      if (error.code == 'invalidCredentials') {
        modal = new liftAdmin.ModalErrorSetCredentialsView({model: {settings: this.settings}});
      } else {
        modal = new liftAdmin.ModalError({model: {settings: this.settings, domains: this.domains, error: error}});
      }

      modal && this.openModal(modal);
      return this;
    },
    openModal: function(view) {
      this.currentModal && this.close(this.currentModal);
      view.setElement($('#modal_content'));
      view.render();
      $('#lift_modal').show();
      this.currentModal = view;
      return this;
    },
    closeModal: function(view) {
      view.close();
      $('#modal_content').html('');
      $('#lift_modal').hide();
      delete this.currentModal;
      return this;
    },
    resetLift: function(options) {
      var success,
          silent;

      options = options ? _.clone(options) : {};
      success = options.success;
      silent = options.silent;
      options.silent = true;
      options.success = function(object, options) {
        var _this = object,
            success;
        options = options ? _.clone(options) : {};
        success = options.success;
        options.success = function() {
          if (success)
            success(_this, options);
          if (!silent) {
            _this.trigger('resetLift', _this, options);
          }
        };
        _this.settings.get('credentials').save({value: {accessKey: '', secretKey: ''}}, options);
        return this;
      };
      this.unsetDomainName(options);
    },
    unsetDomainName: function(options) {
      var _this = this,
          success;
      options = options ? _.clone(options) : {};
      success = options.success;
      options.success = function() {
        if (success)
          success(_this, options);
        if (!options.silent) {
          _this.trigger('unsetDomainName', _this, options);
        }
      }
      this.settings.get('domainname').save({value: ''}, options);
      return this;
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
      this.disablePolling();
    },
    enablePolling: function() {
      this.pollingEnabled || this.fetchWithDeferred();
      this.pollingEnabled = true;
      return this;
    },
    disablePolling: function() {
      this.pollingEnabled && clearTimeout(this.pollingTimeout);
      this.pollingEnabled = false;
      return this;
    },
    fetchWithDeferred: function() {
      var _this = this,
          intervalUpdate = function() {
        _this.fetchWithDeferred();
      };

      return this.deferred = this.fetch()
          .always(function() {
        delete _this.deferred;
        if (_this.pollingEnabled) {
          _this.pollingTimeout = setTimeout(intervalUpdate, 20000);
        }
        if (_this.error && _this.pollingEnabled) {
          _this.trigger('sync_error', this, _this.error);
        }

      });
    },
    url: function() {
      return window.ajaxurl + '?action=lift_domains';
    },
    parse: function(resp) {
      this.nonce = resp.nonce;
      this.error = resp.error;
      return resp.domains;
    },
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
      this.template = _.template(liftAdmin.templateLoader.getTemplate(this._template));
      this.model.settings.get('credentials').on('error', this.onSaveError, this);
    },
    onClose: function() {
      this.model.settings.get('credentials').off('error', this.onSaveError, this);
    },
    events: {
      'click #save_credentials': 'updateCredentials'
    },
    render: function() {
      this.el.innerHTML = this.template(this.model.settings.toJSONObject());
      return this;
    },
    beforeSave: function() {
      $('#errors').hide();
      $('#save_credentials').attr('disabled', 'disabled');
    },
    afterSave: function() {
      $('#save_credentials').removeAttr('disabled');
    },
    updateCredentials: function() {
      var _this = this,
          credentials = {
        accessKey: $('#accessKey').val(),
        secretKey: $('#secretKey').val()
      };
      this.beforeSave();

      this.model.settings.get('credentials').save({value: credentials}, {
      }).always(function() {
        _this.afterSave();
      });
    },
    onSaveError: function(model, resp, options) {
      var errors = $.parseJSON(resp.responseText).errors;
      this.renderErrors(errors);
      return this;
    },
    renderErrors: function(errors) {
      var template = liftAdmin.templateLoader.getTemplate('errors');
      $('#errors').html(_.template(template, {errors: errors})).show();
      return this;
    }

  });

  liftAdmin.ModalSetCredentialsView = liftAdmin.SetCredentialsView.extend({
    _template: 'modal-set-credentials',
    initialize: function() {
      this.model.settings.get('credentials').on('sync', this.closeModal, this);
    },
    onClose: function() {
      this.model.settings.get('credentials').off('sync', this.closeModal, this);
    },
    closeModal: function() {
      adminApp.closeModal(this);
    }
  });

  liftAdmin.ModalErrorSetCredentialsView = liftAdmin.SetCredentialsView.extend({
    _template: 'modal-error-set-credentials',
  });

  liftAdmin.ModalError = Backbone.View.extend({
    _template: 'modal-error',
    initialize: function() {
      this.template = _.template(liftAdmin.templateLoader.getTemplate(this._template));
      this.model.domains.on('reset', this.closeIfFixed, this);
    },
    render: function() {
      this.el.innerHTML = this.template({settings: this.model.settings.toJSONObject(), error: this.model.error});
      return this;
    },
    closeIfFixed: function() {
      !this.model.domains.error && adminApp.closeModal(this);
      return this;
    }
  });

  liftAdmin.ModalMissingDomain = Backbone.View.extend({
    _template: 'modal-error-missing-domain',
    initialize: function() {
      this.template = _.template(liftAdmin.templateLoader.getTemplate(this._template));
    },
    events: {
      'click #reset_lift': 'resetLift',
      'click #unset_domainname': 'unsetDomainName'
    },
    render: function() {
      this.el.innerHTML = this.template({settings: this.model.settings.toJSONObject(), error: this.model.error});
      return this;
    },
    resetLift: function() {
      adminApp.on('resetLift', this.closeModal, this)
          .resetLift();
      return this;
    },
    unsetDomainName: function() {
      adminApp.on('unsetDomainName', this.closeModal, this)
          .unsetDomainName();
      return this;
    },
    closeModal: function() {
      adminApp.closeModal(this);
    }
  });

  liftAdmin.DashboardView = Backbone.View.extend({
    initialize: function() {
      this.template = _.template(liftAdmin.templateLoader.getTemplate('dashboard'));
      this.model.domains.on('reset', this.render, this);
    },
    onClose: function() {
      this.model.domains.off('reset', this.render, this);
    },
    events: {
      'click #batch_interval_update': 'updateBatchInterval',
      'click #lift_reset': 'resetLift'
    },
    render: function() {
      this.el.innerHTML = this.template({settings: this.model.settings.toJSONObject(), domain: this.model.domains.toJSON()});
      $('#batch_interval_unit').val(this.model.settings.getValue('batch_interval').unit);
      return this;
    },
    updateBatchInterval: function() {
      var _this = this,
          batchInterval = {
        value: $('#batch_interval').val(),
        unit: $('#batch_interval_unit').val()
      };
      this.beforeSave();
      this.model.settings.get('batch_interval')
          .save({value: batchInterval}, {
      }).always(function() {
        _this.afterSave();
      });
      return this;
    },
    beforeSave: function() {
      $(this.el).find('input').attr('disabled', true);
      return this;
    },
    afterSave: function() {
      $(this.el).find('input').attr('disabled', false);
      return this;
    },
    resetLift: function() {
      adminApp.resetLift();
      return this;
    },
  });

  liftAdmin.SetDomainView = Backbone.View.extend({
    initialize: function() {
      this.template = _.template(liftAdmin.templateLoader.getTemplate('set-domain'));
      this.model.settings.get('domainname').on('sync', this.onCreateDomainSuccess, this);
      this.model.settings.get('domainname').on('error', this.onCreateDomainError, this);
    },
    onClose: function() {
      this.model.settings.get('domainname').on('sync', this.onCreateDomainSuccess, this);
      this.model.settings.get('domainname').off('error', this.onCreateDomainError, this);
    },
    events: {
      'click #save_domainname': 'setDomainname',
      'click #cancel': 'goBack'
    },
    render: function() {
      this.el.innerHTML = this.template(this.model.settings.toJSONObject());
      return this;
    },
    beforeSave: function() {
      $('#errors').hide();
      $('#save_domainname').attr('disabled', 'disabled');
      return this;
    },
    afterSave: function() {
      $('#save_domainname').removeAttr('disabled');
      return this;
    },
    setDomainname: function() {
      var domainname,
          domain,
          modalView;
      this.beforeSave();
      domainname = $('#domainname').val();
      domain = this.model.domains.get(domainname);

      if (!domain) {
        //if domain doesn't exist, create it
        this.createDomain(domainname);
      } else {
        //have user confirm to override the existing domain
        modalView = new liftAdmin.ModalConfirmDomainView({model: this.model.domains.get(domainname)});
        modalView.bind('cancelled', this.modalCancelled, this);
        modalView.bind('confirmed', this.modalConfirmed, this);
        adminApp.openModal(modalView);
      }
      return this;
    },
    modalCancelled: function(view, model) {
      $('#save_domainname').removeAttr('disabled');
      adminApp.closeModal(view);
      return this;
    },
    modalConfirmed: function(view, domain) {
      adminApp.closeModal(view);
      this.useDomain(domain);
      return this;
    },
    createDomain: function(domainname) {
      var domain, _this = this;
      domain = new liftAdmin.DomainModel({DomainName: domainname});
      domain.nonce = this.model.domains.nonce;
      domain.save().always(function() {
        _this.afterSave();
      });
      return this;
    },
    onCreateDomainSuccess: function(model, resp, options) {
      var domain = new liftAdmin.DomainModel(resp.data);
      this.model.domains.add(domain);
      this.useDomain(domain);
    },
    onCreateDomainError: function(model, resp, options) {
      var errors = $.parseJSON(resp.responseText).errors;
      this.renderErrors(errors);
    },
    renderErrors: function(errors) {
      var template = liftAdmin.templateLoader.getTemplate('errors');
      $('#errors').html(_.template(template, {errors: errors})).show();
      return this;
    },
    goBack: function() {
      adminApp.renderState('set_credentials');
      return this;
    },
    useDomain: function(domain) {
      adminApp.settings.get('domainname').save({value: domain.get('DomainName')});
      return this;
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
      return this;
    },
    confirm: function() {
      this.trigger('confirmed', this, this.model);
      return this;
    },
    cancel: function() {
      this.trigger('cancelled', this, this.model);
      return this;
    }

  });

  liftAdmin.SetupProcessingView = Backbone.View.extend({
    initialize: function() {
      this.template = _.template(liftAdmin.templateLoader.getTemplate('setup-processing'));
      this.model.domains.on('reset', this.render, this);
    },
    events: {
      'click #skip_status': 'fakeStatusComplete'
    },
    render: function() {
      var domain = this.model.domains.get(this.model.settings.getValue('domainname'));
      if (!domain || domain.get('DocService').EndPoint) {
        adminApp.render();
        return this;
      }

      this.el.innerHTML = this.template(domain.toJSON());
      return this;
    },
    fakeStatusComplete: function() {
      adminApp.renderState('dashboard');
      return this;
    },
    onClose: function() {
      this.model.domains.off('reset', this.render, this);
      return this;
    }

  });

  var adminApp = new liftAdmin.App();

})(jQuery, window);
