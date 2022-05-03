<?php
namespace ddn\api\db;
use ddn\api\Helpers;

if (defined('__DB_API_DBOBJECT__')) return;
define('__DB_API_DBOBJECT__', true);

include_once('db.php');

if (!function_exists('get_db')) {
    throw new Exception("Could not find get_db() function, that has to return a DB object");
}

// The timezone string must match the DB timezone, to avoid timezone issues (e.g. when using default timestamps "NOW" in the DB, and mixing with php date functions)
if (!defined('__TIMEZONE_STRING')) {
    p_warning("__TIMEZONE_STRING not defined, using UTC");
    define('__TIMEZONE_STRING', 'UTC');
}

try {
    new \DateTimeZone(__TIMEZONE_STRING);
} catch (\Exception $e) {
    throw new \Exception('Invalid timezone string: ' . __TIMEZONE_STRING);
}

/**
 * This class is used to retrieve objects from the Database.
 */
class DBObjectReadOnly {
    /** 
     * Function that returns the sentence to create the database that backs this kind of objects 
     *   (*) this is useful to create a "autoinstall" script that gets any object of this class and creates the database
     * @return the string that creates the database
     */
    public static function sql_creation() {
        return "";
    }

    /**
     * @param db_tablename The name of the table that stores the object data
     * @param db_tablename_meta The name of the table that stores the metadata for the object
     * @param db_id The name of the field that contains the ID (to search using ID)
     */

    // This is the ID of the object, and should not be set by the user
    private $_id = null;

    // The name of the table that stores the object data
    const DB_TABLENAME = null;

    // The name of the field that will act as the "id"
    const DB_ID = "id";

    // The list of fields that will be stored in the database as they have to be named in the object.
    //  This is an associative array of "field name" => "field type", where the type is one of the following:
    //  "int", "float", "string", "datetime", "json". If ommited, the type will be considered as "string".
    //  The type will be used to convert the value to the right type before storing it in the database and 
    //  to convert the value from the database to the right type when loading it from the database.
    //  (*) The "datetime" type is a special type that will be converted to a string and back to a DateTime object.
    //  (*) The "json" type is a special type that will be converted to a string and back to a JSON object.
    const FIELDS = [];
    static private $__fields = [];
    static private $__rename_fields = [];

    /**
     * Values of the fields that will be stored in the database.
     */
    protected $__field_values = [];

    /** 
     * This function must be used to retrieve the list of fields that will be retrieved or stored in the database
     *   because it returns the parsed version of FIELDS that contains default values for any field. It also checks
     *   that the fields are valid and consistent.
     */
    static protected function _get_fields_list() {

        $class = get_called_class();
        if (! isset(static::$__fields[$class] )) {
            static::$__fields[ $class ] = null;
            static::$__rename_fields[ $class ] = null;
        }

        if (static::$__fields[ $class ] === null) {
            static::$__fields[ $class ] = [];
            foreach (static::FIELDS as $k => $v) {
                if (is_int($k)) {
                    $k = $v;
                    $v = "string";
                }
                if ( ! in_array($v, ["int", "float", "string", "datetime", "json"])) {
                    throw new \Exception("Invalid field type for field $k: $v");
                }
                static::$__fields[ $class ][$k] = $v;
            }
            foreach (static::RENAME_FIELDS as $k => $v) {
                if ( ! isset(static::$__fields[ $class ][$k])) {
                    throw new \Exception("Unknown field $k, with name in the DB $v");
                }
            }
            // RENAME_FIELDS is in the form "field_name" => "field_name_in_db", but in the functions it is needed as "field_name_in_db" => "field_name"
            //   because the renaming action is done as "select k as v, ..." being "k" => "v"
            static::$__rename_fields[ $class ] = array_flip(static::RENAME_FIELDS);

            foreach (static::GROUP_BY as $k => $v) {

                p_warning("GROUP_BY is not fully tested yet");

                if ( ! isset(static::$__fields[ $class ][$k])) {
                    throw new \Exception("Unknown field $k, with name in the DB $v");
                }
            }
        }
        return static::$__fields[ $class ];
    }

