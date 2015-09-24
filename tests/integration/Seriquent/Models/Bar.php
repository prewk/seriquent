<?php

namespace Prewk\Seriquent\Models;

use Illuminate\Database\Eloquent\Model;

class Bar extends Model
{
    public static function getBlueprint()
    {
        return [
            "test",
            "root",
        ];
    }

    public function root()
    {
        return $this->belongsTo("Prewk\\Seriquent\\Models\\Root");
    }
}