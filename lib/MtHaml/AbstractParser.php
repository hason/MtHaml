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
 * Indentation Parser
 */
abstract class AbstractParser
{
    protected $parentStack = array();
    protected $parent;

    protected $prev;

    protected $indentChar;
    protected $indentWidth;
    protected $prevIndentLevel = 0;
    protected $indentLevel = 0;

    protected $filename;
    protected $column;
    protected $lineno;

    public function __construct()
    {
        $this->parent = new Root;
    }

    /**
     * Verifies and maintains indentation state
     *
     * @param Buffer $buf
     * @param string $indent The indentation characters of the current line
     */
    public function checkIndent(Buffer $buf, $indent)
    {
        $this->prevIndentLevel = $this->indentLevel;

        if (0 === strlen($indent)) {
            $this->indentLevel = 0;

            return;
        }

        if (null === $this->prev) {
            $this->syntaxError($buf, 'Indenting at the beginning of the document is illegal');
        }

        $char = count_chars($indent, 3 /* 3 = return all unique chars */);

        if (1 !== strlen($char)) {
            $this->syntaxError($buf, "Indentation can't use both tabs and spaces");
        }

        if (null === $this->indentChar) {

            $this->indentChar = $char;
            $this->indentWidth = strlen($indent);
            $this->indentLevel = 1;

        } else {

            if ($char !== $this->indentChar) {
                $expected = $this->indentChar === ' ' ? 'spaces' : 'tabs';
                $actual = $char === ' ' ? 'spaces' : 'tabs';
                $msg = sprintf('Inconsistent indentation: %s were used for indentation, but the rest of the document was indented using %s', $actual, $expected);
                $this->syntaxError($buf, $msg);
            }

            if (0 !== (strlen($indent) % $this->indentWidth)) {
                $msg = sprintf('Inconsistent indentation: %d is not a multiple of %d', strlen($indent), $this->indentWidth);
                $this->syntaxError($buf, $msg);
            }

            $indentLevel = strlen($indent) / $this->indentWidth;

            if ($indentLevel > $this->indentLevel + 1) {
                $this->syntaxError($buf, 'The line was indented more than one level deeper than the previous line');
            }

            $this->indentLevel = $indentLevel;
        }
    }

    /**
     * Returns the indentation string for the current line
     *
     * Returns the string that should be used for indentation in regard to the
     * current indentation state.
     *
     * @param  int    $levelOffset Identation level offset
     * @param  string $fallback    Fallback indent string. If there is
     *                             currently no indentation level and
     *                             fallback is not null, the first char of
     *                             $fallback is returned instead
     * @return string A string of zero or more spaces or tabs
     */
    public function getIndentString($levelOffset = 0, $fallback = null)
    {
        if (null !== $this->indentChar) {
            $width = $this->indentWidth * ($this->indentLevel + $levelOffset);

            return str_repeat($this->indentChar, $width);
        }

        $char = substr($fallback, 0, 1);
        if (' ' === $char || "\t" === $char) {
            return $char;
        }

        return '';
    }

    /**
     * Processes a statement
     *
     * Inserts a new $node in the tree, given the current and previous
     * indentation level.
     *
     * @param Buffer       $buf
     * @param NodeAbstract $node Node to insert in the tree
     */
    public function processStatement(Buffer $buf, NodeAbstract $node)
    {
        // open tag or block

        if ($this->indentLevel > $this->prevIndentLevel) {

            $this->parentStack[] = $this->parent;
            $this->parent = $this->prev;

        // close tag or block

        } elseif ($this->indentLevel < $this->prevIndentLevel) {

            $diff = $this->prevIndentLevel - $this->indentLevel;
            for ($i = 0; $i < $diff; ++$i) {
                $this->parent = array_pop($this->parentStack);
            }

        }

        // handle nesting

        if (!$this->parent instanceof NestInterface) {
            $parent = $this->parent;
            if ($parent instanceof Statement) {
                $parent = $parent->getContent();
            }
            $msg = sprintf('Illegal nesting: nesting within %s is illegal', $parent->getNodeName());
            $this->syntaxError($buf, $msg);
        }

        if ($this->parent->hasContent() && !$this->parent->allowsNestingAndContent()) {
            if ($this->parent instanceof Tag) {
                $msg = sprintf('Illegal nesting: content can\'t be both given on the same line as %%%s and nested within it', $this->parent->getTagName());
            } else {
                $msg = sprintf('Illegal nesting: nesting within a tag that already has content is illegal');
            }
            $this->syntaxError($buf, $msg);
        }

        if ($this->parent instanceof Tag && $this->parent->getFlags() & Tag::FLAG_SELF_CLOSE) {
            $msg = 'Illegal nesting: nesting within a self-closing tag is illegal';
            $this->syntaxError($buf, $msg);
        }

        $this->parent->addChild($node);
        $this->prev = $node;
    }

