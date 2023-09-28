<?php

namespace Hybrid\Blade;

use function Hybrid\Tools\collect;
use function Hybrid\Tools\value;

class Factory extends \Hybrid\View\Factory {

    use Concerns\ManagesComponents;
    use Concerns\ManagesFragments;
    use Concerns\ManagesLayouts;
    use Concerns\ManagesLoops;
    use Concerns\ManagesStacks;
    use Concerns\ManagesTranslations;

    /**
     * Flush all of the factory state like sections and stacks.
     *
     * @return void
     */
    public function flushState() {
        $this->renderCount  = 0;
        $this->renderedOnce = [];

        $this->flushSections();
        $this->flushStacks();
        $this->flushComponents();
        $this->flushFragments();
    }

    /**
     * Get the evaluated contents of a given fragment.
     *
     * @param  string $fragment
     * @return string
     */
    public function fragment( $fragment ) {
        return $this->render( fn() => $this->factory->getFragment( $fragment ) );
    }

    /**
     * Get the evaluated contents for a given array of fragments.
     *
     * @param  array $fragments
     * @return string
     */
    public function fragments( array $fragments ) {
        return collect( $fragments )->map( fn( $f ) => $this->fragment( $f ) )->implode( '' );
    }

    /**
     * Get the evaluated contents of a given fragment if the given condition is true.
     *
     * @param  bool   $boolean
     * @param  string $fragment
     * @return string
     */
    public function fragmentIf( $boolean, $fragment ) {
        if ( value( $boolean ) ) {
            return $this->fragment( $fragment );
        }

        return $this->render();
    }

    /**
     * Get the evaluated contents for a given array of fragments if the given condition is true.
     *
     * @param  bool  $boolean
     * @param  array $fragments
     * @return string
     */
    public function fragmentsIf( $boolean, array $fragments ) {
        if ( value( $boolean ) ) {
            return $this->fragments( $fragments );
        }

        return $this->render();
    }

}
