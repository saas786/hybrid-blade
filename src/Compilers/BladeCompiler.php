<?php

namespace Hybrid\Blade\Compilers;

use Hybrid\Blade\Component;
use Hybrid\Container\Container;
use Hybrid\Contracts\View\Factory as ViewFactory;
use Hybrid\Contracts\View\View;
use Hybrid\Tools\Arr;
use Hybrid\Tools\Str;
use Hybrid\Tools\Traits\ReflectsClosures;
use Hybrid\View\Compilers\Compiler;
use Hybrid\View\Compilers\CompilerInterface;
use function Hybrid\Tools\collect;

class BladeCompiler extends Compiler implements CompilerInterface {

    use Concerns\CompilesAuthorizations;
    use Concerns\CompilesClasses;
    use Concerns\CompilesComments;
    use Concerns\CompilesComponents;
    use Concerns\CompilesConditionals;
    use Concerns\CompilesEchos;
    use Concerns\CompilesErrors;
    use Concerns\CompilesFragments;
    use Concerns\CompilesHelpers;
    use Concerns\CompilesIncludes;
    use Concerns\CompilesInjections;
    use Concerns\CompilesJson;
    use Concerns\CompilesJs;
    use Concerns\CompilesLayouts;
    use Concerns\CompilesLoops;
    use Concerns\CompilesRawPhp;
    use Concerns\CompilesStacks;
    use Concerns\CompilesStyles;
    use Concerns\CompilesTranslations;
    use ReflectsClosures;

    /**
     * All of the registered extensions.
     *
     * @var array
     */
    protected $extensions = [];

    /**
     * All custom "directive" handlers.
     *
     * @var array
     */
    protected $customDirectives = [];

    /**
     * All custom "condition" handlers.
     *
     * @var array
     */
    protected $conditions = [];

    /**
     * The registered string preparation callbacks.
     *
     * @var array
     */
    protected $prepareStringsForCompilationUsing = [];

    /**
     * All of the registered precompilers.
     *
     * @var array
     */
    protected $precompilers = [];

    /**
     * The file currently being compiled.
     *
     * @var string
     */
    protected $path;

    /**
     * All of the available compiler functions.
     *
     * @var array<string>
     */
    protected $compilers = [
        // 'Comments',
        'Extensions',
        'Statements',
        'Echos',
    ];

    /**
     * Array of opening and closing tags for raw echos.
     *
     * @var array<string>
     */
    protected $rawTags = [ '{!!', '!!}' ];

    /**
     * Array of opening and closing tags for regular echos.
     *
     * @var array<string>
     */
    protected $contentTags = [ '{{', '}}' ];

    /**
     * Array of opening and closing tags for escaped echos.
     *
     * @var array<string>
     */
    protected $escapedTags = [ '{{{', '}}}' ];

    /**
     * The "regular" / legacy echo string format.
     *
     * @var string
     */
    protected $echoFormat = '\Hybrid\Tools\e(%s)';

    /**
     * Array of footer lines to be added to the template.
     *
     * @var array
     */
    protected $footer = [];

    /**
     * Array to temporarily store the raw blocks found in the template.
     *
     * @var array
     */
    protected $rawBlocks = [];

    /**
     * The array of anonymous component paths to search for components in.
     *
     * @var array
     */
    protected $anonymousComponentPaths = [];

    /**
     * The array of anonymous component namespaces to autoload from.
     *
     * @var array
     */
    protected $anonymousComponentNamespaces = [];

    /**
     * The array of class component aliases and their class names.
     *
     * @var array
     */
    protected $classComponentAliases = [];

    /**
     * The array of class component namespaces to autoload from.
     *
     * @var array
     */
    protected $classComponentNamespaces = [];

    /**
     * Indicates if component tags should be compiled.
     *
     * @var bool
     */
    protected $compilesComponentTags = true;

