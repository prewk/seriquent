<?php
/**
 * @author Oskar Thornblad <oskar.thornblad@gmail.com>
 */

namespace Prewk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Container\Container;
use Prewk\Seriquent\Deserialization\BookKeeper as DeserBookKeeper;
use Prewk\Seriquent\Deserializer;
use Prewk\Seriquent\Serialization\BookKeeper as SerBookKeeper;
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
     * @var Container
     */
    private $app;

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
     * @param Container $app Laravel container for resolving through the IoC
     * @param array $customRules Custom model blueprints in the form of Array<FQCN, Blueprint array>
     * @param State $state Optional state helper object
     * @param Serializer $serializer
     * @param Deserializer $deserializer
     */
    public function __construct(
        Container $app,
        array $customRules = [],
        State $state = null,
        Serializer $serializer = null,
        Deserializer $deserializer = null
    )
    {
        $this->app = $app;

        if (is_null($state)) {
            $this->state = new State();
        } else {
            $this->state = $state;
        }
        if (is_null($serializer)) {
            $this->serializer = new Serializer(
                new SerBookKeeper($this->state),
                $this->state,
                $customRules
            );
        } else {
            $this->serializer = $serializer;
        }
        if (is_null($deserializer)) {
            $this->deserializer = new Deserializer(
                $app,
                new DeserBookKeeper($app, $this->state),
                $this->state,
                $customRules
            );
        } else {
            $this->deserializer = $deserializer;
        }
    }

    /**
     * Anonymously serialize an eloquent model and its relations according to blueprint(s)
     *
     * @param Model $model
     * @return array
     */
    public function serialize(Model $model)
    {
        return $this->serializer->serialize($model);
    }

    /**
     * Deserialize a anonymized serialization and its relations according to eloquent model blueprint(s)
     *
     * @param array|Closure $serialization Anonymized serialization in the form of an array or a generator
     * @return array An associative array of the type Array<Internal id, Database id>
     */
    public function deserialize($serialization)
    {
        return $this->deserializer->deserialize($serialization);
    }
}