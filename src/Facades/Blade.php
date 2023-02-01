<?php

namespace Hybrid\Blade\Facades;

use Hybrid\Core\Facades\Facade;

/**
 * @see \Hybrid\View\Compilers\BladeCompiler
 *
 * @method static array getClassComponentAliases()
 * @method static array getCustomDirectives()
 * @method static array getExtensions()
 * @method static bool check(string $name, array ...$parameters)
 * @method static string compileString(string $value)
 * @method static string render(string $string, array $data = [], bool $deleteCachedView = false)
 * @method static string renderComponent(\Hybrid\View\Component $component)
 * @method static string getPath()
 * @method static string stripParentheses(string $expression)
 * @method static void aliasComponent(string $path, string|null $alias = null)
 * @method static void aliasInclude(string $path, string|null $alias = null)
 * @method static void compile(string|null $path = null)
 * @method static void component(string $class, string|null $alias = null, string $prefix = '')
 * @method static void components(array $components, string $prefix = '')
 * @method static void anonymousComponentNamespace(string $directory, string $prefix = null)
 * @method static void componentNamespace(string $namespace, string $prefix)
 * @method static void directive(string $name, callable $handler)
 * @method static void extend(callable $compiler)
 * @method static void if(string $name, callable $callback)
 * @method static void include(string $path, string|null $alias = null)
 * @method static void precompiler(callable $precompiler)
 * @method static void setEchoFormat(string $format)
 * @method static void setPath(string $path)
 * @method static void withDoubleEncoding()
 * @method static void withoutComponentTags()
 * @method static void withoutDoubleEncoding()
 * @method static void stringable(string|callable $class, callable|null $handler = null)
 */
class Blade extends Facade {

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor() {
        return 'blade.compiler';
    }

}