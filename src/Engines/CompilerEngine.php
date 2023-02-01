<?php

namespace Hybrid\Blade\Engines;

use Hybrid\Filesystem\Filesystem;
use Hybrid\View\Compilers\CompilerInterface;
use Hybrid\View\Engines\PhpEngine;

use function Hybrid\Tools\last;

class CompilerEngine extends PhpEngine {

    /**
     * The Blade compiler instance.
     *
     * @var \Hybrid\View\Compilers\CompilerInterface
     */
    protected $compiler;

    /**
     * A stack of the last compiled templates.
     *
     * @var array
     */
    protected $lastCompiled = [];

    /**
     * Create a new compiler engine instance.
     *
     * @param  \Hybrid\View\Compilers\CompilerInterface $compiler
     * @param  \Hybrid\Filesystem\Filesystem|null       $files
     * @return void
     */
    public function __construct( CompilerInterface $compiler, Filesystem $files = null ) {
        parent::__construct( $files ?: new Filesystem() );

        $this->compiler = $compiler;
    }

    /**
     * Get the evaluated contents of the view.
     *
     * @param  string $path
     * @param  array  $data
     * @return string
     */
    public function get( $path, array $data = [] ) {
        $this->lastCompiled[] = $path;

        // If this given view has expired, which means it has simply been edited since
        // it was last compiled, we will re-compile the views so we can evaluate a
        // fresh copy of the view. We'll pass the compiler the path of the view.
        if ( $this->compiler->isExpired( $path ) ) {
            $this->compiler->compile( $path );
        }

        // Once we have the path to the compiled file, we will evaluate the paths with
        // typical PHP just like any other templates. We also keep a stack of views
        // which have been rendered for right exception messages to be generated.
        $results = $this->evaluatePath( $this->compiler->getCompiledPath( $path ), $data );

        array_pop( $this->lastCompiled );

        return $results;
    }

    /**
     * Handle a view exception.
     *
     * @param  \Throwable $e
     * @param  int        $obLevel
     * @return void
     * @throws \Throwable
     */
    protected function handleViewException( \Throwable $e, $obLevel ) {
        $e = new \Hybrid\View\ViewException( $this->getMessage( $e ), 0, 1, $e->getFile(), $e->getLine(), $e );

        parent::handleViewException( $e, $obLevel );
    }

    /**
     * Get the exception message for an exception.
     *
     * @param  \Throwable $e
     * @return string
     */
    protected function getMessage( \Throwable $e ) {
        return $e->getMessage() . ' (View: ' . realpath( last( $this->lastCompiled ) ) . ')';
    }

    /**
     * Get the compiler implementation.
     *
     * @return \Hybrid\View\Compilers\CompilerInterface
     */
    public function getCompiler() {
        return $this->compiler;
    }

}