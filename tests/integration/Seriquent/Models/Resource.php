<?php

namespace Prewk\Seriquent\Models;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    public static function getBlueprint()
    {
        return [
            "name",
            "root",
            "references",
        ];
    }

    public function references()
    {
        return $this->hasMany(ResourceReference::class);
    }

    public function root()
    {
        return $this->belongsTo(Root::class);
    }
}