    /**
     * Compile the view at the given path.
     *
     * @param  string|null $path
     * @return void
     */
    public function compile( $path = null ) {
        if ( $path ) {
            $this->setPath( $path );
        }

        if ( ! is_null( $this->cachePath ) ) {
            $contents = $this->compileString( $this->files->get( $this->getPath() ) );

            if ( ! empty( $this->getPath() ) ) {
                $contents = $this->appendFilePath( $contents );
            }

            $this->ensureCompiledDirectoryExists(
                $compiledPath = $this->getCompiledPath( $this->getPath() )
            );

            $this->files->put( $compiledPath, $contents );
        }
    }

    /**
     * Append the file path to the compiled string.
     *
     * @param  string $contents
     * @return string
     */
    protected function appendFilePath( $contents ) {
        $tokens = $this->getOpenAndClosingPhpTokens( $contents );

        if ( $tokens->isNotEmpty() && $tokens->last() !== T_CLOSE_TAG ) {
            $contents .= ' ?>';
        }

        return $contents . "<?php /**PATH {$this->getPath()} ENDPATH**/ ?>";
    }

    /**
     * Get the open and closing PHP tag tokens from the given string.
     *
     * @param  string $contents
     * @return \Hybrid\Tools\Collection
     */
    protected function getOpenAndClosingPhpTokens( $contents ) {
        return collect( token_get_all( $contents ) )
            ->pluck( 0 )
            ->filter( static fn( $token ) => in_array( $token, [ T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG ] ) );
    }

    /**
     * Get the path currently being compiled.
     *
     * @return string
     */
    public function getPath() {
        return $this->path;
    }

    /**
     * Set the path currently being compiled.
     *
     * @param  string $path
     * @return void
     */
    public function setPath( $path ) {
        $this->path = $path;
    }

    /**
     * Compile the given Blade template contents.
     *
     * @param  string $value
     * @return string
     */
    public function compileString( $value ) {
        [$this->footer, $result] = [ [], '' ];

        $value = $this->storeUncompiledBlocks( $value );

        foreach ( $this->prepareStringsForCompilationUsing as $callback ) {
            $value = $callback( $value );
        }

        // First we will compile the Blade component tags. This is a precompile style
        // step which compiles the component Blade tags into @component directives
        // that may be used by Blade. Then we should call any other precompilers.
        $value = $this->compileComponentTags(
            $this->compileComments( $value )
        );

        foreach ( $this->precompilers as $precompiler ) {
            $value = $precompiler( $value );
        }

        // Here we will loop through all of the tokens returned by the Zend lexer and
        // parse each one into the corresponding valid PHP. We will then have this
        // template as the correctly rendered PHP that can be rendered natively.
        foreach ( token_get_all( $value ) as $token ) {
            $result .= is_array( $token ) ? $this->parseToken( $token ) : $token;
        }

        if ( ! empty( $this->rawBlocks ) ) {
            $result = $this->restoreRawContent( $result );
        }

        // If there are any footer lines that need to get added to a template we will
        // add them here at the end of the template. This gets used mainly for the
        // template inheritance via the extends keyword that should be appended.
        if ( count( $this->footer ) > 0 ) {
            $result = $this->addFooters( $result );
        }

        if ( ! empty( $this->echoHandlers ) ) {
            $result = $this->addBladeCompilerVariable( $result );
        }

        return str_replace(
            [ '##BEGIN-COMPONENT-CLASS##', '##END-COMPONENT-CLASS##' ],
            '',
        $result);
    }

    /**
     * Evaluate and render a Blade string to HTML.
     *
     * @param  string $string
     * @param  array  $data
     * @param  bool   $deleteCachedView
     * @return string
     */
    public static function render( $string, $data = [], $deleteCachedView = false ) {
        $component = new class($string) extends Component
        {

            public function __construct( protected $template ) {
            }

            public function render() {
                return $this->template;
            }

        };

        $view = Container::getInstance()
                    ->make( ViewFactory::class )
                    ->make( $component->resolveView(), $data );

        return tap($view->render(), static function () use ( $view, $deleteCachedView ) {
            if ( $deleteCachedView ) {
                unlink( $view->getPath() );
            }
        });
    }

