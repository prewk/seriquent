<?php

namespace Prewk\Seriquent\Models;

use Illuminate\Database\Eloquent\Model;

class Foo extends Model
{
    protected $casts = ["data" => "json"];

    public static function getBlueprint()
    {
        return [
            "test",
            "polys",
            ["data", [
                "bar_id" => "Prewk\\Seriquent\\Models\\Bar",
                "/\\.bar\\.id/" => "Prewk\\Seriquent\\Models\\Root",
                "/custom_ids\\.[\\d]+/" => "Prewk\\Seriquent\\Models\\Custom",
            ]],
            "root",
        ];
    }

    public function root()
    {
        return $this->belongsTo("Prewk\\Seriquent\\Models\\Root");
    }

    public function polys()
    {
        return $this->morphMany("Prewk\\Seriquent\\Models\\Poly", "polyable");
    }
}