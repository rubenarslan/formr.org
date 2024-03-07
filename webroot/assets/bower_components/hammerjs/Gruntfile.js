module.exports = function( grunt ) {
var path = require( "path" );
require( "load-grunt-config" )( grunt, {
	configPath: [
		path.join( process.cwd(), "build/options" ),
		path.join( process.cwd(), "build/tasks" )
	],
	init: true
} );
};
