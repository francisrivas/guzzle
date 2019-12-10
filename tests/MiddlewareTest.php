<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\Test\TestLogger;

class MiddlewareTest extends TestCase
{
    public function testAddsCookiesToRequests()
    {
        $jar = new CookieJar();
        $m = Middleware::cookies($jar);
        $h = new MockHandler(
            [
                function (RequestInterface $request) {
                    return new Response(200, [
                        'Set-Cookie' => (string) new SetCookie([
                            'Name'   => 'name',
                            'Value'  => 'value',
                            'Domain' => 'foo.com'
                        ])
                    ]);
                }
            ]
        );
        $f = $m($h);
        $f(new Request('GET', 'http://foo.com'), ['cookies' => $jar])->wait();
        self::assertCount(1, $jar);
    }

    public function testThrowsExceptionOnHttpClientError()
    {
        $m = Middleware::httpErrors();
        $h = new MockHandler([new Response(404)]);
        $f = $m($h);
        $p = $f(new Request('GET', 'http://foo.com'), ['http_errors' => true]);
        self::assertSame('pending', $p->getState());

        $this->expectException(\GuzzleHttp\Exception\ClientException::class);
        $p->wait();
    }

    public function testThrowsExceptionOnHttpServerError()
    {
        $m = Middleware::httpErrors();
        $h = new MockHandler([new Response(500)]);
        $f = $m($h);
        $p = $f(new Request('GET', 'http://foo.com'), ['http_errors' => true]);
        self::assertSame('pending', $p->getState());

        $this->expectException(\GuzzleHttp\Exception\ServerException::class);
        $p->wait();
    }

    /**
     * @dataProvider getHistoryUseCases
     */
    public function testTracksHistory($container)
    {
        $m = Middleware::history($container);
        $h = new MockHandler([new Response(200), new Response(201)]);
        $f = $m($h);
        $p1 = $f(new Request('GET', 'http://foo.com'), ['headers' => ['foo' => 'bar']]);
        $p2 = $f(new Request('HEAD', 'http://foo.com'), ['headers' => ['foo' => 'baz']]);
        $p1->wait();
        $p2->wait();
        self::assertCount(2, $container);
        self::assertSame(200, $container[0]['response']->getStatusCode());
        self::assertSame(201, $container[1]['response']->getStatusCode());
        self::assertSame('GET', $container[0]['request']->getMethod());
        self::assertSame('HEAD', $container[1]['request']->getMethod());
        self::assertSame('bar', $container[0]['options']['headers']['foo']);
        self::assertSame('baz', $container[1]['options']['headers']['foo']);
    }

    public function getHistoryUseCases()
    {
        return [
            [[]],                // 1. Container is an array
            [new \ArrayObject()] // 2. Container is an ArrayObject
        ];
    }

    public function testTracksHistoryForFailures()
    {
        $container = [];
        $m = Middleware::history($container);
        $request = new Request('GET', 'http://foo.com');
        $h = new MockHandler([new RequestException('error', $request)]);
        $f = $m($h);
        $f($request, [])->wait(false);
        self::assertCount(1, $container);
        self::assertSame('GET', $container[0]['request']->getMethod());
        self::assertInstanceOf(RequestException::class, $container[0]['error']);
    }

    public function testTapsBeforeAndAfter()
    {
        $calls = [];
        $m = function ($handler) use (&$calls) {
            return function ($request, $options) use ($handler, &$calls) {
                $calls[] = '2';
                return $handler($request, $options);
            };
        };

        $m2 = Middleware::tap(
            function (RequestInterface $request, array $options) use (&$calls) {
                $calls[] = '1';
            },
            function (RequestInterface $request, array $options, PromiseInterface $p) use (&$calls) {
                $calls[] = '3';
            }
        );

        $h = new MockHandler([new Response()]);
        $b = new HandlerStack($h);
        $b->push($m2);
        $b->push($m);
        $comp = $b->resolve();
        $p = $comp(new Request('GET', 'http://foo.com'), []);
        self::assertSame('123', implode('', $calls));
        self::assertInstanceOf(PromiseInterface::class, $p);
        self::assertSame(200, $p->wait()->getStatusCode());
    }

