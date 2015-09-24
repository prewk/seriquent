<?php
/**
 * @author Oskar Thornblad <oskar.thornblad@gmail.com>
 */

namespace Prewk\Seriquent;

use Closure;

/**
 * A helper state object passed around for keeping track of progress and path (tree depth) for debugging purposes
 */
class State
{
    /**
     * @var array
     */
    private $path = [];

    /**
     * @var Closure
     */
    private $callback;

    /**
     * @var null
     */
    private $goal = 1;

    /**
     * @var int
     */
    private $lastProgress = 0;

    /**
     * Constructor
     *
     * @param Closure $progressCallback Optional progress callback
     */
    public function __construct(Closure $progressCallback = null)
    {
        $this->callback = $progressCallback;
    }

    /**
     * Push a path string
     *
     * @param mixed $name Path string
     */
    public function push($name)
    {
        $this->path[] = $name;
    }

    /**
     * Pop a path string
     *
     * @return mixed Popped path part
     */
    public function pop()
    {
        return array_pop($this->path);
    }

    /**
     * Set the progress goal value
     *
     * @internal
     * @param int $goal Progress goal value
     */
    public function setProgressGoal($goal)
    {
        $this->goal = $goal;
    }

    /**
     * Increment current progress and report to the progress callback
     *
     * @internal
     */
    public function incrementProgress()
    {
        $this->lastProgress += 1;
        if (isset($this->callback)) {
            $callback = $this->callback;
            $callback($this->lastProgress / $this->goal);
        }
    }
}
