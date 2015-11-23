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

    /**
     * Subscribe to an event triggered just before a resolve for the given FQCN
     *
     * @param string $fqcn Fully qualified class name
     * @param int $action BookKeeper::DEFERRED_(UPDATE|ASSOCIATE|ATTACH|MORPH|SEARCH)
     * @param callable $callback Called when the specified model is resolved, with the following signature:
     *                           function(Model $model, array $data, string|null $resolvedDbId) -> Boolean { ... }
     *                           $model is the Eloquent model
     *                           $data contains info about the action that's going to be performed, with different
     *                           array structures depending on action:
     *                               DEFERRED_UPDATE: [
     *                                   "dotPath" => Target dot path to update,
     *                                   "referredId" => Internal id
     *                               ]
     *                               DEFERRED_ASSOCIATE: [
     *                                   "field" => Target model field to associate with,
     *                                   "referredId" => Internal id
     *                               ]
     *                               DEFERRED_ATTACH: [
     *                                   "field" => Target model field to attach to,
     *                                   "referredId" => Internal id
     *                               ]
     *                               DEFERRED_MORPH: [
     *                                   "field" => Target model field describing the morph,
     *                                   "morphableType" => Morphable type,
     *                                   "morphableId" => Morphable internal id
     *                               ]
     *                               DEFERRED_SEARCH: [
     *                                   "dotField" => Target dot path for finding the string to search and replace on,
     *                                   "search" => String to search for containing the internal id,
     *                                   "referredId" => Internal id
     *                               ]
     *                           $resolvedDbId contains the relevant resolved database id for the current deferred
     *                           action if one could be found, if this is `null` an exception will be thrown shortly
     *                           after the callback finishes _unless_ the callback returns `false` in which case the
     *                           internal id resolved will be jumped over completely
     */
    public function onBeforeResolve($fqcn, $action, callable $callback)
    {
        $this->deserializer->getBookKeeper()->onBeforeResolve($fqcn, $action, $callback);
    }

    /**
     * Subscribe to an event triggered just after a resolve for the given FQCN
     *
     * @param string $fqcn Fully qualified class name
     * @param int $action BookKeeper::DEFERRED_(UPDATE|ASSOCIATE|ATTACH|MORPH|SEARCH)
     * @param callable $callback Called when the specified model is resolved, with the following signature:
     *                           function(Model $model, array $data, string $resolvedDbId) -> bool { ... }
     *                           $model is the updated Eloquent model (Note: Depending on actions, it might not be saved yet)
     *                           $data contains info about the action that was performed, with different
     *                           array structures depending on action:
     *                               DEFERRED_UPDATE: [
     *                                   "dotPath" => Target dot path that updated,
     *                                   "referredId" => Internal id
     *                               ]
     *                               DEFERRED_ASSOCIATE: [
     *                                   "field" => Target model field that was associated,
     *                                   "referredId" => Internal id
     *                               ]
     *                               DEFERRED_ATTACH: [
     *                                   "field" => Target model field that was attached,
     *                                   "referredId" => Internal id
     *                               ]
     *                               DEFERRED_MORPH: [
     *                                   "field" => Target model field describing the morph,
     *                                   "morphableType" => Morphable type,
     *                                   "morphableId" => Morphable internal id
     *                               ]
     *                               DEFERRED_SEARCH: [
     *                                   "dotField" => Target dot path for finding the string to search and replace on,
     *                                   "search" => String to search for containing the internal id,
     *                                   "referredId" => Internal id
     *                               ]
     *                           $resolvedDbId contains the relevant resolved database id for the current deferred action
     */
    public function onAfterResolve($fqcn, $action, callable $callback)
    {
        $this->deserializer->getBookKeeper()->onAfterResolve($fqcn, $action, $callback);
    }
}