module.exports = function (grunt) {

    // Common source files for both site and admin themes
    // Use this object only after bower_concat task has been run
    var common_src = {
        js: ['bower_components/webshim/js-webshim/dev/polyfiller.js', 'common/js/webshim.js', 'build/js/bower.js', 'common/js/highlight/highlight.pack.js', 'common/js/main.js'],
        css: ['bower_components/bootstrap/dist/css/bootstrap.css', 'build/css/bower.css', 'common/js/highlight/styles/vs.css']
    };
    var assets = grunt.file.readJSON('assets.json');
    var common_assets = ['jquery', 'bootstrap', 'font-awesome', 'webshim', 'select2', 'hammer', 'highlight'];
    var site_assets = ['main:js', 'run_users', 'run', 'survey', 'site', 'site:custom'];
    var admin_assets = ['main:js', 'run_users', 'run', 'run_settings', 'admin'];

    
    function _asset(asset, type) {
        var res = []
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

    function _asset_array(asset, type) {
        var res = []
        for (var a in asset) {
            res = res.concat(_asset(asset[a], type));
        }
        return res;
    }

    function _css(asset) {
        return _asset(asset, 'css');
    }
    
    function _js(asset) {
        return _asset(asset, 'js');
    }
    
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

        // Concatenate bower components "main" js and css and group them in build/js/bower.js and build/css/bower.css respectively
        bower_concat: {
            all: {
                dest: 'build/js/bower.js',
                cssDest: 'build/css/bower.css',
                exclude: [
                    'ace-builds',
                    'webshim',
                    'bootstrap-sass',
                    'bootstrap-material-design'
                ],
                mainFiles: {
                    'font-awesome': ['css/font-awesome.css']
                },
                bowerOptions: {
                    relative: true
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
                    cwd: "bower_components/",
                    dest: "build/fonts/",
                    flatten: true,
                    src: ['font-awesome/fonts/*']
                }]
            },
            // Copy select2 assets. FIX ME: Is this needed?
            select_img: {
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

        // Concate CSS
        concat_css: {
            material: {
                src: _css('bootstrap-material-design'),
                dest: 'build/css/bootstrap-material-design.css'
            },
            site: {
                src: _flattern([_css(common_assets), ['site/css/style.css', 'common/css/custom_item_classes.css']]),
                dest: 'build/css/formr.css'
            },
            site_material: {
                src: _flattern([_css(common_assets), ['site/css/style.css', 'build/css/bootstrap-material-design.css', 'common/css/custom_item_classes.css']]),
                dest: 'build/css/formr-material.css'
            },
            admin: {
                src: _flattern([_css(common_assets), ['admin/css/AdminLTE.css', 'admin/css/style.css']]),
                dest: 'build/css/formr-admin.css'
            }
        },

        // Add browser specific prefixes to CSS
        autoprefixer: {
            options: {
                browsers: [
                    "Android 2.3",
                    "Android >= 4",
                    "Chrome >= 20",
                    "Firefox >= 24",
                    "Explorer >= 8",
                    "iOS >= 6",
                    "Opera >= 12",
                    "Safari >= 6"
                ],
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
                'common/js/webshim.js', 'common/js/main.js', 'common/js/survey.js',
                'common/js/run.js', 'common/js/run_settings.js', 'common/js/run_users.js',
                'site/js/main.js',
                'admin/js/main.js',
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
            options: {
                separator: ';\n',
            },
            material: {
                src: _js('bootstrap-material-design'),
                dest: 'build/js/bootstrap-material-design.js'
            },
            site: {
                src: _flattern([_js(common_assets), _js(site_assets)]),
                dest: 'build/js/formr.js'
            },
            site_material: {
                src: _flattern([_js(common_assets), _js(site_assets), 'build/js/bootstrap-material-design.js']),
                dest: 'build/js/formr-material.js'
            },
            admin: {
                src: _flattern([_js(common_assets), _js(admin_assets)]),
                dest: 'build/js/formr-admin.js'
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
    grunt.registerTask('default', ['copy', 'csslint', 'concat_css', 'autoprefixer', 'cssmin', 'jshint', 'concat', 'uglify', 'clean']);
    grunt.registerTask('update', ['bower', 'default']);
    //grunt.registerTask('css', []);
    //grunt.registerTask('myjs', []);
};
