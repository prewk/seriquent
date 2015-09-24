<?php

namespace Prewk\Seriquent\Models;

use Illuminate\Database\Eloquent\Model;

class Poly extends Model
{
    public static function getBlueprint()
    {
        return [
            "polyable",
            "test",
        ];
    }

    public function polyable()
    {
        return $this->morphTo();
    }
}