<?php
/**
 * @author Oskar Thornblad <oskar.thornblad@gmail.com>
 */

namespace Prewk\Seriquent\Deserialization;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Container\Container;
use Prewk\Seriquent\State;

/**
 * Keeps track of encountered anonymized ids, maps them to real database ids when they come available and
 * provides a way of deferring model updates until the real database ids are all collected
 */
class BookKeeper
{
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
     * Constructor
     *
     * @param Container $app Laravel container for resolving applications from the IoC
     * @param State $state
     */
    public function __construct(Container $app, State $state)
    {
        $this->app = $app;
        $this->state = $state;
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
            $model->$field()->associate($this->books[$referredId]);
            return true;
        } else {
            $this->deferredAssociate(get_class($model), $receivingId, $field, $referredId);
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
            $model->$field()->attach($this->books[$referredId]);
            return true;
        } else {
            $this->deferredAttach(get_class($model), $receivingId, $field, $referredId);
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
            $this->mergeFieldData($model, $dotField, $this->books[$referredId]);
            return true;
        } else {
            $this->deferredUpdate(get_class($model), $receivingId, $dotField, $referredId);
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
            $model->{$model->$field()->getForeignKey()} = $this->books[$referredId];
            $model->{$model->$field()->getMorphType()} = $morphableFqcn;
        } else {
            $this->deferredMorph(get_class($model), $receivingId, $field, $morph);
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
     * @param mixed $referredId Receiving internal id
     */
    protected function deferredUpdate($fqcn, $receivingId, $field, $referredId)
    {
        $this->addDeferredAction($fqcn, $receivingId, [$field, $referredId], "updates");
    }

    /**
     * Add deferred associate action
     *
     * @param string $fqcn Fully qualified class name
     * @param mixed $receivingId Receiving internal id
     * @param string $field Relation field name
     * @param mixed $referredId Receiving internal id
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
     * Run all deferred actions
     *
     * @return array The full, resolved, [Internal id => Db id] array
     * @throws Exception if expected database ids aren't available when performing actions
     */
    public function resolve()
    {
        foreach ($this->deferred as $fqcn => $ids) {
            $modelFactory = $this->app->make($fqcn);

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

                    if (!isset($this->books[$referredId])) {
                        throw new Exception("Expected referred id $referredId to have a db id when associating '$field' to a $fqcn model with db id $dbId and internal id $id");
                    }
                    $referredDbId = $this->books[$referredId];

                    $model->$field()->associate($referredDbId);
                }

                // Attaches
                foreach ($deferee["attaches"] as $attachee) {
                    list($field, $referredId) = $attachee;

                    if (!isset($this->books[$referredId])) {
                        throw new Exception("Expected referred id $referredId to have a db id when attaching '$field' to a $fqcn model with db id $dbId and internal id $id");
                    }
                    $referredDbId = $this->books[$referredId];

                    $model->$field()->attach($referredDbId);
                }

                // Updates
                foreach ($deferee["updates"] as $updatee) {
                    list($dotField, $referredId) = $updatee;
                    if (!isset($this->books[$referredId])) {
                        throw new Exception("Expected referred id $referredId to have a db id when updating '$dotField' to a $fqcn model with db id $dbId and internal id $id");
                    }
                    $referredDbId = $this->books[$referredId];

                    // Overwrite/Merge
                    $this->mergeFieldData($model, $dotField, $referredDbId);
                }

                // Morphs
                foreach ($deferee["morphs"] as $morphee) {
                    list($field, $morph) = $morphee;
                    list($morphableType, $morphableId) = $morph;

                    if (!isset($this->books[$morphableId])) {
                        throw new Exception("Expected referred morphable id $morphableId to have a db id when morphing '$field' ($morphableType) to a $fqcn model with db id $dbId and internal id $id");
                    }
                    $morphableDbId = $this->books[$morphableId];
                    $model->{$model->$field()->getForeignKey()} = $morphableDbId;
                    $model->{$model->$field()->getMorphType()} = $morphableType;
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
}