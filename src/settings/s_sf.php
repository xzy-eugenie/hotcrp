<?php
// settings/s_sf.php -- HotCRP submission field setting object
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Sf_Setting {
    public $id;
    public $name;
    public $order;
    public $type;
    public $description;
    public $display;
    public $visibility;
    public $required;
    public $presence;
    public $exists_if;
    public $values;
    public $ids;
    public $min;
    public $max;

    public $final;
    /** @var list<SfValue_Setting> */
    public $xvalues;
    /** @var bool */
    public $deleted = false;
}

class SfValue_Setting {
    public $id;
    public $name;
    public $order;

    // internal
    public $old_value;
    /** @var bool */
    public $deleted = false;
}
