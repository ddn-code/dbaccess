<?php
namespace ddn\api\db;

if (defined('__DB_API_DBOBJECT__')) return;
define('__DB_API_DBOBJECT__', true);

include_once('db.php');

if (!function_exists('get_db')) {
    throw new Exception("Could not find get_db() function, that has to return a DB object");
}

trait DBObjectActivable {
    /*
    This trait enables to use an "active" flag for a dbobject; that means that when the object is deleted, it will be deactivated instead

    The trait needs to include the "active" field a property:

    class Object extends DBObject {
        const FIELDS = [ ..., 'active' => 'bool' ];
        use DBObjectActivable;
        ...
    }
    */
    
    public function delete($fields = null) {
        $this->active = false;
        if ($this->save('active') !== null)
            return true;
        $this->active = true;
            return false;
    }

    public function activate($autosave = true) {
        $this->active = true;
        if (! $autosave)
            return true;
        if ($this->save('active') !== null)
            return true;
        $this->active = false;
            return false;
    }

    public function is_active() {
        return $this->active == 1;
    }

    public static function searchactive($condition = array(), ...$args) {
        return static::search($condition + ['active' => 1 ], ...$args);
    }
    public static function searchoneactive($condition = array(), ...$args) {
        return static::searchone($condition + ['active' => 1 ], ...$args);
    }
}

