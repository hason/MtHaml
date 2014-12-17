<?php

namespace MtHaml\Filter;

use MtHaml\NodeVisitor\RendererAbstract as Renderer;
use MtHaml\Node\Filter;
use CoffeeScript\Compiler;

class CoffeeScript extends AbstractFilter
{
    private $coffeescript;
    private $options;

    public function __construct(Compiler $coffeescript, array $options = array())
    {
        $this->coffeescript = $coffeescript;
        $this->options = $options;
    }

    public function optimize(Renderer $renderer, Filter $node, $options)
    {
        $renderer->write($this->filter($this->getContent($node), array(), $options));
    }

    public function filter($content, array $context, $options)
    {
        $output = "<script type=\"text/javascript\">\n";

        if ($options['escaping'] === true) {
            $output .= '{% autoescape "js" %}';
        }

        if (isset($options['cdata']) && $options['cdata'] === true) {
            $output .= "//<![CDATA[\n";
        }

        $output .= $this->coffeescript->compile($content, $this->options);

        if (isset($options['cdata']) && $options['cdata'] === true) {
            $output .= "\n//]]\n";
        }

        if ($options['escaping'] === true) {
            $output .= '{% endautoescape %}';
        }

        $output .= "</script>";

        return $output;
    }
}
