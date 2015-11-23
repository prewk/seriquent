<?php

namespace Prewk;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit_Framework_TestCase;
use Prewk\Seriquent\Models\Bar;
use Prewk\Seriquent\Models\Custom;
use Prewk\Seriquent\Models\Foo;
use Prewk\Seriquent\Models\Resource;
use Prewk\Seriquent\Models\ResourceReference;
use Prewk\Seriquent\Models\Root;

require_once(__DIR__ . "/Seriquent/Models/Foo.php");
require_once(__DIR__ . "/Seriquent/Models/Bar.php");
require_once(__DIR__ . "/Seriquent/Models/Root.php");
require_once(__DIR__ . "/Seriquent/Models/Poly.php");
require_once(__DIR__ . "/Seriquent/Models/Custom.php");
require_once(__DIR__ . "/Seriquent/Models/Resource.php");
require_once(__DIR__ . "/Seriquent/Models/ResourceReference.php");

class SeriquentIntegrationTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        date_default_timezone_set("UTC");

        $database = __DIR__ . "/../databases/database.sqlite";
        file_put_contents($database, "");
        $this->capsule = new Capsule;
        $this->capsule->addConnection([
            "driver" => "sqlite",
            "database" => $database,
            "prefix" => "",
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        Capsule::schema()->create("roots", function($table) {
            $table->increments("id");
            $table->string("test")->nullable();
            $table->unsignedInteger("bar_id")->nullable();
            $table->unsignedInteger("special_bar_id")->nullable();
            $table->timestamps();
        });
        Capsule::schema()->create("bars", function($table) {
            $table->increments("id");
            $table->unsignedInteger("root_id")->nullable();
            $table->string("test");
            $table->timestamps();
        });
        Capsule::schema()->create("foos", function($table) {
            $table->increments("id");
            $table->unsignedInteger("root_id")->nullable();
            $table->string("test");
            $table->text("data");
            $table->timestamps();
        });
        Capsule::schema()->create("polies", function($table) {
            $table->increments("id");
            $table->unsignedInteger("root_id")->nullable();
            $table->morphs("polyable");
            $table->string("test");
            $table->timestamps();
        });
        Capsule::schema()->create("customs", function($table) {
            $table->increments("id");
            $table->unsignedInteger("root_id")->nullable();
            $table->text("data");
            $table->timestamps();
        });
        Capsule::schema()->create("resources", function($table) {
            $table->increments("id");
            $table->unsignedInteger("root_id")->nullable();
            $table->string("name");
            $table->timestamps();
        });
        Capsule::schema()->create("resource_references", function($table) {
            $table->increments("id");
            $table->unsignedInteger("root_id")->nullable();
            $table->unsignedInteger("resource_id");
            $table->unsignedInteger("referable_id");
            $table->string("referable_type");
            $table->timestamps();
        });
    }

    public function test_deserialize()
    {
        // Arrange
        $serialization = [
            "Prewk\\Seriquent\\Models\\Root" => [
                [
                    "@id" => "@1",
                    "test" => "Lorem ipsum",
                    "bar" => "@4",
                    "special_bar" => "@4",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Foo" => [
                [
                    "@id" => "@2",
                    "test" => "Foo bar",
                    "data" => ["a" => 1, "b" => 2, "bar_id" => "@4"],
                    "root" => "@1",
                ],
                [
                    "@id" => "@3",
                    "test" => "Baz qux",
                    "data" => ["c" => 3, "d" => 4, "foo" => ["bar" => ["id" => "@1"]]],
                    "root" => "@1",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Bar" => [
                [
                    "@id" => "@4",
                    "test" => "Test test",
                    "root" => "@1",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Poly" => [
                [
                    "@id" => "@5",
                    "test" => "One",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Root", "@1"],
                ],
                [
                    "@id" => "@6",
                    "test" => "Two",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Root", "@1"],
                ],
                [
                    "@id" => "@7",
                    "test" => "Three",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Foo", "@2"],
                ],
                [
                    "@id" => "@8",
                    "test" => "Four",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Foo", "@3"],
                ],
            ],
        ];
        $seriquent = Seriquent::make();

        // Act
        $books = $seriquent->deserialize($serialization);

        // Assert
        $root = Root::findOrFail($books["@1"]);

        $this->assertEquals("Lorem ipsum", $root->test);
        $rootPoly1 = $root->polys->get(0);
        $rootPoly2 = $root->polys->get(1);
        $this->assertEquals("One", $rootPoly1->test);
        $this->assertEquals("Two", $rootPoly2->test);

        $bar = $root->bar;
        $this->assertEquals("Test test", $bar->test);

        $specialBar = $root->special_bar;
        $this->assertEquals("Test test", $specialBar->test);

        $foo1 = Foo::where(["id" => $books["@2"]])->first();
        $this->assertEquals($root->id, $foo1->root->id);
        $this->assertEquals("Foo bar", $foo1->test);
        $this->assertEquals(["a" => 1, "b" => 2, "bar_id" => $books["@4"]], $foo1->data);
        $foo1Poly = $foo1->polys->first();
        $this->assertEquals("Three", $foo1Poly->test);

        $foo2 = Foo::where(["id" => $books["@3"]])->first();
        $this->assertEquals($root->id, $foo2->root->id);
        $this->assertEquals("Baz qux", $foo2->test);
        $this->assertEquals(["c" => 3, "d" => 4, "foo" => ["bar" => ["id" => $root->id]]], $foo2->data);
        $foo2Poly = $foo2->polys->first();
        $this->assertEquals("Four", $foo2Poly->test);
    }

    public function test_serialize()
    {
        // Arrange
        $serialization = [
            "Prewk\\Seriquent\\Models\\Root" => [
                [
                    "@id" => "@1",
                    "test" => "Lorem ipsum",
                    "bar" => "@4",
                    "special_bar" => "@4",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Foo" => [
                [
                    "@id" => "@2",
                    "test" => "Foo bar",
                    "data" => ["a" => 1, "b" => 2, "bar_id" => "@4"],
                    "root" => "@1",
                ],
                [
                    "@id" => "@3",
                    "test" => "Baz qux",
                    "data" => ["c" => 3, "d" => 4],
                    "root" => "@1",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Bar" => [
                [
                    "@id" => "@4",
                    "test" => "Test test",
                    "root" => "@1",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Poly" => [
                [
                    "@id" => "@5",
                    "test" => "One",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Root", "@1"],
                ],
                [
                    "@id" => "@6",
                    "test" => "Two",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Root", "@1"],
                ],
                [
                    "@id" => "@7",
                    "test" => "Three",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Foo", "@2"],
                ],
                [
                    "@id" => "@8",
                    "test" => "Four",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Foo", "@3"],
                ],
            ],
        ];
        $seriquent = Seriquent::make();

        // Act
        $books = $seriquent->deserialize($serialization);
        $root = Root::findOrFail($books["@1"]);

        // Re-serialize
        $reserialization = $seriquent->serialize($root);

        // Assert
        // Compare the serializations
        // Assert same amount of fqcns
        $this->assertEquals(count($serialization), count($reserialization));
        // Iterate and compare
        foreach ($reserialization as $fqcn => $entities) {
            // Assert that the same fqcns exist on both arrays
            $this->assertArrayHasKey($fqcn, $serialization);
            // Assert that the same amount of entities exist on both arrays for this fqcn
            $this->assertEquals(count($serialization[$fqcn]), count($entities));
        }
    }

    public function test_serialize_with_morph_to_many()
    {
        // Arrange
        $serialization = [
            "Prewk\\Seriquent\\Models\\Root" => [
                [
                    "@id" => "@1",
                    "test" => "Lorem ipsum",
                    "bar" => "@4",
                    "special_bar" => "@4",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Foo" => [
                [
                    "@id" => "@2",
                    "test" => "Foo bar",
                    "data" => ["a" => 1, "b" => 2, "bar_id" => "@4"],
                    "root" => "@1",
                ],
                [
                    "@id" => "@3",
                    "test" => "Baz qux",
                    "data" => ["c" => 3, "d" => 4],
                    "root" => "@1",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Bar" => [
                [
                    "@id" => "@4",
                    "test" => "Test test",
                    "root" => "@1",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Poly" => [
                [
                    "@id" => "@5",
                    "test" => "One",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Root", "@1"],
                ],
                [
                    "@id" => "@6",
                    "test" => "Two",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Root", "@1"],
                ],
                [
                    "@id" => "@7",
                    "test" => "Three",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Foo", "@2"],
                ],
                [
                    "@id" => "@8",
                    "test" => "Four",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Foo", "@3"],
                ],
            ],
            "Prewk\\Seriquent\\Models\\Resource" => [
                [
                    "@id" => "@9",
                    "name" => "Resource 1",
                    "root" => "@1",
                ],
                [
                    "@id" => "@10",
                    "name" => "Resource 2",
                    "root" => "@1",
                ],
            ],
            "Prewk\\Seriquent\\Models\\ResourceReference" => [
                [
                    "@id" => "@11",
                    "root" => "@1",
                    "resource" => "@9",
                    "referable" => [
                        "Prewk\\Seriquent\\Models\\Foo",
                        "@3"
                    ],
                ],
                [
                    "@id" => "@12",
                    "root" => "@1",
                    "resource" => "@10",
                    "referable" => [
                        "Prewk\\Seriquent\\Models\\Foo",
                        "@3"
                    ],
                ],
            ],
        ];
        $seriquent = Seriquent::make();

        // Act
        $books = $seriquent->deserialize($serialization);
        $root = Root::findOrFail($books["@1"]);

        // Re-serialize
        $reserialization = $seriquent->serialize($root);

        // Assert
        // Compare the serializations
        // Assert same amount of fqcns
        $this->assertEquals(count($serialization), count($reserialization));
        // Iterate and compare
        foreach ($reserialization as $fqcn => $entities) {
            // Assert that the same fqcns exist on both arrays
            $this->assertArrayHasKey($fqcn, $serialization);
            // Assert that the same amount of entities exist on both arrays for this fqcn
            $this->assertEquals(count($serialization[$fqcn]), count($entities));
        }
    }

    public function test_non_deserializing_entities_rule()
    {
        // Arrange
        $serialization = [
            "Prewk\\Seriquent\\Models\\Root" => [
                [
                    "@id" => "@1",
                    "test" => "Lorem ipsum",
                    "bar" => "@4"
                ],
            ],
            "Prewk\\Seriquent\\Models\\Custom" => [
                [
                    "@id" => "@2",
                    "id" => null, // Set below
                    "data" => "foo",
                ],
                [
                    "@id" => "@3",
                    "id" => null, // Set below
                    "data" => "bar",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Foo" => [
                [
                    "@id" => "@4",
                    "test" => "Foo bar",
                    "data" => ["custom_ids" => ["@2", "@3"]],
                    "root" => "@1",
                ],
            ],
        ];

        // Pre-create the custom entities
        $custom1 = new Custom;
        $custom1->data = ["foo" => "bar"];
        $custom1->save();

        $custom2 = new Custom;
        $custom2->data = ["baz" => "qux"];
        $custom2->save();

        $serialization["Prewk\\Seriquent\\Models\\Custom"][0]["id"] = $custom1->id;
        $serialization["Prewk\\Seriquent\\Models\\Custom"][1]["id"] = $custom2->id;

        $seriquent = Seriquent::make();

        // Act
        $books = $seriquent->deserialize($serialization);

        // Assert
        $root = Root::findOrFail($books["@1"]);
        $foo = $root->foos->first();

        $this->assertEquals($custom1->id, $foo->data["custom_ids"][0]);
        $this->assertEquals($custom2->id, $foo->data["custom_ids"][1]);
    }

    public function test_field_conditionals()
    {
        // Arrange
        $serialization = [
            "Prewk\\Seriquent\\Models\\Root" => [
                [
                    "@id" => "@1",
                    "test" => "",
                    "bar" => "@4"
                ],
            ],
            "Prewk\\Seriquent\\Models\\Custom" => [
                [
                    "@id" => "@2",
                    "data" => [],
                    "id" => null,
                ],
            ],
            "Prewk\\Seriquent\\Models\\Foo" => [
                [
                    "@id" => "@3",
                    "test" => "root",
                    "data" => ["related" => "@1"],
                    "root" => "@1",
                ],
                [
                    "@id" => "@4",
                    "test" => "custom",
                    "data" => ["related" => "@2"],
                    "root" => "@1",
                ],
            ],
        ];

        $customRules = [
            "Prewk\\Seriquent\\Models\\Foo" => [
                "root",
                "test",
                ["data", "test", [
                    "root" => ["related" => "Prewk\\Seriquent\\Models\\Foo"],
                    "custom" => ["related" => "Prewk\\Seriquent\\Models\\Custom"],
                ]],
            ],
        ];

        $seriquent = Seriquent::make();

        // Act
        $books = $seriquent->deserialize($serialization, $customRules);

        // Assert
        $root = Root::findOrFail($books["@1"]);
        $custom = Custom::findOrFail($books["@2"]);

        $foo1 = Foo::findOrFail($books["@3"]);
        $this->assertEquals($root->id, $foo1->data["related"]);

        $foo2 = Foo::findOrFail($books["@4"]);
        $this->assertEquals($custom->id, $foo2->data["related"]);
    }

    public function test_non_deserializing_entities_serialization()
    {
        // Arrange
        $root = new Root;
        $root->test = "";
        $root->save();

        $foo = new Foo;
        $foo->root_id = $root->id;
        $foo->test = "";
        $foo->data = [];
        $foo->save();

        $custom1 = new Custom;
        $custom1->root_id = $root->id;
        $custom1->data = [];
        $custom1->save();

        $custom2 = new Custom;
        $custom2->root_id = $root->id;
        $custom2->data = [];
        $custom2->save();

        $foo->data = ["custom_ids" => [$custom1->id, $custom2->id]];
        $foo->save();

        $seriquent = Seriquent::make();

        // Act
        $serialization = $seriquent->serialize($root);

        // Assert
        $this->assertArrayHasKey("Prewk\\Seriquent\\Models\\Custom", $serialization);
        $this->assertEquals(2, count($serialization["Prewk\\Seriquent\\Models\\Custom"]));
        $this->assertArrayHasKey("id", $serialization["Prewk\\Seriquent\\Models\\Custom"][0]);
        $this->assertArrayHasKey("id", $serialization["Prewk\\Seriquent\\Models\\Custom"][1]);
    }

    public function test_array_overwriting_blueprints()
    {
        // Arrange
        $root = new Root;
        $root->test = "Null me";
        $root->save();

        $seriquent = Seriquent::make();

        // Act
        $serialization = $seriquent->serialize($root, [
            "Prewk\\Seriquent\\Models\\Root" => [],
        ]);

        // Assert
        $this->assertArrayNotHasKey("test", $serialization["Prewk\\Seriquent\\Models\\Root"][0]);

        // Act
        $books = $seriquent->deserialize($serialization);

        // Assert
        $rootId = reset($books);
        $root = Root::findOrFail($rootId);

        $this->assertNull($root->test);
    }

    public function test_callable_overwriting_blueprints()
    {
        // Arrange
        $root = new Root;
        $root->test = "Null me";
        $root->save();

        $called = false;

        $customRules = [
            "Prewk\\Seriquent\\Models\\Root" => function() use (&$called) {
                $called = true;

                return [];
            },
        ];

        $seriquent = Seriquent::make();

        // Act
        $serialization = $seriquent->serialize($root, $customRules);

        // Assert
        $this->assertTrue($called);

        // Arrange
        $called = false;

        // Act
        $seriquent->deserialize($serialization, $customRules);

        // Assert
        $this->assertTrue($called);
    }

    public function test_advanced_callable_rule()
    {
        // Arrange
        $root = new Root;
        $root->test = "Null me";
        $root->save();

        $foo = new Foo;
        $foo->root_id = $root->id;
        $foo->test = "";
        $foo->data = [];
        $foo->save();

        $custom1 = new Custom;
        $custom1->root_id = $root->id;
        $custom1->data = [];
        $custom1->save();

        $custom2 = new Custom;
        $custom2->root_id = $root->id;
        $custom2->data = [];
        $custom2->save();

        $foo->data = ["custom_ids" => [$custom1->id, $custom2->id]];
        $foo->save();

        $exported = [];
        $imported = [];

        $importer = function($something) use (&$imported) {
            $imported[] = $something;
        };

        $exporter = function($something) use (&$exported) {
            $exported[] = $something;
        };

        $customRules = [
            "Prewk\\Seriquent\\Models\\Custom" => function($op, $model, $bookKeeper, $serializedEntity) use($importer, $exporter) {
                switch ($op) {
                    case Seriquent::DESERIALIZING:
                        $importer($serializedEntity["import"]);

                        // Create the model manually
                        $model->data = ["imported" => true];
                        $model->save();

                        // Report the db id to the book keeper
                        $bookKeeper->bind($serializedEntity["@id"], $model->id);

                        // Don't let Seriquent deserialize
                        return false;
                    case Seriquent::SERIALIZING:
                        // Copy files from $model->url to local dir
                        $exporter($model->id);

                        return [
                            "import" => $model->id,
                        ];
                }
            },
        ];

        $seriquent = Seriquent::make();

        $serialization = $seriquent->serialize($root, $customRules);
        $this->assertEquals([$custom1->id, $custom2->id], $exported);

        $books = $seriquent->deserialize($serialization, $customRules);
        $this->assertEquals([$custom1->id, $custom2->id], $imported);

        $custom1 = Custom::findOrFail($books["@3"]);
        $custom2 = Custom::findOrFail($books["@4"]);

        $this->assertTrue($custom1->data["imported"]);
        $this->assertTrue($custom2->data["imported"]);
    }


    public function test_deserialize_with_generator()
    {
        // Arrange
        $serializationParts = [];
        $serializationParts[] = [
            "Prewk\\Seriquent\\Models\\Root" => [
                [
                    "@id" => "@1",
                    "test" => "Lorem ipsum",
                    "bar" => "@4",
                    "special_bar" => "@4",
                ],
            ],
        ];
        $serializationParts[] = [
            "Prewk\\Seriquent\\Models\\Foo" => [
                [
                    "@id" => "@2",
                    "test" => "Foo bar",
                    "data" => ["a" => 1, "b" => 2, "bar_id" => "@4"],
                    "root" => "@1",
                ],
            ],
        ];
        $serializationParts[] = [
            "Prewk\\Seriquent\\Models\\Foo" => [
                [
                    "@id" => "@3",
                    "test" => "Baz qux",
                    "data" => ["c" => 3, "d" => 4, "foo" => ["bar" => ["id" => "@1"]]],
                    "root" => "@1",
                ],
            ],
        ];
        $serializationParts[] = [
            "Prewk\\Seriquent\\Models\\Bar" => [
                [
                    "@id" => "@4",
                    "test" => "Test test",
                    "root" => "@1",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Poly" => [
                [
                    "@id" => "@5",
                    "test" => "One",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Root", "@1"],
                ],
                [
                    "@id" => "@6",
                    "test" => "Two",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Root", "@1"],
                ],
                [
                    "@id" => "@7",
                    "test" => "Three",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Foo", "@2"],
                ],
                [
                    "@id" => "@8",
                    "test" => "Four",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Foo", "@3"],
                ],
            ],
        ];
        $seriquent = Seriquent::make();

        // Act
        $books = $seriquent->deserialize(function() use ($serializationParts) {
            for ($i = 0; $i < count($serializationParts); $i++) {
                yield $serializationParts[$i];
            }
        });

        // Assert
        $root = Root::findOrFail($books["@1"]);

        $this->assertEquals("Lorem ipsum", $root->test);
        $rootPoly1 = $root->polys->get(0);
        $rootPoly2 = $root->polys->get(1);
        $this->assertEquals("One", $rootPoly1->test);
        $this->assertEquals("Two", $rootPoly2->test);

        $bar = $root->bar;
        $this->assertEquals("Test test", $bar->test);

        $specialBar = $root->special_bar;
        $this->assertEquals("Test test", $specialBar->test);

        $foo1 = Foo::where(["id" => $books["@2"]])->first();
        $this->assertEquals($root->id, $foo1->root->id);
        $this->assertEquals("Foo bar", $foo1->test);
        $this->assertEquals(["a" => 1, "b" => 2, "bar_id" => $books["@4"]], $foo1->data);
        $foo1Poly = $foo1->polys->first();
        $this->assertEquals("Three", $foo1Poly->test);

        $foo2 = Foo::where(["id" => $books["@3"]])->first();
        $this->assertEquals($root->id, $foo2->root->id);
        $this->assertEquals("Baz qux", $foo2->test);
        $this->assertEquals(["c" => 3, "d" => 4, "foo" => ["bar" => ["id" => $root->id]]], $foo2->data);
        $foo2Poly = $foo2->polys->first();
        $this->assertEquals("Four", $foo2Poly->test);
    }

    public function test_search_replace()
    {
        // Arrange
        $serialization = [
            "Prewk\\Seriquent\\Models\\Root" => [
                [
                    "@id" => "@1",
                    "test" => "Lorem ipsum",
                    "bar" => "@4",
                    "special_bar" => "@4",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Foo" => [
                [
                    "@id" => "@2",
                    "test" => "Foo bar",
                    "data" => ["content" => "<a href=\"#/links/@8\">linked-1</a>\n<p>Lorem ipsum</p></p><a href=\"#/links/@9\">linked-2</a><a href=\"#/links/@10\">linked-3</a>", "bar" => "@4"],
                    "root" => "@1",
                ],
                [
                    "@id" => "@8",
                    "test" => "linked-1",
                    "data" => [],
                    "root" => "@1",
                ],
                [
                    "@id" => "@9",
                    "test" => "linked-2",
                    "data" => [],
                    "root" => "@1",
                ],
                [
                    "@id" => "@10",
                    "test" => "linked-3",
                    "data" => [],
                    "root" => "@1",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Bar" => [
                [
                    "@id" => "@4",
                    "test" => "Bar 1",
                    "root" => "@1",
                ],
            ],
            "Prewk\\Seriquent\\Models\\Poly" => [
                [
                    "@id" => "@5",
                    "test" => "One",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Root", "@1"],
                ],
                [
                    "@id" => "@6",
                    "test" => "Two",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Root", "@1"],
                ],
                [
                    "@id" => "@7",
                    "test" => "Three",
                    "polyable" => ["Prewk\\Seriquent\\Models\\Foo", "@2"],
                ],
            ],
        ];
        $customRules = [
            "Prewk\\Seriquent\\Models\\Foo" => [
                "root",
                "test",
                ["data", [
                    "bar" => "Prewk\\Seriquent\\Models\\Foo",
                    "content" => [
                        '/href="#\/links\/(@?\d+)\"/' => "Prewk\\Seriquent\\Models\\Foo",
                    ],
                ]],
            ],
        ];

        $seriquent = Seriquent::make();

        // Act
        $books = $seriquent->deserialize($serialization, $customRules);
        $root = Root::findOrFail($books["@1"]);

        // Assert
        $foo = Foo::firstOrFail();

        $this->assertEquals("<a href=\"#/links/2\">linked-1</a>\n<p>Lorem ipsum</p></p><a href=\"#/links/3\">linked-2</a><a href=\"#/links/4\">linked-3</a>", $foo->data["content"]);

        // Re-serialize deserialization
        $serialization = $seriquent->serialize($root, $customRules);

        // Assert
        $content = $serialization['Prewk\Seriquent\Models\Foo'][0]["data"]["content"];

        // Get serialized Foos to compare against
        $compareAgainst = [];
        foreach ($serialization['Prewk\Seriquent\Models\Foo'] as $foo) {
            if ($foo["test"] === "linked-1") {
                $compareAgainst["linked-1"] = $foo["@id"];
            } else if ($foo["test"] === "linked-2") {
                $compareAgainst["linked-2"] = $foo["@id"];
            } else if ($foo["test"] === "linked-3") {
                $compareAgainst["linked-3"] = $foo["@id"];
            }
        }

        // Extract "links"
        preg_match_all('/href="#\/links\/(@?\d+)\"/', $content, $matches);

        $this->assertEquals($compareAgainst["linked-1"], $matches[1][0]);
        $this->assertEquals($compareAgainst["linked-2"], $matches[1][1]);
        $this->assertEquals($compareAgainst["linked-3"], $matches[1][2]);
    }
}