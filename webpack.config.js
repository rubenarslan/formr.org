const path = require('path');
const webpack = require('webpack');
const CopyWebpackPlugin = require('copy-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = (env, argv) => {
    const isWatchMode = argv.watch || false;
    const outputDir = isWatchMode ? 'webroot/assets/dev-build' : 'webroot/assets/build';
    console.log('Webpack watch mode:', isWatchMode, ' | Output dir:', outputDir);

    return {
        target: ['web', 'es5'],
        mode: isWatchMode ? 'development' : 'production',
        // Dev (watch) mode defaults to devtool:'eval', which wraps every module
        // in eval() — blocked by the admin enforce-mode CSP (script-src has no
        // 'unsafe-eval'; see application/Csp.php). Use a non-eval source map so
        // dev-build/ bundles comply with the same CSP as prod build/ (which is
        // devtool:false). Keeps CSP enforcement exercised in dev. Prod stays
        // eval-free and unmapped.
        devtool: isWatchMode ? 'cheap-module-source-map' : false,
        entry: {
            material: './webroot/assets/site/js/material.js',
            frontend: './webroot/assets/site/js/main.js',
            admin: './webroot/assets/admin/js/main.js',
            form: './webroot/assets/form/js/main.js',
            // The participant's logout page: deletes the run's offline queue.
            logout: './webroot/assets/form/js/logout.js',
        },
        output: {
            filename: 'js/[name].bundle.js',
            path: path.resolve(__dirname, outputDir),
            clean: true,
        },
        // Limit parallel processing to prevent memory exhaustion
        parallelism: 1,
        resolve: {
            extensions: ['.js'],
        },
        module: {
            rules: [
                {
                    test: require.resolve('jquery'),
                    loader: 'expose-loader',
                    options: {
                        exposes: [
                            {
                                globalName: '$',
                                override: true,
                            },
                            {
                                globalName: 'jQuery',
                                override: true,
                            },
                        ],
                    },
                },
                {
                    test: /\.js$/,
                    // Transpile all JS, including most node_modules, but skip core-js and regenerator-runtime
                    exclude: /node_modules\/(core-js|regenerator-runtime)\//,
                    use: {
                      loader: 'babel-loader',
                      options: {
                        presets: [
                            ['@babel/preset-env', {
                                useBuiltIns: 'usage',
                                corejs: { version: 3, proposals: false },
                                targets: '> 0.2%, not dead, Chrome >= 60'
                              }]
                        ],
                        sourceType: 'unambiguous'
                      }
                    }
                  },
                // CSS pipeline. Default is style-loader (CSS injected at
                // runtime via <style> tags from the JS bundle). The form
                // bundle gets MiniCssExtractPlugin instead so the CSS lands
                // in a separate file and form_index.php can <link> it in
                // <head>, killing the flash-of-unstyled-content on iOS
                // Safari that style-loader caused. Other bundles keep
                // style-loader so their currently-working flow doesn't
                // change.
                {
                    test: /\.css$/,
                    oneOf: [
                        {
                            issuer: /[\\/]webroot[\\/]assets[\\/]form[\\/]/,
                            use: [MiniCssExtractPlugin.loader, 'css-loader'],
                        },
                        {
                            use: ['style-loader', 'css-loader'],
                        },
                    ],
                },
                {
                    test: /\.s[ac]ss$/,
                    oneOf: [
                        {
                            issuer: /[\\/]webroot[\\/]assets[\\/]form[\\/]/,
                            use: [MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader'],
                        },
                        {
                            use: ['style-loader', 'css-loader', 'sass-loader'],
                        },
                    ],
                },
                {
                    test: /add-to-homescreen.*\.css$/,
                    enforce: 'pre',
                    loader: 'string-replace-loader',
                    options: {
                        search: '@import url\\([^)]*fonts\\.googleapis\\.com[^)]*\\);?',
                        replace: '',
                        flags: 'g',
                    },
                },
                // Fonts
                {
                    test: /\.(woff(2)?|eot|ttf|otf|svg)$/,
                    type: 'asset/resource',
                    generator: {
                        filename: 'fonts/[name][ext]',
                    },
                },
                // Images
                {
                    test: /\.(jpeg|jpg|png|gif)$/,
                    type: 'asset/resource',
                    generator: {
                        filename: 'img/[name][ext]',
                    },
                }
            ],
        },
        plugins: [
            // CSS extraction for the form bundle (see CSS rule above).
            // filename uses [name] which becomes 'form' from the entry key,
            // landing the file at css/form.bundle.css next to the JS.
            new MiniCssExtractPlugin({
                filename: 'css/[name].bundle.css',
            }),

            // Jquery and Bootstrap
            new webpack.ProvidePlugin({
                $: 'jquery',
                jQuery: 'jquery',
                'window.jQuery': 'jquery',
                hljs: 'highlight.js',
                'window.hljs': 'highlight.js',
            }),

            new CopyWebpackPlugin({
                patterns: [
                    // Ace editor
                    {
                        from: 'node_modules/ace-builds/src-min-noconflict',
                        to: path.resolve(__dirname, outputDir + '/js/ace'),
                        info: { minimized: false },
                    },
                    // Webshim
                    {
                        from: path.resolve(__dirname, 'node_modules/webshim/js-webshim/minified/shims'),
                        to: path.resolve(__dirname, outputDir + '/js/shims'),
                        info: { minimized: false },
                    },
                    // Altcha widget for the signup bot-check (registration.challenge
                    // = 'altcha') and form_v2's bot_check item
                    // (form/js/items/bot-check.js): shipped as its prebuilt
                    // standalone module and loaded via <script type=module>, NOT
                    // webpack-bundled (its ESM plugin loader uses a dynamic
                    // require webpack can't resolve). Self-hosted — no CDN, no
                    // third party. The external build does not inject styles at
                    // runtime, so its stylesheet ships alongside.
                    {
                        from: path.resolve(__dirname, 'node_modules/altcha/dist/external/altcha.min.js'),
                        to: path.resolve(__dirname, outputDir + '/js/altcha/altcha.min.js'),
                        info: { minimized: true },
                    },
                    {
                        from: path.resolve(__dirname, 'node_modules/altcha/dist/external/altcha.css'),
                        to: path.resolve(__dirname, outputDir + '/js/altcha/altcha.css'),
                        info: { minimized: true },
                    },
                    // Memory-hard Argon2id Web Worker (inline WASM, no network):
                    // the widget's solver for the challenges AltchaChallenge
                    // mints. Copied verbatim — es5 target + worker-chunk
                    // publicPath make bundling it fragile.
                    {
                        from: path.resolve(__dirname, 'node_modules/altcha/dist/workers/argon2id.js'),
                        to: path.resolve(__dirname, outputDir + '/js/altcha/argon2id.js'),
                        info: { minimized: true },
                    },
                    // Site Images
                    {
                        from: path.resolve(__dirname, 'webroot/assets/site/img/'),
                        to: path.resolve(__dirname, outputDir + '/img/'),
                        info: { minimized: false },
                    },
                    // Admin Images
                    {
                        from: path.resolve(__dirname, 'webroot/assets/admin/img/'),
                        to: path.resolve(__dirname, outputDir + '/img/'),
                        info: { minimized: false },
                    },
                    // Add-to-homescreen assets
                    {
                        from: path.resolve(__dirname, 'node_modules/add-to-homescreen/dist/assets/img/'),
                        to: path.resolve(__dirname, outputDir + '/assets/img/'),
                        info: { minimized: false },
                    },
                ],
            }),
        ],
        devServer: {
            static: path.resolve(__dirname, 'build'),
            compress: true,
            port: 9000,
            hot: true,
            open: true,
        },
        externals: {
            // jquery: 'jQuery',
            //bootstrap: 'bootstrap', // Bootstrap is optional here since it relies on styles more
        }
    }
};
