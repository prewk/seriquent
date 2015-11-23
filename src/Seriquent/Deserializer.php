<?php
/**
 * @author Oskar Thornblad <oskar.thornblad@gmail.com>
 */

namespace Prewk\Seriquent;

use Closure;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Container\Container;
use Prewk\Seriquent;
use Prewk\Seriquent\Contracts\SeriquentIOInterface;
use Prewk\Seriquent\Deserialization\BookKeeper;

/**
 * Creates database entities with eloquent models using an array following a specific schema
 */
class Deserializer implements SeriquentIOInterface
{
    /**
     * @var Container
     */
    private $app;

    /**
     * @var BookKeeper
     */
    private $bookKeeper;

    /**
     * @var State
     */
    private $state;

    /**
     * @var array
     */
    private $customRules;

    public function __construct(
        Container $app,
        BookKeeper $bookKeeper,
        State $state
    )
    {
        $this->app = $app;
        $this->bookKeeper = $bookKeeper;
        $this->state = $state;
        $this->customRules = [];
    }

    public function setCustomRule($fqcn, callable $rule)
    {
        $this->customRules[$fqcn] = $rule;
    }

    /**
     * Constructor
     *
     * @param Container $app Laravel container used for resolving models through the IoC
     * @param BookKeeper $bookKeeper Keeps track of the ids and queues deferred actions
     * @param State $state Progress and debugging
     * @param array $customRules Custom model blueprints in the form of Array<FQCN, Blueprint array>
     */
    public function x__construct(
        Container $app,
        BookKeeper $bookKeeper,
        State $state,
        array $customRules = []
    )
    {
        $this->app = $app;
        $this->bookKeeper = $bookKeeper;
        $this->state = $state;
        $this->customRules = $customRules;
    }

    /**
     * Go through an array field and deal with internal id references
     *
     * @param Model $model The eloquent model
     * @param mixed $receivingId Internal id of the model
     * @param string $field Field to work on
     * @param array $fieldData Current field data
     * @param array $rules Array of match rules
     * @return array Transformed field data
     */
    protected function toDbIds(Model $model, $receivingId, $field, array $fieldData, array $rules)
    {
        // Convert to dot array
        $dotData = Arr::dot($fieldData);

        // Iterate through all of the values
        foreach ($dotData as $path => $data) {
            $this->state->push($path); // Debug
            // Match only against data that starts with a @
            if (is_string($data) && strlen($data) > 0 && $data[0] === "@") {
                // Iterate through the rules and see if any patterns match our data key
                foreach ($rules as $pattern => $mixed) {
                    if (is_array($mixed)) {
                        // [/regexp/ => [/regexp/ => Model]] is only for content search & replace (see further down)
                        continue;
                    }
                    // Match against exact dot pattern or regexp if it starts with a /
                    if ($path === $pattern || ($pattern[0] === "/" && preg_match($pattern, $path) === 1)) {
                        // Match - Update (or defer update if needed) the field with the db id
                        if (!$this->bookKeeper->update($model, "$field.$path", $receivingId, $data)) {
                            // Set to null for now
                            Arr::set($fieldData, $path, null);
                        } else {
                            // It was set directly, update $fieldData with the set value
                            Arr::set($fieldData, $path, Arr::get($model->$field, $path));
                        }
                    }
                }
            } elseif (is_string($data)) {
                // Another possibility: we want to match, search & replace refs in text
                // Iterate through the rules and see if any patterns match in our data string
                foreach ($rules as $pattern => $mixed) {
                    if (!is_array($mixed)) {
                        // [/regexp/ => Model] is only for key => @id matching (see above)
                        continue;
                    }
                    // Match against exact dot pattern or regexp if it starts with a /
                    if ($path === $pattern || ($pattern[0] === "/" && preg_match($pattern, $path) === 1)) {
                        // Key match
                        // Start out with making sure we actually have a string to work on
                        if (!is_string($model->$field)) {
                            // Nope, let's superimpose the blueprint
                            $model->$field = $fieldData;
                        }

                        // Match the string against the rules given in the $mixed array
                        foreach ($mixed as $valuePattern => $fqcn) {
                            // Fetch all matches
                            preg_match_all($valuePattern, $data, $matches);
                            // 0 => ["foo=@1", "foo=@2"]
                            // 1 => ["@1", "@2"]
                            if (count($matches) !== 2) {
                                continue;
                            }
                            if (count($matches[0]) === 0) {
                                continue;
                            }

                            $searchStrings = $matches[0];
                            $refs = $matches[1];

                            // Filter out dupes and invalids
                            $uniqueRefs = [];
                            foreach ($refs as $index => $ref) {
                                // Ref must not already be matched and must start with a @
                                if (!isset($uniqueRefs[$ref]) && isset($ref[0]) && $ref[0] === "@") {
                                    $uniqueRefs[$ref] = true;
                                } else {
                                    // Clear from the matches
                                    unset($refs[$index]);
                                    unset($searchStrings[$index]);
                                }
                            }

                            // Re-set array indices
                            $refs = array_values($refs);
                            $searchStrings = array_values($searchStrings);

                            foreach ($refs as $index => $ref) {
                                $searchString = $searchStrings[$index];

                                // Go through the matches, try to set directly if possible, defer otherwise
                                if ($this->bookKeeper->searchAndReplace($model, "$field.$path", $receivingId, $searchString, $ref)) {
                                    // It was set directly, update $fieldData with the set value
                                    Arr::set($fieldData, $path, Arr::get($model->$field, $path));
                                }
                            }
                        }
                    }
                }
            }
            $this->state->pop(); // Debug
        }

        // Return transformed field data with db ids or temporarily set null values
        return $fieldData;
    }

