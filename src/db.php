<?php
namespace ddn\api\db;
use ddn\api\Helpers;

if (defined('__DB_API_DB__')) return;
define('__DB_API_DB__', true);

if (!defined('__DEBUG_DB_QUERIES'))
    define('__DEBUG_DB_QUERIES', false);

/**
 * This is a simple class that wraps database access for some functions. It tries to be compatible with wordpress wpdb class
 *   in the functions that it implements
 */

class DB {
    /**
     * This function creates a DB object and makes sure that the parameters to connect to the database are correct.
     * @param $dbname the name of the database
     * @param $dbuser the user to connect to the database
     * @param $dbpass the password to connect to the database
     * @param $dbhost the host to connect to the database
     * @return the DB object or false if the parameters are incorrect
     */
    public static function create($dbname, $dbuser, $dbpass, $dbhost = 'localhost') {
        $db = new self($dbhost, $dbuser, $dbpass, $dbname);
        if (!$db->is_connected) {
            return false;
        }
        return $db;
    }

    /**
     * Function that creates a prepared query to the DB. The query string must include the needed ? to place the values, and then
     *   the types_s include the letter corresponding to each of the types of the values. Finally the values_a array includes the
     *   values to be used in the query.
     * @param string $query_str the mysql query string
     * @param string $types_s the string with the types of the values
     * @param array $values_a the array with the values
     */
    public function p_query($query_str, $types_a = [], $values_a = []) {
        self::debug("Prepared query");

        if (count($types_a) != count($values_a)) {
            self::debug("ERROR: The number of types and values are different");
            $this->error = "The number of types and values must be the same";
            return false;
        }

        if ($this->is_connected()) {

            self::debug("Query string: $query_str", "Types: " . implode(",", $types_a), "Values: " . implode(",", $values_a));

            // Prepare the query
            $stmt = $this->conn->prepare($query_str);
            $this->error = null;

            // If failed, inform about the error and return false
            if ($stmt === false) {
                $this->error = $this->conn->error;
                self::debug("Error: " . $this->error);
                return false;
            }

            // Bind the parameters to the query
            if (sizeof($values_a) > 0) {
                $stmt->bind_param(implode("", $types_a), ...$values_a);
            }

            // Execute the query
            $result = false;
            if ($stmt->execute() !== false) {
                $result = true;
                $this->_results = $stmt->get_result();
            } else {
                $this->error = $this->conn->error;
                self::debug("Error: " . $this->error);
            }

            // Close the statement
            $stmt->close();
            return $result;
        }
        return false;
    }

    /**
     * Creates a SELECT query using the given conditions.
     *   @param $table is the table to select from (to qualify the fields)
     *   @param $conditions is the list of conditions to use, as a list of field => value pairs, using the syntax in p_build_where
     *   @param $renaming is a list of field => new_name pairs, to rename the fields in the query (i.e. 'field'=>'newfield' 
     *              will convert into select `table`.`field` as `newfield`). If empty, will not rename any fields and will
     *              select all the fields (i.e. SELECT *); otherwise having a list like [ field1 => ff1, field2, field3 => ff3 ]
     *              will convert into select "field1 as ff1, field2, field3 as ff3" and no other field will be selected.
     * 
     *              it is also possible to use the special field '*' to select all the fields (i.e. SELECT *) in the list, but
     *              also prepend "#" to a field, to select count: [ '#field1' => "nf1", "*" ] will translate into 
     *              "SELECT COUNT(field1) as nf1, *"
     */
    public function p_search($table, $condition = array(), $renamefields = array(), $conditioncompose = 'AND', $orderby = null, $groupby = array(), $rawsql = null) {
        list($where_s, $types_a, $values_a) = self::p_build_where($condition, $table, $conditioncompose);

        $fields_s = trim(self::_qualify_fields($renamefields, $table));
        if ($fields_s === '')
            $fields_s = '*';

        $query_str = "SELECT ${fields_s} FROM $table";
        if ($where_s !== '')
            $query_str .= " WHERE $where_s";

        if ($orderby !== null) {
            if (is_array($orderby))
                $query_str .= ' ORDER BY ' . implode(',', $orderby);
            else
                $query_str .= ' ORDER BY ' . $orderby;
        }

        if (count($groupby) > 0)
            $query_str .= " GROUP BY " . trim(self::_qualify_fields($groupby, $table, false));

        if ($rawsql !== null) { 
            $query_str .= " $rawsql";
        }
        
        $result = $this->p_query($query_str, $types_a, $values_a);
        if ($result === false) return array();

        $objects = [];
        while ($obj = $this->_results->fetch_object()) {
            $objects[] = $obj;
        }
        return $objects;
    }    

