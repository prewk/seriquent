<?php
/**
 * @author Oskar Thornblad <oskar.thornblad@gmail.com>
 */

namespace Prewk\Seriquent\Contracts;

use Prewk\Seriquent\State;

/**
 * Serializers' & deserializers' common functionality
 */
interface SeriquentIOInterface
{
    /**
     * Get the state object for debugging
     *
     * @return State
     */
    public function getState();
}