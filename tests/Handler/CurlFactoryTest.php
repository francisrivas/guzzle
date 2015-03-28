<?php

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Tests\Server;
use GuzzleHttp\Handler;
use GuzzleHttp\Psr7;

/**
 * @covers \GuzzleHttp\Handler\CurlFactory
 */
class CurlFactoryTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl']);
    }

    public static function tearDownAfterClass()
    {
        unset($_SERVER['_curl'], $_SERVER['curl_test']);
    }

    public function testCreatesCurlHandle()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, [
                'Foo' => 'Bar',
                'Baz' => 'bam',
                'Content-Length' => 2,
            ], 'hi')
        ]);
        $stream = Psr7\stream_for();
        $request = new Psr7\Request('PUT', Server::$url, ['Hi' => ' 123'], 'testing');
        $f = new Handler\CurlFactory();
        $result = $f($request, ['sink' => $stream]);
        $this->assertInternalType('array', $result);
        $this->assertInternalType('resource', $result[0]);
        $this->assertInternalType('array', $result[1]);
        $this->assertSame($stream, $result[2]);
        curl_close($result[0]);
        $this->assertEquals('PUT', $_SERVER['_curl'][CURLOPT_CUSTOMREQUEST]);
        $this->assertEquals(
            'http://127.0.0.1:8126/',
            $_SERVER['_curl'][CURLOPT_URL]
        );
        // Sends via post fields when the request is small enough
        $this->assertEquals('testing', $_SERVER['_curl'][CURLOPT_POSTFIELDS]);
        $this->assertEquals(0, $_SERVER['_curl'][CURLOPT_RETURNTRANSFER]);
        $this->assertEquals(0, $_SERVER['_curl'][CURLOPT_HEADER]);
        $this->assertEquals(150, $_SERVER['_curl'][CURLOPT_CONNECTTIMEOUT]);
        $this->assertInstanceOf('Closure', $_SERVER['_curl'][CURLOPT_HEADERFUNCTION]);
        if (defined('CURLOPT_PROTOCOLS')) {
            $this->assertEquals(
                CURLPROTO_HTTP | CURLPROTO_HTTPS,
                $_SERVER['_curl'][CURLOPT_PROTOCOLS]
            );
        }
        $this->assertContains('Expect:', $_SERVER['_curl'][CURLOPT_HTTPHEADER]);
        $this->assertContains('Accept:', $_SERVER['_curl'][CURLOPT_HTTPHEADER]);
        $this->assertContains('Content-Type:', $_SERVER['_curl'][CURLOPT_HTTPHEADER]);
        $this->assertContains('Hi: 123', $_SERVER['_curl'][CURLOPT_HTTPHEADER]);
        $this->assertContains('Host: 127.0.0.1:8126', $_SERVER['_curl'][CURLOPT_HTTPHEADER]);
    }

    public function testSendsHeadRequests()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $response = $a(new Psr7\Request('HEAD', Server::$url), []);
        $response->wait();
        $this->assertEquals(true, $_SERVER['_curl'][CURLOPT_NOBODY]);
        $checks = [CURLOPT_WRITEFUNCTION, CURLOPT_READFUNCTION, CURLOPT_FILE, CURLOPT_INFILE];
        foreach ($checks as $check) {
            $this->assertArrayNotHasKey($check, $_SERVER['_curl']);
        }
        $this->assertEquals('HEAD', Server::received()[0]->getMethod());
    }

    public function testCanAddCustomCurlOptions()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $req = new Psr7\Request('GET', Server::$url);
        $a($req, ['curl' => [CURLOPT_LOW_SPEED_LIMIT => 10]]);
        $this->assertEquals(10, $_SERVER['_curl'][CURLOPT_LOW_SPEED_LIMIT]);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage SSL CA bundle not found: /does/not/exist
     */
    public function testValidatesVerify()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['verify' => '/does/not/exist']);
    }

    public function testCanSetVerifyToFile()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', 'http://foo.com'), ['verify' => __FILE__]);
        $this->assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_CAINFO]);
        $this->assertEquals(2, $_SERVER['_curl'][CURLOPT_SSL_VERIFYHOST]);
        $this->assertEquals(true, $_SERVER['_curl'][CURLOPT_SSL_VERIFYPEER]);
    }

    public function testAddsVerifyAsTrue()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['verify' => true]);
        $this->assertEquals(2, $_SERVER['_curl'][CURLOPT_SSL_VERIFYHOST]);
        $this->assertEquals(true, $_SERVER['_curl'][CURLOPT_SSL_VERIFYPEER]);
        $this->assertArrayNotHasKey(CURLOPT_CAINFO, $_SERVER['_curl']);
    }

    public function testCanDisableVerify()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['verify' => false]);
        $this->assertEquals(0, $_SERVER['_curl'][CURLOPT_SSL_VERIFYHOST]);
        $this->assertEquals(false, $_SERVER['_curl'][CURLOPT_SSL_VERIFYPEER]);
    }

    public function testAddsProxy()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['proxy' => 'http://bar.com']);
        $this->assertEquals('http://bar.com', $_SERVER['_curl'][CURLOPT_PROXY]);
    }

    public function testAddsViaScheme()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), [
            'proxy' => ['http' => 'http://bar.com', 'https' => 'https://t'],
        ]);
        $this->assertEquals('http://bar.com', $_SERVER['_curl'][CURLOPT_PROXY]);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage SSL private key not found: /does/not/exist
     */
    public function testValidatesSslKey()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['ssl_key' => '/does/not/exist']);
    }

    public function testAddsSslKey()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['ssl_key' => __FILE__]);
        $this->assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_SSLKEY]);
    }

    public function testAddsSslKeyWithPassword()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['ssl_key' => [__FILE__, 'test']]);
        $this->assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_SSLKEY]);
        $this->assertEquals('test', $_SERVER['_curl'][CURLOPT_SSLKEYPASSWD]);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage SSL certificate not found: /does/not/exist
     */
    public function testValidatesCert()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['cert' => '/does/not/exist']);
    }

    public function testAddsCert()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['cert' => __FILE__]);
        $this->assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_SSLCERT]);
    }

    public function testAddsCertWithPassword()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['cert' => [__FILE__, 'test']]);
        $this->assertEquals(__FILE__, $_SERVER['_curl'][CURLOPT_SSLCERT]);
        $this->assertEquals('test', $_SERVER['_curl'][CURLOPT_SSLCERTPASSWD]);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage progress client option must be callable
     */
    public function testValidatesProgress()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), ['progress' => 'foo']);
    }

    public function testEmitsDebugInfoToStream()
    {
        $res = fopen('php://memory', 'r+');
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $response = $a(new Psr7\Request('HEAD', Server::$url), ['debug' => $res]);
        $response->wait();
        rewind($res);
        $output = str_replace("\r", '', stream_get_contents($res));
        $this->assertContains("> HEAD / HTTP/1.1", $output);
        $this->assertContains("< HTTP/1.1 200", $output);
        fclose($res);
    }

    public function testEmitsProgressToFunction()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $called = [];
        $request = new Psr7\Request('HEAD', Server::$url);
        $response = $a($request, [
            'progress' => function () use (&$called) {
                $called[] = func_get_args();
            },
        ]);
        $response->wait();
        $this->assertNotEmpty($called);
        foreach ($called as $call) {
            $this->assertCount(4, $call);
        }
    }

    private function addDecodeResponse($withEncoding = true)
    {
        $content = gzencode('test');
        $headers = ['Content-Length' => strlen($content)];
        if ($withEncoding) {
            $headers['Content-Encoding'] = 'gzip';
        }
        $response  = new Psr7\Response(200, $headers, $content);
        Server::flush();
        Server::enqueue([$response]);
        return $content;
    }

    public function testDecodesGzippedResponses()
    {
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => true]);
        $response = $response->wait();
        $this->assertEquals('test', (string) $response->getBody());
        $this->assertEquals('', $_SERVER['_curl'][CURLOPT_ENCODING]);
        $sent = Server::received()[0];
        $this->assertFalse($sent->hasHeader('Accept-Encoding'));
    }

    public function testDecodesGzippedResponsesWithHeader()
    {
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url, ['Accept-Encoding' => 'gzip']);
        $response = $handler($request, ['decode_content' => true]);
        $response = $response->wait();
        $this->assertEquals('gzip', $_SERVER['_curl'][CURLOPT_ENCODING]);
        $sent = Server::received()[0];
        $this->assertEquals('gzip', $sent->getHeader('Accept-Encoding'));
        $this->assertEquals('test', (string) $response->getBody());
    }

    public function testDoesNotForceDecode()
    {
        $content = $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => false]);
        $response = $response->wait();
        $sent = Server::received()[0];
        $this->assertFalse($sent->hasHeader('Accept-Encoding'));
        $this->assertEquals($content, (string) $response->getBody());
    }

    public function testProtocolVersion()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $a = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url, [], null, '1.0');
        $a($request, []);
        $this->assertEquals(CURL_HTTP_VERSION_1_0, $_SERVER['_curl'][CURLOPT_HTTP_VERSION]);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesSink()
    {
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $handler($request, ['sink' => true]);
    }

    public function testSavesToStream()
    {
        $stream = fopen('php://memory', 'r+');
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, [
            'decode_content' => true,
            'sink'           => $stream,
        ]);
        $response->wait();
        rewind($stream);
        $this->assertEquals('test', stream_get_contents($stream));
    }

    public function testSavesToGuzzleStream()
    {
        $stream = Psr7\stream_for();
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, [
            'decode_content' => true,
            'sink'           => $stream,
        ]);
        $response->wait();
        $this->assertEquals('test', (string) $stream);
    }

    public function testSavesToFileOnDisk()
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'testfile');
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('GET', Server::$url);
        $response = $handler($request, [
            'decode_content' => true,
            'sink'           => $tmpfile,
        ]);
        $response->wait();
        $this->assertEquals('test', file_get_contents($tmpfile));
        unlink($tmpfile);
    }

    public function testDoesNotAddMultipleContentLengthHeaders()
    {
        $this->addDecodeResponse();
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('PUT', Server::$url, ['Content-Length' => 3], 'foo');
        $response = $handler($request, []);
        $response->wait();
        $sent = Server::received()[0];
        $this->assertEquals(3, $sent->getHeader('Content-Length'));
        $this->assertFalse($sent->hasHeader('Transfer-Encoding'));
        $this->assertEquals('foo', (string) $sent->getBody());
    }

    public function testSendsPostWithNoBodyOrDefaultContentType()
    {
        Server::flush();
        Server::enqueue([new Psr7\Response()]);
        $handler = new Handler\CurlMultiHandler();
        $request = new Psr7\Request('POST', Server::$url);
        $response = $handler($request, []);
        $response->wait();
        $received = Server::received()[0];
        $this->assertEquals('POST', $received->getMethod());
        $this->assertFalse($received->hasHeader('content-type'));
        $this->assertSame('0', $received->getHeader('content-length'));
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage The connection unexpectedly failed
     */
    public function testFailsWhenNoResponseAndNoBody()
    {
        $req = new Psr7\Request('PUT', Server::$url, [], new Psr7\NoSeekStream(Psr7\stream_for()));
        $bd = Psr7\stream_for('');
        $fn = function () {};
        $p = Handler\CurlFactory::createResponse($fn, $req, [], [], [], $bd);
        $p->wait();
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage but attempting to rewind the request body failed
     */
    public function testFailsWhenCannotRewindRetry()
    {
        $body = new Psr7\NoSeekStream(Psr7\stream_for('foo'));
        $req = new Psr7\Request('PUT', Server::$url, [], $body);
        $fn = function () {};
        $rbody = Psr7\stream_for();
        $res = Handler\CurlFactory::createResponse($fn, $req, [], [], [], $rbody);
        $res->wait();
    }

    public function testRetriesWhenBodyCanBeRewound()
    {
        $callHandler = $called = false;

        $fn = function ($r, $options) use (&$callHandler) {
            $callHandler = true;
            return \GuzzleHttp\Promise\promise_for(new Psr7\Response());
        };

        $bd = Psr7\FnStream::decorate(Psr7\stream_for('test'), [
            'rewind' => function () use (&$called) {
                $called = true;
                return true;
            }
        ]);

        $req = new Psr7\Request('PUT', Server::$url, [], $bd);
        $rbd = Psr7\stream_for();
        $res = Handler\CurlFactory::createResponse($fn, $req, [], [], [], $rbd);
        $res = $res->wait();
        $this->assertTrue($callHandler);
        $this->assertTrue($called);
        $this->assertEquals('200', $res->getStatusCode());
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage The cURL request was retried 3 times
     */
    public function testFailsWhenRetryMoreThanThreeTimes()
    {
        $call = 0;
        $fn = function ($request, $options) use (&$mock, &$call) {
            $call++;
            $bd = Psr7\stream_for();
            return Handler\CurlFactory::createResponse($mock, $request, $options, [], [], $bd);
        };
        $mock = new Handler\MockHandler([$fn, $fn, $fn]);
        $p = $mock(new Psr7\Request('PUT', Server::$url, [], 'test'), []);
        $p->wait(false);
        $this->assertEquals(3, $call);
        $p->wait(true);
    }

    public function testHandles100Continue()
    {
        Server::flush();
        Server::enqueue([
            new Psr7\Response(200, ['Test' => 'Hello', 'Content-Length' => 4], 'test'),
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [
            'Expect' => '100-Continue'
        ], 'test');
        $handler = new Handler\CurlMultiHandler();
        $response = $handler($request, [])->wait();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals('Hello', $response->getHeader('Test'));
        $this->assertEquals('4', $response->getHeader('Content-Length'));
        $this->assertEquals('test', (string) $response->getBody());
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ConnectException
     */
    public function testCreatesConnectException()
    {
        $m = new \ReflectionMethod('GuzzleHttp\Handler\CurlFactory', 'createErrorResponse');
        $m->setAccessible(true);
        $response = $m->invoke(
            null,
            function () {},
            new Psr7\Request('GET', Server::$url),
            [],
            [
                'err_message' => 'foo',
                'curl' => [
                    'errno' => CURLE_COULDNT_CONNECT,
                ]
            ]
        );
        $response->wait();
    }

    public function testAddsTimeouts()
    {
        $f = new Handler\CurlFactory();
        $f(new Psr7\Request('GET', Server::$url), [
            'timeout'         => 0.1,
            'connect_timeout' => 0.2
        ]);
        $this->assertEquals(100, $_SERVER['_curl'][CURLOPT_TIMEOUT_MS]);
        $this->assertEquals(200, $_SERVER['_curl'][CURLOPT_CONNECTTIMEOUT_MS]);
    }

    public function testAddsStreamingBody()
    {
        $f = new Handler\CurlFactory();
        $bd = Psr7\FnStream::decorate(Psr7\stream_for('foo'), [
            'getSize' => function () {
                return null;
            }
        ]);
        $request = new Psr7\Request('PUT', Server::$url, [], $bd);
        $f($request, []);
        $this->assertEquals(1, $_SERVER['_curl'][CURLOPT_UPLOAD]);
        $this->assertTrue(is_callable($_SERVER['_curl'][CURLOPT_READFUNCTION]));
    }
}