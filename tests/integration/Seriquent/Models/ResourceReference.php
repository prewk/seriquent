<?php

namespace Prewk\Seriquent\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceReference extends Model
{
    public static function getBlueprint()
    {
        return [
            "root",
            "referable",
            "resource",
        ];
    }

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    public function referable()
    {
        return $this->morphTo("referable");
    }

    public function root()
    {
        return $this->belongsTo(Root::class);
    }
}