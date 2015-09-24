<?php

namespace Prewk\Seriquent\Models;

use Illuminate\Database\Eloquent\Model;

class Custom extends Model
{
    protected $casts = ["data" => "json"];

    public static function getBlueprint()
    {
        return [
            "id",
            "data",
            "root",
        ];
    }

    public function root()
    {
        return $this->belongsTo("Prewk\\Seriquent\\Models\\Root");
    }
}