    /**
     * Render a component instance to HTML.
     *
     * @return string
     */
    public static function renderComponent( Component $component ) {
        $data = $component->data();

        $view = value( $component->resolveView(), $data );

        if ( $view instanceof View ) {
            return $view->with( $data )->render();
        }

        if ( $view instanceof Htmlable ) {
            return $view->toHtml();
        }

        return Container::getInstance()
            ->make( ViewFactory::class )
            ->make( $view, $data )
            ->render();
    }

    /**
     * Store the blocks that do not receive compilation.
     *
     * @param  string $value
     * @return string
     */
    protected function storeUncompiledBlocks( $value ) {
        if ( str_contains( $value, '@verbatim' ) ) {
            $value = $this->storeVerbatimBlocks( $value );
        }

        if ( str_contains( $value, '@php' ) ) {
            $value = $this->storePhpBlocks( $value );
        }

        return $value;
    }

    /**
     * Store the verbatim blocks and replace them with a temporary placeholder.
     *
     * @param  string $value
     * @return string
     */
    protected function storeVerbatimBlocks( $value ) {
        return preg_replace_callback( '/(?<!@)@verbatim(.*?)@endverbatim/s', fn( $matches ) => $this->storeRawBlock( $matches[1] ), $value );
    }

    /**
     * Store the PHP blocks and replace them with a temporary placeholder.
     *
     * @param  string $value
     * @return string
     */
    protected function storePhpBlocks( $value ) {
        return preg_replace_callback( '/(?<!@)@php((?:.(?!(?<!@)@php))*?)@endphp/s', fn( $matches ) => $this->storeRawBlock( "<?php{$matches[1]}?>" ), $value );
    }

    /**
     * Store a raw block and return a unique raw placeholder.
     *
     * @param  string $value
     * @return string
     */
    protected function storeRawBlock( $value ) {
        return $this->getRawPlaceholder(
            array_push( $this->rawBlocks, $value ) - 1
        );
    }

    /**
     * Compile the component tags.
     *
     * @param  string $value
     * @return string
     */
    protected function compileComponentTags( $value ) {
        if ( ! $this->compilesComponentTags ) {
            return $value;
        }

        return ( new ComponentTagCompiler(
            $this->classComponentAliases, $this->classComponentNamespaces, $this
        ) )->compile( $value );
    }

    /**
     * Replace the raw placeholders with the original code stored in the raw blocks.
     *
     * @param  string $result
     * @return string
     */
    protected function restoreRawContent( $result ) {
        $result = preg_replace_callback( '/' . $this->getRawPlaceholder( '(\d+)' ) . '/', fn( $matches ) => $this->rawBlocks[ $matches[1] ], $result );

        $this->rawBlocks = [];

        return $result;
    }

    /**
     * Get a placeholder to temporarily mark the position of raw blocks.
     *
     * @param  int|string $replace
     * @return string
     */
    protected function getRawPlaceholder( $replace ) {
        return str_replace( '#', $replace, '@__raw_block_#__@' );
    }

    /**
     * Add the stored footers onto the given content.
     *
     * @param  string $result
     * @return string
     */
    protected function addFooters( $result ) {
        return ltrim( $result, "\n" )
                . "\n" . implode( "\n", array_reverse( $this->footer ) );
    }

    /**
     * Parse the tokens from the template.
     *
     * @param  array $token
     * @return string
     */
    protected function parseToken( $token ) {
        [$id, $content] = $token;

        if ( $id === T_INLINE_HTML ) {
            foreach ( $this->compilers as $type ) {
                $content = $this->{"compile{$type}"}( $content );
            }
        }

        return $content;
    }

    /**
     * Execute the user defined extensions.
     *
     * @param  string $value
     * @return string
     */
    protected function compileExtensions( $value ) {
        foreach ( $this->extensions as $compiler ) {
            $value = $compiler( $value, $this );
        }

        return $value;
    }

