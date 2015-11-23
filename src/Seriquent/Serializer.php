<?php
/**
 * @author Oskar Thornblad <oskar.thornblad@gmail.com>
 */

namespace Prewk\Seriquent;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Prewk\Seriquent;
use Prewk\Seriquent\Contracts\SeriquentIOInterface;
use Prewk\Seriquent\Serialization\BookKeeper;

/**
 * Creates an anonymized array serialization of a hierarchy of eloquent models
 */
class Serializer implements SeriquentIOInterface
{
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
        BookKeeper $bookKeeper,
        State $state
    )
    {
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
     * @param BookKeeper $bookKeeper Keeps track of the ids
     * @param State $state Progress and debugging
     * @param array $customRules Custom model blueprints in the form of Array<FQCN, Blueprint array>
     */
    public function x__construct(
        BookKeeper $bookKeeper,
        State $state,
        array $customRules = []
    )
    {
        $this->bookKeeper = $bookKeeper;
        $this->state = $state;
        $this->customRules = $customRules;
    }

    /**
     * Iterate over the given patterned rules and apply them to the given field data
     *
     * @param array $fieldData Field data
     * @param array $rules Associative array of rules in the form of:
     *                     [
     *                         "some_pattern" => "Namespace\To\Model",
     *                         "/some\\.regexp_?pattern$/" => "Namespace\To\Model",
     *                     ]
     * @return array Transformed field data
     */
    protected function toInternalIds(array $fieldData, array $rules)
    {
        // Convert to dot array
        $dotData = Arr::dot($fieldData);
        // Iterate through all of the values
        foreach ($dotData as $path => $data) {
            $this->state->push($path); // Debug
            // Only numeric data can be keys @todo
            if (is_numeric($data) && $data > 0) {
                // Iterate through the rules and see if any patterns match our data key
                foreach ($rules as $pattern => $mixed) {
                    if (is_array($mixed)) {
                        // [/regexp/ => [/regexp/ => Model]] is only for content search & replace (see further down)
                        continue;
                    }
                    // Match against exact dot pattern or regexp if it starts with a /
                    if ($path === $pattern || ($pattern[0] === "/" && preg_match($pattern, $path) === 1)) {
                        // Match - Replace with an internal id
                        Arr::set($fieldData, $path, $this->bookKeeper->getId($mixed, $data));
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

                        // Match the string against the rules given in the $mixed array
                        foreach ($mixed as $valuePattern => $fqcn) {
                            // Fetch all matches
                            preg_match_all($valuePattern, $data, $matches);

                            // 0 => ["foo=1", "foo=2"]
                            // 1 => ["1", "2"]
                            if (count($matches) !== 2) {
                                continue;
                            }
                            if (count($matches[0]) === 0) {
                                continue;
                            }

                            $searchStrings = $matches[0];
                            $ids = $matches[1];

                            // Get the field data to work on
                            $localFieldData = Arr::get($fieldData, $path);
                            // Iterate through the search strings to replace the ids with internal references
                            foreach ($searchStrings as $index => $searchString) {
                                // Create replacement string
                                $replacement = str_replace($ids[$index], $this->bookKeeper->getId($fqcn, $ids[$index]), $searchString);
                                // Actually replace the matched id strings with internal ref'd strings
                                $localFieldData = str_replace($searchString, $replacement, $localFieldData);
                            }
                            Arr::set($fieldData, $path, $localFieldData);
                        }
                    }
                }
            }
            $this->state->pop(); // Debug
        }

        // Returned transformed data
        return $fieldData;
    }

    /**
     * Get blueprint for a model
     *
     * @param Model $model Eloquent model
     * @return array Blueprint
     */
    protected function getBlueprint(Model $model)
    {
        $fqcn = get_class($model);
        // Overwriting blueprint?
        if (isset($this->customRules[$fqcn])) {
            if (is_callable($this->customRules[$fqcn])) {
                // Callable blueprint
                $callable = $this->customRules[$fqcn];
                $rules = $callable(Seriquent::SERIALIZING, $model, $this->bookKeeper, null);
                // Returning an array of rules is optional
                return isset($rules) ? $rules : [];
            } else {
                // Array blueprint
                return $this->customRules[$fqcn];
            }
        } elseif (method_exists($fqcn, "getBlueprint")) {
            // Get blueprint from model
            return $model::getBlueprint(Seriquent::SERIALIZING, $model, $this->bookKeeper, null);
        } else {
            // No model found
            return [];
        }
    }

    /**
     * Perform a serialization
     *
     * @param Model $model Initially the top level model to which all of your other models are related
     * @param array $serialization
     * @return array
     * @throws Exception
     */
    public function serialize(Model $model, array $serialization = [])
    {
        $fqcn = get_class($model);
        $serializedEntity = [];

        // Get blueprints for this model
        $blueprint = $this->getBlueprint($model);

        // If blueprint is false, we don't want to serialize at all
        if ($blueprint === false) {
            return $serialization;
        }

        $this->state->push("$fqcn-" . $model->getKey()); // Debug

        // Set internal id
        $serializedEntity["@id"] = $this->bookKeeper->getId($fqcn, $model->getKey());

        // If blueprint is an associative array (as opposed to a normal array) we just want to merge with @id and continue
        $blueprintKeys = array_keys($blueprint);
        if (count($blueprintKeys) > 0 && is_string(reset($blueprintKeys))) {
            // First key is a string - Assume the whole thing is an associative array
            $serializedEntity = array_merge($serializedEntity, $blueprint); // [@id => @123] -> [@id => @123, foo => bar, ...]
            // Add to the "big" serialized array and return early
            $serialization[$fqcn][] = $serializedEntity;
            return $serialization;
        }

        // Make space in the serialized array for entities of this model type
        if (!isset($serialization[$fqcn])) {
            $serialization[$fqcn] = [];
        }

        // Iterate through the blueprint rules
        foreach ($blueprint as $rule) {
            if (is_array($rule)) {
                $field = $rule[0];
            } else {
                $field = $rule;
            }

            $this->state->push($field); // Debug

            // Get content for the given field from the model
            $content = $model->$field;

            // Is some transformation involved?
            $transformer = null;
            if (method_exists($model, $field)) {
                // Yes, look at the transforming method by running it
                $transformer = $model->$field();
            }

            // Do different things depending on the content type
            if (is_scalar($content) || is_null($content) || is_array($content)) {
                if (is_array($rule) && is_array($content)) {
                    // Rules for this field found, figure out how to use them
                    // [field, [match rules]] or [field, field to match against, [match1 => [match rules], match2 => ...]]
                    if (count($rule) === 2) {
                        // Match rules
                        // [field, [match rules]]
                        // $rule[0] = field name
                        // $rule[1] = array of match rules
                        // $content = array
                        $serializedEntity[$field] = $this->toInternalIds($content, $rule[1]);
                    } else if (count($rule) === 3) {
                        // Conditional rules
                        // [field, field to match against, [match1 => [match rules], match2 => ...]]
                        // $rule[0] = field name
                        // $rule[1] = field name to match condition against
                        // $rule[2] = associative array where key is the value to match the field against,
                        //            and the value is the array of rules to use when a match is found
                        $matchAgainst = $model->{$rule[1]};
                        if (isset($rule[2][$matchAgainst])) {
                            $serializedEntity[$field] = $this->toInternalIds($content, $rule[2][$matchAgainst]);
                        } else {
                            $serializedEntity[$field] = $content;
                        }
                    } else {
                        throw new Exception("Unsupported rule with weird array count of " . count($rule));
                    }
                } else {
                    // The rule or content weren't arrays, so we just want to save the content
                    $serializedEntity[$field] = $content;
                }
            } elseif ($content instanceof Model) {
                // The content is a model, implying a one-to-one relationship
                $contentFqcn = get_class($content);
                $relation = $transformer;
                $relationName = last(explode("\\", get_class($relation)));

                // Handle different relationships differently
                switch ($relationName) {
                    case "BelongsTo":
                        // Do we already have this belonging entity's internal id?
                        if ($this->bookKeeper->hasId($contentFqcn, $content->{$relation->getOtherKey()})) {
                            // Yep, use it and continue
                            $serializedEntity[$field] = $this->bookKeeper->getId($contentFqcn, $content->{$relation->getOtherKey()});
                        } else {
                            // Nope, create it and recurse down the rabbit hole
                            $serializedEntity[$field] = $this->bookKeeper->getId($contentFqcn, $content->{$relation->getOtherKey()});
                            $serialization = $this->serialize($content, $serialization);
                        }
                        break;
                    case "HasOne":
                        // Recurse down the rabbit hole
                        $serialization = $this->serialize($content, $serialization);
                        break;
                    case "MorphTo":
                        // Get morphable id and morphable type and save as a tuple [type, id]
                        $morphedId = $model->{$relation->getForeignKey()};
                        $morphedType = $model->{$relation->getMorphType()};
                        $serializedEntity[$field] = [$morphedType, $this->bookKeeper->getId($morphedType, $morphedId)];
                        break;
                }
            } elseif ($content instanceof Collection) {
                // The content is a Collection, implying a one-to-many relationship
                $relation = $model->$field();
                $relationName = last(explode("\\", get_class($relation)));

                switch ($relationName) {
                    case "HasMany":
                    case "MorphMany":
                    case "MorphToMany":
                        // Iterate through the related entities
                        foreach ($content as $child) {
                            $serialization = $this->serialize($child, $serialization);
                        }
                        break;
                }
            }

            $this->state->pop(); // Debug
        }

        // Abort if entity is already processed
        // @todo Inefficient to do these checks at this point and not earlier
        foreach ($serialization[$fqcn] as $entity) {
            if ($entity["@id"] === $serializedEntity["@id"]) {
                $this->state->pop(); // Debug
                return $serialization;
            }
        }

        // Add entity to the serialization
        $serialization[$fqcn][] = $serializedEntity;

        $this->state->pop(); // Debug

        // Return the serialization
        return $serialization;
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