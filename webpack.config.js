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
        entry: {
            material: './webroot/assets/site/js/material.js',
            frontend: './webroot/assets/site/js/main.js',
            admin: './webroot/assets/admin/js/main.js',
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
                    exclude: /node_modules/,
                    use: 'babel-loader'
                },
                {
                    test: /\.css$/,
                    use: [MiniCssExtractPlugin.loader, 'css-loader'],
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
            // Jquery and Bootstrap
            new webpack.ProvidePlugin({
                $: 'jquery',
                jQuery: 'jquery',
                'window.jQuery': 'jquery',
                hljs: 'highlight.js',
                'window.hljs': 'highlight.js',
            }),

            new MiniCssExtractPlugin({
                filename: 'css/[name].bundle.css',
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
