<?php
/**
 * @author Oskar Thornblad <oskar.thornblad@gmail.com>
 */

namespace Prewk\Seriquent\Serialization;

use Prewk\Seriquent\State;

/**
 * Keeps track of and creates internal ids adequate for anonymized serialization when traversing over database entities
 */
class BookKeeper
{
    /**
     * @var int
     */
    private $internalIdCounter = 0;

    /**
     * @var array Key = Namespace\To\Model-<PrimaryKey>, Value = Database primary key
     */
    private $books = [];

    /**
     * @var State
     */
    private $state;

    /**
     * @param State $state
     */
    public function __construct(State $state)
    {
        $this->state = $state;
    }

    /**
     * Determine whether the books have a given database entity already
     *
     * @param string $modelFqcn Fully qualified class name of an eloquent model
     * @param mixed $dbId Database id
     * @return bool
     */
    public function hasId($modelFqcn, $dbId)
    {
        $key = "$modelFqcn-$dbId";
        return isset($this->books[$key]);
    }

    /**
     * Generates unique internal ids used for anonymized serializations
     *
     * @return string
     */
    protected function idGenerator()
    {
        return "@" . ++$this->internalIdCounter;
    }

    /**
     * Get an internal id pointing to the referenced database entity, and create one if missing
     *
     * @param string $modelFqcn Fully qualified class name of an eloquent model
     * @param mixed $dbId Database id
     * @return mixed Internal anonymized id
     */
    public function getId($modelFqcn, $dbId)
    {
        $key = "$modelFqcn-$dbId";
        if (!$this->hasId($modelFqcn, $dbId)) {
            $this->books[$key] = $this->idGenerator();
        }
        return $this->books[$key];
    }
}