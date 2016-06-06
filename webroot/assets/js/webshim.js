webshim.setOptions({
    extendNative: false,
    waitReady: false,
    loadStyles: true,
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
        replaceUI: {range: true, color: true}
    }
});
webshim.polyfill('es5 es6 forms forms-ext geolocation');
webshim.activeLang('de');