    /**
     * Function that creates an INSERT query using the given values.
     * @param table is the table to insert into
     * @param values is the list of values to insert, as a list of field => value pairs, using the syntax in p_build_where
     * @return false if fails, the id of the inserted row otherwise
     */
    public function p_insert($table, $values) {
        list($markers_a, $types_a, $values_a, $fields_a) = self::_prepare_markers($values);
        $query_str = "INSERT INTO `$table` (" . implode(',', $fields_a) . ") VALUES (" . implode("," , $markers_a) . ")";

        $result = $this->p_query($query_str, $types_a, $values_a);

        // An insert does not return anything but false
        if ($result !== false) {
            $this->insert_id = $this->conn->insert_id;
            return $this->insert_id;
        }

        return false;
    }

    /**
     * Function that creates a DELETE query using the given values.
     * @param table is the table in which to delete
     * @param condition is the list of conditions to use, as a list of field => value pairs, using the syntax in p_build_where
     * @return true if succeded; false otherwise
     */
    public function p_delete($table, $condition, $conditioncompose = 'AND') {
        list($where_s, $types_a, $values_a) = $this->p_build_where($condition, $table, $conditioncompose);

        $query_str = "DELETE FROM $table";
        if ($where_s !== '')
            $query_str .= " WHERE $where_s";

        $result = $this->p_query($query_str, $types_a, $values_a);
        return $result;
    }

    /*
    * Function that creates an UPDATE query using the given values.
    * @param table is the table in which to update
    * @param new_values is the list of new values to set, as a list of field => value pairs
    * @param where is the list of conditions to use, as a list of field => value pairs, using the syntax in p_build_where
    * @return true if succeded; false otherwise
    */
    public function p_update($table, $new_values, $where) {
        list($markers_a, $types_a, $values_a, $fields_a) = self::_prepare_markers($new_values);

        // Prepare the update
        $set_a = array();
        for ($i = 0; $i < sizeof($markers_a); $i++)
            array_push($set_a, sprintf("%s = %s", $fields_a[$i], $markers_a[$i]));

        $query_str = "UPDATE `$table` SET " . implode(' , ', $set_a);

        // Now build where
        list($where_s, $types_w_a, $values_w_a) = $this->p_build_where($where, $table, 'AND');
        if ($where_s !== '')
            $query_str .= " WHERE $where_s";
    
        $types_a = array_merge($types_a, $types_w_a);
        $values_a = array_merge($values_a, $values_w_a);

        return $this->p_query($query_str, $types_a, $values_a);
    }

    /**
     * Begins a transaction so that a series of queries can be executed (or canceled)
     *  (*) the connection is set in autocommit mode
     * @return boolean: true if the transaction was started, false otherwise
     */
    public function begin_transaction() {
        self::debug("Begin transaction");
        
        $result = false;
        if ($this->is_connected()) {
            $result = $this->conn->begin_transaction(MYSQLI_TRANS_START_READ_WRITE);
            if ($result)
                $this->conn->autocommit(false);
        }

        return $result;
    }

    /**
     * Aborts a transaction that has been started
     *  (*) the connection is set in autocommit mode
     * @return boolean: true if the transaction was started, false otherwise
     */
    public function abort_transaction() {
        self::debug("Abort transaction");

        $result = false;
        if ($this->is_connected())
            $result = $this->conn->rollback();

        $this->conn->autocommit(true);
        return $result;
    }

