<?php

namespace Hybrid\Blade;

use Hybrid\Blade\Compilers\BladeCompiler;
use Hybrid\Blade\Engines\CompilerEngine;
use Hybrid\Core\ServiceProvider;
use Hybrid\View\Facades\View;

use function Hybrid\Tools\tap;

class Provider extends ServiceProvider {

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register() {
        $this->registerBladeFactory();
        $this->registerBladeCompiler();
    }

    /**
     * Override the View factory implementation with Blade factory.
     *
     * @return void
     */
    public function registerBladeFactory() {
        $this->app->singleton('view', function ( $app ) {
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

            return $factory;
        });
    }

    /**
     * Register the Blade compiler implementation.
     *
     * @return void
     */
    public function registerBladeCompiler() {
        $this->app->singleton('blade.compiler', static fn( $app ) => tap(new BladeCompiler(
            $app['files'],
            $app['config']['view.compiled'],
            $app['config']->get( 'view.relative_hash', false ) ? $app->basePath() : '',
            $app['config']->get( 'view.cache', false ),
            $app['config']->get( 'view.compiled_extension', 'php' )
            ), static function ( $blade ) {
                    $blade->component( 'dynamic-component', DynamicComponent::class );
        }));
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
        View::addExtension( 'blade.php', 'blade', fn() => new CompilerEngine( $this->app['blade.compiler'], $this->app['files'] ) );
    }

}
