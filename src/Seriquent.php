<?php
/**
 * @author Oskar Thornblad <oskar.thornblad@gmail.com>
 */

namespace Prewk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Container\Container;
use Prewk\Seriquent\Deserialization\BookKeeper as DeserializeBookKeeper;
use Prewk\Seriquent\Deserializer;
use Prewk\Seriquent\Serialization\BookKeeper as SerializeBookKeeper;
use Prewk\Seriquent\Serializer;
use Prewk\Seriquent\State;
use Closure;

/**
 * Anonymized serialization of eloquent models
 */
class Seriquent
{
    const SERIALIZING = 1;
    const DESERIALIZING = 2;

    /**
     * @var State
     */
    private $state;

    /**
     * @var Serializer
     */
    private $serializer;
    /**
     * @var Deserializer
     */
    private $deserializer;

    /**
     * Constructor
     *
     * @param Serializer $serializer
     * @param Deserializer $deserializer
     */
    public function __construct(
        Serializer $serializer,
        Deserializer $deserializer
    )
    {
        $this->serializer = $serializer;
        $this->deserializer = $deserializer;
    }

    /**
     * Seriquent factory - makes Seriquents out of thin air
     *
     * @return Seriquent
     */
    public static function make()
    {
        // Assume we're in a Laravel framework environment but fallback to self-created IoC container
        $app = function_exists("app") ? app() : new Container;
        if (is_null($app)) {
            $app = new Container;
        }
        // Construct a state object for debugging purposes
        $state = new State;
        // Construct a serializer
        $serializer = new Serializer(
            new SerializeBookKeeper($state),
            $state
        );
        // Construct a deserializer
        $deserializer = new Deserializer(
            $app,
            new DeserializeBookKeeper(
                $app,
                $state
            ),
            $state
        );

        // Return a constructed Seriquent
        return new static(
            $serializer,
            $deserializer
        );
    }

    /**
     * Anonymously serialize an eloquent model and its relations according to blueprint(s)
     *
     * @param Model $model
     * @param array $customRules Custom model blueprints in the form of Array<FQCN, Blueprint array>
     * @return array
     */
    public function serialize(Model $model, array $customRules = [])
    {
        foreach ($customRules as $fqcn => $callback) {
            $this->serializer->setCustomRule($fqcn, $callback);
        }

        return $this->serializer->serialize($model);
    }

    /**
     * Deserialize a anonymized serialization and its relations according to eloquent model blueprint(s)
     *
     * @param array|Closure $serialization Anonymized serialization in the form of an array or a generator
     * @param array $customRules Custom model blueprints in the form of Array<FQCN, Blueprint array>
     * @return array An associative array of the type Array<Internal id, Database id>
     */
    public function deserialize($serialization, array $customRules = [])
    {
        foreach ($customRules as $fqcn => $callback) {
            $this->deserializer->setCustomRule($fqcn, $callback);
        }

        return $this->deserializer->deserialize($serialization);
    }
}