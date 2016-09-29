<?php
/**
 * @author Oskar Thornblad <oskar.thornblad@gmail.com>
 */

namespace Prewk\Seriquent\Deserialization;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Container\Container;
use Prewk\Seriquent\State;

/**
 * Keeps track of encountered anonymized ids, maps them to real database ids when they come available and
 * provides a way of deferring model updates until the real database ids are all collected
 */
class BookKeeper
{
    const DEFERRED_UPDATE = 1;
    const DEFERRED_ASSOCIATE = 2;
    const DEFERRED_ATTACH = 4;
    const DEFERRED_MORPH = 8;
    const DEFERRED_SEARCH = 9;

    /**
     * @var array Subscribers to onBeforeResolve
     */
    private $onBeforeResolves = [];

    /**
     * @var array Subscribers to onAfterResolve
     */
    private $onAfterResolves = [];

    /**
     * @var array Key = Internal anonymized id, Value = Newly created database id
     */
    private $books = [];

    /**
     * Keeps an array of updates to perform when all of the entities have been created and all real
     * database ids are available, the deferred actions are then iterated through and referenced internal ids
     * are replaced with their corresponding real database ids
     *
     *
     * Structure:
     * [
     *     "Namespace\To\Model" => [
     *         <Internal id> => [
     *             "updates" => [
     *                 [<Field to update, dot notation supported for deep array fields>, <Referenced internal id>]
     *             ],
     *             "associates" => [
     *                 [<Relation name to associate to another model>, <Referenced internal id>]
     *             ],
     *             "attaches" => [
     *                 [<Relation name to attach to another model>, <Referenced internal id>]
     *             ],
     *             "morphs" => [
     *                 [<Relation name that morphs to other models>, [<Referenced model's FQCN, Referenced internal id>]]
     *             ],
     *             "searches" => [
     *                 [<Field to update, dot notation supported for deep array fields, <String to search for>, <Referenced internal id>]
     *             ]
     *         ],
     *         <Internal id> => ...
     *         ...
     *     ],
     *     "Namespace\To\AnotherModel" => ...
     *     ...
     * ]
     *
     * @var array
     */
    private $deferred = [];

    /**
     * @var Container
     */
    private $app;

    /**
     * @var State
     */
    private $state;

    /**
     * @var array
     **/
    private $morphMap;

    /**
     * @var array
     **/
    private $revMorphMap;

    /**
     * Constructor
     *
     * @param Container $app Laravel container for resolving applications from the IoC
     * @param State $state
     */
    public function __construct(Container $app, State $state)
    {
        $this->app = $app;
        $this->state = $state;
        $this->morphMap = Relation::morphMap();
        $this->revMorphMap = array_flip($this->morphMap);
    }

    /**
     * Gets the class name, taking eloquent morphMap into account
     * 
     * @param Model $model Eloquent model
     * @return array Tuple with morph map or class name as first entry and fqcn as second
     */
    protected function getClassName($model)
    {
        $fqcn = get_class($model);

        return [isset($this->revMorphMap[$fqcn]) ? $this->revMorphMap[$fqcn] : $fqcn, $fqcn];
    }

    /**
     * Creates the class through the IoC container, taking eloquent morphMap into account
     * 
     * @param string $fqcn Eloquent model
     * @return Model The IoC created model
     */
    protected function makeModel($fqcn)
    {
        return $this->app->make(
            isset($this->morphMap[$fqcn])
                ? $this->morphMap[$fqcn]
                : $fqcn
        );
    }

    /**
     * Get the internal to db id array in its current state
     *
     * @return array Key = Internal anonymized id, Value = Newly created database id
     */
    public function getBooks()
    {
        return $this->getBooks();
    }

    /**
     * Report created database entity
     *
     * @param mixed $id Internal id
     * @param mixed $dbId Database id
     * @throws Exception if this database id already was reported
     */
    public function bind($id, $dbId)
    {
        if (isset($this->books[$id])) {
            throw new Exception("Bind collision: Internal id $id is already bound to database id " . $this->books[$id] . " and can't be re-bound to $dbId");
        }

        $this->books[$id] = $dbId;
    }

