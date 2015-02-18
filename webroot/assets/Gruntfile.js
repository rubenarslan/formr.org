module.exports = function(grunt) {

// Project configuration.
grunt.initConfig({
	pkg: grunt.file.readJSON('package.json'),
	copy: {
		dist: {
			files: [{
				expand: true,
				dot: true,
				cwd: 'bower_components/',
				dest: 'lib/',
//	  nonull: true,
				src: [
				  'webshim/js-webshim/dev/*',
				  'webshim/js-webshim/dev/shims/**',
				  'webshim/js-webshim/minified/*',
				  'webshim/js-webshim/minified/shims/**',
				  'ace-builds/**'
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
			'webshim',
			'ace'
		],
		bowerOptions: {
		  relative: true
		}
	  }
	},
	concat: {
		options: {
		  separator: ';',
		},
		js: {
		  src: ['lib/bower.js', 'js/main.js'],
		  dest: 'lib/bower.js',
		},
		css: {
		  src: ['lib/bower.css', 'css/main.css'],
		  dest: 'lib/bower.css',
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
		build: {
			src: 'lib/bower.js',
			dest: 'lib/bower.min.js'
		}
	}
});

  // Load the plugin that provides the "uglify" task.
  grunt.loadNpmTasks('grunt-contrib-copy');
  grunt.loadNpmTasks('grunt-contrib-uglify');
  grunt.loadNpmTasks('grunt-contrib-cssmin');
  grunt.loadNpmTasks('grunt-contrib-concat');
  grunt.loadNpmTasks('grunt-bower-concat');

  // Default task(s).
  grunt.registerTask('default', ['copy','bower_concat','concat','uglify','cssmin']);
//	grunt.registerTask('bowerinstall', ['bower']);
};

//https://www.npmjs.com/package/grunt-autoprefixer