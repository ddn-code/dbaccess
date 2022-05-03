<?php

namespace ddn\api\db;

class DBObjectSimple {
    public function __construct($values = null) {
        if ($values !== null)
            $this->load_values($values);
    }

    public function get_field($f_name) {
        $vars = get_object_vars($this);
        if (isset($vars[$f_name])) return $vars[$f_name];
        throw new \Exception('Invalid field name: ' . $field);
        // return null;
    }

    public function set_field($f_name, $f_value) {
        $this->{$f_name} = $f_value;
    }

    public function has_field($field) {
        $vars = get_object_vars($this);
        return (isset($vars[$field]));
    }

    public function load_values($values) {
        if (! is_array($values)) return;
        foreach ($values as $k => $v) {
            if (! is_int($k))
                $this->set_field($k, $v);
        }
    }
}