    /**
     * Commits a transaction that has been started
     *  (*) the connection is set in autocommit mode
     * @return boolean: true if the transaction was successfully commited, false if failed
     */
    public function end_transaction() {
        self::debug("End transaction");

        $result = false;
        if ($this->is_connected())
            $result = $this->conn->commit();

        $this->conn->autocommit(true);
        return $result;
    }

    /**
     * Return wether the connection is established or not
     * @return boolean: true if the connection is established, false otherwise
     */
    public function is_connected() { 
        return $this->is_connected; 
    }  

    /** Constructs the object, but it is private because we want that the DB connection is created
     *    using class function ::create
     *  (*) the connection is set in autocommit mode
     *  @param db_servername: the server name
     *  @param db_username: the username
     *  @param db_password: the password
     *  @param db_name: the database name
     */
    private function __construct($db_servername, $db_username, $db_password, $db_database) {
        self::debug("Construct DB Object");

        // Create connection
        $this->conn = new \mysqli($db_servername, $db_username, $db_password, $db_database);    
        $this->is_connected = ! $this->conn->connect_error;
        $this->error = $this->conn->connect_error;

        // Set autocommit to true
        $this->conn->autocommit(true);
    }

    /**
     * Special function to debug the database connection
     */
    private static function debug(...$args) {
        if (__DEBUG_DB_QUERIES) {
            foreach ($args as $arg)
                Helpers::p_debug_h($arg);
        }
    }

    // Whether the connection was successfully established or not
    private $is_connected = false;

    // The error in the last query (not retrievable at this moment)
    private $error = null;

    // The connection resource
    private $conn = null;

    // The ID of the last inserted element
    private $insert_id = null;

    // The mysql result of the last successfull query (not intended to be retrievable at this moment)
    private $_results = null;

    /**
     * Obtains the last error for this connection
     * @return string: the last error
     */
    public function get_error() {
        return $this->error;
    }