    /**
     * Parses a document
     *
     * @param string $string    A document
     * @param string $fileaname Filename to report in error messages
     * @param string $lineno    Line number of the first line of $string in
     *                          $filename (for error messages)
     */
    public function parse($string, $filename, $lineno = 1)
    {
        $this->filename = $filename;

        $buf = new Buffer($string, $lineno);
        while ($buf->nextLine()) {
            $this->handleMultiline($buf);
            $this->parseLine($buf);
        }

        if (count($this->parentStack) > 0) {
            return $this->parentStack[0];
        } else {
            return $this->parent;
        }
    }

    /**
     * Handles multiline syntax
     *
     * Any line terminated by ` |` is concatenated with the following lines
     * also terminated by ` |`. Empty or whitespace-only lines are ignored. The
     * current line is replaced by the resulting line in $buf.
     *
     * @param Buffer $buf
     */
    public function handleMultiline(Buffer $buf)
    {
        $line = $buf->getLine();

        if (!$this->isMultiline($line)) {
            return;
        }

        $line = substr(rtrim($line), 0, -1);

        while ($next = $buf->peekLine()) {
            if (trim($next) == '') {
                $buf->nextLine();
                continue;
            }
            if (!$this->isMultiline($next)) break;
            $line .= substr(trim($next), 0, -1);
            $buf->nextLine();
        }

        $buf->replaceLine($line);
    }

    public function isMultiline($string)
    {
        return ' |' === substr(rtrim($string), -2);
    }

    /**
     * Parses a line
     */
    protected function parseLine(Buffer $buf)
    {
        if ('' === trim($buf->getLine())) {
            return;
        }

        $buf->match('/[ \t]*/A', $match);
        $indent = $match[0];
        $this->checkIndent($buf, $indent);

        if (null === $node = $this->parseStatement($buf)) {
            $this->syntaxErrorExpected($buf, 'statement');
        }
        $this->processStatement($buf, $node);
    }

    protected function parseStatement(Buffer $buf)
    {
        if (null !== $node = $this->parseTag($buf)) {
            return $node;

        } elseif (null !== $node = $this->parseFilter($buf)) {
            return $node;

        } elseif (null !== $comment = $this->parseComment($buf)) {
            return $comment;

        } elseif ($buf->match('/-(?!#)/A', $match)) {

            $buf->skipWs();

            return new Run($match['pos'][0], $buf->getLine());

        } elseif (null !== $doctype = $this->parseDoctype($buf)) {
            return $doctype;

        } else {
            if (null !== $node = $this->parseNestableStatement($buf)) {
                return new Statement($node->getPosition(), $node);
            }
        }
    }

    abstract protected function parseDoctype(Buffer $buf);

    abstract protected function parseComment(Buffer $buf);

    abstract protected function parseTag(Buffer $buf);

