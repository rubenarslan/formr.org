<?php
class DB extends PDO
{

    #make a connection
    public function __construct() 
	{
		require_once(dirname(__FILE__)."/../../../Config/database.php");

		$db = new DATABASE_CONFIG();
		$db->default['port'] = isset($db->default['port'])?$db->default['port']:'';
		
        parent::__construct( "mysql:dbname={$db->default['database']};host={$db->default['host']};port={$db->default['port']};charset=utf8",  $db->default['login'], $db->default['password']);

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
            return self::get_error();
        }
    }

    #display error
    public function get_error() 
    {
        $this->connection->errorInfo();
    }

    # closes the database connection when object is destroyed.
    public function __destruct()
    {
        $this->connection = null;
    }
}