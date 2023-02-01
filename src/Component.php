<?php

namespace Hybrid\Blade;

use Closure;
use Hybrid\Container\Container;
use Hybrid\Contracts\View\View as ViewContract;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

abstract class Component {

    /**
     * The cache of public property names, keyed by class.
     *
     * @var array
     */
    protected static $propertyCache = [];

    /**
     * The cache of public method names, keyed by class.
     *
     * @var array
     */
    protected static $methodCache = [];

    /**
     * The properties / methods that should not be exposed to the component.
     *
     * @var array
     */
    protected $except = [];

    /**
     * The component alias name.
     *
     * @var string
     */
    public $componentName;

    /**
     * The component attributes.
     *
     * @var \Hybrid\View\ComponentAttributeBag
     */
    public $attributes;

    /**
     * Get the view / view contents that represent the component.
     *
     * @return \Hybrid\Contracts\View\View|\Hybrid\Contracts\Htmlable|\Closure|string
     */
    abstract public function render();

    /**
     * Resolve the Blade view or view file that should be used when rendering the component.
     *
     * @return \Hybrid\Contracts\View\View|\Hybrid\Contracts\Htmlable|\Closure|string
     */
    public function resolveView() {
        $view = $this->render();

        if ( $view instanceof ViewContract ) {
            return $view;
        }

        if ( $view instanceof Htmlable ) {
            return $view;
        }

        $resolver = function ( $view ) {
            $factory = Container::getInstance()->make( 'view' );

            return strlen( $view ) <= PHP_MAXPATHLEN && $factory->exists( $view )
                        ? $view
                        : $this->createBladeViewFromString( $factory, $view );
        };

        return $view instanceof Closure ? static fn( array $data = [] ) => $resolver( $view( $data ) )
        : $resolver( $view );
    }

    /**
     * Create a Blade view with the raw component string content.
     *
     * @param  \Hybrid\Contracts\View\Factory $factory
     * @param  string                         $contents
     * @return string
     */
    protected function createBladeViewFromString( $factory, $contents ) {
        $factory->addNamespace(
            '__components',
            $directory = Container::getInstance()['config']->get( 'view.compiled' )
        );

        if ( ! is_file( $viewFile = $directory . '/' . sha1( $contents ) . '.blade.php' ) ) {
            if ( ! is_dir( $directory ) ) {
                mkdir( $directory, 0755, true );
            }

            file_put_contents( $viewFile, $contents );
        }

        return '__components::' . basename( $viewFile, '.blade.php' );
    }

    /**
     * Get the data that should be supplied to the view.
     *
     * @return array
     *
     * @author Freek Van der Herten
     * @author Brent Roose
     */
    public function data() {
        $this->attributes = $this->attributes ?: $this->newAttributeBag();

        return array_merge( $this->extractPublicProperties(), $this->extractPublicMethods() );
    }

    /**
     * Extract the public properties for the component.
     *
     * @return array
     */
    protected function extractPublicProperties() {
        $class = static::class;

        if ( ! isset( static::$propertyCache[ $class ] ) ) {
            $reflection = new ReflectionClass( $this );

            static::$propertyCache[ $class ] = collect( $reflection->getProperties( ReflectionProperty::IS_PUBLIC ) )
                ->reject( static fn( ReflectionProperty $property ) => $property->isStatic() )
                ->reject( fn( ReflectionProperty $property ) => $this->shouldIgnore( $property->getName() ) )
                ->map( static fn( ReflectionProperty $property ) => $property->getName() )->all();
        }

        $values = [];

        foreach ( static::$propertyCache[ $class ] as $property ) {
            $values[ $property ] = $this->{$property};
        }

        return $values;
    }

    /**
     * Extract the public methods for the component.
     *
     * @return array
     */
    protected function extractPublicMethods() {
         $class = static::class;

        if ( ! isset( static::$methodCache[ $class ] ) ) {
            $reflection = new ReflectionClass( $this );

            static::$methodCache[ $class ] = collect( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) )
                ->reject( fn( ReflectionMethod $method ) => $this->shouldIgnore( $method->getName() ) )
                ->map( static fn( ReflectionMethod $method ) => $method->getName() );
        }

        $values = [];

        foreach ( static::$methodCache[ $class ] as $method ) {
            $values[ $method ] = $this->createVariableFromMethod( new ReflectionMethod( $this, $method ) );
        }

        return $values;
    }

    /**
     * Create a callable variable from the given method.
     *
     * @param  \ReflectionMethod $method
     * @return mixed
     */
    protected function createVariableFromMethod( ReflectionMethod $method ) {
        return $method->getNumberOfParameters() === 0
                        ? $this->createInvokableVariable( $method->getName() )
                        : Closure::fromCallable( [ $this, $method->getName() ] );
    }

    /**
     * Create an invokable, toStringable variable for the given component method.
     *
     * @param  string $method
     * @return \Hybrid\View\InvokableComponentVariable
     */
    protected function createInvokableVariable( string $method ) {
        return new InvokableComponentVariable( fn() => $this->{$method}() );
    }

    /**
     * Determine if the given property / method should be ignored.
     *
     * @param  string $name
     * @return bool
     */
    protected function shouldIgnore( $name ) {
        return str_starts_with( $name, '__' ) ||
               in_array( $name, $this->ignoredMethods() );
    }

    /**
     * Get the methods that should be ignored.
     *
     * @return array
     */
    protected function ignoredMethods() {
        return array_merge([
            'data',
            'render',
            'resolveView',
            'shouldRender',
            'view',
            'withName',
            'withAttributes',
        ], $this->except);
    }

    /**
     * Set the component alias name.
     *
     * @param  string $name
     * @return $this
     */
    public function withName( $name ) {
        $this->componentName = $name;

        return $this;
    }

    /**
     * Set the extra attributes that the component should make available.
     *
     * @param  array $attributes
     * @return $this
     */
    public function withAttributes( array $attributes ) {
        $this->attributes = $this->attributes ?: $this->newAttributeBag();

        $this->attributes->setAttributes( $attributes );

        return $this;
    }

    /**
     * Get a new attribute bag instance.
     *
     * @param  array $attributes
     * @return \Hybrid\View\ComponentAttributeBag
     */
    protected function newAttributeBag( array $attributes = [] ) {
        return new ComponentAttributeBag( $attributes );
    }

    /**
     * Determine if the component should be rendered.
     *
     * @return bool
     */
    public function shouldRender() {
        return true;
    }

}