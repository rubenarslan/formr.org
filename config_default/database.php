<?php
class DATABASE_CONFIG {
	//initalize variable as null
	var $default=null;

	public $dev = array(
		'datasource' => 'Database/Mysql',
		'persistent' => false,
		'host' => 'localhost',
		'login' => 'user',
		'password' => 'password',
		'database' => 'database',
		'prefix' => '',
		'encoding' => 'utf8',
		'unix_socket' => '/Applications/MAMP/tmp/mysql/mysql.sock',
	);
	public $prod = array( // instance 1004
		'datasource' => 'Database/Mysql',
		'persistent' => false,
		'host' => 'localhost',
		'login' => 'user',
		'password' => 'password',
		'database' => 'database',
		'prefix' => '',
		'port' => 4309,
		'encoding' => 'utf8',
	);
 
	// the construct function is called automatically, and chooses prod or dev.
	function __construct () {		
		//check to see if server name is set (thanks Frank)
		if(isset($_SERVER['SERVER_NAME'])){
			switch($_SERVER['SERVER_NAME']){
				case 'localhost':
					$this->default = $this->dev;
					break;
				default:
					$this->default = $this->prod;
					break;
			}
		}
	    else // we are likely baking, use our local db
	    {
	        $this->default = $this->dev;
	    }
	}
}