    /**
     * Get the database id of a given internal id, if possible
     *
     * @param mixed $id Internal id
     * @param mixed $fallback If the db id is missing, return this instead
     * @return mixed The database id or fallback
     */
    public function get($id, $fallback = 0)
    {
        return isset($this->books[$id]) ? $this->books[$id] : $fallback;
    }

    /**
     * Associate now or defer if needed
     *
     * @param Model $model Model with one-to-one relation
     * @param string $field Relation field name
     * @param mixed $receivingId Internal id of the receiving model
     * @param mixed $referredId Internal id of the referenced model
     * @return bool Returns true if association was immediate, false if it was deferred
     */
    public function associate(Model $model, $field, $receivingId, $referredId)
    {
        if (isset($this->books[$referredId])) {
            // Trigger onBefore events
            $fqcn = $this->getClassName($model)[0];
            if (isset($this->onBeforeResolves[$fqcn], $this->onBeforeResolves[$fqcn][self::DEFERRED_ASSOCIATE])) {
                if (!$this->publish(
                    $this->onBeforeResolves[$fqcn][self::DEFERRED_ASSOCIATE],
                    $model,
                    ["field" => $field, "referredId" => $referredId],
                    $this->books[$referredId]
                )) {
                    return true;
                }
            }

            $model->$field()->associate($this->books[$referredId]);

            // Trigger onAfter events
            if (isset($this->onAfterResolves[$fqcn], $this->onAfterResolves[$fqcn][self::DEFERRED_ASSOCIATE])) {
                $this->publish(
                    $this->onAfterResolves[$fqcn][self::DEFERRED_ASSOCIATE],
                    $model,
                    ["field" => $field, "referredId" => $referredId],
                    $this->books[$referredId]
                );
            }

            return true;
        } else {
            $this->deferredAssociate($this->getClassName($model)[0], $receivingId, $field, $referredId);
            return false;
        }
    }

    /**
     * Attach now or defer if needed
     *
     * @param Model $model Model with one-to-many relation
     * @param string $field Relation field name
     * @param mixed $receivingId Internal id of the receiving model
     * @param mixed $referredId Internal id of the referenced model
     * @return bool Returns true if attachment was immediate, false if it was deferred
     */
    public function attach(Model $model, $field, $receivingId, $referredId)
    {
        if (isset($this->books[$referredId])) {
            $fqcn = $this->getClassName($model)[0];

            // Trigger onBefore events
            if (isset($this->onBeforeResolves[$fqcn], $this->onBeforeResolves[$fqcn][self::DEFERRED_ATTACH])) {
                if (!$this->publish(
                    $this->onBeforeResolves[$fqcn][self::DEFERRED_ATTACH],
                    $model,
                    ["field" => $field, "referredId" => $referredId],
                    $this->books[$referredId]
                )) {
                    return true;
                }
            }

            $model->$field()->attach($this->books[$referredId]);

            // Trigger onAfter events
            if (isset($this->onAfterResolves[$fqcn], $this->onAfterResolves[$fqcn][self::DEFERRED_ATTACH])) {
                $this->publish(
                    $this->onAfterResolves[$fqcn][self::DEFERRED_ATTACH],
                    $model,
                    ["field" => $field, "referredId" => $referredId],
                    $this->books[$referredId]
                );
            }

            return true;
        } else {
            $this->deferredAttach($this->getClassName($model)[0], $receivingId, $field, $referredId);
            return false;
        }
    }

