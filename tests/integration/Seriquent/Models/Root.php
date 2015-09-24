<?php

namespace Prewk\Seriquent\Models;

use Illuminate\Database\Eloquent\Model;

class Root extends Model
{
    public static function getBlueprint()
    {
        return [
            "test",
            "polys",
            "foos",
            "bar",
            "polys",
            "customs",
            "special_bar",
        ];
    }

    public function customs()
    {
        return $this->hasMany("Prewk\\Seriquent\\Models\\Custom");
    }

    public function foos()
    {
        return $this->hasMany("Prewk\\Seriquent\\Models\\Foo");
    }

    public function special_bar()
    {
        return $this->belongsTo("Prewk\\Seriquent\\Models\\Bar", "special_bar_id", "id");
    }

    public function bar()
    {
        return $this->hasOne("Prewk\\Seriquent\\Models\\Bar");
    }

    public function polys()
    {
        return $this->morphMany("Prewk\\Seriquent\\Models\\Poly", "polyable");
    }
}