    /**
     * Compile Blade statements that start with "@".
     *
     * @param  string $template
     * @return string
     */
    protected function compileStatements( $template ) {
        preg_match_all( '/\B@(@?\w+(?:::\w+)?)([ \t]*)(\( ( [\S\s]*? ) \))?/x', $template, $matches );

        $offset = 0;

        for ( $i = 0; isset( $matches[0][ $i ] ); $i++ ) {
            $match = [
                $matches[0][ $i ],
                $matches[1][ $i ],
                $matches[2][ $i ],
                $matches[3][ $i ] ?: null,
                $matches[4][ $i ] ?: null,
            ];

            // Here we check to see if we have properly found the closing parenthesis by
            // regex pattern or not, and will recursively continue on to the next ")"
            // then check again until the tokenizer confirms we find the right one.
            while ( isset( $match[4] ) &&
                Str::endsWith( $match[0], ')' ) &&
                ! $this->hasEvenNumberOfParentheses( $match[0] ) ) {
                if ( ( $after = Str::after( $template, $match[0] ) ) === $template ) {
                    break;
                }

                $rest = Str::before( $after, ')' );

                if ( isset( $matches[0][ $i + 1 ] ) && Str::contains( $rest . ')', $matches[0][ $i + 1 ] ) ) {
                    unset( $matches[0][ $i + 1 ] );
                    ++$i;
                }

                $match[0] = $match[0] . $rest . ')';
                $match[3] = $match[3] . $rest . ')';
                $match[4] = $match[4] . $rest;
            }

            [$template, $offset] = $this->replaceFirstStatement(
                $match[0],
                $this->compileStatement( $match ),
                $template,
                $offset
            );
        }

        return $template;
    }

    /**
     * Replace the first match for a statement compilation operation.
     *
     * @param  string $search
     * @param  string $replace
     * @param  string $subject
     * @param  int    $offset
     * @return array
     */
    protected function replaceFirstStatement( $search, $replace, $subject, $offset ) {
        $search = (string) $search;

        if ( $search === '' ) {
            return $subject;
        }

        $position = strpos( $subject, $search, $offset );

        if ( $position !== false ) {
            return [
                substr_replace( $subject, $replace, $position, strlen( $search ) ),
                $position + strlen( $replace ),
            ];
        }

        return [ $subject, 0 ];
    }

    /**
     * Determine if the given expression has the same number of opening and closing parentheses.
     *
     * @return bool
     */
    protected function hasEvenNumberOfParentheses( string $expression ) {
        $tokens = token_get_all( '<?php ' . $expression );

        if ( Arr::last( $tokens ) !== ')' ) {
            return false;
        }

        $opening = 0;
        $closing = 0;

        foreach ( $tokens as $token ) {
            if ( $token == ')' ) {
                ++$closing;
            } elseif ( $token == '(' ) {
                ++$opening;
            }
        }

        return $opening === $closing;
    }

    /**
     * Compile a single Blade @ statement.
     *
     * @param  array $match
     * @return string
     */
    protected function compileStatement( $match ) {
        if ( str_contains( $match[1], '@' ) ) {
            $match[0] = isset( $match[3] ) ? $match[1] . $match[3] : $match[1];
        } elseif ( isset( $this->customDirectives[ $match[1] ] ) ) {
            $match[0] = $this->callCustomDirective( $match[1], Arr::get( $match, 3 ) );
        } elseif ( method_exists( $this, $method = 'compile' . ucfirst( $match[1] ) ) ) {
            $match[0] = $this->$method( Arr::get( $match, 3 ) );
        } else {
            return $match[0];
        }

        return isset( $match[3] ) ? $match[0] : $match[0] . $match[2];
    }

    /**
     * Call the given directive with the given value.
     *
     * @param  string      $name
     * @param  string|null $value
     * @return string
     */
    protected function callCustomDirective( $name, $value ) {
        $value ??= '';

        if ( str_starts_with( $value, '(' ) && str_ends_with( $value, ')' ) ) {
            $value = Str::substr( $value, 1, -1 );
        }

        return call_user_func( $this->customDirectives[ $name ], trim( $value ) );
    }

    /**
     * Strip the parentheses from the given expression.
     *
     * @param  string $expression
     * @return string
     */
    public function stripParentheses( $expression ) {
        if ( Str::startsWith( $expression, '(' ) ) {
            $expression = substr( $expression, 1, -1 );
        }

        return $expression;
    }

