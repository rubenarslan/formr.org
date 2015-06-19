module.exports = function(grunt) {

// Project configuration.
grunt.initConfig({
	pkg: grunt.file.readJSON('package.json'),
	bower: {
		install: {
			options: {
				targetDir: "bower_components/",
				copy: false
			}
		}
	},
	copy: {
		webshim_min: {
			files: [{
				expand: true,
				cwd: 'bower_components/webshim/js-webshim/minified/shims/',
				dest: 'minified/shims/',
				src: [
				  '**',
				]
			}]
		},
		webshim: {
			files: [{
				expand: true,
				cwd: 'bower_components/webshim/js-webshim/dev/shims/',
				dest: 'lib/shims/',
				src: [
				  '**',
				]
			}]
		},
		ace_min: {
			files: [{
				expand: true,
				dot: true,
				cwd: 'bower_components/ace-builds/src-min-noconflict/',
				dest: 'minified/ace/',
				src: [
				  '**'
				]
			}]
		},
		ace: {
			files: [{
				expand: true,
				dot: true,
				cwd: 'bower_components/ace-builds/src-noconflict/',
				dest: 'lib/ace/',
				src: [
				  '**'
				]
			}]
		},
		fonts: {
			files: [{
				expand: true,
				cwd: "bower_components/",
				dest: "fonts/",
				flatten: true,
				src: [
					"select2.png", "select2x2.png", "select2-spinner.gif",
					'font-awesome/fonts/*'
				]
			}]
		},
		select_img: {
			files: [{
				expand: true,
				cwd: "bower_components/select2/",
				dest: "lib/",
				flatten: true,
				src: [
					"select2.png", "select2x2.png", "select2-spinner.gif",
					'font-awesome/fonts/*'
				]
			}]
		}
	},
	bower_concat: {
	  all: {
		dest: 'lib/bower.js',
		cssDest: 'lib/bower.css',
		exclude: [
			'ace-builds',
			'webshim'
		],
		bowerOptions: {
		  relative: true
		}
	  }
	},
	csslint: {
	  lax: {
		  options: {
			  "adjoining-classes": false,
			  "overqualified-elements": false,
			  "important": false,
			  "duplicate-background-images": false,
			  "box-model": false,
		  },
	    src: ['css/main.css']
	  },
	},
	concat: {
		options: {
		  separator: ';',
		},
		js: {
		  src: ['bower_components/webshim/js-webshim/dev/polyfiller.js','lib/bower.js', 'js/main.js'],
		  dest: 'lib/bower.js',
		},
		css: {
		  src: ['bower_components/bootstrap/dist/css/bootstrap.css','lib/bower.css', 'css/main.css'],
		  dest: 'lib/bower.css',
		},
	},
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
		your_target: {
			src: 'lib/bower.css'
			// Target-specific file lists and/or options go here.
		},
	},
	cssmin: {
		options: {
			shorthandCompacting: false,
			roundingPrecision: -1,
			rebase: false
		},
		target: {
			files: {
				'lib/bower.min.css': 'lib/bower.css'
			}
		}
	},
	uglify: {
		main: {
			src: 'lib/bower.js',
			dest: 'minified/bower.js'
		},
		run: {
			src: 'js/run.js',
			dest: 'minified/run.js'
		},
		run_users: {
			src: 'js/run_users.js',
			dest: 'minified/run_users.js'
		},
		run_settings: {
			src: 'js/run_settings.js',
			dest: 'minified/run_settings.js'
		},
		survey: {
			src: 'js/survey.js',
			dest: 'minified/survey.js'
		}
	},
	jshint: {
//		beforeconcat:  ['bower_components/webshim/js-webshim/dev/polyfiller.js','lib/bower.js', 'js/main.js'],
//		afterconcat: ['lib/bower.js'],
		files:  ['js/main.js','js/survey.js','js/run.js','js/run_settings.js','js/run_users.js'],
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
	}
});

  // Load the plugin that provides the "uglify" task.
  grunt.loadNpmTasks('grunt-bower-task');
//  grunt.loadNpmTasks('grunt-contrib-watch');
//  grunt.loadNpmTasks('grunt-contrib-less');
  grunt.loadNpmTasks('grunt-contrib-csslint');
  grunt.loadNpmTasks('grunt-autoprefixer');
  grunt.loadNpmTasks('grunt-contrib-copy');
  grunt.loadNpmTasks('grunt-contrib-uglify');
  grunt.loadNpmTasks('grunt-contrib-cssmin');
  grunt.loadNpmTasks('grunt-contrib-concat');
  grunt.loadNpmTasks('grunt-contrib-jshint');
  grunt.loadNpmTasks('grunt-bower-concat');

  // Default task(s).
  grunt.registerTask('default', ['bower','copy','jshint','bower_concat','concat','uglify','csslint',"autoprefixer",'cssmin']);
//	grunt.registerTask('bowerinstall', ['bower']);
};

//https://www.npmjs.com/package/grunt-autoprefixer