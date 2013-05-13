<?php
class DB extends PDO
{

    #make a connection
    public function __construct() 
	{
		require_once INCLUDE_ROOT."../../Config/database.php";
		
		
		$db = new DATABASE_CONFIG();
		$db->default['port'] = isset($db->default['port'])?$db->default['port']:'';
		
        parent::__construct( "mysql:dbname={$db->default['database']};host={$db->default['host']};port={$db->default['port']};charset=utf8",  $db->default['login'], $db->default['password']);
		
		$dt = new DateTime();
		$offset = $dt->format("P");

		# Finally, we execute the SET time_zone command.

		parent::exec("SET time_zone='$offset';");

        try 
        { 
            $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        }
        catch (PDOException $e) 
        {
            die($e->getMessage());
        }
    }
    #get the number of rows in a result
    public function num_rows($query)
    {
		
        # create a prepared statement
        $stmt = parent::prepare($query);

        if($stmt) 
        {
            # execute query 
            $stmt->execute();

            return $stmt->rowCount();
        }
        else
        {
            return $this->errorInfo();
        }
    }

	public function table_exists($table) {
		$num = $this->num_rows("SHOW TABLES LIKE '".$table."'");
	    if( $num == 1 ) {
	        return true;
	    } else {
	        return false;
	    }
	}

    # closes the database connection when object is destroyed.
    public function __destruct()
    {
        $this->connection = null;
    }
}