    /**
     * Register a custom Blade compiler.
     *
     * @return void
     */
    public function extend( callable $compiler ) {
        $this->extensions[] = $compiler;
    }

    /**
     * Get the extensions used by the compiler.
     *
     * @return array
     */
    public function getExtensions() {
        return $this->extensions;
    }

    /**
     * Register an "if" statement directive.
     *
     * @param  string $name
     * @return void
     */
    public function if( $name, callable $callback ) {
        $this->conditions[ $name ] = $callback;

        $this->directive($name, static fn( $expression ) => $expression !== ''
                    ? "<?php if (\Hybrid\Blade\Facades\Blade::check('{$name}', {$expression})): ?>"
        : "<?php if (\Hybrid\Blade\Facades\Blade::check('{$name}')): ?>");

        $this->directive('unless' . $name, static fn( $expression ) => $expression !== ''
                ? "<?php if (! \Hybrid\Blade\Facades\Blade::check('{$name}', {$expression})): ?>"
        : "<?php if (! \Hybrid\Blade\Facades\Blade::check('{$name}')): ?>");

        $this->directive('else' . $name, static fn( $expression ) => $expression !== ''
                ? "<?php elseif (\Hybrid\Blade\Facades\Blade::check('{$name}', {$expression})): ?>"
        : "<?php elseif (\Hybrid\Blade\Facades\Blade::check('{$name}')): ?>");

        $this->directive( 'end' . $name, static fn() => '<?php endif; ?>' );
    }

    /**
     * Check the result of a condition.
     *
     * @param  string $name
     * @param  array  ...$parameters
     * @return bool
     */
    public function check( $name, ...$parameters ) {
        return call_user_func( $this->conditions[ $name ], ...$parameters );
    }

    /**
     * Register a class-based component alias directive.
     *
     * @param  string      $class
     * @param  string|null $alias
     * @param  string      $prefix
     * @return void
     */
    public function component( $class, $alias = null, $prefix = '' ) {
        if ( ! is_null( $alias ) && str_contains( $alias, '\\' ) ) {
            [$class, $alias] = [ $alias, $class ];
        }

        if ( is_null( $alias ) ) {
            $alias = str_contains( $class, '\\View\\Components\\' )
                            ? collect( explode( '\\', Str::after( $class, '\\View\\Components\\' ) ) )->map( static fn( $segment ) => Str::kebab( $segment ) )->implode( ':' )
                            : Str::kebab( class_basename( $class ) );
        }

        if ( ! empty( $prefix ) ) {
            $alias = $prefix . '-' . $alias;
        }

        $this->classComponentAliases[ $alias ] = $class;
    }

    /**
     * Register an array of class-based components.
     *
     * @param  array  $components
     * @param  string $prefix
     * @return void
     */
    public function components( array $components, $prefix = '' ) {
        foreach ( $components as $key => $value ) {
            if ( is_numeric( $key ) ) {
                $this->component( $value, null, $prefix );
            } else {
                $this->component( $key, $value, $prefix );
            }
        }
    }

    /**
     * Get the registered class component aliases.
     *
     * @return array
     */
    public function getClassComponentAliases() {
        return $this->classComponentAliases;
    }

    /**
     * Register a new anonymous component path.
     *
     * @return void
     */
    public function anonymousComponentPath( string $path, ?string $prefix = null ) {
        $prefixHash = md5( $prefix ?: $path );

        $this->anonymousComponentPaths[] = [
            'path'       => $path,
            'prefix'     => $prefix,
            'prefixHash' => $prefixHash,
        ];

        Container::getInstance()
            ->make( ViewFactory::class )
            ->addNamespace( $prefixHash, $path );
    }

    /**
     * Register an anonymous component namespace.
     *
     * @return void
     */
    public function anonymousComponentNamespace( string $directory, ?string $prefix = null ) {
        $prefix ??= $directory;

        $this->anonymousComponentNamespaces[ $prefix ] = Str::of( $directory )
                ->replace( '/', '.' )
                ->trim( '. ' )
                ->toString();
    }

