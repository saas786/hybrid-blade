<?php

namespace Hybrid\Blade\Compilers\Concerns;

trait CompilesStyles {

    /**
     * Compile the conditional style statement into valid PHP.
     *
     * @param  string $expression
     * @return string
     */
    protected function compileStyle( $expression ) {
        $expression = is_null( $expression ) ? '([])' : $expression;

        return "style=\"<?php echo \Hybrid\Tools\Arr::toCssStyles{$expression} ?>\"";
    }

}