    /** 
     * Function that converts a value from PHP to MySQL format 
     *   (*) at this moment converts only from DateTime to string; the rest is a "best effort" (json encoding)
    */
    static private function _convert_value_to_msyql($value) {
        switch (gettype($value)) {
            case 'object':
                if (get_class($value) == 'DateTime')
                    $value = $value->format('Y-m-d H:i:s.v'); 
                else
                    $value = json_encode($value); 
                break;
        }
        return $value;
    }
    /**
     * Converts a value to the format that is expected by MySQL in a
     *   prepared query.
     */
    static private function _convert_value_t($value) {
        if ($value === null) {
            return "";
        }
        $c = "s";
        if (is_bool($value)) $c = 'd';
        if (is_int($value)) $c = 'd';
        if (is_float($value)) $c = 'f';
        return $c;
    }
    /** 
     * Function that prepares the markers for a prepared query.
     * 
     * Converts a set of keys and values (in the form of an associative key => object array) to 4 arrays
     *   that contain the markers for a prepared query, the type chars, the values and the keys.
     * @param $value the keys to convert
     * @return a list with 4 values:
     *  - an array of markers for the query (either '?' or null, if the value is null)
     *  - an array of the type chars for the query (supported by function _convert_value_t)
     *  - the array of values to substitute in the query
     *  - the array of the field names
     */
    static private function _prepare_markers($value) {
        $keys_a = array();
        $markers_a = array();
        $values_a = array();
        $types_a = array();
        foreach ($value as $k => $v) {
            array_push($keys_a, $k);
            if ($v === null) {
                array_push($markers_a, 'null');
            }
            else {
                array_push($markers_a, '?');
                array_push($values_a, self::_convert_value_to_msyql($v));
                array_push($types_a, self::_convert_value_t($v));
            }
        }
        return [ $markers_a, $types_a, $values_a, $keys_a ];
    }    
    /**
     * Query language:
     *  - starts with ! negates the rest
     *  - starts with * applies 'AND' to inner list expressions
     *  - start >, <, >=, <=, =, <> use that operator; start with ~ uses 'LIKE', and start with i~ uses LOWER LIKE
     *  - then use the name of the field
     * 
     *  - if value is null, use IS NULL regarding the operator
     *  - if value is list, use IN (if operator is '=', or multiple 'OR' (*) if operator is other than '=' (e.g. >=))
     * 
     *  (e.g.) "*>=d1" => [ "a", "b" ] will translate into "d1>='a' AND d1>='b'"
     *         ">=d1" => [ "a", "b" ] will translate into "d1>='a' OR d1>='b'" 
     *         "=d1" => [ "a", "b" ] will translate into "d1 in ('a', 'b')"
     * 
     * (*) there are more elegants implementations for this function, but this one is the more readable, for the sake
     *     of future maintenance.
     */
    static private function p_build_where($conditions, $tablename = "", $compose = "AND") {
        $tprefix = "";
        if ($tablename !== '')
            $tprefix = "`$tablename`.";

        $values = [];
        $types = [];
        $where = [];
        // $types = "";

        foreach ($conditions as $field => $value) {
            $analysis = self::query_field_analysis($field);

            // The code has been extracted to enable the analysis of field names in other functions
            $field = $analysis['field'];
            $op = $analysis['op'];
            $nop = $analysis['nop'];
            $negate = $analysis['negate'];
            $prefix = $analysis['prefix'];
            $postfix = $analysis['postfix'];
            $injoin = $analysis['injoin'];

            /*
            $op = null;
            $nop = null;
            $negate = false;
            $prefix = "";
            $postfix = "";
            $injoin = " OR ";

            if ((strlen($field) > 0) && ($field[0] === '!')) {
                $field = substr($field, 1);
                $negate = true;
            }
            if ((strlen($field) > 0) && ($field[0] === '*')) {
                $field = substr($field, 1);
                $injoin = " AND ";
            }

            switch (substr($field, 0, 2)) {
                case '>=': $op = '>='; $nop = '<'; break;
                case '<=': $op = '<='; $nop = '>'; break;
                case '<>': $op = '<>'; $nop = '='; break;
                case 'i%': $op = 'LIKE'; $nop = 'NOT LIKE'; $prefix = "LOWER("; $postfix = ")"; break;
            }
            if ($op !== null) {
                $field = substr($field, 2);
            } else {
                switch (substr($field, 0, 1)) {
                    case '>': $op = '>'; $nop = '<='; break;
                    case '<': $op = '<'; $nop = '>='; break;
                    case '=': $op = '='; $nop = '<>'; break;
                    case '%': $op = 'LIKE'; $nop = 'NOT LIKE'; break;
                }
                if ($op !== null) {
                    $field = substr($field, 1);
                }
            }
            if ($op === null) {
                $op = '=';
                $nop = '<>';
            }
            */

            if ($value === null) {
                if ($negate) {
                    $where[] = "($tprefix`$field` is NOT NULL)";
                } else {
                    $where[] = "($tprefix`$field` is NULL)";
                }
            } else {

                if (!is_array($value)) {
                    $value = [$value];
                }

                $subquery = [];

                $pos = array_search(null, $value);
                if ($pos !== false) {
                    // Remove all NULLs
                    while ($pos !== false) {
                        unset($value[$pos]);
                        $pos = array_search(null, $value);
                    }

                    // But only add one of them
                    if ($negate) {
                        $subquery[] = "$tprefix`$field` is NOT NULL";
                    } else {
                        $subquery[] = "$tprefix`$field` is NULL";
                    }
                }

                $sub_t = array_map([__CLASS__, "_convert_value_t"], $value);
                $sub_v = array_map([__CLASS__, "_convert_value_to_msyql"], $value);

                // It is a simple list
                if ((count($value) > 1) && ($op === "=")) {
                    $sub_m = implode(',', array_map(function($x) { return "?"; }, $value));

                    if ($negate)
                        $subquery[] = "$prefix$tprefix`$field`$postfix not in ($sub_m)";
                    else
                        $subquery[] = "$prefix$tprefix`$field`$postfix in ($sub_m)";
                } else {
                    // It is a list
                    for ($i = 0; $i < count($value); $i++) {
                        if ($negate)
                            $subquery[] = "$prefix$tprefix`$field`$postfix $nop ?";
                        else
                            $subquery[] = "$prefix$tprefix`$field`$postfix $op ?";
                    }
                }

                $values = array_merge($values, $sub_v);
                $types = array_merge($types, $sub_t);

                $where[] = '('.implode($injoin, $subquery).')';
            }
        }

        return [ implode(" $compose ", $where), $types, $values ];
    }

