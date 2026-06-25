<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Diagnostic;

use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\ErrorPayloadFactory;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Log\LogClientFactory;
use ApplicationLogger\Sdk\Log\LogConfig;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\Severity;
use ApplicationLogger\Sdk\Transport\HttpTransport;
use ApplicationLogger\Sdk\Transport\TransportFactory;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Runs a "did it work?" diagnostic over an SDK configuration: DSN, transport
 * discovery, cache backend, log pipeline, and (optionally) a real test event.
 * Total — never throws (Rule #1).
 */
final class Diagnostics
{
    public function __construct(
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?RequestFactoryInterface $requestFactory = null,
        private readonly ?StreamFactoryInterface $streamFactory = null,
        private readonly string $sdkVersion = '1.0.0',
    ) {
    }

    /** @param array<string, mixed> $options */
    public function run(array $options, bool $sendTestEvent = true): DiagnosticReport
    {
        $checks = [];
        try {
            $opts = Options::fromArray($options);
            $checks[] = $this->checkDsn($opts);
            $checks[] = $this->checkTransport($opts);
            $checks[] = $this->checkCache($opts);
            $checks[] = $this->checkLogging($options);
            if ($sendTestEvent) {
                $checks[] = $this->checkTestEvent($opts);
            }
        } catch (\Throwable $t) {
            $checks[] = new DiagnosticCheck('Diagnostics', DiagnosticCheck::FAIL, 'internal error: '.$t::class);
        }

        return new DiagnosticReport($checks);
    }

    private function checkDsn(Options $opts): DiagnosticCheck
    {
        if (null === $opts->dsn) {
            return new DiagnosticCheck('DSN', DiagnosticCheck::FAIL, 'missing or invalid (expected scheme://host/project-id, no user@)');
        }

        return new DiagnosticCheck('DSN', DiagnosticCheck::OK, 'host='.$opts->dsn->host.' project='.$opts->dsn->projectId);
    }

    private function checkTransport(Options $opts): DiagnosticCheck
    {
        $transport = TransportFactory::create($opts);
        if ($transport instanceof HttpTransport) {
            return new DiagnosticCheck('Transport', DiagnosticCheck::OK, 'HTTP transport active');
        }
        if (null === $opts->dsn) {
            return new DiagnosticCheck('Transport', DiagnosticCheck::WARN, 'telemetry disabled (no DSN)');
        }

        return new DiagnosticCheck('Transport', DiagnosticCheck::FAIL, 'degraded to NullTransport — no PSR-18 client discoverable (install symfony/http-client or a php-http client)');
    }

    private function checkCache(Options $opts): DiagnosticCheck
    {
        $dir = $opts->cacheDir;
        $writable = is_dir($dir) ? is_writable($dir) : is_writable(\dirname($dir));
        if ($writable) {
            return new DiagnosticCheck('Cache', DiagnosticCheck::OK, 'filesystem at '.$dir);
        }

        return new DiagnosticCheck('Cache', DiagnosticCheck::WARN, 'cache dir not writable: '.$dir.' (breaker/rate-limit state will not persist)');
    }

    /** @param array<string, mixed> $options */
    private function checkLogging(array $options): DiagnosticCheck
    {
        $config = LogConfig::fromArray($options);
        if (!$config->isEnabled()) {
            return new DiagnosticCheck('Logging', DiagnosticCheck::INFO, 'not configured (set log_endpoint + log_token)');
        }
        if (null === LogClientFactory::create($config, new DataScrubber([]))) {
            return new DiagnosticCheck('Logging', DiagnosticCheck::FAIL, 'configured but client unavailable (no PSR-18 client?)');
        }

        return new DiagnosticCheck('Logging', DiagnosticCheck::OK, 'endpoint='.(string) $config->endpoint);
    }

    private function checkTestEvent(Options $opts): DiagnosticCheck
    {
        if (null === $opts->dsn) {
            return new DiagnosticCheck('Test event', DiagnosticCheck::WARN, 'skipped (no DSN)');
        }

        try {
            $requestFactory = $this->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
            $streamFactory = $this->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
            $client = $this->httpClient ?? $this->discoverBoundedClient($opts, $streamFactory);
        } catch (\Throwable) {
            return new DiagnosticCheck('Test event', DiagnosticCheck::FAIL, 'no PSR-18 client discoverable');
        }

        try {
            $event = new Event(
                'Diagnostic',
                'applogger diagnose test event',
                'applogger-diagnostic',   // synthetic file — never leak the absolute install path (privacy)
                0,
                Severity::Info,
                $opts->environment,
                $opts->release,
                new \DateTimeImmutable(),
            );
            $body = json_encode((new ErrorPayloadFactory())->fromEvent($event), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

            $request = $requestFactory
                ->createRequest('POST', $opts->dsn->ingestUrl('/api/v1/errors'))
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Application-Logger-DSN', $opts->dsn->raw)
                ->withHeader('X-Application-Logger-Sdk-Version', $this->sdkVersion)
                ->withHeader('X-Idempotency-Key', $this->idempotencyKey())
                ->withBody($streamFactory->createStream($body));

            if (null !== $opts->apiKey) {
                $request = $request->withHeader('X-Application-Logger-Api-Key', $opts->apiKey);
            }

            $start = microtime(true);
            $status = $client->sendRequest($request)->getStatusCode();
            $ms = (int) round((microtime(true) - $start) * 1000);

            if (202 === $status || 409 === $status) {
                return new DiagnosticCheck('Test event', DiagnosticCheck::OK, 'accepted (HTTP '.$status.', '.$ms.'ms)');
            }

            return new DiagnosticCheck('Test event', DiagnosticCheck::FAIL, 'rejected (HTTP '.$status.')');
        } catch (\Throwable $t) {
            return new DiagnosticCheck('Test event', DiagnosticCheck::FAIL, 'send failed: '.$t::class);
        }
    }

    /**
     * Discover a TIMEOUT-BOUNDED PSR-18 client, mirroring TransportFactory::buildClient.
     * A bare Psr18ClientDiscovery::find() client has NO timeout, so against a dead/
     * blackholed host the diagnostic — the one tool you run when connectivity is already
     * suspect — could hang indefinitely. Prefer the budget-bounded symfony/http-client.
     */
    private function discoverBoundedClient(Options $opts, StreamFactoryInterface $streamFactory): ClientInterface
    {
        if (class_exists(\Symfony\Component\HttpClient\Psr18Client::class)) {
            $sf = \Symfony\Component\HttpClient\HttpClient::create([
                'timeout' => $opts->timeout,
                'max_duration' => $opts->timeout,
            ]);

            return new \Symfony\Component\HttpClient\Psr18Client($sf, null, $streamFactory);
        }

        return Psr18ClientDiscovery::find();
    }

    private function idempotencyKey(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr(\ord($data[6]) & 0x0F | 0x40);
        $data[8] = \chr(\ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
