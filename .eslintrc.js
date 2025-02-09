module.exports = {
    env: {
        browser: true,
    },
    globals: {
        "$": "readonly",
        "jQuery": "readonly",
        "webshim": "readonly",
        "hljs": "readonly",
    },
    rules: {
        "no-eval": "off",
        "no-undef": "warn",
    },
    parserOptions: {
        ecmaVersion: 2020,
    },
    overrides: [],
};
