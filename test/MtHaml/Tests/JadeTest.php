<?php

namespace MtHaml\Tests;

use MtHaml\Environment;

require_once __DIR__ . '/TestCase.php';

class JadeTest extends TestCase
{
    /**
     * @dataProvider getTestData
     */
    public function testJade($file)
    {
        $parts = $this->parseTestFile($file);

        $env = new Environment('twig', array());
        $html = $env->compileString($parts['JADE'], basename($file).'.jade');

        $this->assertSame($parts['EXPECT'], rtrim($html));
    }

    public function getTestData()
    {
        if (false !== $tests = getenv('JADE_SPEC_TESTS')) {
            $files = explode(' ', $tests);
        } else {
            $files = glob(__DIR__ . '/fixtures/jade/*.test');
        }

        return array_map(function ($file) {
            return array($file);
        }, $files);
    }
}
