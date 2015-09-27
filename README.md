# Seriquent https://travis-ci.org/prewk/seriquent.svg

1. __Serialize__ a tree of Eloquent models into a self-referential array with anonymized primary keys
2. __Deserialize__ it back into the database with new primary keys

# Installation

`composer require prewk/seriquent`

# Example

Your database has a `foos` table, belonging to a `qux` table.

`foos` has a one-to-many relation to the `bars` table.

Both `bars` and `qux` have a polymorphic one-to-many relation to the `bazes` table (They are "`bazable`").

## Database contents

````
                ╔═══════╗    ╔═══════╗
             +--║ Foo 5 ║----║ Qux 2 ║
             |  ╚═══════╝    ╚═══════╝
             |                   |
             |                   |
    /--------+--------\          |
╔═══════╗╔═══════╗╔═══════╗      |
║ Bar 2 ║║ Bar 3 ║║ Bar 4 ║      |
╚═══════╝╚═══════╝╚═══════╝      |
    |        |                   |
    |        |                   |
╔═══════╗╔═══════╗           ╔═══════╗
║ Baz 5 ║║ Baz 6 ║           ║ Baz 7 ║
╚═══════╝╚═══════╝           ╚═══════╝

╔═════════════════════╗
║ foos                ║
╟─────────────────────╢
║ id: 5               ║
║ qux_id: 2           ║
║ name: "Lorem ipsum" ║
╚═════════════════════╝
╔═══════════╗╔═══════════╗╔═══════════╗
║ bars      ║║ bars      ║║ bars      ║
╟───────────╢╟───────────╢╟───────────╢
║ id: 2     ║║ id: 3     ║║ id: 4     ║
║ foo_id: 5 ║║ foo_id: 5 ║║ foo_id: 5 ║
╚═══════════╝╚═══════════╝╚═══════════╝
╔═══════════════════════╗
║ quxes                 ║
╟───────────────────────╢
║ id: 2                 ║
║ data: ["a", "b", "c"] ║
╚═══════════════════════╝
╔═════════════════╗╔═════════════════╗╔═════════════════╗
║ bazes           ║║ bazes           ║║ bazes           ║
╟─────────────────╢╟─────────────────╢╟─────────────────╢
║ id: 5           ║║ id: 6           ║║ id: 7           ║
║ bazable_type: 5 ║║ bazable_type: 5 ║║ bazable_type: 5 ║
║ bazable_id: 5   ║║ bazable_id: 5   ║║ bazable_id: 5   ║
╚═════════════════╝╚═════════════════╝╚═════════════════╝


````

## Desired results

````json
{
    "Foo": [
        { "@id": "@1", "qux": "@7", "name": "Lorem ipsum" }
    ],
    "Bar": [
        { "@id": "@2", "foo": "@1", "baz": "@3" },
        { "@id": "@4", "foo": "@1", "baz": "@5" },
        { "@id": "@6", "foo": "@1", "baz": null }
    ],
    "Baz": [
        { "@id": "@3", "bazable": ["Bar", "@2"] },
        { "@id": "@5", "bazable": ["Bar", "@4"] },
        { "@id": "@8", "bazable": ["Qux", "@7"] }
    ],
    "Qux": [
        { "@id": "@7", "foo": "@1", "bazable": "@8", "data": ["a", "b", "c"] }
    ]
}
````

* `Foo`, `Bar` etc are the Eloquent models' FQCNs
* All entities get a unique internal id `"@id": "@123"`
* An entity refers to another entity by its internal id and the relation name `"foo": "@1"`
* Regular columns are just values `"name": "Lorem ipsum"`

## Eloquent models and serialization blueprints

````php
<?php
use Illuminate\Database\Eloquent\Model;

class Foo extends Model {
    public static function getBlueprint() {
         return ["name", "bars", "qux"];
    }
    public function bars() { return $this->hasMany("Bar"); }
    public function qux() { return $this->belongsTo("Qux"); }
}

class Bar extends Model {
    public static function getBlueprint() {
         return ["foo", "bazes"];
    }
    public function foo() { return $this->belongsTo("Foo"); }
    public function bazes() { return $this->morphMany("Baz", "bazable"); }
}

class Baz extends Model {
    public static function getBlueprint() {
         return ["bazable"];
    }
    public function bazable() { $this->morphTo(); }
}

class Qux extends Model {
    protected $casts = ["data" => "json"];
    public static function getBlueprint() {
         return ["foo", "bazes"];
    }
    public function foo() { return $this->hasOne("Foo"); }
    public function bazes() { return $this->morphMany("Baz", "bazable"); }
}

````

Describe a model's _fields_ and _relations_ to serialize/deserialize with a static `getBlueprint` method returning an array of strings.

The strings are either a column name or a relation name. Primary key is automatically fetched.

## Usage

````php
<?php
use Prewk\Seriquent;

// The Laravel container app() is needed for model resolution
$seriquent = new Seriquent(app());

// Deserialize from Foo with id 5
$serialization = $seriquent->deserialize(Foo::findOrFail(5));

// Save to disk
file_put_contents("serialization.json", json_encode($serialization));

// Load from disk
$serialization = file_get_contents("serialization.json");

// Deserialize into database
$results = $seriquent->deserialize($serialization);

// $results is an <internal @id> => <database id> associative array
````

# License

MIT