    static protected function _get_rename_fields() {
        // Make sure that the rename fields are prepared
        static::_get_fields_list();
        return static::$__rename_fields[ get_called_class() ];
    }

    // The name of the fields in the database (i.e. an associative array "object field" => "database field")
    //  (*) if not translated, the name of the field will be the same as the name of the object field
    const RENAME_FIELDS = [];

    // A list of fields that will be used to group when querying the database
    const GROUP_BY = [];

    /**
     * The constructor for the class. It is protected to not be able to create the object directly.
     * @param type
     * @param id
     */
    protected function __construct($id = null) {
        // Set the ID of the object
        $this->_id = $id;
    }

    /**
     * Retrieves the ID of the object
     * @return id
     */
    public function get_id() {
        return $this->_id;
    }

    /**
     * Function to get whether the object has a field or not
     * @param field The name of the field
     * @return true if the field exists, false otherwise
     */
    public static function has_fields(...$fields) {
        $field_list = static::_get_fields_list();
        foreach ($fields as $field) {
            $has = isset($field_list[$field]) || ($field === static::DB_ID);
            if (! $has) return false;
        }
        return true;
    }

    /**
     * Function to get the value of a field
     * @param the field name to get the value of
     * @return the value of the field
     */
    public function get_field($field) {
        if ( ! $this->has_fields($field))
            throw new \Exception("Field $field not found in the object");
        if ($field === static::DB_ID)
            return $this->get_id();
        return $this->__field_values[$field];
    }

    /**
     * Function to ease the reading of the fields as properties
     * @param field The name of the field
     * @return the value of the field
     */
    // public function __get($field) {
    //     if ( $this->has_fields($field))
    //         return $this->get_field($field);
    //     return $this->${field};
    // }

    /**
     * Function to get the values of a list of fields
     * @param the list of field names to get the value of
     * @return an associative array of names of the fields and its values
     */
    public function get_fields($fields = null) {
        if ($fields === null)
            $fields = array_keys(static::_get_fields_list());

        $result = array();
        foreach ($fields as $f) {
            $result[$f] = $this->get_field($f);
        }
        return $result;
    }

    /**
     * Sets the id of the object. It can be called only from inside the class to avoid ID changes
     * @return type
     */
    protected function _set_id($id) {
        $this->_id = $id;
    }

    /**
     * Function that converts a value from the database to the right type, according to the definition of the field
     * @param value The value to convert
     * @param type The type of the field
     * @return The converted value
     */
    protected static function _value_from_db($value, $type) {
        try {
            // If it is a null, raw copy it; otherwise try to convert the type
            if ($value !== null) {
                switch ($type) {
                    case 'int': $value = (int)$value; break;
                    case 'float': $value = (float)$value; break;
                    case 'bool': $value = (bool)$value; break;
                    case 'datetime': 
                        $formats = [ 'Y-m-d H:i:s.u', 'Y-m-d H:i:s.v', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ];
                        $newvalue = null;
                        foreach ($formats as $format) {
                            $d = \DateTime::createFromFormat($format, $value, new \DateTimeZone(__TIMEZONE_STRING));
                            if ($d !== false) {
                                $newvalue = $d;
                                break;
                            }
                        }
                        $value = $newvalue;
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                    case 'string':
                        break;
                    default: 
                        throw new \Exception("Unknown type $type");
                        break;
                }
            }
        } catch (\Exception $e) {
            throw new \Exception("Error while converting value $value to type $type: " . $e->getMessage());
        }        
        return $value;
    }