    /**
     * Update now or defer if needed
     *
     * @param Model $model Model with field
     * @param string $dotField Field name with dot notation depth support (eg. data.foo.bar refers to
     *                         column 'data', expected to have an array cast, in ["foo" => ["bar" => <Internal id>]] )
     * @param mixed $receivingId Internal id of the receiving model
     * @param mixed $referredId Internal id of the referenced model
     * @return bool Returns true if update was immediate, false if it was deferred
     */
    public function update(Model $model, $dotField, $receivingId, $referredId)
    {
        if (isset($this->books[$referredId])) {
            $fqcn = $this->getClassName($model)[0];

            // Trigger onBefore events
            if (isset($this->onBeforeResolves[$fqcn], $this->onBeforeResolves[$fqcn][self::DEFERRED_UPDATE])) {
                if (!$this->publish(
                    $this->onBeforeResolves[$fqcn][self::DEFERRED_UPDATE],
                    $model,
                    ["dotField" => $dotField, "referredId" => $referredId],
                    $this->books[$referredId]
                )) {
                    return true;
                }
            }

            $this->mergeFieldData($model, $dotField, $this->books[$referredId]);

            // Trigger onAfter events
            if (isset($this->onAfterResolves[$fqcn], $this->onAfterResolves[$fqcn][self::DEFERRED_UPDATE])) {
                $this->publish(
                    $this->onAfterResolves[$fqcn][self::DEFERRED_UPDATE],
                    $model,
                    ["dotField" => $dotField, "referredId" => $referredId],
                    $this->books[$referredId]
                );
            }

            return true;
        } else {
            $this->deferredUpdate($this->getClassName($model)[0], $receivingId, $dotField, $referredId);
            return false;
        }
    }

    /**
     * Update by search and replace or defer if needed
     *
     * @param Model $model Model with field
     * @param string $dotField Field name with dot notation depth support (eg. data.foo.bar refers to
     *                         column 'data', expected to have an array cast, in ["foo" => ["bar" => <Internal id>]] )
     * @param mixed $receivingId Internal id of the receiving model
     * @param string $search String to search for which contains $referredId
     * @param mixed $referredId Internal id of the referenced model
     * @return bool Returns true if update was immediate, false if it was deferred
     */
    public function searchAndReplace(Model $model, $dotField, $receivingId, $search, $referredId)
    {
        if (isset($this->books[$referredId])) {
            $fqcn = $this->getClassName($model)[0];

            // Trigger onBefore events
            if (isset($this->onBeforeResolves[$fqcn], $this->onBeforeResolves[$fqcn][self::DEFERRED_SEARCH])) {
                if (!$this->publish(
                    $this->onBeforeResolves[$fqcn][self::DEFERRED_SEARCH],
                    $model,
                    ["dotField" => $dotField, "search" => $search, "referredId" => $referredId],
                    $this->books[$referredId]
                )) {
                    return true;
                }
            }

            $this->searchAndReplaceFieldData($model, $dotField, $referredId, $search, $this->books[$referredId]);

            // Trigger onAfter events
            if (isset($this->onAfterResolves[$fqcn], $this->onAfterResolves[$fqcn][self::DEFERRED_SEARCH])) {
                $this->publish(
                    $this->onAfterResolves[$fqcn][self::DEFERRED_SEARCH],
                    $model,
                    ["dotField" => $dotField, "search" => $search, "referredId" => $referredId],
                    $this->books[$referredId]
                );
            }

            return true;
        } else {
            $this->deferredSearchAndReplace($this->getClassName($model)[0], $receivingId, $dotField, $search, $referredId, $referredId);
            return false;
        }
    }

    /**
     * Update a polymorphed relation or defer if needed
     *
     * @param Model $model Model with one-to-many relation
     * @param string $field Relation field name
     * @param mixed $receivingId Internal id of the receiving model
     * @param array $morph Tuple with polymorph FQCN and internal id, like so: ["Namespace\To\Model", <Internal id>]
     * @return bool Returns true if association was immediate, false if it was deferred
     */
    public function morph(Model $model, $field, $receivingId, array $morph)
    {
        list($morphableFqcn, $referredId) = $morph;
        if (isset($this->books[$referredId])) {
            $fqcn = $this->getClassName($model)[0];

            // Trigger onBefore events
            if (isset($this->onBeforeResolves[$fqcn], $this->onBeforeResolves[$fqcn][self::DEFERRED_MORPH])) {
                if (!$this->publish(
                    $this->onBeforeResolves[$fqcn][self::DEFERRED_MORPH],
                    $model,
                    ["field" => $field, "morphableType" => $morphableFqcn, "morphableId" => $referredId],
                    $this->books[$referredId]
                )) {
                    return true;
                }
            }

            // Set the morphable fields
            $model->{$model->$field()->getForeignKey()} = $this->books[$referredId];
            $model->{$model->$field()->getMorphType()} = $morphableFqcn;

            // Trigger onAfter events
            if (isset($this->onAfterResolves[$fqcn], $this->onAfterResolves[$fqcn][self::DEFERRED_MORPH])) {
                $this->publish(
                    $this->onAfterResolves[$fqcn][self::DEFERRED_MORPH],
                    $model,
                    ["field" => $field, "morphableType" => $morphableFqcn, "morphableId" => $referredId],
                    $this->books[$referredId]
                );
            }

            return true;
        } else {
            // Set the morphable fields with a placeholder id to circumvent NOT NULL problems
            $model->{$model->$field()->getForeignKey()} = 0;
            $model->{$model->$field()->getMorphType()} = $morphableFqcn;
            $this->deferredMorph($this->getClassName($model)[0], $receivingId, $field, $morph);

            return false;
        }
    }