    public function testMapsRequest()
    {
        $h = new MockHandler([
            function (RequestInterface $request, array $options) {
                self::assertSame('foo', $request->getHeaderLine('Bar'));
                return new Response(200);
            }
        ]);
        $stack = new HandlerStack($h);
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            return $request->withHeader('Bar', 'foo');
        }));
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com'), []);
        self::assertInstanceOf(PromiseInterface::class, $p);
    }

    public function testMapsResponse()
    {
        $h = new MockHandler([new Response(200)]);
        $stack = new HandlerStack($h);
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
            return $response->withHeader('Bar', 'foo');
        }));
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com'), []);
        $p->wait();
        self::assertSame('foo', $p->wait()->getHeaderLine('Bar'));
    }

    public function testLogsRequestsAndResponses()
    {
        $h = new MockHandler([new Response(200)]);
        $stack = new HandlerStack($h);
        $logger = new TestLogger();
        $formatter = new MessageFormatter();
        $stack->push(Middleware::log($logger, $formatter));
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com'), []);
        $p->wait();
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('"PUT / HTTP/1.1" 200', $logger->records[0]['message']);
    }

    public function testRewindStreamAfterLogsRequestsAndResponses()
    {
        $h = new MockHandler([new Response(200, [], 'bar')]);
        $stack = new HandlerStack($h);
        $logger = new TestLogger();
        $formatter = new MessageFormatter(MessageFormatter::DEBUG);
        $stack->push(Middleware::log($logger, $formatter));
        $comp = $stack->resolve();
        $p = $comp(new Request('GET', 'http://www.google.com', [], 'foo=bar'), []);
        $response = $p->wait();
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('bar', $logger->records[0]['message']);
        self::assertStringContainsString('foo', $h->getLastRequest()->getBody()->getContents());
        self::assertStringContainsString('bar', $response->getBody()->getContents());
    }

    public function testLogDoesNotRewindStreamWhenRequestsAndResponsesNotUsed()
    {
        $h = new MockHandler([new Response(200)]);
        $stack = new HandlerStack($h);
        $logger = new TestLogger();
        $formatter = new MessageFormatter();
        $stack->push(Middleware::log($logger, $formatter));
        $comp = $stack->resolve();
        $p = $comp(new Request('GET', 'http://www.google.com'), []);
        $response = $p->wait();
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('"GET / HTTP/1.1" 200', $logger->records[0]['message']);
        self::assertFalse($h->getLastRequest()->getBody()->eof());
        self::assertFalse($response->getBody()->eof());
    }

    public function testLogUsesEmptyStreamWhenRequestBodyIsNotSeekable()
    {
        $stream = fopen('php://temp', 'wb');
        fwrite($stream, 'foobar');
        fseek($stream, 2);

        $mockBody = $this->getMockBuilder(Stream::class)
            ->setConstructorArgs([$stream])
            ->setMethods(['isSeekable'])
            ->getMock();

        $mockBody->expects($this->any())
            ->method('isSeekable')
            ->willReturn(false);

        $h = new MockHandler([new Response(200, [], 'bar')]);
        $stack = new HandlerStack($h);
        $logger = new TestLogger();
        $formatter = new MessageFormatter(MessageFormatter::DEBUG);
        $stack->push(Middleware::log($logger, $formatter));
        $comp = $stack->resolve();
        $request = new Request('GET', 'http://www.google.com', [], $mockBody);
        $p = $comp($request, []);
        $p->wait();
        self::assertCount(1, $logger->records);
        self::assertStringNotContainsString('foobar', $logger->records[0]['message']);
        self::assertEquals(2, $request->getBody()->tell());

        fclose($stream);
    }

    public function testLogUsesEmptyStreamWhenResponseBodyIsNotSeekable()
    {
        $stream = fopen('php://temp', 'wb');
        fwrite($stream, 'foobar');
        fseek($stream, 3);

        $mockBody = $this->getMockBuilder(Stream::class)
            ->setConstructorArgs([$stream])
            ->setMethods(['isSeekable'])
            ->getMock();

        $mockBody->expects($this->any())
            ->method('isSeekable')
            ->willReturn(false);

        $h = new MockHandler([new Response(200, [], $mockBody)]);
        $stack = new HandlerStack($h);
        $logger = new TestLogger();
        $formatter = new MessageFormatter(MessageFormatter::DEBUG);
        $stack->push(Middleware::log($logger, $formatter));
        $comp = $stack->resolve();
        $p = $comp(new Request('GET', 'http://www.google.com'), []);
        $response = $p->wait();
        self::assertCount(1, $logger->records);
        self::assertStringNotContainsString('foobar', $logger->records[0]['message']);
        self::assertEquals(3, $response->getBody()->tell());

        fclose($stream);
    }

    public function testSeeksStreamToTheSamePositionAfterLogsRequestsAndResponses()
    {
        $h = new MockHandler([new Response(200, [], 'baz')]);
        $stack = new HandlerStack($h);
        $logger = new TestLogger();
        $formatter = new MessageFormatter(MessageFormatter::DEBUG);
        $stack->push(Middleware::log($logger, $formatter));
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $request->getBody()->seek(1);
            return $request;
        }));
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
            $response->getBody()->seek(2);
            return $response;
        }));
        $comp = $stack->resolve();
        $request = new Request('GET', 'http://www.google.com', [], 'foo=bar');
        $p = $comp($request, []);
        $response = $p->wait();
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('foo=bar', $logger->records[0]['message']);
        self::assertStringContainsString('baz', $logger->records[0]['message']);
        self::assertEquals(1, $request->getBody()->tell());
        self::assertEquals(2, $response->getBody()->tell());
    }

    public function testLogsRequestsAndResponsesCustomLevel()
    {
        $h = new MockHandler([new Response(200)]);
        $stack = new HandlerStack($h);
        $logger = new TestLogger();
        $formatter = new MessageFormatter();
        $stack->push(Middleware::log($logger, $formatter, 'debug'));
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com'), []);
        $p->wait();
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('"PUT / HTTP/1.1" 200', $logger->records[0]['message']);
        self::assertSame('debug', $logger->records[0]['level']);
    }

    public function testLogsRequestsAndErrors()
    {
        $h = new MockHandler([new Response(404)]);
        $stack = new HandlerStack($h);
        $logger = new TestLogger();
        $formatter = new MessageFormatter('{code} {error}');
        $stack->push(Middleware::log($logger, $formatter));
        $stack->push(Middleware::httpErrors());
        $comp = $stack->resolve();
        $p = $comp(new Request('PUT', 'http://www.google.com'), ['http_errors' => true]);
        $p->wait(false);
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('PUT http://www.google.com', $logger->records[0]['message']);
        self::assertStringContainsString('404 Not Found', $logger->records[0]['message']);
    }
}
