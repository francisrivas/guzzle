<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\ArrayDescriptionBuilder;

/**
 * @covers Guzzle\Service\Description\ArrayDescriptionBuilder
 */
class ArrayDescriptionBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Description\ServiceDescription::factory
     * @covers Guzzle\Service\Description\ArrayDescriptionBuilder::build
     */
    public function testAllowsDeepNestedInheritance()
    {
        $d = ServiceDescription::factory(array(
            'operations' => array(
                'abstract' => array(
                    'httpMethod' => 'GET',
                    'parameters' => array(
                        'test' => array('type' => 'string', 'required' => true)
                    )
                ),
                'abstract2' => array('uri' => '/test', 'extends' => 'abstract'),
                'concrete'  => array('extends' => 'abstract2')
            )
        ));

        $c = $d->getOperation('concrete');
        $this->assertEquals('/test', $c->getUri());
        $this->assertEquals('GET', $c->getHttpMethod());
        $params = $c->getParams();
        $param = $params['test'];
        $this->assertEquals('string', $param->getType());
        $this->assertTrue($param->getRequired());
    }

    /**
     * @covers Guzzle\Service\Description\ServiceDescription::factory
     * @covers Guzzle\Service\Description\ArrayDescriptionBuilder::build
     * @expectedException RuntimeException
     */
    public function testThrowsExceptionWhenExtendingMissingCommand()
    {
        ServiceDescription::factory(array(
            'operations' => array(
                'concrete' => array(
                    'extends' => 'missing'
                )
            )
        ));
    }

    public function testAllowsMultipleInheritance()
    {
        $description = ServiceDescription::factory(array(
            'operations' => array(
                'a' => array(
                    'httpMethod' => 'GET',
                    'parameters' => array(
                        'a1' => array(
                            'default'  => 'foo',
                            'required' => true,
                            'prepend'  => 'hi'
                        )
                    )
                ),
                'b' => array(
                    'extends' => 'a',
                    'parameters' => array(
                        'b2' => array()
                    )
                ),
                'c' => array(
                    'parameters' => array(
                        'a1' => array(
                            'default'     => 'bar',
                            'required'    => true,
                            'description' => 'test'
                        ),
                        'c3' => array()
                    )
                ),
                'd' => array(
                    'httpMethod' => 'DELETE',
                    'extends'    => array('b', 'c'),
                    'parameters' => array(
                        'test' => array()
                    )
                )
            )
        ));

        $command = $description->getOperation('d');
        $this->assertEquals('DELETE', $command->getHttpMethod());
        $this->assertContains('a1', $command->getParamNames());
        $this->assertContains('b2', $command->getParamNames());
        $this->assertContains('c3', $command->getParamNames());
        $this->assertContains('test', $command->getParamNames());

        $this->assertTrue($command->getParam('a1')->getRequired());
        $this->assertEquals('bar', $command->getParam('a1')->getDefault());
        $this->assertEquals('test', $command->getParam('a1')->getDescription());
    }
}