    /**
     * Function that converts a value from the actual type to a value that can be stored in the database, according to the definition of the field
     * @param value The value to convert
     * @param type The type of the field
     * @return The converted value
     */
    protected static function _value_to_db($value, $type) {
        try {
            $dbvalue = null;
            // If it is a null, raw copy it; otherwise try to convert the type
            if ($value !== null) {
                switch ($type) {
                    case "bool": 
                        $dbvalue = $value==true?1:0; 
                        break;
                    case "int": 
                        $dbvalue = (int)$value; 
                        break;
                    case "float": 
                        $dbvalue = (float)$value; 
                        break;
                    case "datetime":
                        if ((gettype($value) == 'object') && (get_class($value) == "DateTime")) {
                            $dbvalue = (clone $value)->setTimeZone(new \DateTimeZone(__TIMEZONE_STRING))->format('Y-m-d H:i:s.u');
                            // $dbvalue = $value->format('Y-m-d H:i:s.u');
                        } else {
                            throw new \Exception("Invalid datetime value: $value");
                        }
                        break;
                    case "json":
                        $dbvalue = json_encode($value);
                        break;
                    case "string": 
                        $dbvalue = $value;
                        break;
                    default: 
                        throw new \Exception("Unknown type $type");
                        break;
                }
            }
        } catch (\Exception $e) {
            throw new \Exception("Error while converting value $value to type $type: " . $e->getMessage());
        }        
        return $dbvalue;
    }

    /**
     * Function that sets a value that comes from the database
     * @param field the name of the field to initialize
     * @param value the value to initialize
     * @param object the object that is being initialized
     * @return the value to set
     *
     * (*) this function is used to enable editing the values prior to setting them, when unserializing
     */
    protected static function _process_field_value_from_db($field, $value, $object) {
        return $value;
    }

    /**
     * Function that gets a value that will be stored in the database
     * @param field the name of the field to initialize
     * @param value the value to initialize
     * @param object the object that is being initialized (null if it is being a creation)
     * @return the value to store in the DB
     *
     * (*) this function is used to enable editing the values prior to setting them, when unserializing
     */
    protected static function _process_field_value_to_db($field, $value, $object) {
        return $value;
    }

    /**
     * Gets the object coming from the database and transform the data to attributes in the object
     * @return correct whether the data has been properly imported or not
     */
    protected function _initialize_from_db($data, $continue_if_not_exists = false) {

        $failed = false;
        $fields = static::_get_fields_list();

        foreach ($fields as $field => $type) {

            $value = $data->{$field}??null;
            $this->__field_values[$field] = $this->_process_field_value_from_db($field, static::_value_from_db($value, $type), $this);
        }

        // Set the ID at last just in case that any other field fails
        if (isset($data->{static::DB_ID})) 
            $this->_set_id($data->{static::DB_ID});

        return ! $failed;
    }

    /** 
     * Function that converts a list of objects retrieved from the database, to a list of objects of this class
     * @param $objs the list of objects retrieved from the database
     * @return the list of objects of this class, created using the data from the database
     */
    protected static function _result_to_objects($objs) {
        $class = get_called_class();

        $result = array();
        foreach ($objs as $dbobj) {

            // Instantiate the object from the current class
            $new_obj = new $class();

            // Load the data
            if ($new_obj->_initialize_from_db($dbobj)) {
                array_push($result, $new_obj);
            }
        }
        return $result;
    }

    /**
     * Searches for objects using the conditions given. The result is an array of objects of this class.
     *  (*) the values for the fields retrieved from the database are converted to the right type, according
     *      to the definition in FIELDS constant in the class
     * 
     * @param condition Array of fields => value that will compose the WHERE clause
     * @param orderby Array of fields that will compose the ORDER BY clause
     * @param conditioncompose The keyword to compose the condition (default AND)
     * @param rawsql SQL query to add to the search clause built by the function (e.g. "LIMIT 1, 10")
     * @return the list of objects found in the database
     */
    public static function search($condition = array(), $orderby = null, $conditioncompose = 'AND', $rawsql = null) {
        $objs = get_db()->p_search(static::DB_TABLENAME, $condition, static::_get_rename_fields(), $conditioncompose, $orderby, static::GROUP_BY, $rawsql);
        if ($objs === false)
            return [];

        return static::_result_to_objects($objs);
    }

