webshim.setOptions({
    extendNative: false,
    waitReady: false,
    loadStyles: true,
    replaceUI: true,
    basePath: '/assets/build/js/shims/',
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
            errorBoxClass: 'controls ws-errorbox',
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
        replaceUI: {range: true, color: true, date: true, month: true, number: true},
        widgets: {
            'startView': 1,
            'openOnFocus': true,
            'calculateWidth': false
        }
    }
});

// Add language detection and management
window.formrLanguage = {
    getPreferredLanguage: function() {
        // Get from localStorage if previously set
        const storedLang = localStorage.getItem('formr-language');
        if (storedLang) {
            return storedLang;
        }

        // Otherwise detect from browser
        const browserLang = navigator.language.split('-')[0].toLowerCase();
        
        // Store for future use
        localStorage.setItem('formr-language', browserLang);
        return browserLang;
    },

    setLanguage: function(lang) {
        const normalizedLang = lang.toLowerCase();
        localStorage.setItem('formr-language', normalizedLang);
        webshim.activeLang(normalizedLang);
        // Trigger a custom event that other components can listen to
        $(document).trigger('formr-language-changed', [normalizedLang]);
    },

    // Translation helper that uses webshim's language system
    translate: function(text) {
        const currentLang = webshim.activeLang();
        return window.formrTranslations?.[currentLang]?.[text] || text;
    }
};
window.formrTranslations = {};

// Initialize language
const detectedLang = window.formrLanguage.getPreferredLanguage();
webshim.activeLang(detectedLang);

webshim.polyfill('es5 es6 forms forms-ext geolocation');
