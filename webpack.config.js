const path = require('path');
const webpack = require('webpack');
const CopyWebpackPlugin = require('copy-webpack-plugin');

module.exports = {
    mode: 'development', // Change to 'production' for optimized builds
    entry: {
        material: './webroot/assets/site/js/material.js',
        frontend: './webroot/assets/site/js/main.js',
        admin: './webroot/assets/admin/js/main.js',
    },
    output: {
        filename: 'js/[name].bundle.js',
        path: path.resolve(__dirname, 'webroot/assets/build'),
        clean: true,
    },
    resolve: {
        extensions: ['.js'],
    },
    module: {
        rules: [
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: 'babel-loader'
            },
            {
                test: /\.css$/,
                use: ['style-loader', 'css-loader'], // Handle CSS files
            },
            // Fonts
            {
                test: /\.(woff(2)?|eot|ttf|otf|svg)$/, // Match font files
                type: 'asset/resource', // Copy the files to the output directory
                generator: {
                    filename: 'fonts/[name][ext]', // Place them in the 'fonts' folder
                },
            },
            // Images
            {
                test: /\.(jpeg|jpg|png|gif)$/, // Match font files
                type: 'asset/resource', // Copy the files to the output directory
                generator: {
                    filename: 'img/[name][ext]', // Place them in the 'fonts' folder
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
        
        new CopyWebpackPlugin({
            patterns: [
                // Ace editor
                {
                    from: path.resolve(__dirname, 'node_modules/ace-builds/src-noconflict'), // Path to Ace in node_modules
                    to: path.resolve(__dirname, 'webroot/assets/build/js/ace'), // Copy to 'build/ace'
                },
                // Webshim
                {
                    from: path.resolve(__dirname, 'node_modules/webshim/js-webshim/minified/shims'), // Path to Ace in node_modules
                    to: path.resolve(__dirname, 'webroot/assets/build/js/shims'), // Copy to 'build/ace'
                },
                // Site Images
                {
                    from: path.resolve(__dirname, 'webroot/assets/site/img/'), // Path to Ace in node_modules
                    to: path.resolve(__dirname, 'webroot/assets/build/img/'), // Copy to 'build/ace'
                },
                // Admin Images
                {
                    from: path.resolve(__dirname, 'webroot/assets/admin/img/'), // Path to Ace in node_modules
                    to: path.resolve(__dirname, 'webroot/assets/build/img/'), // Copy to 'build/ace'
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
        // jquery: 'jQuery', // Make jQuery available globally
        //bootstrap: 'bootstrap', // Bootstrap is optional here since it relies on styles more
    },
};
