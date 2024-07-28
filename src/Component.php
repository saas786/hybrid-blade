<?php

namespace Hybrid\Blade;

use Closure;
use Hybrid\Container\Container;
use Hybrid\Contracts\Htmlable;
use Hybrid\Contracts\View\View as ViewContract;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use function Hybrid\Tools\collect;

abstract class Component {

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
     * @var \Hybrid\Blade\ComponentAttributeBag
     */
    public $attributes;

    /**
     * The view factory instance, if any.
     *
     * @var \Hybrid\Contracts\View\Factory|null
     */
    protected static $factory;

    /**
     * The component resolver callback.
     *
     * @var (\Closure(string, array): \Hybrid\Blade\Component)|null
     */
    protected static $componentsResolver;

    /**
     * The cache of blade view names, keyed by contents.
     *
     * @var array<string, string>
     */
    protected static $bladeViewCache = [];

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
     * The cache of constructor parameters, keyed by class.
     *
     * @var array<class-string, array<int, string>>
     */
    protected static $constructorParametersCache = [];

    /**
     * The cache of ignored parameter names.
     *
     * @var array
     */
    protected static $ignoredParameterNames = [];

    /**
     * Get the view / view contents that represent the component.
     *
     * @return \Hybrid\Contracts\View\View|\Hybrid\Contracts\Htmlable|\Closure|string
     */
    abstract public function render();

    /**
     * Resolve the component instance with the given data.
     *
     * @param  array $data
     * @return static
     */
    public static function resolve( $data ) {
        if ( static::$componentsResolver ) {
            return call_user_func( static::$componentsResolver, static::class, $data );
        }

        $parameters = static::extractConstructorParameters();

        $dataKeys = array_keys( $data );

        if ( empty( array_diff( $parameters, $dataKeys ) ) ) {
            return new static( ...array_intersect_key( $data, array_flip( $parameters ) ) );
        }

        return Container::getInstance()->make( static::class, $data );
    }

    /**
     * Extract the constructor parameters for the component.
     *
     * @return array
     */
    protected static function extractConstructorParameters() {
        if ( ! isset( static::$constructorParametersCache[ static::class ] ) ) {
            $class = new ReflectionClass( static::class );

            $constructor = $class->getConstructor();

            static::$constructorParametersCache[ static::class ] = $constructor
                ? collect( $constructor->getParameters() )->map->getName()->all()
                : [];
        }

        return static::$constructorParametersCache[ static::class ];
    }

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
            if ( $view instanceof ViewContract ) {
                return $view;
            }

            return $this->extractBladeViewFromString( $view );
        };

        return $view instanceof Closure ? static fn( array $data = [] ) => $resolver( $view( $data ) )
        : $resolver( $view );
    }

    /**
     * Create a Blade view with the raw component string content.
     *
     * @param  string $contents
     * @return string
     */
    protected function extractBladeViewFromString( $contents ) {
        $key = sprintf( '%s::%s', static::class, $contents );

        if ( isset( static::$bladeViewCache[ $key ] ) ) {
            return static::$bladeViewCache[ $key ];
        }

        if ( $this->factory()->exists( $contents ) ) {
            return static::$bladeViewCache[ $key ] = $contents;
        }

        return static::$bladeViewCache[ $key ] = $this->createBladeViewFromString( $this->factory(), $contents );
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

        if ( ! is_file( $viewFile = $directory . '/' . hash( 'xxh128', $contents ) . '.blade.php' ) ) {
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
        return array_merge( [
            'data',
            'render',
            'resolve',
            'resolveView',
            'shouldRender',
            'view',
            'withName',
            'withAttributes',
            'flushCache',
            'forgetFactory',
            'forgetComponentsResolver',
            'resolveComponentsUsing',
        ], $this->except );
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
     * @return \Hybrid\Blade\ComponentAttributeBag
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

    /**
     * Get the evaluated view contents for the given view.
     *
     * @param  string|null                       $view
     * @param  \Hybrid\Contracts\Arrayable|array $data
     * @param  array                             $mergeData
     * @return \Hybrid\Contracts\View\View
     */
    public function view( $view, $data = [], $mergeData = [] ) {
        return $this->factory()->make( $view, $data, $mergeData );
    }

    /**
     * Get the view factory instance.
     *
     * @return \Hybrid\Contracts\View\Factory
     */
    protected function factory() {
        if ( is_null( static::$factory ) ) {
            static::$factory = Container::getInstance()->make( 'view' );
        }

        return static::$factory;
    }

    /**
     * Get the cached set of anonymous component constructor parameter names to exclude.
     *
     * @return array
     */
    public static function ignoredParameterNames() {
        if ( ! isset( static::$ignoredParameterNames[ static::class ] ) ) {
            $constructor = ( new ReflectionClass(static::class) )->getConstructor();

            if ( ! $constructor ) {
                return static::$ignoredParameterNames[ static::class ] = [];
            }

            static::$ignoredParameterNames[ static::class ] = collect( $constructor->getParameters() )
                ->map->getName()
                ->all();
        }

        return static::$ignoredParameterNames[ static::class ];
    }

    /**
     * Flush the component's cached state.
     *
     * @return void
     */
    public static function flushCache() {
        static::$bladeViewCache             = [];
        static::$constructorParametersCache = [];
        static::$methodCache                = [];
        static::$propertyCache              = [];
    }

    /**
     * Forget the component's factory instance.
     *
     * @return void
     */
    public static function forgetFactory() {
        static::$factory = null;
    }

    /**
     * Forget the component's resolver callback.
     *
     * @return void
     * @internal
     */
    public static function forgetComponentsResolver() {
        static::$componentsResolver = null;
    }

    /**
     * Set the callback that should be used to resolve components within views.
     *
     * @param  \Closure(string $component, array $data): \Hybrid\Blade\Component $resolver
     * @return void
     * @internal
     */
    public static function resolveComponentsUsing( $resolver ) {
        static::$componentsResolver = $resolver;
    }

}
