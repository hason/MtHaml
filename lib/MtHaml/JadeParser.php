<?php

namespace MtHaml;

use MtHaml\Node\Root;
use MtHaml\Exception\SyntaxErrorException;
use MtHaml\Node\NodeAbstract;
use MtHaml\Parser\Buffer;
use MtHaml\Node\Doctype;
use MtHaml\Node\Tag;
use MtHaml\Node\TagAttribute;
use MtHaml\Node\Comment;
use MtHaml\Node\Insert;
use MtHaml\Node\Text;
use MtHaml\Node\InterpolatedString;
use MtHaml\Node\Run;
use MtHaml\Node\Statement;
use MtHaml\Node\NestInterface;
use MtHaml\Node\Filter;
use MtHaml\Node\ObjectRefClass;
use MtHaml\Node\ObjectRefId;
use MtHaml\Node\TagAttributeInterpolation;
use MtHaml\Node\TagAttributeList;

/**
 * Jade Parser
 *
 * @author Martin Hasoň <martin.hason@gmail.com>
 */
class JadeParser extends AbstractParser
{
    protected function parseStatement(Buffer $buf)
    {
        if (null !== $node = $this->parseHtml($buf)) {
            return $node;
        } else {
            return parent::parseStatement($buf);
        }
    }

    protected function parseComment(Buffer $buf)
    {
        if (!$buf->match('!(//|//-)\s*!A', $match)) {
            return;
        }

        $pos = $match['pos'][0];
        $rendered = '//' === $match[1];

        $node = new Comment($pos, $rendered);

        if ('' !== $line = trim($buf->getLine())) {
            $content = new Text($buf->getPosition(), $line);
            $node->setContent($content);
        }
    }

    protected function parseDoctype(Buffer $buf)
    {
        return null;
    }

    protected function parseTag(Buffer $buf)
    {
        $tagRegex = '/
            (?P<tag_name>[\w:-]+)   # explicit tag name ( tagname )
            | (?=[.#][\w-])         # implicit div followed by class or id
                                    # ( .class or #id )
            /xA';

        if (!$buf->match($tagRegex, $match)) {
            return;
        }

    }

    protected function parseHtml(Buffer $buf)
    {
        if (!$buf->match('/</', $match)) {
            return;
        }
    }
}