    /**
     * Get, and create if needed, a deferred item
     *
     * @param string $fqcn Fully qualified class name
     * @param mixed $id Internal id
     * @return array The deferred item
     */
    protected function getDeferred($fqcn, $id)
    {
        if (!isset($this->deferred[$fqcn])) {
            $this->deferred[$fqcn] = [];
        }
        if (!isset($this->deferred[$fqcn][$id])) {
            $this->deferred[$fqcn][$id] = [
                "updates" => [],
                "associates" => [],
                "attaches" => [],
                "morphs" => [],
                "searches" => [],
            ];
        }

        return $this->deferred[$fqcn][$id];
    }

    /**
     * Add deferred action
     *
     * @param string $fqcn Fully qualified class name
     * @param mixed $receivingId Receiving internal id
     * @param array $data Data needed to perform the action later
     * @param string $ns "updates"|"associates"|"attaches"|"morphs"
     */
    protected function addDeferredAction($fqcn, $receivingId, array $data, $ns)
    {
        $this->getDeferred($fqcn, $receivingId);
        $this->deferred[$fqcn][$receivingId][$ns][] = $data;
    }

    /**
     * Add deferred update action
     *
     * @param string $fqcn Fully qualified class name
     * @param mixed $receivingId Receiving internal id
     * @param string $field Field to update, dot notation supported
     * @param mixed $referredId Referred internal id
     */
    protected function deferredUpdate($fqcn, $receivingId, $field, $referredId)
    {
        $this->addDeferredAction($fqcn, $receivingId, [$field, $referredId], "updates");
    }

    /**
     * Add deferred search and replace action
     *
     * @param string $fqcn Fully qualified class name
     * @param mixed $receivingId Receiving internal id
     * @param string $field Field to update, dot notation supported
     * @param string $search
     * @param mixed $referredId Referred internal id
     */
    protected function deferredSearchAndReplace($fqcn, $receivingId, $field, $search, $referredId)
    {
        $this->addDeferredAction($fqcn, $receivingId, [$field, $search, $referredId], "searches");
    }

    /**
     * Add deferred associate action
     *
     * @param string $fqcn Fully qualified class name
     * @param mixed $receivingId Receiving internal id
     * @param string $field Relation field name
     * @param mixed $referredId Referred internal id
     */
    protected function deferredAssociate($fqcn, $receivingId, $field, $referredId)
    {
        $this->addDeferredAction($fqcn, $receivingId, [$field, $referredId], "associates");
    }

    /**
     * Add deferred attach action
     *
     * @param string $fqcn Fully qualified class name
     * @param mixed $receivingId Receiving internal id
     * @param string $field Relation field name
     * @param mixed $referredId Receiving internal id
     */
    protected function deferredAttach($fqcn, $receivingId, $field, $referredId)
    {
        $this->addDeferredAction($fqcn, $receivingId, [$field, $referredId], "attaches");
    }

    /**
     * Add deferred polymorph relation update
     *
     * @param string $fqcn Fully qualified class name
     * @param mixed $receivingId Receiving internal id
     * @param string $field Relation field name
     * @param array $morph Tuple with polymorph FQCN and internal id, like so: ["Namespace\To\Model", <Internal id>]
     */
    protected function deferredMorph($fqcn, $receivingId, $field, $morph)
    {
        $this->addDeferredAction($fqcn, $receivingId, [$field, $morph], "morphs");
    }

