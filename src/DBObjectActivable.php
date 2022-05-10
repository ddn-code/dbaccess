<?php
namespace ddn\api\db;

if (defined('__DB_API_DBOBJECT_ACTIVABLE__')) return;
define('__DB_API_DBOBJECT_ACTIVABLE__', true);

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
    
    public function delete() {
        if ($this->get_field('active') === true) {
            return $this->set_field('active', false, true);
        }
        return true;
    }

    public function activate() {
        if ($this->get_field('active') === false) {
            return $this->set_field('active', false, true);
        }
        return true;
    }

    public function is_active() {
        return $this->get_field('active') === true;
    }

    public static function searchactive($condition = array(), ...$args) {
        return static::search($condition + ['active' => 1 ], ...$args);
    }
}