    protected function parseTagAttributes(Buffer $buf)
    {
        $attrs = array();

        // short notation for classes and ids

        while ($buf->match('/(?P<type>[#.])(?P<name>[\w-]+)/A', $match)) {
            if ($match['type'] == '#') {
                $name = 'id';
            } else {
                $name = 'class';
            }
            $name = new Text($match['pos'][0], $name);
            $value = new Text($match['pos'][1], $match['name']);
            $attr = new TagAttribute($match['pos'][0], $name, $value);
            $attrs[] = $attr;
        }

        $hasRubyAttrs = false;
        $hasHtmlAttrs = false;
        $hasObjectRef = false;

        // accept ruby-attrs, html-attrs, and object-ref in any order,
        // but only one of each

        while (true) {
            switch ($buf->peekChar()) {
            case '{':
                if ($hasRubyAttrs) {
                    break 2;
                }
                $hasRubyAttrs = true;
                $newAttrs = $this->parseTagAttributesRuby($buf);
                $attrs = array_merge($attrs, $newAttrs);
                break;
            case '(':
                if ($hasHtmlAttrs) {
                    break 2;
                }
                $hasHtmlAttrs = true;
                $newAttrs = $this->parseTagAttributesHtml($buf);
                $attrs = array_merge($attrs, $newAttrs);
                break;
            case '[':
                if ($hasObjectRef) {
                    break 2;
                }
                $hasObjectRef = true;
                $newAttrs = $this->parseTagAttributesObject($buf);
                $attrs = array_merge($attrs, $newAttrs);
                break;
            default:
                break 2;
            }
        }

        return $attrs;
    }

    protected function parseTagAttributesRuby(Buffer $buf)
    {
        $attrs = array();

        if ($buf->match('/\{\s*/')) {
            do {
                if ($expr = $this->parseInterpolation($buf)) {
                    $attrs[] = new TagAttributeInterpolation($expr->getPosition(), $expr);
                } else {
                    $name = $this->parseAttrExpression($buf, '=,');

                    $buf->skipWs();
                    if (!$buf->match('/=>\s*/A')) {
                        $attr = new TagAttributeList($name->getPosition(), $name);
                    } else {
                        $value = $this->parseAttrExpression($buf, ',');
                        $attr = new TagAttribute($name->getPosition(), $name, $value);
                    }
                    $attrs[] = $attr;
                }

                $buf->skipWs();
                if ($buf->match('/}/A')) {
                    break;
                }

                $buf->skipWs();
                if (!$buf->match('/,\s*/A')) {
                    $this->syntaxErrorExpected($buf, "',' or '}'");
                }
                // allow line break after comma
                if ($buf->isEol()) {
                    $buf->nextLine();
                    $buf->skipWs();
                }
            } while (true);
        }

        return $attrs;
    }

    protected function parseTagAttributesHtml(Buffer $buf)
    {
        $attrs = array();

        if ($buf->match('/\(\s*/A')) {
            do {
                if ($expr = $this->parseInterpolation($buf)) {
                    $attrs[] = new TagAttributeInterpolation($expr->getPosition(), $expr);
                } elseif ($buf->match('/[\w+:-]+/A', $match)) {
                    $name = new Text($match['pos'][0], $match[0]);

                    if (!$buf->match('/\s*=\s*/A')) {
                        $value = null;
                    } else {
                        $value = $this->parseAttrExpression($buf, ' ');
                    }

                    $attr = new TagAttribute($name->getPosition(), $name, $value);
                    $attrs[] = $attr;

                } else {
                    $this->syntaxErrorExpected($buf, 'html attribute name or #{interpolation}');
                }

                if ($buf->match('/\s*\)/A')) {
                    break;
                }
                if (!$buf->match('/\s+/A')) {
                    if (!$buf->isEol()) {
                        $this->syntaxErrorExpected($buf, "' ', ')' or end of line");
                    }
                }

                // allow line break
                if ($buf->isEol()) {
                    $buf->nextLine();
                    $buf->skipWs();
                }

            } while (true);
        }

        return $attrs;
    }

