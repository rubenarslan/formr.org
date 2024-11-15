<?php

/**
 * Database abstraction class for PDO with security improvements
 */
class DB {

    /**
     * @var DB Singleton instance
     */
    protected static $instance = null;

    /**
     * Data types mapping
     *
     * @var array
     */
    protected $types = array(
        // Integer types
        'int' => PDO::PARAM_INT,
        'integer' => PDO::PARAM_INT,
        // String Types
        'str' => PDO::PARAM_STR,
        'string' => PDO::PARAM_STR,
        // Boolean types
        'bool' => PDO::PARAM_BOOL,
        'boolean' => PDO::PARAM_BOOL,
        // NULL type
        'null' => PDO::PARAM_NULL,
    );

    /**
     * Default data-type
     *
     * @var integer
     */
    protected $default_type = PDO::PARAM_STR;

    /**
     * Get the singleton DB instance
     *
     * @return DB
     */
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @var PDO PDO instance
     */
    protected $PDO;

    /**
     * @var array Last executed statement and parameters
     */
    protected $lastStatement;

    /**
     * Constructor - Establish PDO connection
     */
    protected function __construct() {
        $params = (array) Config::get('database');
    
        $driver = isset($params['driver']) ? $params['driver'] : 'mysql';
    
        if ($driver === 'mysql') {
            $options = array(
                'host' => $params['host'],
                'dbname' => $params['database'],
                'charset' => $params['encoding'],
            );
            if (!empty($params['port'])) {
                $options['port'] = $params['port'];
            }
    
            $dsn = 'mysql:' . http_build_query($options, '', ';');
            $this->PDO = new PDO($dsn, $params['login'], $params['password'], array(
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $options['charset'],
            ));
    
            $dt = new DateTime();
            $offset = $dt->format("P");
    
            $this->PDO->exec("SET time_zone='$offset';");
            $this->PDO->exec("SET SESSION sql_mode='STRICT_ALL_TABLES';");
        } elseif ($driver === 'sqlite') {
            $dsn = 'sqlite:' . $params['database'];
            $this->PDO = new PDO($dsn, null, null, array(
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ));
        } else {
            throw new Exception("Unsupported database driver: $driver");
        }
    }    

