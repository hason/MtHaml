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
class HamlParser extends AbstractParser
{
    protected function parseComment(Buffer $buf)
    {
        if ($buf->match('!(-#|/)\s*!A', $match)) {
            $pos = $match['pos'][0];
            $rendered = '/' === $match[1];
            $condition = null;

            if ($rendered) {
                // IE conditional comments
                // example: [if IE lte 8]
                //
                // matches nested [...]
                if ($buf->match('!(\[ ( [^\[\]]+ | (?1) )+  \])$!Ax', $match)) {
                    $condition = $match[0];
                }
            }

            $node = new Comment($pos, $rendered, $condition);

            if ('' !== $line = trim($buf->getLine())) {
                $content = new Text($buf->getPosition(), $line);
                $node->setContent($content);
            }

            if (!$rendered) {

                while (null !== $next = $buf->peekLine()) {

                    $indent = '';

                    if ('' !== trim($next)) {
                        $indent = $this->getIndentString(1, $next);
                        if ('' === $indent) {
                            break;
                        }
                        if (strpos($next, $indent) !== 0) {
                            break;
                        }
                    }

                    $buf->nextLine();

                    if ('' !== trim($next)) {
                        $buf->eatChars(strlen($indent));
                        $str = new Text($buf->getPosition(), $buf->getLine());
                        $node->addChild(new Statement($str->getPosition(), $str));
                    }
                }
            }

            return $node;
        }
    }

    protected function parseDoctype(Buffer $buf)
    {
        $doctypeRegex = '/
            !!!                         # start of doctype decl
            (?:
                \s(?P<type>[^\s]+)      # optional doctype id
                (?:\s(?P<options>.*))?  # doctype options (e.g. charset, for
                                        # xml decls)
            )?$/Ax';

        if ($buf->match($doctypeRegex, $match)) {
            $type = empty($match['type']) ? null : $match['type'];
            $options = empty($match['options']) ? null : $match['options'];
            $node = new Doctype($match['pos'][0], $type, $options);

            return $node;
        }
    }

    protected function parseTag(Buffer $buf)
    {
        $tagRegex = '/
            %(?P<tag_name>[\w:-]+)  # explicit tag name ( %tagname )
            | (?=[.#][\w-])         # implicit div followed by class or id
                                    # ( .class or #id )
            /xA';

        if ($buf->match($tagRegex, $match)) {
            $tag_name = empty($match['tag_name']) ? 'div' : $match['tag_name'];

            $attributes = $this->parseTagAttributes($buf);

            $flags = $this->parseTagFlags($buf);

            $node = new Tag($match['pos'][0], $tag_name, $attributes, $flags);

            $buf->skipWs();

            if (null !== $nested = $this->parseNestableStatement($buf)) {

                if ($flags & Tag::FLAG_SELF_CLOSE) {
                    $msg = 'Illegal nesting: nesting within a self-closing tag is illegal';
                    $this->syntaxError($buf, $msg);
                }

                $node->setContent($nested);
            }

            return $node;
        }
    }

    protected function parseTagFlags(Buffer $buf)
    {
        $flags = 0;
        while (null !== $char = $buf->peekChar()) {
            switch ($char) {
                case '<':
                    $flags |= Tag::FLAG_REMOVE_INNER_WHITESPACES;
                    $buf->eatChar();
                    break;
                case '>':
                    $flags |= Tag::FLAG_REMOVE_OUTER_WHITESPACES;
                    $buf->eatChar();
                    break;
                case '/':
                    $flags |= Tag::FLAG_SELF_CLOSE;
                    $buf->eatChar();
                    break;
                default:
                    break 2;
            }
        }

        return $flags;
    }
}
