(function() {
	"use strict";
	/**
	 * formr.org main.js
	 * @requires jQuery, webshim
	 */
	webshim.setOptions({
		extendNative: false,
		waitReady: false,
		forms: {
			addValidators: true,
	        iVal: {
	            //add config to find right wrapper
	            fieldWrapper: '.form-group',
				//wether an invalid input should be re-checked while user types
				recheckDelay: 600,
	            //add bootstrap specific classes
	            errorMessageClass: 'help-block',
		    	errorMessageWrapper: "span",
	            successWrapperClass: '',
	            errorWrapperClass: 'has-error',
            
	            //general iVal cfg
	            sel: '.ws-validate',
	            handleBubble: 'hide' // hide error bubble
	        },
			customDatalist: true,
			replaceValidationUI: true
		},
		geolocation: {
			confirmText: '{location} wants to know your position. You will have to enter one manually if you decline.'
		},
		'forms-ext': {
			types: 'range date time number month color',
			customDatalist: true,
			replaceUI: {range: true, color: true}
		}
	});
	webshim.polyfill('es5 forms forms-ext geolocation');
	webshim.activeLang('de');


	$(document).ready(function() {
		if ($(".schmail").length == 1) {
			var schmail = $(".schmail").attr('href');
			schmail = schmail.replace("IMNOTSENDINGSPAMTO", "").
					replace("that-big-googly-eyed-email-provider", "gmail").
					replace(encodeURIComponent("If you are not a robot, I have high hopes that you can figure out how to get my proper email address from the above."), "").
					replace(encodeURIComponent("\r\n\r\n"), "");
			$(".schmail").attr('href', schmail);
		}
		if ($(".navbar-toggle").length == 1)
			var navbar_toggle = new FastClick($(".navbar-toggle")[0]); // particularly annoying if this one doesn't fastclick

		$('*[title]').tooltip({
			container: 'body'
		});

		hljs.initHighlighting();
		$('.nav-tabs, .tab-content').stickyStuff();

		// Higlight current menu item
		$('ul.menu-highlight a').each(function() {
			var $a = $(this);
			var href = $a.attr('href');
			if (href === document.location.href) {
				$a.parents('li').addClass('active');
			}
		});

		//Social share button click
		$('.social-share-icon').unbind('click').bind('click', function() {
			var $social = $(this), href = $social.attr('data-href');
			if (href) {
				if ($social.attr('data-target')) {
					window.open(href, $social.attr('data-target'), $social.attr('data-width') ? 'width=' + $social.attr('data-width') + ',height=' + $social.attr('data-height') : undefined);
				} else {
					window.location.href = href;
				}
			}
		});
	});
}());

function general_alert(message, place) {
	$(place).append(message);
}

function bootstrap_alert(message, bold, where, cls) {
	cls = cls || 'alert-danger';
	var $alert = $('<div class="row"><div class="col-md-6 col-sm-6 all-alerts"><div class="alert ' + cls + '"><button type="button" class="close" data-dismiss="alert">&times;</button><strong>' + (bold ? bold : 'Problem') + '</strong> ' + message + '</div></div></div>');
	$alert.prependTo($(where));
	$alert[0].scrollIntoView(false);
}

function bootstrap_modal(header, body, t) {
	t = t || 'tpl-test-modal';
	var $modal = $($.parseHTML(getHTMLTemplate(t, {'body': body, 'header': header})));
	$modal.modal('show').on('hidden.bs.modal', function() {
		$modal.remove();
	});
	return $modal;
}

function bootstrap_spinner() {
	return ' <i class="fa fa-spinner fa-spin"></i>';
}

function ajaxErrorHandling(e, x, settings, exception) {
	var message;
	var statusErrorMap = {
		'400': "Server understood the request but request content was invalid.",
		'401': "You don't have access.",
		'403': "You were logged out while coding, please open a new tab and login again. This way no data will be lost.",
		'404': "Page not found.",
		'500': "Internal Server Error.",
		'503': "Server can't be reached."
	};
	if (e.status) {
		message = statusErrorMap[e.status];
		if (!message)
			message = (typeof e.statusText !== 'undefined' && e.statusText !== 'error') ? e.statusText : 'Unknown error. Check your internet connection.';
	}
	else if (e.statusText === 'parsererror')
		message = "Parsing JSON Request failed.";
	else if (e.statusText === 'timeout')
		message = "The attempt to save timed out. Are you connected to the internet?";
	else if (e.statusText === 'abort')
		message = "The request was aborted by the server.";
	else
		message = (typeof e.statusText !== 'undefined' && e.statusText !== 'error') ? e.statusText : 'Unknown error. Check your internet connection.';

	if (e.responseText) {
		var resp = $(e.responseText);
		resp = resp.find(".alert").addBack().filter(".alert").html();
		message = message + "<br>" + resp;
	}

	bootstrap_alert(message, 'Error.', '.main_body');
}

function stringTemplate(string, params) {
	for (var i in params) {
		var t = "%?\{" + i + "\}";
		string = string.replace((new RegExp(t, 'g')), params[i]);
	}
	return string;
}

function getHTMLTemplate(id, params) {
	var $tpl = jQuery('#' + id);
	if (!$tpl.length)
		return;
	return stringTemplate($.trim($tpl.html()), params);
}

function toggleElement(id) {
	$('#' + id).toggleClass('hidden');
}

/**
 * jQuery Plugin: Sticky Accordion and Tabs
 *
 */
(function($) {
	"use strict";
	$.fn.stickyStuff = function() {
		var context = this;
		// Show the tab/collapsible corresponding with the hash in the URL, or the first tab (if the collapsible is inside a tab, show that too).
		var showStuffFromHash = function() {
			var hash = window.location.hash;
			var selector = hash ? 'a[href="' + hash + '"]' : 'li.active > a';
			if($(selector, context).data('toggle') === "tab") {
				$(selector, context).tab('show');
			} else if($(selector, context).data('toggle') === "collapse") {
				var collapsible = hash;
				$(collapsible, context).collapse("show");
				var parent_tab = $(collapsible, context).parents('.tab-pane');
				if (parent_tab && !parent_tab.hasClass("active")) {
					$('a[href=#' + parent_tab.attr('id') + ']').tab('show');
				}
			}
		};
		

		// Set the correct tab when the page loads
		showStuffFromHash(context);

		// Set the correct tab when a user uses their back/forward button
		$(window).on('hashchange', function() {
			showStuffFromHash(context);
		});

		// Change the URL when tabs are clicked
		$('a', context).on('click', function(e) {
			history.pushState(null, null, this.href);
			showStuffFromHash(context);
		});

		return this;
	};
}(jQuery));
