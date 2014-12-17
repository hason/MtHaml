<?php

namespace MtHaml\Filter;

use MtHaml\NodeVisitor\RendererAbstract as Renderer;
use MtHaml\Node\Filter;

class Javascript extends Plain
{
    public function optimize(Renderer $renderer, Filter $filter, $options)
    {
        $renderer->write('<script type="text/javascript">');

        if ($options['escaping'] === true) {
            $renderer->write('{% autoescape "js" %}');
        }

        if ($options['cdata'] === true) {
            $renderer->write('//<![CDATA[');
        }

        $renderer->indent();
        $this->renderFilter($renderer, $filter);
        $renderer->undent();

        if ($options['cdata'] === true) {
            $renderer->write('//]]>');
        }

        if ($options['escaping'] === true) {
            $renderer->write('{% endautoescape %}');
        }

        $renderer->write('</script>');
    }
}
