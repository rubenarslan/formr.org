/**
 * Uses cookieconsent
 * @see https://cookieconsent.insites.com/documentation/javascript-api/
 * 
 */

if (!window.cookieconsent) {
    throw "cookieconsent not found";
}

function FormrCookieConsent(cookieconsent, _config) {
    this.cookieconsent = cookieconsent;
    this.config = this.mergeConfig(_config);
    this._init = false;
}

FormrCookieConsent.prototype.init = function () {
    if (this._init) {
        return;
    }
    this.initCookieConsent();
    this._init = true;
};

FormrCookieConsent.prototype.mergeConfig = function (config) {
    var message = "" +
            "On our website we're using cookies to optimize user experience and to improve our website. " +
			"By using our website you agree that cookies can be stored on your local computer";

    var defaultConfig = {
        palette: {
            popup: {background: '#333333', text: '#fff', link: '#fff'},
			button: {background: "#8dc63f", text: '#fff'}
        },
        content: {
            message: message,
            dismiss: 'OK',
            allow: 'Allow cookies',
            deny: 'Decline',
            link: 'Learn more',
            href: config.href || window.formr.site_url,
            close: '&#x274c;'
        },
        cookie: {
            name: 'formrcookieconsent',
            expiryDays: 2 * 365 // 2 years
        }
    };

    return this.extendObject(defaultConfig, config);
};

FormrCookieConsent.prototype.isPlainObject = function (obj) {
    return typeof obj === 'object' && obj !== null && obj.constructor == Object;
};

FormrCookieConsent.prototype.extendObject = function (target, source) {
    for (var prop in source) {
        if (source.hasOwnProperty(prop)) {
            if (prop in target && this.isPlainObject(target[prop]) && this.isPlainObject(source[prop])) {
                this.extendObject(target[prop], source[prop]);
            } else {
                target[prop] = source[prop];
            }
        }
    }

    return target;
};

FormrCookieConsent.prototype.initCookieConsent = function () {
    var self = this;

    window.addEventListener("load", function () {
        self.cookieconsent.initialise(self.config);
    });
};

// Call
(function () {
    (new FormrCookieConsent(window.cookieconsent, window.formr.cookieconsent || {})).init();
})();
