<?php

namespace Hybrid\Blade;

use ArrayAccess;
use ArrayIterator;
use Hybrid\Contracts\Arrayable;
use Hybrid\Contracts\Htmlable;
use Hybrid\Tools\Arr;
use Hybrid\Tools\Collection;
use Hybrid\Tools\HtmlString;
use Hybrid\Tools\Str;
use Hybrid\Tools\Traits\Conditionable;
use Hybrid\Tools\Traits\InteractsWithData;
use Hybrid\Tools\Traits\Macroable;
use IteratorAggregate;
use JsonSerializable;
use Stringable;
use Traversable;
use function Hybrid\Tools\e as hybridEcho;

class ComponentAttributeBag implements Arrayable, ArrayAccess, Htmlable, IteratorAggregate, JsonSerializable, Stringable {

    use Conditionable;
    use InteractsWithData;
    use Macroable;

    /**
     * The raw array of attributes.
     *
     * @var array
     */
    protected $attributes = [];

    /**
     * Create a new component attribute bag instance.
     *
     * @param array $attributes
     */
    public function __construct( array $attributes = [] ) {
        $this->setAttributes( $attributes );
    }

    /**
     * Get all the attribute values.
     *
     * @param mixed $keys
     *
     * @return array
     */
    public function all( $keys = null ) {
        if ( is_null( $keys ) ) {
            return $this->attributes;
        }

        return $this->only( $keys )->toArray();
    }

    /**
     * Get the first attribute's value.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function first( $default = null ) {
        return $this->getIterator()->current() ?? value( $default );
    }

    /**
     * Get a given attribute from the attribute array.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get( $key, $default = null ) {
        return $this->attributes[ $key ] ?? value( $default );
    }

    /**
     * Retrieve data from the instance.
     *
     * @param string|null $key
     * @param mixed       $default
     *
     * @return mixed
     */
    protected function data( $key = null, $default = null ) {
        if ( is_null( $key ) ) {
            return $this->attributes;
        }

        return $this->get( $key, $default );
    }

    /**
     * Only include the given attribute from the attribute array.
     *
     * @param mixed $keys
     *
     * @return static
     */
    public function only( $keys ) {
        if ( is_null( $keys ) ) {
            $values = $this->attributes;
        } else {
            $keys = Arr::wrap( $keys );

            $values = Arr::only( $this->attributes, $keys );
        }

        return new static( $values );
    }

    /**
     * Exclude the given attribute from the attribute array.
     *
     * @param mixed|array $keys
     *
     * @return static
     */
    public function except( $keys ) {
        if ( is_null( $keys ) ) {
            $values = $this->attributes;
        } else {
            $keys = Arr::wrap( $keys );

            $values = Arr::except( $this->attributes, $keys );
        }

        return new static( $values );
    }

    /**
     * Filter the attributes, returning a bag of attributes that pass the filter.
     *
     * @param callable $callback
     *
     * @return static
     */
    public function filter( $callback ) {
        return new static( ( new Collection( $this->attributes ) )->filter( $callback )->all() );
    }

    /**
     * Return a bag of attributes that have keys starting with the given value / pattern.
     *
     * @param string|array<string> $needles
     *
     * @return static
     */
    public function whereStartsWith( $needles ) {
        return $this->filter( function ( $value, $key ) use ( $needles ) {
            return Str::startsWith( $key, $needles );
        } );
    }

    /**
     * Return a bag of attributes with keys that do not start with the given value / pattern.
     *
     * @param string|array<string> $needles
     *
     * @return static
     */
    public function whereDoesntStartWith( $needles ) {
        return $this->filter( function ( $value, $key ) use ( $needles ) {
            return ! Str::startsWith( $key, $needles );
        } );
    }

    /**
     * Return a bag of attributes that have keys starting with the given value / pattern.
     *
     * @param string|array<string> $needles
     *
     * @return static
     */
    public function thatStartWith( $needles ) {
        return $this->whereStartsWith( $needles );
    }

    /**
     * Only include the given attribute from the attribute array.
     *
     * @param mixed|array $keys
     *
     * @return static
     */
    public function onlyProps( $keys ) {
        return $this->only( static::extractPropNames( $keys ) );
    }

    /**
     * Exclude the given attribute from the attribute array.
     *
     * @param mixed|array $keys
     *
     * @return static
     */
    public function exceptProps( $keys ) {
        return $this->except( static::extractPropNames( $keys ) );
    }

    /**
     * Conditionally merge classes into the attribute bag.
     *
     * @param mixed|array $classList
     *
     * @return static
     */
    public function class( $classList ) {
        $classList = Arr::wrap( $classList );

        return $this->merge( [ 'class' => Arr::toCssClasses( $classList ) ] );
    }

    /**
     * Conditionally merge styles into the attribute bag.
     *
     * @param mixed|array $styleList
     *
     * @return static
     */
    public function style( $styleList ) {
        $styleList = Arr::wrap( $styleList );

        return $this->merge( [ 'style' => Arr::toCssStyles( $styleList ) ] );
    }