    /**
     * This function searches for only one result using ::search function. If none or more than one found, will return false
     * @param condition the same than in ::search
     * @return the object found, or false if none or more than one found
     */
    public static function search_one(...$args) {
        $result = static::search(...$args);
        if (count($result) != 1) 
            return false;
        return $result[0];
    }

    /**
     * This function searches for only the first result using ::search function. If no one is found, will return false
     * @param condition the same than in ::search
     * @return the object found, or false if none or more than one found
     */
    public static function search_first(...$args) {
        $args[0] = $args[0]??[];
        $args[1] = $args[1]??null;
        $args[2] = $args[2]??'AND';
        $args[3] = $args[3]??"";
        $args[3] = 'LIMIT 1 ' . $args[3];

        $result = static::search(...$args);
        if (count($result) != 1) 
            return false;

        return $result[0];
    }


    /**
     * This function is a helper for search: instead of returning the list of objects, it returns a dictionary
     *   where the keys are the values of the field specified in the parameter, and the values are the objects.
     * 
     *   e.g.: being (id, name) the results, and the values (1, "John") and (2, "Mary"), the result of 
     *          search_indexed("id", ...) will be:
     *              [ "1" => object(id=1, name="John"), "2" => object(id=2, name="Mary") ]
     * 
     * @param field The field to use as index
     * @param ... The same as in ::search
     */
    public static function search_indexed($field, ...$args) {
        if (! $this->has_fields($field))
            throw new \Exception("Field $field not found in the object");

        $result = static::search(...$args);
        $r_a = [];
        foreach ($result as $r) {
            if ($field === static::DB_ID)
                $r_a[$r->get_id()] = $r;
            else
                $r_a[$r->get_field($field)] = $r;
        }
        return $r_a;
    }

    /**
     * This function is a helper for search: instead of returning the list of objects, it returns a dictionary
     *   where the keys are the values of the field specified in the parameter, and the values are arrays of objects
     *   that have the same value of the field.
     * 
     *   e.g.: being (group, name) the results, and the values ("admin", "Mat"), ("user", "John") and ("user", "Mary"), 
     *          the result of search_aggregated("group", ...) will be 
     *          [
     *                  "admin" => [ object(group="admin", name="Mat") ], 
     *                  "user" => [ object(group="user", name="John"), object(group="user", name="Mary") ] 
     *          ]
     * @param field The field to use as the aggregation index
     * @param ... The same as in ::search
     */
    public static function search_aggregated($field, ...$args) {
        if (! $this->has_fields($field))
            throw new \Exception("Field $field not found in the object");

        $result = static::search(...$args);
        $r_a = [];
        foreach ($result as $r) {
            if ($field === static::DB_ID)
                $i = $r->get_id();
            else
                $i = $r->get_field($field);
            if (!isset($r_a[$i])) {
                $r_a[$i] = [];
            }
            array_push($r_a[$i], $r);
        }
        return $r_a;
    }

    /**
     * This function is a enhancement for search: instead of returning the list of objects, it returns a list of the
     *   values of the field specified in the parameter.
     * 
     *   e.g.: being (group, name) the results, and the values ("admin", "Mat"), ("user", "John") and ("user", "Mary"), 
     *          the result of search_field("name", ...) will be [ "Mat", "John", "Mary" ]

     * @param field The field to retrieve
     * @param ... The same as in ::search
     */
    public static function search_field($field, ...$args) {
        $result = static::search(...$args);
        return array_map(function($o) use ($field) {
            if ($field === 'id')
                return $o->get_id();
            return $o->get_field($field);
        }, $result);
    }

    // /**
    //  * This function searches for only one result using ::search function. If none or more than one found, will return false
    //  */
    // public static function searchone($condition = array(), ...$args) {
    //     $result = static::search($condition, ...$args);
    //     if (count($result) != 1) return false;
    //     return $result[0];
    // }