    protected function parseTagAttributesObject(Buffer $buf)
    {
        $nodes = array();
        $attrs = array();

        if (!$buf->match('/\[\s*/A', $match)) {
            return $attrs;
        }

        $pos = $match['pos'][0];

        do {
            if ($buf->match('/\s*\]\s*/A')) {
                break;
            }

            list($expr, $pos) = $this->parseExpression($buf, ',\\]');
            $nodes[] = new Insert($pos, $expr);

            if ($buf->match('/\s*\]\s*/A')) {
                break;
            } elseif (!$buf->match('/\s*,\s*/A')) {
                $this->syntaxErrorExpected($buf, "',' or ']'");
            }

        } while (true);

        list ($object, $prefix) = array_pad($nodes, 2, null);

        if (!$object) {
            return $attrs;
        }

        $class = new ObjectRefClass($pos, $object, $prefix);
        $id = new ObjectRefId($pos, $object, $prefix);

        $name = new Text($pos, 'class');
        $attrs[] = new TagAttribute($pos, $name, $class);

        $name = new Text($pos, 'id');
        $attrs[] = new TagAttribute($pos, $name, $id);

        return $attrs;
    }

    protected function parseAttrExpression(Buffer $buf, $delims)
    {
        $sub = clone $buf;

        list($expr, $pos) = $this->parseExpression($buf, $delims);

        // hack to return a parsed string or symbol instead of an expression
        // if the whole expression can be parsed as string or symbol.

        if (preg_match('/"/A', $expr)) {
            try {
                $string = $this->parseInterpolatedString($sub);
                if ($sub->getColumn() >= $buf->getColumn()) {
                    $buf->eatChars($sub->getColumn() - $buf->getColumn());

                    return $string;
                }
            } catch (SyntaxErrorException $e) {
            }
        } elseif (preg_match('/:/A', $expr)) {
            try {
                $sym = $this->parseSymbol($sub);
                if ($sub->getColumn() >= $buf->getColumn()) {
                    $buf->eatChars($sub->getColumn() - $buf->getColumn());

                    return $sym;
                }
            } catch (SyntaxErrorException $e) {
            }
        }

        return new Insert($pos, $expr);
    }