    /**
     * Function that analyzes the name of the field used in a query and obtains which operation the user is trying to
     *  perform (>, <, >=, <=, =, <>, LIKE), the equivalent negative operation.
     */
    public static function query_field_analysis($field) {
        $op = null;
        $nop = null;
        $negate = false;
        $prefix = "";
        $postfix = "";
        $injoin = " OR ";

        if ((strlen($field) > 0) && ($field[0] === '!')) {
            $field = substr($field, 1);
            $negate = true;
        }
        if ((strlen($field) > 0) && ($field[0] === '*')) {
            $field = substr($field, 1);
            $injoin = " AND ";
        }

        switch (substr($field, 0, 2)) {
            case '>=': $op = '>='; $nop = '<'; break;
            case '<=': $op = '<='; $nop = '>'; break;
            case '<>': $op = '<>'; $nop = '='; break;
            case 'i%': $op = 'LIKE'; $nop = 'NOT LIKE'; $prefix = "LOWER("; $postfix = ")"; break;
        }
        if ($op !== null) {
            $field = substr($field, 2);
        } else {
            switch (substr($field, 0, 1)) {
                case '>': $op = '>'; $nop = '<='; break;
                case '<': $op = '<'; $nop = '>='; break;
                case '=': $op = '='; $nop = '<>'; break;
                case '%': $op = 'LIKE'; $nop = 'NOT LIKE'; break;
            }
            if ($op !== null) {
                $field = substr($field, 1);
            }
        }
        if ($op === null) {
            $op = '=';
            $nop = '<>';
        }        

        return [
            'op' => $op,
            'nop' => $nop,
            'negate' => $negate,
            'prefix' => $prefix,
            'postfix' => $postfix,
            'injoin' => $injoin,
            'field' => $field
        ];
    }

    /**
     * This function qualifies the fields, prepending the name of the table to which they belong and rename them to other name. It also converts
     *   the expression #<field> to count(field)
     * @param fields array of fields to rename or to qualify; if has a "field" => "rename", the result will be <table>.<field> as <rename>, in case
     *               that it is a single field (e.g. 0 => "field"), the result for that field will be <table>.<field>
     * @param table the table identifier that will be prepended to each field
     * @param as if true, the fields will be renamed; otherwise, they will be only qualified (this is useful for GROUP BY clauses, thus ignoring the renaming)
     * @return str an string that contains the fields, qualidied by the table to which they belong and (if needed) the renamed field
     */
    private static function _qualify_fields($fields, $table = "", $as = true) {

        if ($fields === null)
            return "";

        $table_str = "";
        if (!empty($table)) {
            $table_str = "`${table}`.";
        }
    
        $fields_a = [];
        foreach ($fields as $k => $v) {
            if (is_int($k)) {
                $k = $v;
            }

            $count = false;
            // Detect the "count" clause
            if ($k[0] === "#") {
                $count = true;
                if ($k == $v) {
                    $v = substr($v, 1);
                }
                $k = substr($k, 1);
            } 

            // If needed to rename and the field is different, rename it
            $_f_str = $k === "*"? "${table_str}$k" : "${table_str}`${k}`";
            $_f_str = $count? "COUNT(${_f_str})" : "${_f_str}";
            if (($as) && ($k != $v)) {
                $fields_a[] = "$_f_str AS `$v`";
            } else {
                $fields_a[] = $_f_str;
            }
        }
        if (count($fields_a) === 0) 
            return "${table_str}*";
            
        return implode(', ', $fields_a);
    }    
}