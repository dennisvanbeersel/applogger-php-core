<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Diagnostic;

use ApplicationLogger\Sdk\Diagnostic\DiagnosticCheck;
use ApplicationLogger\Sdk\Diagnostic\DiagnosticReport;
use ApplicationLogger\Sdk\Diagnostic\Diagnostics;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    /** @return array<string, string> name => status */
    private function statuses(DiagnosticReport $r): array
    {
        $map = [];
        foreach ($r->checks as $c) {
            $map[$c->name] = $c->status;
        }

        return $map;
    }

    public function testMissingDsnFailsDsnAndWarnsTransport(): void
    {
        $report = (new Diagnostics())->run([], sendTestEvent: false);
        $s = $this->statuses($report);
        self::assertSame(DiagnosticCheck::FAIL, $s['DSN']);
        self::assertSame(DiagnosticCheck::WARN, $s['Transport']); // no DSN → telemetry disabled, not a hard fail
    }

    public function testValidDsnPassesDsnAndTransport(): void
    {
        $report = (new Diagnostics())->run(['dsn' => 'https://applogger.eu/0xP'], sendTestEvent: false);
        $s = $this->statuses($report);
        self::assertSame(DiagnosticCheck::OK, $s['DSN']);
        // symfony/http-client is a dev dep, so an HttpTransport is discoverable → OK.
        self::assertSame(DiagnosticCheck::OK, $s['Transport']);
    }

    public function testLoggingInfoWhenUnconfiguredOkWhenConfigured(): void
    {
        $bare = $this->statuses((new Diagnostics())->run(['dsn' => 'https://applogger.eu/0xP'], sendTestEvent: false));
        self::assertSame(DiagnosticCheck::INFO, $bare['Logging']);

        $configured = $this->statuses((new Diagnostics())->run([
            'dsn' => 'https://applogger.eu/0xP',
            'log_endpoint' => 'https://app.logs.applogger.eu',
            'log_token' => 'sk_log_x',
        ], sendTestEvent: false));
        self::assertSame(DiagnosticCheck::OK, $configured['Logging']);
    }

    public function testCacheCheckPresentAndNotFailingForTempDir(): void
    {
        $s = $this->statuses((new Diagnostics())->run([
            'dsn' => 'https://applogger.eu/0xP',
            'cache_dir' => sys_get_temp_dir().'/applogger-diag-'.uniqid('', true),
        ], sendTestEvent: false));
        self::assertArrayHasKey('Cache', $s);
        self::assertNotSame(DiagnosticCheck::FAIL, $s['Cache']); // temp dir parent is writable
    }

    public function testNeverThrowsAndOmitsTestEventWhenDisabled(): void
    {
        $report = (new Diagnostics())->run(['dsn' => 'https://applogger.eu/0xP'], sendTestEvent: false);
        foreach ($report->checks as $c) {
            self::assertNotSame('Test event', $c->name, 'test event must be omitted when sendTestEvent=false');
        }
    }

    /** @return array<string, DiagnosticCheck> name => check */
    private function checks(DiagnosticReport $r): array
    {
        $map = [];
        foreach ($r->checks as $c) {
            $map[$c->name] = $c;
        }

        return $map;
    }

    /**
     * Returns [Diagnostics $d, \stdClass $spy] where $spy->request is the captured RequestInterface after run().
     *
     * @return array{Diagnostics, \stdClass}
     */
    private function diagnosticsWithStatus(int $status): array
    {
        $spy = new \stdClass();
        $spy->request = null;

        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
        $client = new class($status, $spy) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private int $status, private \stdClass $spy)
            {
            }

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->spy->request = $request;

                return new \Nyholm\Psr7\Response($this->status);
            }
        };

        return [new Diagnostics($client, $psr17, $psr17), $spy];
    }

    public function testTestEventAcceptedOn202(): void
    {
        [$diag] = $this->diagnosticsWithStatus(202);
        $report = $diag->run(['dsn' => 'https://applogger.eu/0xP']);
        $check = $this->checks($report)['Test event'];
        self::assertSame(DiagnosticCheck::OK, $check->status);
        self::assertStringContainsString('202', $check->detail);
        self::assertTrue($report->isHealthy());
    }

    public function testTestEventAcceptedOn409(): void
    {
        [$diag] = $this->diagnosticsWithStatus(409);
        $check = $this->checks($diag->run(['dsn' => 'https://applogger.eu/0xP']))['Test event'];
        self::assertSame(DiagnosticCheck::OK, $check->status);
    }

    public function testTestEventFailsOn401(): void
    {
        [$diag] = $this->diagnosticsWithStatus(401);
        $report = $diag->run(['dsn' => 'https://applogger.eu/0xP']);
        $check = $this->checks($report)['Test event'];
        self::assertSame(DiagnosticCheck::FAIL, $check->status);
        self::assertStringContainsString('401', $check->detail);
        self::assertFalse($report->isHealthy());
    }

    public function testTestEventNeverThrowsOnTransportError(): void
    {
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
        $throwing = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new class('boom') extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
            }
        };
        $check = $this->checks((new Diagnostics($throwing, $psr17, $psr17))->run(['dsn' => 'https://applogger.eu/0xP']))['Test event'];
        self::assertSame(DiagnosticCheck::FAIL, $check->status);
    }

    public function testTestEventSkippedWithoutDsn(): void
    {
        [$diag] = $this->diagnosticsWithStatus(202);
        $check = $this->checks($diag->run([]))['Test event'];
        self::assertSame(DiagnosticCheck::WARN, $check->status);
    }

    public function testTestEventRequestIsPiiFreeAndWellFormed(): void
    {
        [$diag, $spy] = $this->diagnosticsWithStatus(202);
        $diag->run(['dsn' => 'https://applogger.eu/0xP']);

        /** @var \Psr\Http\Message\RequestInterface $request */
        $request = $spy->request;
        self::assertNotNull($request, 'spy must have captured a request');

        // Correct endpoint
        self::assertStringEndsWith('/api/v1/errors', (string) $request->getUri());

        // Required headers present
        self::assertNotSame('', $request->getHeaderLine('X-Application-Logger-DSN'));
        self::assertNotSame('', $request->getHeaderLine('X-Application-Logger-Sdk-Version'));
        self::assertNotSame('', $request->getHeaderLine('X-Idempotency-Key'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        // Decode body and verify the synthetic file field (F3 privacy fix)
        $bodyStr = (string) $request->getBody();
        /** @var array<string, mixed> $body */
        $body = json_decode($bodyStr, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('applogger-diagnostic', $body['file'], 'file field must be the synthetic constant, not an absolute path');

        // Must NOT contain a real filesystem path component from this package
        self::assertFalse(
            str_contains($bodyStr, \DIRECTORY_SEPARATOR.'src'.\DIRECTORY_SEPARATOR.'Diagnostic'),
            'request body must not contain a real install path segment',
        );
        // Must NOT contain the package root directory
        self::assertStringNotContainsString(\dirname(__DIR__, 2), $bodyStr, 'request body must not contain the package root path');
    }
}
