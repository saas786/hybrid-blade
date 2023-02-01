<?php

namespace Hybrid\Blade;

class Factory extends \Hybrid\View\Factory {

    use Concerns\ManagesComponents;
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
    }

}