    // /**
    //  * Sets the value of a field in the object; it makes sure that the field is declared in the DB
    //  */
    // public function set_field($field, $value) {
    //     if (in_array($field, static::FIELDS)|| (isset(static::FIELDS[$field]))) {
    //         $this->{$field} = $value;
    //         return true;
    //     }
    //     return false;
    // }    

    // /**
    //  * Sets the values of a set of fields in the object (it uses set_field)
    //  */
    // public function set_fields($values = []) {

    //     foreach ($values as $key=>$value) {
    //         if (!$this->has_fields($key)) return false;
    //     }
    
    //     foreach ($values as $key => $value) {
    //         $this->set_field($key, $value);
    //     }

    //     return true;
    // }

    // /**
    //  * This function set the values for a set of fields and saves them in the database (if needed); it makes use of set_field function
    //  * @param values array of [ "key" => "value", ... ]
    //  * @param autosave true if the keys set are to be saved in the database
    //  * @return true if the values are set (and saved)
    //  */
    // public function set_values($values = [], $autosave = true) {
    //     $keys = [];

    //     foreach ($values as $key => $value) {
    //         $this->set_field($key, $value);
    //         array_push($keys, $key);
    //     }
    //     if ($autosave)
    //         return $this->save($keys);
        
    //     return true;
    // }

    public function to_simple_object() {
        $o = new DBObjectSimple();

        foreach (static::FIELDS as $field => $type) {
            if (is_integer($field))
                $field = $type;
            $o->set_field($field, $this->__field_values[$field]);
        }

        return $o;
    }    
}

class DBObject extends DBObjectReadOnly {
    /**
     * These are the fields being saved; in this case FIELDS will be those fields retrieved from the DB, while SAVEFIELDS will be those
     *   fields saved to the DB when using "save" functions. If SAVEFIELDS is "null", the fields saved will be those in 
     *   FIELDS.
     */
    const SAVEFIELDS = null;

    protected static $__savefields = null;

    static protected function _get_fields_list() {
        $fields = parent::_get_fields_list();

        $class = get_called_class();
        if (! isset(static::$__savefields[$class] )) {
            static::$__savefields[ $class ] = null;
        }

        if (static::$__savefields[ $class ] === null) {
            $fields_to_save = static::SAVEFIELDS;
            if ($fields_to_save === null)
                $fields_to_save = array_keys($fields);

            foreach ($fields_to_save as $field) {
                if (!isset($fields[$field]))
                    throw new \Exception("Field $field not found in the object");
            }
            static::$__savefields[ $class ] = $fields_to_save;
        }
        return $fields;
    }

    static protected function _get_save_fields_list() {
        // Make sure that the fields to save are set
        static::_get_fields_list();

        $class = get_called_class();
        return static::$__savefields[ $class ];
    }

    /**
     * This is a function to create the ID of the object; it is called when the object is created, and it is used to create the ID. If
     *   returns null, the ID will be considered to be generated in the DB (e.g. autoincrement). If returns a string, it will be used as
     *   the value of the ID field (in DB_ID constant).
     * 
     * @param values The values for the fields of the object (as they are provided during the creation)
     */
    protected static function _id_function($values = []) {
        return null;
    }

    /**
     * Function used to create an object and to save it in the database.
     * @param values The values for the fields of the object
     * @return The object created
     */
    public static function create($values) {
        if (! self::has_fields(...array_keys($values)))
            throw new \Exception("Fields not found in the object");
        
        array_walk($values, function(&$value, $key) {
            $value = static::_process_field_value_to_db($key, $value, null);
        });
    
        $prepared_values = self::_prepare_values_for_sql($values);
        $id = static::_id_function($values);
        $db = get_db();

        $o = new static($id);

        if ($id === null) {
            // Autogenerated ID
            $id = $db->p_insert(static::DB_TABLENAME, $prepared_values);
            if ($id === false) {
                Helpers::p_warning("Error inserting into table " . static::DB_TABLENAME . ": " . $db->get_error());
                return false;
            }

            $o->_set_id($id);
        } else {
            throw new \Exception("UNCHECKED CODE");
            // TODO: check this part of code
            $prepared_values[static::DB_ID] = $id;
            $result = $db->p_insert(static::DB_TABLENAME, $prepared_values);
            if ($result === false) 
                return false;
        }

        $o->set_fields($values);
        return $o;
    }