    /**
     * Validate an action
     *
     * @param int $action Action
     * @return bool Returns whether it validates
     */
    protected function validateAction($action)
    {
        return in_array($action, [
            self::DEFERRED_UPDATE,
            self::DEFERRED_ASSOCIATE,
            self::DEFERRED_ATTACH,
            self::DEFERRED_MORPH,
            self::DEFERRED_SEARCH
        ]);
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
     * @throws Exception if an invalid action is specified
     */
    public function onBeforeResolve($fqcn, $action, callable $callback)
    {
        if (!$this->validateAction($action)) {
            throw new Exception("Invalid action specified");
        }
        if (!isset($this->onBeforeResolves[$fqcn])) {
            $this->onBeforeResolves[$fqcn] = [];
        }
        if (!isset($this->onBeforeResolves[$fqcn][$action])) {
            $this->onBeforeResolves[$fqcn][$action] = [];
        }

        $this->onBeforeResolves[$fqcn][$action][] = $callback;
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
     * @throws Exception if an invalid action is specified
     */
    public function onAfterResolve($fqcn, $action, callable $callback)
    {
        if (!$this->validateAction($action)) {
            throw new Exception("Invalid action specified");
        }
        if (isset($this->onAfterResolves[$fqcn])) {
            $this->onAfterResolves[$fqcn] = [];
        }
        if (!isset($this->onAfterResolves[$fqcn][$action])) {
            $this->onAfterResolves[$fqcn][$action] = [];
        }

        $this->onAfterResolves[$fqcn][$action][] = $callback;
    }

    /**
     * Publish the given parameters to the given array of events
     *
     * @param array $events Array of subscribers
     * @param Model $model Eloquent model
     * @param array $data Deferred action data
     * @param string|null $resolvedId Resolved database id
     * @return bool Returns false if any of the subscribers return false, otherwise true
     */
    protected function publish(array $events, Model $model, array $data, $resolvedId)
    {
        $returnee = true;

        // Publish to everyone listening
        foreach ($events as $subscriber) {
            if ($subscriber($model, $data, $resolvedId) === false) {
                $returnee = false;
            }
        }

        return $returnee;
    }

    /**
     * Run all deferred actions
     *
     * @return array The full, resolved, [Internal id => Db id] array
     * @throws Exception if expected database ids aren't available when performing actions
     */
    public function resolve()
    {
        foreach ($this->deferred as $fqcn => $ids) {
            $modelFactory = $this->makeModel($fqcn);

            foreach ($ids as $id => $deferee) {
                if (!isset($this->books[$id])) {
                    throw new Exception("Expected id $id to have a db id when resolving a $fqcn model");
                }
                $dbId = $this->books[$id];

                $model = $modelFactory->find($dbId);
                if (is_null($model)) {
                    throw new Exception("Expected $fqcn with db id $dbId and internal id $id to exist in the database");
                }

                // Associates
                foreach ($deferee["associates"] as $associatee) {
                    list($field, $referredId) = $associatee;

                    // Trigger onBefore events
                    if (isset($this->onBeforeResolves[$fqcn], $this->onBeforeResolves[$fqcn][self::DEFERRED_ASSOCIATE])) {
                        if (!$this->publish(
                            $this->onBeforeResolves[$fqcn][self::DEFERRED_ASSOCIATE],
                            $model,
                            ["field" => $field, "referredId" => $referredId],
                            isset($this->books[$referredId]) ? $this->books[$referredId] : null
                        )) {
                            continue;
                        }
                    }

                    if (!isset($this->books[$referredId])) {
                        throw new Exception("Expected referred id $referredId to have a db id when associating '$field' to a $fqcn model with db id $dbId and internal id $id");
                    }
                    $referredDbId = $this->books[$referredId];

                    // Associate
                    $model->$field()->associate($referredDbId);

                    // Trigger onAfter events
                    if (isset($this->onAfterResolves[$fqcn], $this->onAfterResolves[$fqcn][self::DEFERRED_ASSOCIATE])) {
                        $this->publish(
                            $this->onAfterResolves[$fqcn][self::DEFERRED_ASSOCIATE],
                            $model,
                            ["field" => $field, "referredId" => $referredId],
                            $referredDbId
                        );
                    }
                }

                // Attaches
                foreach ($deferee["attaches"] as $attachee) {
                    list($field, $referredId) = $attachee;

                    // Trigger onBefore events
                    if (isset($this->onBeforeResolves[$fqcn], $this->onBeforeResolves[$fqcn][self::DEFERRED_ATTACH])) {
                        if (!$this->publish(
                            $this->onBeforeResolves[$fqcn][self::DEFERRED_ATTACH],
                            $model,
                            ["field" => $field, "referredId" => $referredId],
                            isset($this->books[$referredId]) ? $this->books[$referredId] : null
                        )) {
                            continue;
                        }
                    }

                    if (!isset($this->books[$referredId])) {
                        throw new Exception("Expected referred id $referredId to have a db id when attaching '$field' to a $fqcn model with db id $dbId and internal id $id");
                    }
                    $referredDbId = $this->books[$referredId];

                    // Attach
                    $model->$field()->attach($referredDbId);

                    // Trigger onAfter events
                    if (isset($this->onAfterResolves[$fqcn], $this->onAfterResolves[$fqcn][self::DEFERRED_ATTACH])) {
                        $this->publish(
                            $this->onAfterResolves[$fqcn][self::DEFERRED_ATTACH],
                            $model,
                            ["field" => $field, "referredId" => $referredId],
                            $referredDbId
                        );
                    }
                }

                // Updates
                foreach ($deferee["updates"] as $updatee) {
                    list($dotField, $referredId) = $updatee;

                    // Trigger onBefore events
                    if (isset($this->onBeforeResolves[$fqcn], $this->onBeforeResolves[$fqcn][self::DEFERRED_UPDATE])) {
                        if (!$this->publish(
                            $this->onBeforeResolves[$fqcn][self::DEFERRED_UPDATE],
                            $model,
                            ["dotField" => $dotField, "referredId" => $referredId],
                            isset($this->books[$referredId]) ? $this->books[$referredId] : null
                        )) {
                            continue;
                        }
                    }

                    if (!isset($this->books[$referredId])) {
                        throw new Exception("Expected referred id $referredId to have a db id when updating '$dotField' to a $fqcn model with db id $dbId and internal id $id");
                    }
                    $referredDbId = $this->books[$referredId];

                    // Overwrite/Merge
                    $this->mergeFieldData($model, $dotField, $referredDbId);

                    // Trigger onAfter events
                    if (isset($this->onAfterResolves[$fqcn], $this->onAfterResolves[$fqcn][self::DEFERRED_UPDATE])) {
                        $this->publish(
                            $this->onAfterResolves[$fqcn][self::DEFERRED_UPDATE],
                            $model,
                            ["dotField" => $dotField, "referredId" => $referredId],
                            $referredDbId
                        );
                    }
                }

                // Searches
                foreach ($deferee["searches"] as $searchee) {
                    list($dotField, $search, $referredId) = $searchee;

                    // Trigger onBefore events
                    if (isset($this->onBeforeResolves[$fqcn], $this->onBeforeResolves[$fqcn][self::DEFERRED_SEARCH])) {
                        if (!$this->publish(
                            $this->onBeforeResolves[$fqcn][self::DEFERRED_SEARCH],
                            $model,
                            ["dotField" => $dotField, "search" => $search, "referredId" => $referredId],
                            isset($this->books[$referredId]) ? $this->books[$referredId] : null
                        )) {
                            continue;
                        }
                    }

                    if (!isset($this->books[$referredId])) {
                        throw new Exception("Expected referred id $referredId to have a db id when updating '$dotField' to a $fqcn model with db id $dbId and internal id $id");
                    }
                    $referredDbId = $this->books[$referredId];

                    // Search and replace
                    $this->searchAndReplaceFieldData($model, $dotField, $referredId, $search, $referredDbId);

                    // Trigger onAfter events
                    if (isset($this->onAfterResolves[$fqcn], $this->onAfterResolves[$fqcn][self::DEFERRED_SEARCH])) {
                        $this->publish(
                            $this->onAfterResolves[$fqcn][self::DEFERRED_SEARCH],
                            $model,
                            ["dotField" => $dotField, "search" => $search, "referredId" => $referredId],
                            $referredDbId
                        );
                    }
                }

                // Morphs
                foreach ($deferee["morphs"] as $morphee) {
                    list($field, $morph) = $morphee;
                    list($morphableType, $morphableId) = $morph;

                    // Trigger onBefore events
                    if (isset($this->onBeforeResolves[$fqcn], $this->onBeforeResolves[$fqcn][self::DEFERRED_MORPH])) {
                        if (!$this->publish(
                            $this->onBeforeResolves[$fqcn][self::DEFERRED_MORPH],
                            $model,
                            ["field" => $field, "morphableType" => $morphableType, "morphableId" => $morphableId],
                            isset($this->books[$referredId]) ? $this->books[$referredId] : null
                        )) {
                            continue;
                        }
                    }

                    if (!isset($this->books[$morphableId])) {
                        throw new Exception("Expected referred morphable id $morphableId to have a db id when morphing '$field' ($morphableType) to a $fqcn model with db id $dbId and internal id $id");
                    }
                    $morphableDbId = $this->books[$morphableId];
                    $model->{$model->$field()->getForeignKey()} = $morphableDbId;
                    $model->{$model->$field()->getMorphType()} = $morphableType;

                    // Trigger onAfter events
                    if (isset($this->onAfterResolves[$fqcn], $this->onAfterResolves[$fqcn][self::DEFERRED_MORPH])) {
                        $this->publish(
                            $this->onAfterResolves[$fqcn][self::DEFERRED_MORPH],
                            $model,
                            ["field" => $field, "morphableType" => $morphableType, "morphableId" => $morphableId],
                            $referredDbId
                        );
                    }
                }
                $model->save();
            }
        }

        // Return the books
        return $this->books;
    }

    /**
     * Write a resolved db id into a possibly deep array structure without touching
     * the rest of the field's data
     *
     * @param Model $model
     * @param string $dotField Field name with support for dot notation for array depth
     * @param mixed $referredDbId Referenced internal id to write
     */
    protected function mergeFieldData(Model $model, $dotField, $referredDbId)
    {
        $dotPos = strpos($dotField, ".");
        $field = $dotPos === false ? $dotField : substr($dotField, 0, $dotPos);

        if ($field === $dotField) {
            $fieldData = $referredDbId;
        } else {
            $fieldData = $model->$field;
            // foo.bar -> bar
            $arrayDotPath = substr($dotField, strlen($field) + 1);
            // Make sure the receiver is an array
            $fieldData = is_array($fieldData) ? $fieldData : [];
            // Overwrite with new data
            Arr::set($fieldData, $arrayDotPath, $referredDbId);
        }

        $model->$field = $fieldData;
    }

    /**
     * Search and replace a resolved db id in a possibly deep array structure's string value
     * without touching the rest of the field's data
     *
     * @param Model $model
     * @param string $dotField Field name with support for dot notation for array depth
     * @param mixed $referredId Referred internal id found in the $search string to replace with $referredDbId
     * @param mixed $search String to search for containing the $referredId
     * @param mixed $referredDbId Referenced internal id to replace with
     */
    protected function searchAndReplaceFieldData(Model $model, $dotField, $referredId, $search, $referredDbId)
    {
        $dotPos = strpos($dotField, ".");
        $field = $dotPos === false ? $dotField : substr($dotField, 0, $dotPos);
        $replacement = str_replace($referredId, $referredDbId, $search);

        if ($field === $dotField) {
            $fieldData = str_replace($search, $replacement, $model->$field);
        } else {
            $fieldData = $model->$field;
            // foo.bar -> bar
            $arrayDotPath = substr($dotField, strlen($field) + 1);
            // Make sure the receiver is an array
            $fieldData = is_array($fieldData) ? $fieldData : [];
            // Get current string
            $subject = Arr::get($fieldData, $arrayDotPath);
            // Replace occurrences with referred db id
            Arr::set($fieldData, $arrayDotPath, str_replace($search, $replacement, $subject));
        }

        $model->$field = $fieldData;
    }

}