<?php

namespace Hybrid\Blade;

use ArrayIterator;
use Closure;
use Hybrid\Tools\DeferringDisplayableValue;
use Hybrid\Tools\Enumerable;
use IteratorAggregate;
use Stringable;
use Traversable;

class InvokableComponentVariable implements DeferringDisplayableValue, IteratorAggregate, Stringable {

    /**
     * The callable instance to resolve the variable value.
     *
     * @var \Closure
     */
    protected $callable;

    /**
     * Create a new variable instance.
     *
     * @return void
     */
    public function __construct( Closure $callable ) {
        $this->callable = $callable;
    }

    /**
     * Resolve the displayable value that the class is deferring.
     *
     * @return \Hybrid\Contracts\Htmlable|string
     */
    public function resolveDisplayableValue() {
        return $this();
    }

    /**
     * Get an iterator instance for the variable.
     *
     * @return \ArrayIterator
     */
    public function getIterator(): Traversable {
        $result = $this();

        return new ArrayIterator( $result instanceof Enumerable ? $result->all() : $result );
    }

    /**
     * Dynamically proxy attribute access to the variable.
     *
     * @param  string $key
     * @return mixed
     */
    public function __get( $key ) {
        return $this()->{$key};
    }

    /**
     * Dynamically proxy method access to the variable.
     *
     * @param  string $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call( $method, $parameters ) {
        return $this()->{$method}( ...$parameters );
    }

    /**
     * Resolve the variable.
     *
     * @return mixed
     */
    public function __invoke() {
        return call_user_func( $this->callable );
    }

    /**
     * Resolve the variable as a string.
     *
     * @return string
     */
    public function __toString() {
        return (string) $this();
    }

}
