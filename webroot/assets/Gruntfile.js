module.exports = function (grunt) {

    // Common source files for both site and admin themes
    // Use this object only after bower_concat task has been run
    var common_src = {
        js: ['bower_components/webshim/js-webshim/dev/polyfiller.js', 'common/js/webshim.js', 'build/js/bower.js', 'common/js/highlight/highlight.pack.js', 'common/js/main.js'],
        css: ['bower_components/bootstrap/dist/css/bootstrap.css', 'build/css/bower.css', 'common/js/highlight/styles/vs.css']
    };

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
                    dest: "build/img/",
                    flatten: true,
                    src: ["select2.png", "select2x2.png", "select2-spinner.gif"]
                }]
            },
            // Copy Images from site
            theme_site_img: {
                files: [{
                    expand: true,
                    cwd: "site/img/",
                    dest: "build/img/",
                    src: ["**"]
                }]
            },
            // Copy Images from admin
            theme_admin_img: {
                files: [{
                    expand: true,
                    cwd: "admin/img/",
                    dest: "build/img/",
                    flatten: true,
                    src: ["**"]
                }]
            },
            // Copy bootstrap-material-design
            material: {
                expand: true,
                dot: true,
                cwd: "bower_components/bootstrap-material-design/dist",
                dest: "build/bs-material/",
                flatten: true,
                src: [
                    'css/bootstrap-material-design.css',
                    'css/ripples.css',
                    'js/material.js',
                    'js/ripples.js'
                ]
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
                    "ids": false,
                },
                src: [
                    'common/css/custom_item_classes.css',
                    'site/css/style.css',
                    'admin/AdminLTE.css', 'admin/AdminLTE-select2.css', 'admin/skin-black.css', 'admin/custom.css'
                ],
            }
        },

        // Concate CSS
        concat_css: {
            site: {
                src: common_src.css.concat(['site/css/style.css', 'common/css/custom_item_classes.css']),
                dest: 'build/css/formr.css'
            },
            admin: {
                src: common_src.css.concat([
                    'admin/AdminLTE.css', 'admin/AdminLTE-select2.css', 'admin/skin-black.css', 'admin/custom.css'
                ]),
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
            admin: {
                src: ['build/css/formr-admin.css']
            },
        },

        // Minify CSS
        cssmin: {
            options: {
                shorthandCompacting: false,
                roundingPrecision: -1,
                rebase: false
            },
            target: {
                files: {
                    'build/css/formr.min.css': 'build/css/formr.css',
                    'build/css/formr-admin.min.css': 'build/css/formr-admin.css'
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
                separator: ';',
            },
            site: {
                src: common_src.js.concat(['site/js/main.js']),
                dest: 'build/js/formr.js',
            },
            admin: {
                src: common_src.js.concat(['admin/js/main.js']),
                dest: 'build/js/formr-admin.js',
            },
        },
        
        // Uglify JS
        uglify: {
            site: {
                src: 'build/js/formr.js',
                dest: 'build/js/formr.min.js'
            },
            admin: {
                src: 'build/js/formr-admin.js',
                dest: 'build/js/formr-admin.min.js'
            },
            run: {
                src: 'common/js/run.js',
                dest: 'build/js/run.min.js'
            },
            run_users: {
                src: 'common/js/run_users.js',
                dest: 'build/js/run_users.min.js'
            },
            run_settings: {
                src: 'common/js/run_settings.js',
                dest: 'build/js/run_settings.min.js'
            },
            survey: {
                src: 'common/js/survey.js',
                dest: 'build/js/survey.min.js'
            }
        },

        // Clean up
        clean: {
            build: [
                'build/css/bower.css', 'build/css/formr.css', 'build/css/formr-admin.css',
                'build/js/bower.js', 'build/js/formr.js', 'build/js/formr-admin.js'
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
    grunt.registerTask('default', ['bower_concat', 'copy', 'csslint', 'concat_css', 'autoprefixer', 'cssmin', 'jshint', 'concat', 'uglify', 'clean']);
    grunt.registerTask('update', ['bower', 'bower_concat', 'copy', 'csslint', 'concat_css', 'autoprefixer', 'cssmin', 'jshint', 'concat', 'uglify', 'clean']);
    //grunt.registerTask('css', []);
    //grunt.registerTask('myjs', []);
};
