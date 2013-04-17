/*global window, $, jQuery, document, ajaxurl*/

/**
 * Manages JS-Enhanced search form
 * @module LiftSearchForm
 */
var LiftSearchForm = (function (document, jQuery) {
	"use strict";

	var $, options, bindClickFields, focusOnTerm, setValue, togglers, submitForm, module;
	
	$ = jQuery;
	/**
	 * Extendable options for the module
	 * @property
	 * @type Object
	 */
	options = {
		'submitOnClick': false
	};
	
	/**
	 * Take the clicked HTML element and set the form element value
	 * @method setValue
	 * @param el HTMLel
	 */
	setValue = function setValue(el){
		var boundTo, val, selectEl;
		boundTo = $(el).parent().data('lift_bind');
		val = $(el).data('lift_value');
		selectEl = $('.' + boundTo); // get by class, not ID since there may be more than one set of filters on a page
		selectEl.find('option').removeAttr("selected");
		selectEl.find('option[value="'+val+'"]').attr('selected', 'selected') ;
	};

	/**
	 * Attach a jQuery live click event to each selectable faux form element
	 * @method bindClickFields
	 */
	bindClickFields = function bindClickFields(){
		$('li[data-lift_value]').live('click', function(e){
			e.preventDefault();
			setValue(this);
			if(options.submitOnClick === true){
				submitForm($(this).closest('form'));
			}
		});
	};
	
	/**
	 *@method focusOnTerm
	 **/
	focusOnTerm = function focusOnTerm(){
		$("#s").mouseup(function(e){
			e.preventDefault();
		});
		$("#s").focus(function(){
			$(this).select();
		});
	};
	
	/**
	 * Handle the show/hide of the select/list elements
	 * @method togglers
	 */
	togglers = function togglers(){
		$('body').live('click', function(e){
			var $target = $(e.target);
			if($target.parent().data('role') === 'list-toggler'){
				e.preventDefault();
				$target = $target.parent();
				if($target.find('ul').is(":visible")){
					$target.removeClass('clicked');
					$target.children('ul').hide();
				}else {
					$target.addClass('clicked');
					$target.siblings('li').removeClass('clicked').children('ul').hide();
					$target.children('ul').show();	
				}
			} else {
				$("li[data-role='list-toggler']").each(function(){
					$(this).removeClass('clicked').children('ul').hide();
				});
			}
		});

	};
	
	/**
	 * Send form
	 * @method submitForm
	 */
	submitForm = function submitForm(formEl){
		formEl.submit();
	};
	
	/**
	 * @method module
	 * @constructor
	 * @param o Object Options to be extended to the instance
	 */
	module = function module(o) {
		o = o || {};
		options = $.extend(options, o);
		$('.lift-js-filters').show();
		bindClickFields();
		togglers();
		focusOnTerm();
	};

	/**
	 * @prototype for the module
	 */
	module.prototype = {
		constructor: module
	};

	return module;
}(document, jQuery));
var hideDefaultForm;

/**
 * If JS is enabled this method hide the regular form, allowing the custom 
 * list element styles
 **/
hideDefaultForm = function hideDefaultForm(){
	"use strict";
	var css = '',//.lift-search-form-filters, .lift-submit, .lift-hidden { display: none; }',
	head = document.getElementsByTagName('head')[0],
	style = document.createElement('style');

	style.type = 'text/css';
	if(style.styleSheet){
		style.styleSheet.cssText = css;
	}else{
		style.appendChild(document.createTextNode(css));
	}
	head.appendChild(style);

};
hideDefaultForm();

/**
 * Instantiate the Lift Search Form
 */
jQuery(document).ready(function(){
	"use strict";
	window.lift_search_form = new LiftSearchForm({
		'submitOnClick':true
	});
});