    /**
     * Get blueprint for a model
     *
     * @param Model $model Eloquent model
     * @param array $serializedEntity Serialized entity
     * @return array Blueprint
     */
    protected function getBlueprint(Model $model, array $serializedEntity)
    {
        $fqcn = get_class($model);
        // Overwriting blueprint?
        if (isset($this->customRules[$fqcn])) {
            if (is_callable($this->customRules[$fqcn])) {
                // Callable blueprint
                $callable = $this->customRules[$fqcn];
                $rules = $callable(Seriquent::DESERIALIZING, $model, $this->bookKeeper, $serializedEntity);
                // Returning an array of rules is optional
                return isset($rules) ? $rules : [];
            } else {
                // Array blueprint
                return $this->customRules[$fqcn];
            }
        } elseif (method_exists($fqcn, "getBlueprint")) {
            // Get blueprint from model
            return $model::getBlueprint(Seriquent::DESERIALIZING, $model, $this->bookKeeper, $serializedEntity);
        } else {
            // No model found
            return [];
        }
    }

    /**
     * Perform a deserialization
     *
     * @param array|Closure $serializationProvider An anonymized serialization by array or generator
     * @return array An array consisting of the encountered internal ids as keys, and created db ids as values
     * @throws Exception on invalid rules
     */
    public function deserialize($serializationProvider)
    {
        if (is_array($serializationProvider)) {
            // Pretend the array is an generator
            $generator = function() use($serializationProvider) { return [$serializationProvider]; };
        } elseif ($serializationProvider instanceof Closure) {
            // Assume the given closure is a generator
            $generator = $serializationProvider;
        } else {
            throw new Exception("Provided serialization provider must be either an array or a generator");
        }

        // Crank out the serialized data
        foreach ($generator() as $serialization) {
            // Count entities for simple progress functionality
            $entityCount = 0;
            foreach ($serialization as $items) {
                $entityCount += count($items);
            }

            // Set the goal to include all entities
            $this->state->setProgressGoal($entityCount);

            // Iterate through the serialization data Array<FQCN, Array<Serialized entity>>
            foreach ($serialization as $fqcn => $serializedEntities) {
                $this->state->push($fqcn); // Debug

                // Iterate through the serialized entities
                foreach ($serializedEntities as $serializedEntity) {
                    $model = $this->app->make($fqcn);

                    // Get the internal id of this entity
                    $id = $serializedEntity["@id"];

                    // Has primary key in data?
                    if (isset($serializedEntity[$model->getKeyName()])) {
                        // Get database id
                        $dbId = $serializedEntity[$model->getKeyName()];
                        
                        // Set manually
                        $model->id = $dbId;
                        $model->exists = true;
                    }

                    // Get blueprints for this model
                    $blueprint = $this->getBlueprint($model, $serializedEntity);

                    // If blueprint is false, we don't want to deserialize at all
                    if ($blueprint === false) {
                        continue;
                    }

                    foreach ($blueprint as $rule) {
                        if (is_array($rule)) {
                            $field = $rule[0];
                        } else {
                            $field = $rule;
                        }

                        $this->state->push($field);

                        if (!method_exists($model, $field)) {
                            // Non-relation

                            // If the rule is an array, it's supposed contain a set of rules
                            if (is_array($rule) && is_array($serializedEntity[$field])) {
                                // Match rules for this field found, figure out how to use them
                                if (count($rule) === 2 && is_array($serializedEntity[$field])) {
                                    // Rules
                                    // $rule[0] = field name
                                    // $rule[1] = array of rules
                                    // serialized content = array
                                    $model->$field = $this->toDbIds($model, $id, $field, $serializedEntity[$field], $rule[1]);
                                } elseif (count($rule) === 3) {
                                    // Conditional Rules
                                    // $rule[0] = field name
                                    // $rule[1] = field name to match condition against
                                    // $rule[2] = associative array where key is the value to match the field against,
                                    //            and the value is the array of rules to use when a match is found
                                    $matchAgainst = $serializedEntity[$rule[1]];
                                    if (isset($rule[2][$matchAgainst])) {
                                        $model->$field = $this->toDbIds($model, $id, $field, $serializedEntity[$field], $rule[2][$matchAgainst]);
                                    } else {
                                        $model->$field = $serializedEntity[$field];
                                    }
                                } else {
                                    throw new Exception("Unsupported rule with weird array count of " . count($rule));
                                }
                            } else {
                                // The rule or content weren't arrays, so we just want to save the content
                                $model->$field = $serializedEntity[$field];
                            }
                        } else {
                            // The field is a relation
                            $relation = $model->$field();
                            $relationName = last(explode("\\", get_class($relation)));

                            switch ($relationName) {
                                case "BelongsTo":
                                    // Don't associate nulls
                                    if (isset($serializedEntity[$field])) {
                                        // Associate or defer an association
                                        $this->bookKeeper->associate($model, $field, $id, $serializedEntity[$field]);
                                    }
                                    break;
                                case "MorphTo":
                                    // Morph or defer a morph
                                    $this->bookKeeper->morph($model, $field, $id, $serializedEntity[$field]);
                                    break;
                            }
                        }

                        $this->state->pop(); // Debug
                    }

                    // Handle progress
                    $this->state->incrementProgress();

                    // Create/Update the database entity
                    $model->save();

                    // Report the real db id
                    $this->bookKeeper->bind($id, $model->getKey());
                }
                $this->state->pop(); // Debug
            }

        }

        // Resolve all deferred actions and return the book keeping Array<Internal id, Database id>
        return $this->bookKeeper->resolve();
    }

    /**
     * Get the state object for debugging
     *
     * @return State
     */
    public function getState()
    {
        return $this->state;
    }
}