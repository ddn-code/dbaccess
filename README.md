# DDN Database access API
A library to ease the access to MySQL database from  PHP web applications.

## DB

DB is a helper class to enable a general purpose access to MySQL.

### Usage

To connect to the DB you can simply use the static method `create`:

```php
$DB = DB::create(__DB_NAME, __DB_USER, __DB_PASS, __DB_HOST);
if ($DB === false) {
    throw new Exception("Could not connect to database");
    exit;
}
```

If constant `__DEBUG_DB_QUERIES` is defined to true, any query will be output as a `DEBUG` message, if in `DEBUG` mode.

### Functions

- `p_query($query_str, $types_a = [], $values_a = [])`

    Executes the generic SQL query `$query_str`. It is assumed that it is a *prepared query*, so in case of using parameters, they must be included using `?` inside the query; then `$types_a` is an array of sql char types for each of the parameters, and `$values_a` is an array that contains the values of these parameters.

- `p_search($table, $condition = array(), $renamefields = array(), $conditioncompose = 'AND', $orderby = null, $groupby = array(), $rawsql = null)`

    Builds a SQL SELECT query using the conditions in `$condition` (*). The fields selected can be renamed to other values, using array `$renamefields`. It also accepts ordering or grouping using sets of fields. At the end, it builds a `SELECT 't1'.'f1', 't1'.'f2' from 'table' WHERE ... ` query. If needed, an extra raw sql suffix can be added using `$rawsql`, which will be appended to the query string.

    The built SQL query will be executed and the return will be an array of objects (using mysql_fetch_object) or `false` in case of error.

    (*) Query language:
    - starts with ! negates the rest
    - starts with * applies 'AND' to inner list expressions
    - start >, <, >=, <=, =, <> use that operator; start with % uses 'LIKE', and start with i% uses LOWER LIKE
    - after the prefixes, it is assumed to include the name of the field
    - if value is null, use IS NULL regarding the operator
    - if value is list, use IN (if operator is '=', or multiple 'OR' (*) if operator is other than '=' (e.g. >=)):
        - "*>=d1" => [ "a", "b" ] will translate into "d1>='a' AND d1>='b'"
        - ">=d1" => [ "a", "b" ] will translate into "d1>='a' OR d1>='b'" 
        - "=d1" => [ "a", "b" ] will translate into "d1 in ('a', 'b')"


- `p_insert($table, $values)`

    Builds an SQL INSERT INTO `$table` query using the values in `$values`, which is an associative array. When building the query, the type of each value is detected, to use it into a prepared query. In case that an object is detected, it will be serialized to a string:
    - If it is a `DateTime` object, it will be fromatted to `Y-m-d H:i:s.v`.
    - Any other object will be serialized using `json_encode`.

- `p_delete($table, $condition, $conditioncompose = 'AND')`

    Builds a SQL DELETE FROM `$table` query using the values in `$values`. As in `p_insert`, the format of values will be detected and used to a prepared query.

- `p_update($table, $new_values, $where)`

    Builds a SQL UPDATE `$table` query using the values in `$new_values` to build the *SET* part of the query, and the values in `$where` for the *WHERE* part (here the query language of `p_search` is used). As in `p_insert`, the format of values will be detected and used to a prepared query.

- `begin_transaction()`, `abort_transaction()` and `end_transaction()`

    Are functions to control transactions in the DB.

- `is_connected()` returns whether the DB object is considered to be connected or not.

## DBObject and DBObjectReadOnly

These are class to ease the creation of objects that are backed in a database.

### Usage

These objects rely on the existence of a function `get_db()`:

(example)
```php
use ddn\api\db\DB;
function get_db() {
    global $DB;

    if (empty($DB)) {
        $DB = DB::create(__DB_NAME, __DB_USER, __DB_PASS, __DB_HOST);
        if ($DB === false) {
            throw new Exception("Could not connect to database");
            exit;
        }
    }
    return $DB;
}
```

Then, define the object backed in the database as in the next example:

```php
class DBTokenObject extends DBObject {
    const DB_TABLENAME = 'tokenobjects';

    function sql_creation() {
        $sql = "
            CREATE TABLE IF NOT EXISTS " . static::DB_TABLENAME . " (
                id bigint NOT NULL AUTO_INCREMENT,
                token varchar(32) NOT NULL,
                type varchar(32) NOT NULL,
                d1 varchar(255) DEFAULT NULL,
                d2 varchar(255) DEFAULT NULL,
                created datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                expires datetime(3) DEFAULT NULL,
                data text,
                PRIMARY KEY (id), UNIQUE (token)
            );";
        return $sql;
    }

    // We won't store any other field, just to ease legal issues
    const FIELDS = [
        'token',
        'type',
        'expires' => 'datetime',
        'data' => 'json',
        'created' => 'datetime',
        'd1',
        'd2',
    ];

    // The token will not be stored again in the database, because it should not be modified; moreover "created" will also not been stored because it is automatically set
    const SAVEFIELDS = [
        'type',
        'expires',
        'data',
        'd1',
        'd2',
    ];
}
```

The important parts here are:

- `DB_TABLENAME`, which is the table which backs the object, in the database.
- `FIELDS` is the definition of fields backed in the database, and their types (if ommited, the type will be assumed to be `string`). The possible types are `int`, `float`, `string`, `datetime` and `json`.
    - In case of `datetime`, the type of the php object will be assumed to be `Datetime`. The information will be stored in the database using format string `Y-m-d H:i:s.v`, and converted back to `Datetime` when retrieved.
    - In case of `json`, the object will be serialized into a string and converted back to a generic object when retrieved.
- `SAVEFIELDS` is the list of fields which are saved as a default when calling function `save`.

Now that the object has been defined, it is possible to issue commands like:

```php
$object = DBTokenObject::create([ "token" => "ABC", "type" => "generic" ]);
$object->set_fields(["d1" => "data value"]);
$object->save("d1");

...

$objects = DBTokenObject::search(["type" => "generic"]);
```

### Other helpers

- `RENAME_FIELDS` is an associative array `'object_field' => 'db_column'` that can be used to have different column names in the DB than the names for the fields in the object. Each query made using `DBObject` class uses this helper.
- `GROUP_BY` is an array that automatically adds a GROUP BY clause to each search query. The array consists of a list of object fields.

### Functions

`static` functions in the class, dedicated to search objects:

- `function search($condition = array(), $orderby = null, $conditioncompose = 'AND', $rawsql = null)`
    
    Function that creates a SELECT query and returns a list of objects of the corresponding class.

- `function search_one(...$args)`

    Helper function to get one object (if exists). Returns the object or false if none or more than one are found.

- `function search_first(...$args)`

    Helper function to get the first object found (it appends `LIMIT 1` to the `$rawsql`) parameter.

- `function search_indexed($field, ...$args)`

    Helper function to postprocess the results from search and return a dictionary where the keys are the values of the objects and their values are the objects (the first object with each key is included)

- `function search_aggregated($field, ...$args)`

    Similar to `search_indexed`, but all the objects for each key are included (a list of objects for each value)

- `function search_field($field, ...$args)`

    Returns a list of the values of the fields of each result, instead of the whole object.

object methods

- `get_field($field)` and `get_fields($fieldlist)`
- `set_field($field, $value)` and `set_fields($values)`
    