    protected function parseExpression(Buffer $buf, $delims)
    {
        // matches everything until a delimiter is found
        // delimiters are allowed inside quoted strings,
        // {}, and () (recursive)

        $re = "/(?P<expr>(?:

                # anything except \", ', (), {}, []
                (?:[^(){}\[\]\"\'\\\\$delims]+(?=(?P>expr)))
                |(?:[^(){}\[\]\"\'\\\\ $delims]+)

                # double quoted string
                | \"(?: [^\"\\\\]+ | \\\\[\#\"\\\\] )*\"

                # single quoted string
                | '(?: [^'\\\\]+ | \\\\[\#'\\\\] )*'

                # { ... } pair
                | \{ (?: (?P>expr) | [ $delims] )* \}

                # ( ... ) pair
                | \( (?: (?P>expr) | [ $delims] )* \)

                # [ ... ] pair
                | \[ (?: (?P>expr) | [ $delims] )* \]
            )+)/xA";

        if ($buf->match($re, $match)) {
            return array($match[0], $match['pos'][0]);
        }

        $this->syntaxErrorExpected($buf, 'target language expression');
    }

    protected function parseSymbol(Buffer $buf)
    {
        if (!$buf->match('/:(\w+)/A', $match)) {
            $this->syntaxErrorExpected($buf, 'symbol');
        }

        return new Text($match['pos'][0], $match[1]);
    }

    protected function parseInterpolatedString(Buffer $buf, $quoted = true)
    {
        if ($quoted && !$buf->match('/"/A', $match)) {
            $this->syntaxErrorExpected($buf, 'double quoted string');
        }

        $node = new InterpolatedString($buf->getPosition());

        if ($quoted) {
            $stringRegex = '/(
                    [^\#"\\\\]+           # anything without hash or " or \
                    |\\\\(?:["\\\\]|\#\{) # or escaped quote slash or hash followed by {
                    |\#(?!\{)             # or hash, but not followed by {
                )+/Ax';
        } else {
            $stringRegex = '/(
                    [^\#\\\\]+          # anything without hash or \
                    |\\\\(?:\#\{|[^\#]) # or escaped hash followed by { or anything without hash
                    |\#(?!\{)           # or hash, but not followed by {
                )+/Ax';
        }

        do {
            if ($buf->match($stringRegex, $match)) {
                $text = $match[0];
                if ($quoted) {
                    // strip slashes
                    $text = preg_replace('/\\\\(["\\\\])/', '\\1', $match[0]);
                }
                // strip back slash before hash followed by {
                $text = preg_replace('/\\\\\#\{/', '#{', $text);
                $text = new Text($match['pos'][0], $text);
                $node->addChild($text);
            } elseif ($expr = $this->parseInterpolation($buf)) {
                $node->addChild($expr);
            } elseif ($quoted && $buf->match('/"/A')) {
                break;
            } elseif (!$quoted && $buf->match('/$/A')) {
                break;
            } else {
                $this->syntaxErrorExpected($buf, 'string or #{...}');
            }
        } while (true);

        // ensure that the InterpolatedString has at least one child
        if (0 === count($node->getChilds())) {
            $text = new Text($buf->getPosition(), '');
            $node->addChild($text);
        }

        return $node;
    }

    protected function parseInterpolation(Buffer $buf)
    {
        // This matches an interpolation:
        // #{ expr... }
        $exprRegex = '/
            \#\{(?P<insert>(?P<expr>
                # do not allow {}"\' in expr
                [^\{\}"\']+
                # allow balanced {}
                | \{ (?P>expr)* \}
                # allow balanced \'
                | \'([^\'\\\\]+|\\\\[\'\\\\])*\'
                # allow balanced "
                | "([^"\\\\]+|\\\\["\\\\])*"
            )+)\}
            /AxU';

        if ($buf->match($exprRegex, $match)) {
            return new Insert($match['pos']['insert'], $match['insert']);
        }
    }

    protected function parseNestableStatement(Buffer $buf)
    {
        if ($buf->match('/([&!]?)(==?|~)\s*/A', $match)) {

            if ($match[2] == '==') {
                $node = $this->parseInterpolatedString($buf, false);
            } else {
                $node = new Insert($match['pos'][0], $buf->getLine());
            }

            if ($match[1] == '&') {
                $node->getEscaping()->setEnabled(true);
            } elseif ($match[1] == '!') {
                $node->getEscaping()->setEnabled(false);
            }

            $buf->skipWs();

            return $node;
        }

        if (null !== $comment = $this->parseComment($buf)) {
            return $comment;
        }

        if ('\\' === $buf->peekChar()) {
            $buf->eatChar();
        }

        if (strlen(trim($buf->getLine())) > 0) {
            return $this->parseInterpolatedString($buf, false);
        }
    }

    protected function parseFilter(Buffer $buf)
    {
        if (!$buf->match('/:(.*)/A', $match)) {
            return null;
        }

        $node = new Filter($match['pos'][0], $match[1]);

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
            $buf->eatChars(strlen($indent));
            $str = $this->parseInterpolatedString($buf, false);
            $node->addChild(new Statement($str->getPosition(), $str));
        }

        return $node;
    }

    protected function syntaxErrorExpected(Buffer $buf, $expected)
    {
        $unexpected = $buf->peekChar();
        if ($unexpected) {
            $unexpected = "'$unexpected'";
        } else {
            $unexpected = 'end of line';
        }
        $msg = sprintf("Unexpected %s, expected %s", $unexpected, $expected);
        $this->syntaxError($buf, $msg);
    }

    protected function syntaxError(Buffer $buf, $msg)
    {
        $this->column = $buf->getColumn();
        $this->lineno = $buf->getLineno();

        $msg = sprintf('%s in %s on line %d, column %d',
            $msg, $this->filename, $this->lineno, $this->column);

        throw new SyntaxErrorException($msg);
    }

    public function getColumn()
    {
        return $this->column;
    }

    public function getLineno()
    {
        return $this->lineno;
    }

    public function getFilename()
    {
        return $this->filename;
    }

}
