<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Collection;
use Guzzle\Service\Inspector;
use Guzzle\Service\Exception\ValidationException;

/**
 * @covers Guzzle\Service\Inspector
 */
class InspectorTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Inspector::setTypeValidation
     * @covers Guzzle\Service\Inspector::getTypeValidation
     */
    public function testTypeValidationCanBeToggled()
    {
        $i = new Inspector();
        $this->assertTrue($i->getTypeValidation());
        $i->setTypeValidation(false);
        $this->assertFalse($i->getTypeValidation());
    }

    /**
     * @cover Guzzle\Service\Inspector::__constructor
     */
    public function testRegistersDefaultFilters()
    {
        $inspector = new Inspector();
        $this->assertNotEmpty($inspector->getRegisteredConstraints());
    }

    /**
     * @covers Guzzle\Service\Inspector
     * @expectedException InvalidArgumentException
     */
    public function testChecksFilterValidity()
    {
        Inspector::getInstance()->getConstraint('foooo');
    }

    /**
     * @covers Guzzle\Service\Inspector::registerConstraint
     * @covers Guzzle\Service\Inspector::getConstraint
     * @covers Guzzle\Service\Inspector::getRegisteredConstraints
     */
    public function testRegistersCustomConstraints()
    {
        $constraintClass = 'Guzzle\Validation\Ip';

        Inspector::getInstance()->registerConstraint('mock', $constraintClass);
        Inspector::getInstance()->registerConstraint('mock_2', $constraintClass, array(
           'version' => '4'
        ));

        $this->assertArrayHasKey('mock', Inspector::getInstance()->getRegisteredConstraints());
        $this->assertArrayHasKey('mock_2', Inspector::getInstance()->getRegisteredConstraints());

        $this->assertInstanceOf($constraintClass, Inspector::getInstance()->getConstraint('mock'));
        $this->assertInstanceOf($constraintClass, Inspector::getInstance()->getConstraint('mock_2'));

        $this->assertTrue(Inspector::getInstance()->validateConstraint('mock', '192.168.16.121'));
        $this->assertTrue(Inspector::getInstance()->validateConstraint('mock_2', '10.1.1.0'));
    }
}