    public function save($values = null) {
        if ($values === null) {
            $values = static::_get_save_fields_list();
        } else {
            if (! is_array($values))
                $values = [$values];

            if (! self::has_fields(...$values))
                throw new \Exception("Fields not found in the object");
        }

        $values = $this->get_fields($values);
        array_walk($values, function(&$value, $key) {
            $value = static::_process_field_value_to_db($key, $value, $this);
        });
        $prepared_values = self::_prepare_values_for_sql($values);

        $id = $this->get_id();
        if ($id === null)
            throw new \Exception("Cannot update an object without ID");

        $db = get_db();
        return $db->p_update(static::DB_TABLENAME, $prepared_values, [static::DB_ID => $id]);
    }

    public function delete() {
        $id = $this->get_id();

        if ($id === null)
            throw new \Exception("Cannot delete an object without ID");

        $db = get_db();
        return $db->p_delete(static::DB_TABLENAME, [static::DB_ID => $id]);
    }

    /**
     * Function created to intercep the call to set the attributes of the object. It tries to avoid
     *   setting the fields directly using the $object->field = $value method. Will force to use the
     *   set_field function, instead.
     */
    public function __set($field, $value) {
        if ($this->has_fields($field))
            throw new \Exception("Attribute $field is not directly writable because it is a field of the object; please use set_field instead");
        /*
        if ($this->has_fields($field))
            $this->set_field($field, $value);
        */
        else
            $this->$field = $value;
    }

    /**
     * Function used to set the value of a field (that is to be the database) in the object.
     * @param field The field to set
     * @param value The value to set
     * @param autosave If true, the values will be saved in the database once they have been set
     * @return True if the values were set (and saved), false otherwise
     */
    public function set_field($key, $value, $autosave = false) {
        if (! $this->has_fields($key))
            throw new \Exception("Field not found in the object");
        if ($key === static::DB_ID)
            throw new \Exception("Cannot set the ID of an object");
        $this->__field_values[$key] = $value;

        if ($autosave)
            return $this->save($key);

        return true;
    }

    /** 
     * Function to set the value of a group of fields (that are to be the database) in the object.
     * @param values The values to set in the form of [ field => value, ... ]
     * @param autosave If true, the values will be saved in the database once they have been set
     * @return True if the values were set (and saved), false otherwise
     */
    public function set_fields($values, $autosave = false) {
        foreach ($values as $key => $value) {
            $this->set_field($key, $value);
        }
        if ($autosave)
            return $this->save(array_keys($values));

        return true;
    }
    
    /**
     * Function that receives the values that are to be used to create or to update the object, and prepare these values to 
     *   be used in the database (i.e. changing the format to store the values in the database according to the valid types
     *   accepted by function _value_to_db: "int", "string", "bool", "date", "datetime", "json").
     * 
     * (*) the values are not modified; instead a new dict is created
     * 
     * @param values The values for the fields of the object
     * @return The values prepared for the database
     */
    protected static function _prepare_values_for_sql($values) {
        if (! self::has_fields(...array_keys($values)))
            throw new \Exception("Some of the fields are not declared in the object");

        // Now we'll get the list of fields declared in the DB
        $field_list = static::_get_fields_list();

        $prepared_values = [];

        foreach ($values as $key => $value) {
            $type = $field_list[$key];
            $prepared_values[$key] = static::_value_to_db($value, $type);
        }

        return $prepared_values;
    }
}