<?php

namespace Guzzle\Tests\Service\Command;

use Guzzle\Http\Utils;
use Guzzle\Http\Message\PostFile;
use Guzzle\Http\Message\EntityEnclosingRequest;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Client;
use Guzzle\Service\Command\OperationCommand;
use Guzzle\Service\Command\Factory\ServiceDescriptionFactory;
use Guzzle\Service\Description\Operation;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Command\LocationVisitor\Request\HeaderVisitor;
use Guzzle\Service\Command\DefaultRequestSerializer;

/**
 * @covers Guzzle\Service\Command\OperationCommand
 */
class OperationCommandTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testHasRequestSerializer()
    {
        $operation = new OperationCommand();
        $a = $operation->getRequestSerializer();
        $b = new DefaultRequestSerializer();
        $operation->setRequestSerializer($b);
        $this->assertNotSame($a, $operation->getRequestSerializer());
    }

    public function testPreparesRequestUsingSerializer()
    {
        $op = new OperationCommand(array(), new Operation());
        $op->setClient(new Client());
        $s = $this->getMockBuilder('Guzzle\Service\Command\RequestSerializerInterface')
            ->setMethods(array('prepare'))
            ->getMockForAbstractClass();
        $s->expects($this->once())
            ->method('prepare')
            ->will($this->returnValue(new EntityEnclosingRequest('POST', 'http://foo.com')));
        $op->setRequestSerializer($s);
        $op->prepare();
    }

    public function testParsesResponsesWithResponseParser()
    {
        $op = new OperationCommand(array(), new Operation());
        $p = $this->getMockBuilder('Guzzle\Service\Command\ResponseParserInterface')
            ->setMethods(array('parse'))
            ->getMockForAbstractClass();
        $p->expects($this->once())
            ->method('parse')
            ->will($this->returnValue(array('foo' => 'bar')));
        $op->setResponseParser($p);
        $op->setClient(new Client());
        $request = $op->prepare();
        $request->setResponse(new Response(200), true);
        $this->assertEquals(array('foo' => 'bar'), $op->execute());
    }

    public function testParsesResponsesUsingModelParserWhenMatchingModelIsFound()
    {
        $description = new ServiceDescription(array(
            'operations' => array('foo' => array('responseClass' => 'bar')),
            'models' => array(
                'bar' => array()
            )
        ));
        $op = new OperationCommand(array(),$description->getOperation('foo'));
        $op->setClient(new Client());
        $request = $op->prepare();
        $request->setResponse(new Response(200, array(
            'Content-Type' => 'application/xml'
        ), '<Foo><Baz>Bar</Baz></Foo>'), true);
        $this->assertEquals(array(
            'Baz' => 'Bar'
        ), $op->execute());
    }
}
