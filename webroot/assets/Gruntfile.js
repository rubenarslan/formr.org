module.exports = function (grunt) {

    /**
     * Read assets structure from assets.json and define
     * common assets using 'asset key' of site and admin
     */
    var assets = grunt.file.readJSON('assets.json');
    var common_assets = assets["config"]["common"];
    var site_assets = assets["config"]["site"];
    var admin_assets = assets["config"]["admin"];

    /**
     * Returns the JS or CSS structure of an asset defined in assets.json
     * 
     * @param {Array|String} asset String representing asset key or an array of asset keys
     * @param {String} type. Strings 'css' or 'js'
     * @return {Array}
     */
    function _asset(asset, type) {
        var res = [];
        if (Array.isArray(asset)) {
            return _asset_array(asset, type);
        }

        if (typeof assets[asset] != 'undefined') {
            var _asset = assets[asset], res = [];
            if (typeof _asset[type] != 'undefined') {
                if (Array.isArray(_asset[type])) {
                    res = _asset[type];
                } else {
                    res = [_asset[type]];
                }
            }
        }
        return res;
    }

    /**
     * Returns the JS or CSS structure of an array of assets
     * 
     * @param {Array} asset Array of asset keys
     * @param {String} type. Strings 'css' or 'js'
     * @return {Array}
     */
    function _asset_array(asset, type) {
        var res = [];
        for (var a in asset) {
            res = res.concat(_asset(asset[a], type));
        }
        return res;
    }

    /**
     * Returns the CSS structure of an asset
     *
     * @see _asset
     */
    function _css(asset) {
        return _asset(asset, 'css');
    }

    /**
     * Returns the JS structure of an asset
     *
     * @see _asset
     */
    function _js(asset) {
        return _asset(asset, 'js');
    }

    /**
     * Gets an array of arrays and returns a single array
     *
     * @param {Array}
     * @return {Array}
     */
    function _flattern(array) {
        return [].concat.apply([], array);
    }

    // Grunt project configuration
    grunt.initConfig({
        // Read package configuration
        pkg: grunt.file.readJSON('package.json'),

        // Install bower components
        bower: {
            install: {
                options: {
                    targetDir: "bower_components/",
                    copy: false
                }
            }
        },

        // Copy required/missing files from packages to build folder
        copy: {
            // Copy webshim
            webshim: {
                files: [{
                        expand: true,
                        cwd: 'bower_components/webshim/js-webshim/minified/shims/',
                        dest: 'build/js/shims/',
                        src: ['**']

                    }]
            },
            // Copy ace
            ace: {
                files: [{
                        expand: true,
                        cwd: 'bower_components/ace-builds/src-min-noconflict/',
                        dest: 'build/js/ace/',
                        src: ['**']
                    }]
            },
            // Copy fonts
            fonts: {
                files: [{
                        expand: true,
                        cwd: "common/fonts/",
                        dest: "build/fonts/",
                        flatten: true,
                        src: ['*']
                    }, {
                        expand: true,
                        cwd: "bower_components/",
                        dest: "build/fonts/",
                        flatten: true,
                        src: ['font-awesome/fonts/*']
                    }]
            },
            // Copy select2 assets.
            select2_img: {
                files: [{
                        expand: true,
                        cwd: "bower_components/select2/",
                        dest: "build/css/",
                        flatten: true,
                        src: ["select2.png", "select2x2.png", "select2-spinner.gif"]
                    }]
            },
            // Copy Images from site and admin
            theme_img: {
                files: [{
                        expand: true,
                        cwd: "site/img/",
                        dest: "build/img/",
                        src: ["**"]
                    }, {
                        expand: true,
                        cwd: "admin/img/",
                        dest: "build/img/",
                        flatten: true,
                        src: ["**"]
                    }]
            }
        },

        /* ************* 
         *     CSS     *
         * *************/

        // Lint all theme custom css files
        csslint: {
            lax: {
                options: {
                    "adjoining-classes": false,
                    "overqualified-elements": false,
                    "qualified-headings": false,
                    "unique-headings": false,
                    "important": false,
                    "duplicate-background-images": false,
                    "box-model": false,
                    'box-sizing': false,
                    "floats": false,
                    "font-sizes": false,
                    "vendor-prefix": false,
                    "compatible-vendor-prefixes": false,
                    "fallback-colors": false,
                    "gradients": false,
                    "zero-units": false,
                    "ids": false
                },
                src: _flattern([_css('site:custom'), _css('site'), _css('admin')])
            }
        },

        // Add browser specific prefixes to CSS
        autoprefixer: {
            options: {
                browsers: [
                    "android 2.3",
                    "android >= 4",
                    "chrome >= 20",
                    "ff > 25",
                    "ie >= 8",
                    "ios >= 6",
                    "opera >= 12",
                    "safari >= 6"
                ]
                        // Task-specific options go here.
            },
            site: {
                src: ['build/css/formr.css']
            },
            site_material: {
                src: ['build/css/formr-material.css']
            },
            admin: {
                src: ['build/css/formr-admin.css']
            }
        },

        // Minify CSS
        cssmin: {
            options: {
                shorthandCompacting: false,
                roundingPrecision: -1,
                rebase: false
            },
            all: {
                files: {
                    'build/css/formr.min.css': 'build/css/formr.css',
                    'build/css/formr-material.min.css': 'build/css/formr-material.css',
                    'build/css/formr-admin.min.css': 'build/css/formr-admin.css',
                    'build/css/bootstrap-material-design.min.css': 'build/css/bootstrap-material-design.css'
                }
            }
        },

        /* ************* 
         *     JS      *
         * *************/

        // lint JS
        jshint: {
            files: [
                'common/js/webshim.js', 'common/js/main.js', 'common/js/survey.js', 'common/js/run.js',
                'common/js/run_settings.js', 'common/js/run_users.js', 'common/js/cookieconsent.js',
                'site/js/main.js',
                'admin/js/main.js',
                'admin/js/admin.js'
            ],
            options: {
                globals: {
                    "$": false,
                    jQuery: false,
                    webshim: false,
                    hljs: false
                },
                "-W085": true,
                evil: true,
                browser: true
            }
        },

        // Concatenate JS
        concat: {
            js: {
                options: {
                    separator: ';\n'
                },
                files: {
                    'build/js/bootstrap-material-design.js': [_js('bootstrap-material-design')],
                    'build/js/formr.js': _flattern([_js(common_assets), _js(site_assets)]),
                    'build/js/formr-material.js': _flattern([_js(common_assets), _js(site_assets), 'build/js/bootstrap-material-design.js']),
                    'build/js/formr-admin.js': _flattern([_js(common_assets), _js(admin_assets)]),
                }
            },
            css: {
                options: {
                    separator: '\n'
                },
                files: {
                    'build/css/bootstrap-material-design.css': [_css('bootstrap-material-design')],
                    'build/css/formr.css': _flattern([_css(common_assets), ['site/css/style.css', 'common/css/custom_item_classes.css']]),
                    'build/css/formr-material.css': _flattern([_css(common_assets), ['site/css/style.css', 'build/css/bootstrap-material-design.css', 'common/css/custom_item_classes.css']]),
                    'build/css/formr-admin.css': _flattern([_css(common_assets), ['admin/css/AdminLTE.css', 'admin/css/style.css']]),
                }
            }
        },

        // Uglify JS
        uglify: {
            all: {
                files: {
                    'build/js/formr.min.js': 'build/js/formr.js',
                    'build/js/formr-material.min.js': 'build/js/formr-material.js',
                    'build/js/formr-admin.min.js': 'build/js/formr-admin.js',
                    'build/js/bootstrap-material-design.min.js': 'build/js/bootstrap-material-design.js'
                }
            }
        },

        // Clean up
        clean: {
            build: [
                'build/css/formr.css', 'build/css/formr-admin.css', 'build/css/formr-material.css', 'build/css/bootstrap-material-design.css',
                'build/js/formr.js', 'build/js/formr-admin.js', 'build/js/formr-material.js', 'build/js/bootstrap-material-design.js'
            ]
        }

    });

    // Load required plugins
    grunt.loadNpmTasks('grunt-bower-task');
    grunt.loadNpmTasks('grunt-contrib-csslint');
    grunt.loadNpmTasks('grunt-autoprefixer');
    grunt.loadNpmTasks('grunt-contrib-copy');
    grunt.loadNpmTasks('grunt-contrib-uglify');
    grunt.loadNpmTasks('grunt-contrib-cssmin');
    grunt.loadNpmTasks('grunt-contrib-concat');
    grunt.loadNpmTasks('grunt-concat-css');
    grunt.loadNpmTasks('grunt-contrib-jshint');
    grunt.loadNpmTasks('grunt-bower-concat');
    grunt.loadNpmTasks('grunt-contrib-clean');

    // Register Tasks
    grunt.registerTask('default', ['copy', 'csslint', 'concat:css', 'autoprefixer', 'cssmin', 'jshint', 'concat:js', 'uglify', 'clean']);
    grunt.registerTask('update', ['bower', 'default']);
    grunt.registerTask('mycss', ['csslint', 'concat:css', 'autoprefixer', 'cssmin', 'clean']);
    grunt.registerTask('myjs', ['jshint', 'concat:js', 'uglify', 'clean']);
    grunt.registerTask('minimal', ['mycss', 'myjs']);
};