    /**
     * Register a class-based component namespace.
     *
     * @param  string $namespace
     * @param  string $prefix
     * @return void
     */
    public function componentNamespace( $namespace, $prefix ) {
        $this->classComponentNamespaces[ $prefix ] = $namespace;
    }

    /**
     * Get the registered anonymous component paths.
     *
     * @return array
     */
    public function getAnonymousComponentPaths() {
        return $this->anonymousComponentPaths;
    }

    /**
     * Get the registered anonymous component namespaces.
     *
     * @return array
     */
    public function getAnonymousComponentNamespaces() {
        return $this->anonymousComponentNamespaces;
    }

    /**
     * Get the registered class component namespaces.
     *
     * @return array
     */
    public function getClassComponentNamespaces() {
        return $this->classComponentNamespaces;
    }

    /**
     * Register a component alias directive.
     *
     * @param  string      $path
     * @param  string|null $alias
     * @return void
     */
    public function aliasComponent( $path, $alias = null ) {
        $alias = $alias ?: Arr::last( explode( '.', $path ) );

        $this->directive($alias, static fn( $expression ) => $expression
                        ? "<?php \$__env->startComponent('{$path}', {$expression}); ?>"
        : "<?php \$__env->startComponent('{$path}'); ?>");

        $this->directive( 'end' . $alias, static fn( $expression ) => '<?php echo $__env->renderComponent(); ?>' );
    }

    /**
     * Register an include alias directive.
     *
     * @param  string      $path
     * @param  string|null $alias
     * @return void
     */
    public function include( $path, $alias = null ) {
        $this->aliasInclude( $path, $alias );
    }

    /**
     * Register an include alias directive.
     *
     * @param  string      $path
     * @param  string|null $alias
     * @return void
     */
    public function aliasInclude( $path, $alias = null ) {
        $alias = $alias ?: Arr::last( explode( '.', $path ) );

        $this->directive($alias, function ( $expression ) use ( $path ) {
            $expression = $this->stripParentheses( $expression ) ?: '[]';

            return "<?php echo \$__env->make('{$path}', {$expression}, \Hybrid\Tools\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>";
        });
    }

    /**
     * Register a handler for custom directives.
     *
     * @param  string $name
     * @return void
     * @throws \InvalidArgumentException
     */
    public function directive( $name, callable $handler ) {
        if ( ! preg_match( '/^\w+(?:::\w+)?$/x', $name ) ) {
            throw new \InvalidArgumentException( "The directive name [{$name}] is not valid. Directive names must only contain alphanumeric characters and underscores." );
        }

        $this->customDirectives[ $name ] = $handler;
    }

    /**
     * Get the list of custom directives.
     *
     * @return array
     */
    public function getCustomDirectives() {
        return $this->customDirectives;
    }

    /**
     * Indicate that the following callable should be used to prepare strings for compilation.
     *
     * @return $this
     */
    public function prepareStringsForCompilationUsing( callable $callback ) {
        $this->prepareStringsForCompilationUsing[] = $callback;

        return $this;
    }

    /**
     * Register a new precompiler.
     *
     * @return void
     */
    public function precompiler( callable $precompiler ) {
        $this->precompilers[] = $precompiler;
    }

    /**
     * Set the echo format to be used by the compiler.
     *
     * @param  string $format
     * @return void
     */
    public function setEchoFormat( $format ) {
        $this->echoFormat = $format;
    }

    /**
     * Set the "echo" format to double encode entities.
     *
     * @return void
     */
    public function withDoubleEncoding() {
        $this->setEchoFormat( '\Hybrid\Tools\e(%s, true)' );
    }

    /**
     * Set the "echo" format to not double encode entities.
     *
     * @return void
     */
    public function withoutDoubleEncoding() {
        $this->setEchoFormat( '\Hybrid\Tools\e(%s, false)' );
    }

    /**
     * Indicate that component tags should not be compiled.
     *
     * @return void
     */
    public function withoutComponentTags() {
        $this->compilesComponentTags = false;
    }

}
