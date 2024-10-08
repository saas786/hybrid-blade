<?php

namespace Hybrid\Blade;

use Hybrid\Blade\Compilers\BladeCompiler;
use Hybrid\Blade\Engines\CompilerEngine;
use Hybrid\Container\Container;
use Hybrid\Core\ServiceProvider;
use Hybrid\View\Facades\View;
use function Hybrid\Tools\tap;

class Provider extends ServiceProvider {

    /**
     * Register.
     *
     * @return void
     */
    public function register() {
        $this->registerBladeFactory();
        $this->registerBladeCompiler();

        $this->app->terminating( static function () {
            Component::flushCache();
        } );
    }

    /**
     * Override the View factory implementation with Blade factory.
     *
     * @return void
     */
    public function registerBladeFactory() {
        $this->app->singleton( 'view', function ( $app ) {
            // Next we need to grab the engine resolver instance that will be used by the
            // environment. The resolver will be used by an environment to get each of
            // the various engine implementations such as plain PHP or Blade engine.
            $resolver = $app['view.engine.resolver'];

            $finder = $app['view.finder'];

            $factory = $this->createFactory( $resolver, $finder, $app['events'] );

            // We will also set the container instance on this view environment since the
            // view composers may be classes registered in the container, which allows
            // for great testable, flexible composers for the application developer.
            $factory->setContainer( $app );

            $factory->share( 'app', $app );

            $app->terminating( static function () {
                Component::forgetFactory();
            } );

            return $factory;
        } );
    }

    /**
     * Register the Blade compiler implementation.
     *
     * @return void
     */
    public function registerBladeCompiler() {
        $this->app->singleton(
            'blade.compiler',
            static fn( $app ) => tap(
                new BladeCompiler(
                    $app['files'],
                    $app['config']['view.compiled'],
                    $app['config']->get( 'view.relative_hash', false ) ? $app->basePath() : '',
                    $app['config']->get( 'view.cache', false ),
                    $app['config']->get( 'view.compiled_extension', 'php' )
                ),
                static function ( $blade ) {
                    $blade->component( 'dynamic-component', DynamicComponent::class );
                }
            )
        );
    }

    /**
     * Create a new Factory Instance.
     *
     * @param  \Hybrid\View\Engines\EngineResolver $resolver
     * @param  \Hybrid\View\ViewFinderInterface    $finder
     * @param  \Hybrid\Contracts\Events\Dispatcher $events
     * @return \Hybrid\Blade\Factory
     */
    protected function createFactory( $resolver, $finder, $events ) {
        return new Factory( $resolver, $finder, $events );
    }

    /**
     * Boot.
     *
     * @return void
     */
    public function boot() {
        // Register the Blade engine implementation.
        // Not using $this->registerBladeEngine().
        View::addExtension( 'blade.php', 'blade', static function () {
            $app = Container::getInstance();

            $compiler = new CompilerEngine(
                $app->make( 'blade.compiler' ),
                $app->make( 'files' )
            );

            $app->terminating( static function () use ( $compiler ) {
                $compiler->forgetCompiledOrNotExpired();
            } );

            return $compiler;
        } );
    }

    /**
     * Register the Blade engine implementation.
     *
     * @param  \Hybrid\View\Engines\EngineResolver $resolver
     * @return void
     */
    public function registerBladeEngine( $resolver ) {
        $resolver->register( 'blade', static function () {
            $app = Container::getInstance();

            $compiler = new CompilerEngine(
                $app->make( 'blade.compiler' ),
                $app->make( 'files' )
            );

            $app->terminating( static function () use ( $compiler ) {
                $compiler->forgetCompiledOrNotExpired();
            } );

            return $compiler;
        } );
    }

	/**
	 * Register the given view components with a custom prefix.
	 *
	 * @param  string  $prefix
	 * @param  array  $components
	 * @return void
	 */
	protected function loadViewComponentsAs($prefix, array $components)
	{
		$this->callAfterResolving(\Hybrid\Blade\Compilers\BladeCompiler::class, function ($blade) use ($prefix, $components) {
			foreach ($components as $alias => $component) {
				$blade->component($component, is_string($alias) ? $alias : null, $prefix);
			}
		});
	}
}
