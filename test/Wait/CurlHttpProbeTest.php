<?php

namespace ConductorAppOrchestrationTest\Wait;

use ConductorAppOrchestration\Wait\CurlHttpProbe;
use PHPUnit\Framework\TestCase;

/**
 * CTAP-2145. The real request, against PHP's built-in web server: the status and body come back,
 * the method, headers and body go out, and a refused connection is a result rather than an
 * exception.
 */
class CurlHttpProbeTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        $router = sys_get_temp_dir() . '/ctap-2145-router-' . getmypid() . '.php';
        file_put_contents($router, <<<'PHP'
            <?php
            $status = (int) ($_GET['status'] ?? 200);
            http_response_code($status);
            header('Content-Type: application/json');
            echo json_encode([
                'method' => $_SERVER['REQUEST_METHOD'],
                'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
                'body' => file_get_contents('php://input'),
            ]);
            PHP);

        self::$port = self::freePort();
        // The image's ini can print a startup deprecation into the response, which sends the headers
        // before the router sets the status; keep the server's own messages out of the body
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'display_startup_errors=0', '-S', '127.0.0.1:' . self::$port, $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );

        // Ready when it accepts a connection
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);

                return;
            }
            usleep(100000);
        }

        self::fail('The built-in web server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(sys_get_temp_dir() . '/ctap-2145-router-' . getmypid() . '.php');
    }

    public function testReturnsTheStatusAndBody(): void
    {
        $result = (new CurlHttpProbe())->probe('GET', $this->url('?status=503'), [], null, 5, true);

        $this->assertSame(503, $result->status);
        $this->assertSame('GET', json_decode($result->body, true)['method']);
        $this->assertNull($result->error);
    }

    public function testSendsTheMethodHeadersAndBody(): void
    {
        $result = (new CurlHttpProbe())->probe(
            'POST',
            $this->url('/graphql'),
            ['Content-Type' => 'application/json'],
            '{"query":"{ __typename }"}',
            5,
            true
        );

        $this->assertSame(200, $result->status);
        $this->assertSame(
            ['method' => 'POST', 'contentType' => 'application/json', 'body' => '{"query":"{ __typename }"}'],
            json_decode($result->body, true)
        );
    }

    public function testARefusedConnectionIsAResultWithTheReason(): void
    {
        $result = (new CurlHttpProbe())->probe('GET', 'http://127.0.0.1:' . self::freePort() . '/', [], null, 5, true);

        $this->assertNull($result->status);
        // libcurl words the refusal differently across versions; the prefix is stable
        $this->assertStringStartsWith('Failed to connect to 127.0.0.1', (string) $result->error);
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    /** A port nothing listens on at the moment it is returned. */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