    /**
     * Execute a query with parameters and fetch results
     *
     * @param string $query Query string with placeholders
     * @param array $params Parameters to bind
     * @param bool $fetchcol [optional] Fetch single column
     * @param bool $fetchrow [optional] Fetch single row
     * @return array|mixed Results array or single value
     */
    public function execute($query, $params = array(), $fetchcol = false, $fetchrow = false) {
        $stmt = $this->PDO->prepare($query);
        $this->lastStatement = [$stmt, $params];
        $stmt->execute($params);
        if ($fetchcol) {
            return $stmt->fetchColumn();
        }
        if ($fetchrow) {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Execute an INSERT, UPDATE, or DELETE query
     *
     * @param string $query SQL query with placeholders
     * @param array $params Parameters to bind
     * @return int Number of affected rows
     */
    public function exec($query, array $params = array()) {
        $stmt = $this->PDO->prepare($query);
        $this->lastStatement = [$stmt, $params];
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Execute a raw SQL query
     *
     * @param string $query SQL query to execute
     * @param bool $return_statement [optional] Return PDOStatement if true
     * @return PDOStatement|array PDOStatement or result set
     */
    public function query($query, $return_statement = false) {
        $stmt = $this->PDO->query($query);
        if (preg_match('/^select/i', $query) && $return_statement === false) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $stmt;
    }

    /**
     * Securely prepare and execute a query with parameters
     *
     * @param string $query SQL query with placeholders
     * @param array $params Parameters to bind
     * @return PDOStatement Executed statement
     */
    public function rquery($query, $params = array()) {
        $stmt = $this->PDO->prepare($query);
        $this->lastStatement = [$stmt, $params];
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Get the number of rows affected by a query
     *
     * @param string $query SQL query
     * @param array $params [optional] Parameters to bind
     * @return int Number of rows
     */
    public function num_rows($query, $params = array()) {
        $stmt = $this->PDO->prepare($query);
        $this->lastStatement = [$stmt, $params];
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Check if a table exists in the database
     *
     * @param string $table Table name
     * @return bool True if table exists, false otherwise
     */
    public function table_exists($table) {
        // Validate that table name contains only allowed characters
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid table name');
        }

        // Get the current database name
        $schema = $this->PDO->query('SELECT DATABASE()')->fetchColumn();

        $query = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table";
        $stmt = $this->PDO->prepare($query);
        $stmt->execute([
            ':schema' => $schema,
            ':table' => $table
        ]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Destructor - Close PDO connection
     */
    public function __destruct() {
        $this->PDO = null;
    }

    /**
     * Get error information from last operation
     *
     * @return array Error info
     */
    public function getError() {
        return $this->PDO->errorInfo();
    }

    /**
     * Find records from a table
     *
     * @param string $table_name Table name
     * @param string|array $where WHERE clause or parameters
     * @param array $params [optional] Additional parameters
     * @return array Result set
     */
    public function find($table_name, $where = null, $params = array()) {
        if (empty($params['cols'])) {
            $params['cols'] = array();
        }
        $select = $this->select($params['cols']);
        $select->from($table_name);

        if ($where) {
            $select->where($where);
        }

        if (!empty($params['order']) && !empty($params['order_by'])) {
            $select->order($params['order_by'], $params['order']);
        }

        if (!empty($params['limit'])) {
            $offset = isset($params['offset']) ? $params['offset'] : 0;
            $select->limit($params['limit'], $offset);
        }

        // Unset parameters not needed for binding
        unset($params['cols'], $params['order_by'], $params['order'], $params['limit'], $params['offset']);
        if ($params) {
            $select->bindParams($params);
        }

        return $select->fetchAll();
    }

    /**
     * Find a single row from a table
     *
     * @param string $table_name Table name
     * @param string|array $where WHERE clause or parameters
     * @param array $cols [optional] Columns to select
     * @return array|null Single row or null
     */
    public function findRow($table_name, $where = null, $cols = array()) {
        return $this->select($cols)->from($table_name)->where($where)->limit(1)->fetch();
    }

    /**
     * Find a single value from a table
     *
     * @param string $table_name Table name
     * @param string|array $where WHERE clause or parameters
     * @param array $cols [optional] Columns to select
     * @return mixed Single value
     */
    public function findValue($table_name, $where = null, $cols = array()) {
        return $this->select($cols)->from($table_name)->where($where)->limit(1)->fetchColumn();
    }

    /**
     * Create a new SELECT query builder
     *
     * @param array|string $cols [optional] Columns to select
     * @return DB_Select
     */
    public function select($cols = array()) {
        if (is_string($cols)) {
            $cols = explode(',', $cols);
        }
        return new DB_Select($this->PDO, $cols);
    }

    /**
     * Count the number of rows matching criteria
     *
     * @param string $table_name Table name
     * @param array|string $where WHERE clause or parameters
     * @param string $col [optional] Column to count
     * @return int Count result
     */
    public function count($table_name, $where = array(), $col = '*') {
        $table_name = self::quoteIdentifier($table_name);
        $col = $col === '*' ? '*' : self::quoteIdentifier($col);

        $query = "SELECT COUNT($col) FROM $table_name";
        $params = array();
        if ($where && is_array($where)) {
            $wc = $this->parseConditions($where);
            $query .= " WHERE {$wc['clause']}";
            $params = $wc['params'];
        } elseif ($where && is_string($where)) {
            $query .= " WHERE $where";
        }

        $stmt = $this->PDO->prepare($query);
        $this->lastStatement = [$stmt, $params];
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * Check if an entry exists in a table
     *
     * @param string $table_name Table name
     * @param array|string $where WHERE clause or parameters
     * @return bool True if exists, false otherwise
     */
    public function entry_exists($table_name, $where) {
        return $this->count($table_name, $where) > 0;
    }

    /**
     * Insert data into a table
     *
     * @param string $table_name Table name
     * @param array $data Data to insert
     * @param array $types [optional] Data types
     * @return mixed Last insert ID or null
     */
    public function insert($table_name, array $data, array $types = array()) {
        if (!$this->checkTypeCount($data, $types)) {
            throw new Exception("Array count for data and data-types do not match");
        }

        $columns = array();
        $placeholders = array();
        $params = array();
        $paramCounter = 0;

        foreach ($data as $key => $value) {
            $columns[] = self::quoteIdentifier($key);
            $placeholder = self::pkey('param_' . $paramCounter++);
            $placeholders[] = $placeholder;
            $params[$placeholder] = $value;
        }

        $columns_str = implode(', ', $columns);
        $placeholders_str = implode(', ', $placeholders);
        $table_name = self::quoteIdentifier($table_name);

        $query = "INSERT INTO $table_name ($columns_str) VALUES ($placeholders_str)";

        $stmt = $this->PDO->prepare($query);
        $stmt = $this->bindValues($stmt, $params, array_values($types), false, true);
        $this->lastStatement = [$stmt, $params];
        $stmt->execute();
        return $this->lastInsertId();
    }

    /**
     * Insert data and update on duplicate key
     *
     * @param string $table Table name
     * @param array $data Data to insert
     * @param array $updates [optional] Data to update on duplicate key
     * @return int Number of affected rows
     */
    public function insert_update($table, array $data, array $updates = array()) {
        // Quote the table name
        $table = self::quoteIdentifier($table);

        // Prepare columns and placeholders for insert
        $columns = [];
        $placeholders = [];
        $params = [];
        $paramCounter = 0;

        foreach ($data as $col => $value) {
            $columns[] = self::quoteIdentifier($col);
            $placeholder = self::pkey('param_' . $paramCounter++);
            $placeholders[] = $placeholder;
            $params[$placeholder] = $value;
        }

        // If no updates specified, default to updating all columns in $data
        if (empty($updates)) {
            $updates = $data;
        }

        // Prepare the ON DUPLICATE KEY UPDATE clause with parameter binding
        $updates_str = [];
        foreach ($updates as $col => $value) {
            $col_quoted = self::quoteIdentifier($col);
            $placeholder = self::pkey('update_param_' . $paramCounter++);
            $updates_str[] = "$col_quoted = $placeholder";
            $params[$placeholder] = $value;
        }
        $updates_clause = implode(', ', $updates_str);

        // Build the query
        $columns_str = implode(', ', $columns);
        $placeholders_str = implode(', ', $placeholders);

        $query = "INSERT INTO $table ($columns_str) VALUES ($placeholders_str) ON DUPLICATE KEY UPDATE $updates_clause";

        // Prepare and execute the statement
        $stmt = $this->PDO->prepare($query);
        $this->lastStatement = [$stmt, $params];
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Update records in a table
     *
     * @param string $table_name Table name
     * @param array $data Data to update
     * @param array $where WHERE conditions
     * @param array $data_types [optional] Data types for data
     * @param array $where_types [optional] Data types for where
     * @return int Number of affected rows
     * @throws Exception
     */
    public function update($table_name, array $data, array $where, array $data_types = array(), array $where_types = array()) {
        if (!$this->checkTypeCount($data, $data_types)) {
            throw new Exception("Array count for data and data-types do not match");
        }
    
        if (!$this->checkTypeCount($where, $where_types)) {
            throw new Exception("Array count for where clause and where clause data-types do not match");
        }
    
        // Prepare the SET clause
        $set_parts = [];
        $params = [];
        $paramCounter = 0;
    
        foreach ($data as $col => $value) {
            $col_quoted = self::quoteIdentifier($col);
            $placeholder = self::pkey('param_' . $paramCounter++);
            $set_parts[] = "$col_quoted = $placeholder";
            $params[$placeholder] = $value;
        }
    
        // Prepare the WHERE clause
        $whereParsed = $this->parseConditions($where, $paramCounter);
        $where_str = $whereParsed['clause'];
        $params = array_merge($params, $whereParsed['params']);
    
        $table_name = self::quoteIdentifier($table_name);
        $set_str = implode(', ', $set_parts);
    
        $query = "UPDATE $table_name SET $set_str WHERE ($where_str)";
    
        $stmt = $this->PDO->prepare($query);
        $stmt = $this->bindValues($stmt, $params, array_merge(array_values($data_types), array_values($where_types)), false, true);
        $this->lastStatement = [$stmt, $params];
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    /**
     * Delete records from a table
     *
     * @param string $table_name Table name
     * @param array $where WHERE conditions
     * @param array $types [optional] Data types
     * @return int Number of affected rows
     */
    public function delete($table_name, array $where, array $types = array()) {
        $params = [];
        $paramCounter = 0;
        $whereParsed = $this->parseConditions($where, $paramCounter);
        $where_str = $whereParsed['clause'];
        $params = $whereParsed['params'];
        $table_name = self::quoteIdentifier($table_name);

        $query = "DELETE FROM $table_name WHERE ($where_str)";

        $stmt = $this->PDO->prepare($query);
        $stmt = $this->bindValues($stmt, $params, array_values($types), false, true);
        $this->lastStatement = [$stmt, $params];
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Parse conditions and generate SQL parts and parameters
     *
     * @param array $conditions Conditions array
     * @param int &$paramCounter Parameter counter (by reference)
     * @return array SQL clause and parameters
     */
    private function parseConditions(array $conditions, int &$paramCounter = 0) {
        $clauses = [];
        $params = [];

        foreach ($conditions as $col_condition => $value) {
            $col_condition = trim($col_condition);
            $operator = '=';
            if (preg_match('/^(.*?)(\s+|)(>=|<=|<>|>|<|!=|=)$/', $col_condition, $matches)) {
                $col = trim($matches[1]);
                $operator = $matches[3];
            } else {
                $col = $col_condition;
            }
            $col_quoted = self::quoteIdentifier($col);
            $placeholder = self::pkey('param_' . $paramCounter++);
            $clauses[] = "$col_quoted $operator $placeholder";
            $params[$placeholder] = $value;
        }
        $clause_str = implode(' AND ', $clauses);
        return ['clause' => $clause_str, 'params' => $params];
    }

    /**
     * Prepare a SQL statement
     *
     * @param string $query SQL query
     * @param array $options [optional] Driver options
     * @return PDOStatement
     */
    public function prepare($query, $options = array()) {
        return $this->PDO->prepare($query, $options);
    }

    /**
     * Quote a string for use in a query
     *
     * @param string $string String to quote
     * @return string Quoted string
     */
    public function quote($string) {
        return $this->PDO->quote($string);
    }

    /**
     * Get the underlying PDO instance
     *
     * @return PDO
     */
    public function pdo() {
        return $this->PDO;
    }

    /**
     * Get the last insert ID
     *
     * @return string Last insert ID
     */
    public function lastInsertId() {
        return $this->PDO->lastInsertId();
    }

    /**
     * Bind values to a PDOStatement
     *
     * @param PDOStatement $stmt Prepared statement
     * @param array $data Parameters to bind
     * @param array $types [optional] Data types
     * @param bool $numeric [optional] Use numeric indexes
     * @param bool $reset [optional] Reset internal counter
     * @return PDOStatement
     */
    private function bindValues($stmt, $data, $types, $numeric = true, $reset = false) {
        static $i;
        if ($reset || $i === null) {
            $i = 0;
        }
        foreach ($data as $key => $value) {
            $type = $this->default_type;
            if (isset($types[$i]) && isset($this->types[$types[$i]])) {
                $type = $this->types[$types[$i]];
            }
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $param = ($numeric ? $i + 1 : $key);
            $stmt->bindValue($param, $value, $type);
            $i++;
        }
        return $stmt;
    }

    /**
     * Check if data and types arrays have matching counts
     *
     * @param array $data Data array
     * @param array $types Types array
     * @return bool True if counts match or types array is empty
     */
    private function checkTypeCount(array $data, array $types) {
        if (!$types) {
            return true;
        }
        return count($types) === count($data);
    }

    /**
     * Quote an identifier (e.g., table or column name)
     *
     * @param string $identifier Identifier to quote
     * @return string Quoted identifier
     */
    public static function quoteIdentifier($identifier) {
        // Replace backticks and null bytes to prevent injection
        $identifier = str_replace(["`", "\0"], ["``", ""], $identifier);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid identifier name');
        }

        return "`$identifier`";
    }

    /**
     * Generate a parameter placeholder
     *
     * @param string $key Parameter key
     * @return string Parameter placeholder
     */
    public static function pkey($key) {
        $key = trim($key);
        $key = trim($key, ':');
        return ':' . $key;
    }

    /**
     * Begin a database transaction
     */
    public function beginTransaction() {
        if (!$this->PDO->inTransaction()) {
            return $this->PDO->beginTransaction();
        }
    }

    /**
     * Commit a database transaction
     */
    public function commit() {
        if ($this->PDO->inTransaction()) {
            return $this->PDO->commit();
        }
    }

    /**
     * Rollback a database transaction
     */
    public function rollBack() {
        if ($this->PDO->inTransaction()) {
            return $this->PDO->rollBack();
        }
    }

    /**
     * Get table definition
     *
     * @param string $table Table name
     * @param string $property [optional] Property to filter by
     * @return array Table definition
     */
    public function getTableDefinition($table, $property = null) {
        // Validate that table name contains only allowed characters
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid table name');
        }

        if (!$this->table_exists($table)) {
            return array();
        }

        $tableQuoted = self::quoteIdentifier($table);

        $stmt = $this->PDO->prepare("SHOW COLUMNS FROM $tableQuoted");
        $stmt->execute();
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($property === null) {
            return $cols;
        }
        $filtered = array();
        foreach ($cols as $col) {
            $filtered[$col[$property]] = $col;
        }
        return $filtered;
    }

    /**
     * Check if exception is a transaction retry error
     *
     * @param Exception $e Exception to check
     * @return bool True if retryable, false otherwise
     */
    public function retryTransaction(Exception $e) {
        return strstr($e->getMessage(), 'try restarting transaction') !== false;
    }

    /**
     * Log the last executed statement
     *
     * @param Exception $e Exception that occurred
     */
    public function logLastStatement(Exception $e) {
        $interestingCodes = ['HY000', '23000', '40001'];
        if (in_array($e->getCode(), $interestingCodes) && $this->lastStatement) {
            // Ensure sensitive data is not logged
            formr_log($this->lastStatement[0]->queryString, 'MySQL_QUERY');
            // Do not log parameters directly if they may contain sensitive data
            // formr_log($this->lastStatement[1], 'MySQL_PARAMS');
        }
    }

}

/**
 * Database SELECT query builder with security enhancements
 */
class DB_Select {

    /**
     * @var PDO PDO instance
     */
    protected $PDO;

    /**
     * Constructed SQL statement
     *
     * @var string
     */
    protected $query;

    /**
     * WHERE clauses
     *
     * @var array
     */
    protected $where = array();

    /**
     * JOIN clauses
     *
     * @var array
     */
    protected $joins = array();

    /**
     * Columns to select
     *
     * @var array
     */
    protected $columns = array('*');

    /**
     * Parameters to bind
     *
     * @var array
     */
    protected $params = array();

    /**
     * ORDER BY clauses
     *
     * @var array
     */
    protected $order = array();

    /**
     * LIMIT value
     *
     * @var int
     */
    protected $limit;

    /**
     * OFFSET value
     *
     * @var int
     */
    protected $offset;

    /**
     * Table to select from
     *
     * @var string
     */
    protected $table;

    /**
     * Parameter counter for unique parameter names
     *
     * @var int
     */
    private $paramCounter = 0;

    /**
     * Constructor
     *
     * @param PDO $pdo PDO instance
     * @param array $cols [optional] Columns to select
     */
    public function __construct(PDO $pdo, array $cols = array()) {
        $this->PDO = $pdo;
        $this->columns($cols);
    }

    /**
     * Destructor
     */
    public function __destruct() {
        $this->PDO = null;
    }

    /**
     * Set columns to select
     *
     * @param array $cols Columns to select
     */
    public function columns(array $cols) {
        if ($cols) {
            $this->columns = $this->parseCols($cols);
        }
    }

    /**
     * Set the FROM clause
     *
     * @param string $table Table name
     * @return $this
     */
    public function from($table) {
        $this->table = DB::quoteIdentifier($table);
        return $this;
    }

    /**
     * Add a LEFT JOIN clause
     *
     * @param string $table Table to join
     * @param string $condition Join condition
     * @return $this
     */
    public function leftJoin($table, $condition) {
        $table = DB::quoteIdentifier($table);
        $condition = $this->parseJoinConditions(func_get_args());
        $this->joins[] = " LEFT JOIN $table ON ($condition)";
        return $this;
    }

    /**
     * Add a RIGHT JOIN clause
     *
     * @param string $table Table to join
     * @param string $condition Join condition
     * @return $this
     */
    public function rightJoin($table, $condition) {
        $table = DB::quoteIdentifier($table);
        $condition = $this->parseJoinConditions(func_get_args());
        $this->joins[] = " RIGHT JOIN $table ON ($condition)";
        return $this;
    }

    /**
     * Add an INNER JOIN clause
     *
     * @param string $table Table to join
     * @param string $condition Join condition
     * @return $this
     */
    public function join($table, $condition) {
        $table = DB::quoteIdentifier($table);
        $condition = $this->parseJoinConditions(func_get_args());
        $this->joins[] = " INNER JOIN $table ON ($condition)";
        return $this;
    }

    /**
     * Add a WHERE clause
     *
     * @param array|string $where WHERE conditions
     * @return $this
     */
    public function where($where) {
        if (is_array($where)) {
            $whereParsed = $this->parseConditions($where);
            $this->where = array_merge($this->where, $whereParsed['clauses']);
            $this->params = array_merge($this->params, $whereParsed['params']);
        } elseif (is_string($where)) {
            $this->where[] = $where;
        }
        return $this;
    }

    /**
     * Add a WHERE IN clause with parameter binding
     *
     * @param string $field Field name
     * @param array $values Values for IN clause
     * @return $this
     */
    public function whereIn($field, array $values) {
        $field = $this->parseColName($field);
        $placeholders = [];
        foreach ($values as $value) {
            $paramKey = ':param_' . $this->paramCounter++;
            $placeholders[] = $paramKey;
            $this->params[$paramKey] = $value;
        }
        $placeholders_str = implode(', ', $placeholders);
        $this->where[] = "$field IN ($placeholders_str)";
        return $this;
    }

    /**
     * Add a LIKE clause with parameter binding
     *
     * @param string $colname Column name
     * @param string $value Value to match
     * @param string $pad [optional] Padding direction ('both', 'left', 'right')
     * @return $this
     */
    public function like($colname, $value, $pad = 'both') {
        $colname = $this->parseColName($colname);
        if ($pad === 'right') {
            $value = "$value%";
        } elseif ($pad === 'left') {
            $value = "%$value";
        } else {
            $value = "%$value%";
        }
        $paramKey = ':param_' . $this->paramCounter++;
        $this->where[] = "$colname LIKE $paramKey";
        $this->params[$paramKey] = $value;
        return $this;
    }

    /**
     * Set the LIMIT clause
     *
     * @param int $limit Limit value
     * @param int $offset [optional] Offset value
     * @return $this
     */
    public function limit($limit, $offset = 0) {
        $this->limit = (int) $limit;
        $this->offset = (int) $offset;
        return $this;
    }

    /**
     * Set the ORDER BY clause
     *
     * @param string $by Column to order by
     * @param string|null $order [optional] Order direction ('ASC', 'DESC')
     * @return $this
     * @throws Exception
     */
    public function order($by, $order = 'ASC') {
        $byUpper = strtoupper($by);

        // Handle ordering by RAND()
        if ($byUpper === 'RAND' || $byUpper === 'RAND()') {
            $this->order[] = 'RAND()';
            return $this;
        }

        // If $order is null or empty, add $by directly to the order clause
        if ($order === null || $order === '') {
            $this->order[] = $by;
            return $this;
        }

        $orderUpper = strtoupper($order);

        if (!in_array($orderUpper, array('ASC', 'DESC'))) {
            throw new Exception("Invalid order direction: $order");
        }
        $by = $this->parseColName($by);
        $this->order[] = "$by $orderUpper";
        return $this;
    }

    /**
     * Fetch all results
     *
     * @param int $fetch_style [optional] PDO fetch style
     * @return array Result set
     */
    public function fetchAll($fetch_style = PDO::FETCH_ASSOC) {
        $this->constructQuery();
        $query = $this->trimQuery();
        $stmt = $this->PDO->prepare($query);
        $stmt->execute($this->params);
        return $stmt->fetchAll($fetch_style);
    }

    /**
     * Fetch a single row
     *
     * @param int $fetch_style [optional] PDO fetch style
     * @return mixed Single row or false
     */
    public function fetch($fetch_style = PDO::FETCH_ASSOC) {
        $this->constructQuery();
        $query = $this->trimQuery();
        $stmt = $this->PDO->prepare($query);
        $stmt->execute($this->params);
        return $stmt->fetch($fetch_style);
    }

    /**
     * Fetch a single column value
     *
     * @return mixed Single column value or false
     */
    public function fetchColumn() {
        $this->constructQuery();
        $query = $this->trimQuery();
        $stmt = $this->PDO->prepare($query);
        $stmt->execute($this->params);
        return $stmt->fetchColumn();
    }

    /**
     * Get the executed PDO statement
     *
     * @return PDOStatement
     */
    public function statement() {
        $this->constructQuery();
        $query = $this->trimQuery();
        $stmt = $this->PDO->prepare($query);
        $stmt->execute($this->params);
        return $stmt;
    }

    /**
     * Get the parameters bound to the query
     *
     * @return array Parameters
     */
    public function getParams() {
        return $this->params;
    }

    /**
     * Set parameters for the query
     *
     * @param array $params Parameters to bind
     * @return $this
     */
    public function setParams(array $params) {
        $this->params = $params;
        return $this;
    }

    /**
     * Bind additional parameters to the query
     *
     * @param array $params Parameters to bind
     * @return $this
     */
    public function bindParams(array $params) {
        $parsedParams = [];
        foreach ($params as $key => $value) {
            $paramKey = strpos($key, ':') === 0 ? $key : ':' . $key;
            $parsedParams[$paramKey] = $value;
        }
        $this->params = array_merge($this->params, $parsedParams);
        return $this;
    }

    /**
     * Get the last constructed query
     *
     * @return string SQL query
     */
    public function lastQuery() {
        $this->constructQuery();
        return $this->query;
    }

    /**
     * Remove newlines from the query
     *
     * @return string Trimmed query
     */
    private function trimQuery() {
        return str_replace("\n", " ", $this->query);
    }

    /**
     * Construct the SQL query
     */
    private function constructQuery() {
        $columns = implode(', ', $this->columns);

        $query = "SELECT $columns FROM {$this->table}";
        if ($this->joins) {
            $query .= " " . implode(" ", $this->joins);
        }

        if ($this->where) {
            $where = implode(' AND ', $this->where);
            $query .= " WHERE $where";
        }

        if ($this->order) {
            $order = implode(', ', $this->order);
            $query .= " ORDER BY $order";
        }

        if ($this->limit !== null) {
            $offset = $this->offset !== null ? $this->offset : 0;
            $query .= " LIMIT $offset, {$this->limit}";
        }
        $this->query = $query;
    }

    /**
     * Parse JOIN conditions
     *
     * @param array $conditions Conditions
     * @return string Parsed conditions
     */
    private function parseJoinConditions($conditions) {
        array_shift($conditions); // Remove table name from arguments
        $parsed = array();
        foreach ($conditions as $condition) {
            $parsed[] = $this->parseJoinCondition($condition);
        }
        return implode(' AND ', $parsed);
    }

    /**
     * Parse a single JOIN condition
     *
     * @param string $condition Condition
     * @return string Parsed condition
     * @throws Exception
     */
    private function parseJoinCondition($condition) {
        $operators = ['=', '>', '<', '>=', '<=', '<>'];
        foreach ($operators as $operator) {
            if (strpos($condition, $operator) !== false) {
                $parts = explode($operator, $condition, 2);
                if (count($parts) == 2) {
                    $left = $this->parseColName(trim($parts[0]));
                    $right = $this->parseColName(trim($parts[1]));
                    return "$left $operator $right";
                }
            }
        }
        throw new Exception("Invalid join condition: $condition");
    }

    /**
     * Parse column names
     *
     * @param array $cols Columns
     * @return array Parsed columns
     */
    private function parseCols(array $cols) {
        $parsed = array();
        foreach ($cols as $key => $val) {
            if (is_numeric($key)) {
                // Handle column with possible alias, e.g., 'u.id AS user_id'
                if (preg_match('/\s+AS\s+/i', $val)) {
                    $parts = preg_split('/\s+AS\s+/i', $val);
                    $parsed[] = $this->parseColName($parts[0]) . ' AS ' . DB::quoteIdentifier($parts[1]);
                } else {
                    $parsed[] = $this->parseColName($val);
                }
            } else {
                $parsed[] = $this->parseColName($key) . ' AS ' . DB::quoteIdentifier($val);
            }
        }
        return $parsed;
    }

    /**
     * Parse a column name, safely quoting identifiers
     *
     * @param string $string Column name
     * @return string Parsed column name
     */
    private function parseColName($string) {
        $string = trim($string);
    
        // Remove existing backticks to prevent double quoting
        $string = str_replace('`', '', $string);
    
        // If the string is '*', return it directly
        if ($string === '*') {
            return '*';
        }
    
        // Handle functions and expressions
        if (preg_match('/\b(AVG|COUNT|MIN|MAX|SUM)\s*\(/i', $string) || preg_match('/[\(\)\+\-\/\*\s,]/', $string)) {
            // Contains SQL functions or operators, return as is
            return $string;
        }
    
        // Handle table.column format
        if (strpos($string, '.') !== false) {
            list($table, $column) = explode('.', $string, 2);
            $table = DB::quoteIdentifier($table);
            if ($column === '*') {
                // Do not quote the '*'
                return $table . '.*';
            } else {
                $column = DB::quoteIdentifier($column);
                return $table . '.' . $column;
            }
        }
    
        // Otherwise, quote the column name
        return DB::quoteIdentifier($string);
    }

    /**
     * Parse conditions and bind parameters
     *
     * @param array $conditions Conditions array
     * @return array Parsed clauses and parameters
     */
    private function parseConditions(array $conditions) {
        $clauses = [];
        $params = [];
        foreach ($conditions as $col_condition => $value) {
            $col_condition = trim($col_condition);
            $operator = '=';
            if (preg_match('/^(.*?)(\s+|)(>=|<=|<>|>|<|!=|=)$/', $col_condition, $matches)) {
                $col = trim($matches[1]);
                $operator = $matches[3];
            } else {
                $col = $col_condition;
            }
            $colName = $this->parseColName($col);
            $paramKey = ':param_' . $this->paramCounter++;
            $clauses[] = "$colName $operator $paramKey";
            $params[$paramKey] = $value;
        }
        return array(
            'clauses' => $clauses,
            'params' => $params,
        );
    }

    public function showQuery() {
        $this->constructQuery();
        return $this->query;
    }

}
