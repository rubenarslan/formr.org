<?php

class DB {

    /**
     * @var DB
     */
    protected static $instance = null;

    /**
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
     * @var interger
     */
    protected $default_type = PDO::PARAM_STR;

    /**
     * Get a DB instance
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
     * @var PDO
     */
    protected $PDO;
	/**
	 * 
	 * @var array(PDOStatement, array)
	 */
	protected $lastStatement;

	protected function __construct() {
        $params = (array) Config::get('database');

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
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES '.$options['charset'],
        ));

        $dt = new DateTime();
        $offset = $dt->format("P");

        $this->PDO->exec("SET time_zone='$offset';");
        $this->PDO->exec("SET SESSION sql_mode='STRICT_ALL_TABLES';");
    }

    /**
     * Execute any query with parameters and get results
     *
     * @param string $query Query string with optional placeholders
     * @param array $params An array of parameters to bind to PDO statement
     * @param bool $fetchcol
     * @param bool $fetchrow
     * @return array Returns an associative array of results
     */
    public function execute($query, $params = array(), $fetchcol = false, $fetchrow = false) {
        $data = self::parseWhereBindParams($params);
        $params = $data['params'];
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
     * Used for INSERT, UPDATE and DELETE
     *
     * @param string $query MySQL query with placeholders
     * @param array $data Optional associative array of parameters that will be bound to the query
     * @return int Returns the number of affected rows of the query
     */
    public function exec($query, array $data = array()) {
        if ($data) {
            $data = self::parseWhereBindParams($data);
            $params = $data['params'];
            $sth = $this->PDO->prepare($query);
			$this->lastStatement = [$sth, $params];
            $sth->execute($params);
            return $sth->rowCount();
        }
        return $this->PDO->exec($query);
    }

    /**
     * Used for SELECT or 'non-modify' queries
     *
     * @param string $query SQL query to execute
     * @param bool $return_statemnt [optional] If set to true, PDOStatement is always returned
     * @return mixed Returns a PDOStatement if not selecting else returns selected results in an associative array
     */
    public function query($query, $return_statemnt = false) {
        $stmt = $this->PDO->query($query);
        if (preg_match('/^select/', strtolower($query)) && $return_statemnt === false) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $stmt;
    }

    /**
     * @param string $query
     * @param array $params
     *
     * @return PDOStatement
     */
    public function rquery($query, $params = array()) { //secured query with prepare and execute
        $stmt = $this->PDO->prepare($query);
		$this->lastStatement = [$stmt, $params];
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Get the number of rows in a result
     *
     * @param string $query
     * @return mixed
     */
    public function num_rows($query) {
        # create a prepared statement
        $stmt = $this->PDO->prepare($query);
		$this->lastStatement = [$stmt, null];
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function table_exists($table) {
        return $this->num_rows("SHOW TABLES LIKE '" . $table . "'") > 0;
    }

    public function __destruct() {
        $this->PDO = null;
    }

    public function getError() {
        return $this->PDO->errorInfo();
    }

    /**
     * Find a set of records from db
     *
     * @param string $table_name
     * @param string|aray $where
     * @param array $params
     * @return array
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

        // unset all the shit that is not necessary for binding
        unset($params['cols'], $params['order_by'], $params['order'], $params['limit'], $params['offset']);
        if ($params) {
            $params = self::parseWhereBindParams($params);
            $select->setParams($params['binds']);
        }

        return $select->fetchAll();
    }

    public function findRow($table_name, $where = null, $cols = array()) {
        return $this->select($cols)->from($table_name)->where($where)->limit(1)->fetch();
    }

    public function findValue($table_name, $where = null, $cols = array()) {
        return $this->select($cols)->from($table_name)->where($where)->limit(1)->fetchColumn();
    }

    public function select($cols = array()) {
        if (is_string($cols)) {
            $cols = explode(',', $cols);
        }
        return new DB_Select($this->PDO, $cols);
    }

    /**
     * Count
     *
     * @param string $table_name
     * @param array|string $where If a string is given, it must be properly escaped
     * @param string $col specify some column name to count
     * @return int
     */
    public function count($table_name, $where = array(), $col = '*') {
        $query = "SELECT count({$col}) FROM {$table_name}";
        $params = array();
        if ($where && is_array($where)) {
            $wc = self::parseWhereBindParams($where);
            $query .= " WHERE {$wc['clauses_str']}";
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
     * Assert the existence of rows in a table
     *
     * @param string $table_name
     * @param array|string $where If a string is given, it must be properly escaped
     * @return boolean
     */
    public function entry_exists($table_name, $where) {
        return $this->count($table_name, $where) > 0;
    }

    /**
     * Insert Data into a MySQL Table
     *
     * @param string $table_name Table Name
     * @param array $data An associative array of data with keys representing column names
     * @param array $types A numerically indexed array representing the data-types of the value in the $data array.
     * @throws Exception
     * @return mix Returns an integer if last insert id was set else returns null
     */
    public function insert($table_name, array $data, array $types = array()) {
        if (!$this->checkTypeCount($data, $types)) {
            throw new Exception("Array count for data and data-types do not match");
        }

        $keys = array_map(array('DB', 'pkey'), array_keys($data));
        $cols = array_map(array('DB', 'quoteCol'), array_keys($data));

        $query = self::replace("INSERT INTO %{table_name} (%{cols}) VALUES (%{values})", array(
                    'cols' => implode(', ', $cols),
                    'values' => implode(', ', $keys),
                    'table_name' => $table_name,
        ));

        /* @var $stmt PDOStatement */
        $stmt = $this->PDO->prepare($query);
        $stmt = $this->bindValues($stmt, $data, array_values($types), false, true);
		$this->lastStatement = [$stmt, $data];
        $stmt->execute();
        return $this->lastInsertId();
    }

    /**
     * Insert data and update values on duplicate keys
     *
     * @param string $table Table name
     * @param array $data An associative array of key,value pairs representing data to insert
     * @param array $updates [optional] Optional column names representing what values to update
     * @return int Returns number of affected rows
     */
    public function insert_update($table, array $data, array $updates = array()) {
        $columns = array_map(array('DB', 'quoteCol'), array_keys($data));
        $values = array_map(array('DB', 'pkey'), array_keys($data));
        if (!$updates) {
            $updates = array_keys($data);
        }
        $table = self::quoteCol($table);

        $columns_str = implode(',', $columns);
        $values_str = implode(',', $values);
        $updates_str = $this->getDuplicateUpdateString($updates);

        $query = "INSERT INTO $table ($columns_str) VALUES ($values_str) ON DUPLICATE KEY UPDATE $updates_str";
        return $this->exec($query, $data);
    }

    public function update($table_name, array $data, array $where, array $data_types = array(), array $where_types = array()) {
        if (!$this->checkTypeCount($data, $data_types)) {
            throw new Exception("Array count for data and data-types do not match");
        }

        if (!$this->checkTypeCount($where, $where_types)) {
            throw new Exception("Array count for where clause and where clause data-types do not match");
        }

        // remove signs from where clauses (<, >, <= , >=, != can be used in array keys of $where array. E.g $where['id >'] = 45)
        $cols_where = array_keys($where);
        $signs = array();
        foreach ($cols_where as $i => $col_condition) {
            $col_condition = trim($col_condition);
            $col_condition = preg_replace('/\s+/', ' ', $col_condition);
            $parts = array_filter(explode(' ', $col_condition));
            if (count($parts) === 1) {
                $signs[$i] = '=';
            } else {
                $signs[$i] = $parts[1];
            }
            $cols_where[$i] = $parts[0];
        }

        $cols = array_map(array('DB', 'quoteCol'), array_keys($data));
        $cols_where = array_map(array('DB', 'quoteCol'), $cols_where);
        $set_values = $where_values = array();

        foreach ($cols as $col) {
            $set_values[] = "$col = ?";
        }

        foreach ($cols_where as $i => $col) {
            $sign = !empty($signs[$i]) ? $signs[$i] : '=';
            $where_values[] = "$col $sign ?";
        }

        $query = self::replace("UPDATE %{table_name} SET %{set_values} WHERE (%{where_values})", array(
                    'where_values' => implode(' AND ', $where_values),
                    'set_values' => implode(', ', $set_values),
                    'table_name' => $table_name,
        ));

        /* @var $stmt PDOStatement */
        $stmt = $this->PDO->prepare($query);
        $stmt = $this->bindValues($stmt, $data, array_values($data_types), true, true);
        $stmt = $this->bindValues($stmt, $where, array_values($where_types));
		$this->lastStatement = [$stmt, $data];
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function delete($table_name, array $data, array $types = array()) {
        $cols = array_map(array('DB', 'pCol'), array_keys($data));
        $query = self::replace("DELETE FROM %{table_name} WHERE (%{values})", array(
                    'values' => implode(' AND ', $cols),
                    'table_name' => self::quoteCol($table_name),
        ));

        /* @var $stmt PDOStatement */
        $stmt = $this->PDO->prepare($query);
        $stmt = $this->bindValues($stmt, $data, array_values($types), false, true);
		$this->lastStatement = [$stmt, $data];
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * @param string $query
     * @param array $options [optional] driver options
     * @return PDOStatement
     */
    public function prepare($query, $options = array()) {
        return $this->PDO->prepare($query, $options);
    }

    /**
     * Quote a string for sql query
     *
     * @param string $string
     * @return string
     */
    public function quote($string) {
        return $this->PDO->quote($string);
    }

    /**
     * @return PDO
     */
    public function pdo() {
        return $this->PDO;
    }

    public function lastInsertId() {
        return $this->PDO->lastInsertId();
    }

    /**
     * 
     * @param PDOStatement $stmt
     * @param array $data
     * @param array $types
     * @param bool $numeric
     * #param bool $reset
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
            $stmt->bindValue(($numeric ? $i + 1 : self::pkey($key)), $value, $type);
            $i++;
        }
        return $stmt;
    }

    private function checkTypeCount(array $data, array $types) {
        if (!$types) {
            return true;
        }
        return count($types) === count($data);
    }

    public function getDuplicateUpdateString($columns) {
        foreach ($columns as $i => $column) {
            if (is_numeric($i)) {
                $column = trim($column, '`');
                $columns[$i] = "`$column` = VALUES(`$column`)";
            } else {
                $value = $column;
                $column = trim($i, '`');

                if ($value !== null) {
                    if (strstr($value, '::') !== false) {
                        $value = str_replace('::', '', $value);
                    } else {
                        $value = $this->PDO->quote($value);
                    }
                } else {
                    $value = 'NULL';
                }
                $columns[$i] = "`$column` = $value";
            }
        }
        return implode(', ', $columns);
    }

    public static function pkey($key) {
        $key = trim($key);
        $key = trim($key, '`:');
        return ':' . $key;
    }

    public static function pCol($col) {
        $col = trim($col);
        $col = trim($col, '`:');
        $col = "`$col` = :$col";
        return $col;
    }

    public static function quoteCol($col, $table = null) {
        $col = trim($col);
        $col = trim($col, '`');
        return $table !== null ? "`$table`.`$col`" : "`$col`";
    }

    public static function parseColName($string) {
        if (strpos($string, '.') !== false) {
            $string = explode('.', $string, 2);
            $tableName = self::quoteCol($string[0]);
            $fieldName = self::quoteCol($string[1]);
            return $tableName . '.' . $fieldName;
        }
        return self::quoteCol($string);
    }

    public static function parseWhereBindParams(array $array) {
        $cols = array_keys($array);
        $values = array_values($array);

        $clauses = array_map(array('DB', 'pCol'), $cols);
        $params = array();

        foreach ($cols as $i => $col) {
            $params[self::pkey($col)] = $values[$i];
        }
        return array(
            'clauses' => $clauses,
            'clauses_str' => implode(' AND ', $clauses),
            'params' => $params,
        );
    }

    public static function replace($string, $params = array()) {
        foreach ($params as $key => $value) {
            $key = "%{" . $key . "}";
            $string = str_replace($key, $value, $string);
        }
        return $string;
    }

    /**
     * For transactions just use same calls
     */

    /**
     * {inherit PDO doc}
     */
    public function beginTransaction() {
        if (!$this->PDO->inTransaction()) {
            return $this->PDO->beginTransaction();
        }
    }

    /**
     * {inherit PDO doc}
     */
    public function commit() {
        if ($this->PDO->inTransaction()) {
            return $this->PDO->commit();
        }
    }

    /**
     * {inherit PDO doc}
     */
    public function rollBack() {
        if ($this->PDO->inTransaction()) {
            return $this->PDO->rollBack();
        }
    }

    public function getTableDefinition($table, $property = null) {
        if (!$this->table_exists($table)) {
            return array();
        }

        $query = "SHOW COLUMNS FROM `$table`";
        $stmt = $this->PDO->query($query);
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

    public function retryTransaction(Exception $e) {
        return strstr($e->getMessage(), 'try restarting transaction') !== false;
    }
    
    public function logLastStatement(Exception $e) {
        $interestingCodes = ['HY000', '23000', '40001'];
        if (in_array($e->getCode(), $interestingCodes) && $this->lastStatement) {
			formr_log($this->lastStatement[0]->queryString, 'MySQL_QUERY');
			formr_log($this->lastStatement[1], 'MySQL_PARAMS');
		}
    }

}

class DB_Select {

    /**
     * @var PDO
     */
    protected $PDO;

    /**
     * Constructed SQL statement
     *
     * @var string
     */
    protected $query;
    protected $where = array();
    protected $or_where = array();
    protected $joins = array();
    protected $columns = array('*');
    protected $params = array();
    protected $order = array();
    protected $limit;
    protected $offset;
    protected $table;

    public function __construct(PDO $pdo, array $cols = array()) {
        $this->PDO = $pdo;
        $this->columns($cols);
    }

    public function __destruct() {
        $this->PDO = null;
    }

    public function columns(array $cols) {
        if ($cols) {
            $this->columns = $this->parseCols($cols);
        }
    }

    public function from($table) {
        $this->table = DB::quoteCol($table);
        return $this;
    }

    public function leftJoin($table, $condition) {
        $table = DB::quoteCol($table);
        $condition = $this->parseJoinConditions(func_get_args());
        $this->joins[] = " LEFT JOIN $table ON ($condition)";
        return $this;
    }

    public function rightJoin($table, $condition) {
        $table = DB::quoteCol($table);
        $condition = $this->parseJoinConditions(func_get_args());
        $this->joins[] = " RIGHT JOIN $table ON ($condition)";
        return $this;
    }

    public function join($table, $condition) {
        $table = DB::quoteCol($table);
        $condition = $this->parseJoinConditions(func_get_args());
        $this->joins[] = " INNER JOIN $table ON ($condition)";
        return $this;
    }

    public function where($where) {
        if (is_array($where)) {
            $whereParsed = $this->parseWhere($where);
            $this->where = array_merge($this->where, $whereParsed['clauses']);
            $this->params = array_merge($this->params, $whereParsed['params']);
        } elseif (is_string($where)) {
            $this->where[] = $where;
        }
        return $this;
    }

    public function whereIn($field, array $values) {
        $field = $this->parseColName($field);
        $values = array_map(array($this->PDO, 'quote'), $values);
        $this->where[] = "{$field} IN (" . implode(',', $values) . ")";
    }

    public function like($colname, $value, $pad = 'both') {
        $colname = $this->parseColName($colname);
        if ($pad === 'right') {
            $value = "$value%";
        } elseif ($pad === 'left') {
            $value = "%$value";
        } else {
            $value = "%$value%";
        }
        $this->PDO->quote($value);
        $this->where("$colname LIKE '$value'");
        return $this;
    }

    public function limit($limit, $offset = 0) {
        $this->limit = (int) $limit;
        $this->offset = (int) $offset;
        return $this;
    }

    public function order($by, $order = 'asc') {
        if ($by === 'RAND') {
            $this->order[] = 'RAND()';
            return $this;
        }

        if ($order === null) {
            $this->order[] = $by;
            return $this;
        }

        $order = strtoupper($order);
        if (!in_array($order, array('ASC', 'DESC'))) {
            throw new Exception("Invalid Order");
        }
        $by = $this->parseColName($by);
        $this->order[] = "$by $order";
        return $this;
    }

    /**
     * {inherit PDO doc}
     */
    public function fetchAll($fetch_style = PDO::FETCH_ASSOC) {
        $this->constructQuery();
        $query = $this->trimQuery();
        $stmt = $this->PDO->prepare($query);
        $stmt->execute($this->params);
        return $stmt->fetchAll($fetch_style);
    }

    /**
     * {inherit PDO doc}
     */
    public function fetch($fetch_style = PDO::FETCH_ASSOC) {
        $this->constructQuery();
        $query = $this->trimQuery();
        $stmt = $this->PDO->prepare($query);
        $stmt->execute($this->params);
        return $stmt->fetch($fetch_style);
    }

    /**
     * {inherit PDO doc}
     */
    public function fetchColumn() {
        $this->constructQuery();
        $query = $this->trimQuery();
        $stmt = $this->PDO->prepare($query);
        $stmt->execute($this->params);
        return $stmt->fetchColumn();
    }

    /**
     * Returns executed PDO statement of current query
     *
     * @return PDOStatement
     */
    public function statement() {
        $this->constructQuery();
        $query = $this->trimQuery();
        $stmt = $this->PDO->prepare($query);
        if ($this->params) {
            foreach ($this->params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
        }

        $stmt->execute();
        return $stmt;
    }

    public function getParams() {
        return $this->params;
    }

    public function setParams(array $params) {
        $this->params = $params;
        return $this;
    }

    public function bindParams(array $params) {
        $params = $this->parseWhere($params);
        $this->params = array_merge($this->params, $params['params']);
        return $this;
    }

    public function lastQuery() {
        $this->constructQuery();
        return $this->query;
    }

    private function trimQuery() {
        return str_replace("\n", "", $this->query);
    }

    /**
     * @todo Add or_where and or_like clauses
     */
    private function constructQuery() {
        $columns = implode(', ', $this->columns);

        $query = "SELECT $columns FROM {$this->table} \n";
        if ($this->joins) {
            $query .= implode(" \n", $this->joins);
        }

        if ($this->where) {
            $where = implode(' AND ', $this->where);
            $query .= " WHERE ($where)";
        }

        if ($this->order) {
            $order = implode(', ', $this->order);
            $query .= " \nORDER BY " . $order;
        }

        if ($this->limit) {
            $query .= " \nLIMIT {$this->offset}, {$this->limit}";
        }
        $this->query = $query;
    }

    private function parseJoinConditions($conditions) {
        array_shift($conditions); // first arguement is the table name
        $parsed = array();
        foreach ($conditions as $condition) {
            $parsed[] = $this->parseJoinCondition($condition);
        }
        return implode(' AND ', $parsed);
    }

    private function parseJoinCondition($condition) {
        $conditions = explode('=', $condition, 2);
        if (count($conditions) != 2) {
            throw new Exception("Unable to get join condition clauses");
        }
        $conditions = $this->parseCols($conditions);
        return implode(' = ', $conditions);
    }

    private function parseCols(array $cols) {
        $select = array();
        foreach ($cols as $key => $val) {
            if (is_numeric($key)) {
                $select[] = $this->parseColName($val);
            } else {
                $select[] = $this->parseColName($key) . ' AS ' . $this->parseColName($val);
            }
        }
        return $select;
    }

    private function parseColName($string) {
        $string = trim($string);
        // If the column is not one of these then maybe some mysql func or so is called
        if (preg_match('/[^a-zA-Z0-9_\.\`]/i', $string, $matches)) {
            return $string;
        }

        $string = trim($string, '`');

        if (strpos($string, '.') !== false) {
            $string = explode('.', $string, 2);
            $tableName = DB::quoteCol($string[0]);
            $fieldName = DB::quoteCol($string[1]);
            return $tableName . '.' . $fieldName;
        }
        return DB::quoteCol($string);
    }

    private function parseWhere(array $array) {
        $cols = array_keys($array);
        $values = array_values($array);

        $signs = array();
        foreach ($cols as $i => $col_condition) {
            $col_condition = preg_replace('/\s+/', ' ', trim($col_condition));
            $parts = array_filter(explode(' ', $col_condition));
            if (count($parts) === 1) {
                $signs[$i] = '=';
            } else {
                $signs[$i] = $parts[1];
            }
            $cols[$i] = $parts[0];
        }

        $clauses = array(); //array_map(array('DB', 'pCol'), $cols);
        $params = array();
        foreach ($cols as $i => $col) {
            $sign = !empty($signs[$i]) ? $signs[$i] : '=';
            $param = $col;
            if (strstr($col, '.') !== false) {
                list($c, $param) = explode('.', $col, 2);
            }
            $col = $this->parseColName($col);
            $clauses[] = "$col $sign :$param";
            $params[DB::pkey($param)] = $values[$i];
        }

        return array(
            'clauses' => $clauses,
            'params' => $params,
        );
    }

}