    /**
     * Merge additional attributes / values into the attribute bag.
     *
     * @param array $attributeDefaults
     * @param bool  $escape
     *
     * @return static
     */
    public function merge( array $attributeDefaults = [], $escape = true ) {
        $attributeDefaults = array_map( function ( $value ) use ( $escape ) {
            return $this->shouldEscapeAttributeValue( $escape, $value )
                ? hybridEcho( $value )
                : $value;
        }, $attributeDefaults );

        [$appendableAttributes, $nonAppendableAttributes] = ( new Collection( $this->attributes ) )
            ->partition( function ( $value, $key ) use ( $attributeDefaults ) {
                return 'class' === $key || 'style' === $key || (
                        isset( $attributeDefaults[ $key ] ) &&
                        $attributeDefaults[ $key ] instanceof AppendableAttributeValue
                    );
            } );

        $attributes = $appendableAttributes->mapWithKeys( function ( $value, $key ) use ( $attributeDefaults, $escape ) {
            $defaultsValue = isset( $attributeDefaults[ $key ] ) && $attributeDefaults[ $key ] instanceof AppendableAttributeValue
                ? $this->resolveAppendableAttributeDefault( $attributeDefaults, $key, $escape )
                : ( $attributeDefaults[ $key ] ?? '' );

            if ( 'style' === $key ) {
                $value = Str::finish( $value, ';' );
            }

            return [ $key => implode( ' ', array_unique( array_filter( [ $defaultsValue, $value ] ) ) ) ];
        } )->merge( $nonAppendableAttributes )->all();

        return new static( array_merge( $attributeDefaults, $attributes ) );
    }

    /**
     * Determine if the specific attribute value should be escaped.
     *
     * @param bool  $escape
     * @param mixed $value
     *
     * @return bool
     */
    protected function shouldEscapeAttributeValue( $escape, $value ) {
        if ( ! $escape ) {
            return false;
        }

        return ! is_object( $value ) &&
                ! is_null( $value ) &&
                ! is_bool( $value );
    }

    /**
     * Create a new appendable attribute value.
     *
     * @param mixed $value
     *
     * @return \Hybrid\View\AppendableAttributeValue
     */
    public function prepends( $value ) {
        return new AppendableAttributeValue( $value );
    }

    /**
     * Resolve an appendable attribute value default value.
     *
     * @param array  $attributeDefaults
     * @param string $key
     * @param bool   $escape
     *
     * @return mixed
     */
    protected function resolveAppendableAttributeDefault( $attributeDefaults, $key, $escape ) {
        if ( $this->shouldEscapeAttributeValue( $escape, $value = $attributeDefaults[ $key ]->value ) ) {
            $value = hybridEcho( $value );
        }

        return $value;
    }

    /**
     * Determine if the attribute bag is empty.
     *
     * @return bool
     */
    public function isEmpty() {
        return trim( (string) $this ) === '';
    }

    /**
     * Determine if the attribute bag is not empty.
     *
     * @return bool
     */
    public function isNotEmpty() {
        return ! $this->isEmpty();
    }

    /**
     * Get all of the raw attributes.
     *
     * @return array
     */
    public function getAttributes() {
        return $this->attributes;
    }

    /**
     * Set the underlying attributes.
     *
     * @param array $attributes
     *
     * @return void
     */
    public function setAttributes( array $attributes ) {
        if ( isset( $attributes['attributes'] ) &&
            $attributes['attributes'] instanceof self ) {
            $parentBag = $attributes['attributes'];

            unset( $attributes['attributes'] );

            $attributes = $parentBag->merge( $attributes, $escape = false )->getAttributes();
        }

        $this->attributes = $attributes;
    }

    /**
     * Extract "prop" names from given keys.
     *
     * @param array $keys
     *
     * @return array
     */
    public static function extractPropNames( array $keys ) {
        $props = [];

        foreach ( $keys as $key => $default ) {
            $key = is_numeric( $key ) ? $default : $key;

            $props[] = $key;
            $props[] = Str::kebab( $key );
        }

        return $props;
    }

    /**
     * Get content as a string of HTML.
     *
     * @return string
     */
    public function toHtml() {
        return (string) $this;
    }

    /**
     * Merge additional attributes / values into the attribute bag.
     *
     * @param array $attributeDefaults
     *
     * @return \Hybrid\Tools\HtmlString
     */
    public function __invoke( array $attributeDefaults = [] ) {
        return new HtmlString( (string) $this->merge( $attributeDefaults ) );
    }

    /**
     * Determine if the given offset exists.
     *
     * @param string $offset
     */
    public function offsetExists( $offset ): bool {
        return isset( $this->attributes[ $offset ] );
    }

    /**
     * Get the value at the given offset.
     *
     * @param string $offset
     */
    public function offsetGet( $offset ): mixed {
        return $this->get( $offset );
    }

    /**
     * Set the value at a given offset.
     *
     * @param string $offset
     * @param mixed  $value
     */
    public function offsetSet( $offset, $value ): void {
        $this->attributes[ $offset ] = $value;
    }

    /**
     * Remove the value at the given offset.
     *
     * @param string $offset
     */
    public function offsetUnset( $offset ): void {
        unset( $this->attributes[ $offset ] );
    }

    /**
     * Get an iterator for the items.
     *
     * @return \ArrayIterator
     */
    public function getIterator(): Traversable {
        return new ArrayIterator( $this->attributes );
    }

    /**
     * Convert the object into a JSON serializable form.
     */
    public function jsonSerialize(): mixed {
        return $this->attributes;
    }

    /**
     * Get all the attribute values.
     *
     * @return array
     */
    public function toArray() {
        return $this->all();
    }

    /**
     * Implode the attributes into a single HTML ready string.
     *
     * @return string
     */
    public function __toString() {
        $string = '';

        foreach ( $this->attributes as $key => $value ) {
            if ( false === $value || is_null( $value ) ) {
                continue;
            }

            if ( true === $value ) {
                $value = 'x-data' === $key || str_starts_with( $key, 'wire:' ) ? '' : $key;
            }

            $string .= ' ' . $key . '="' . str_replace( '"', '\\"', trim( $value ) ) . '"';
        }

        return trim( $string );
    }
}
