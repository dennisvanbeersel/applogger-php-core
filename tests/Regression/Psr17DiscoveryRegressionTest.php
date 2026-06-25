<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Regression;

use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\Log\LogClient;
use ApplicationLogger\Sdk\Log\LogClientFactory;
use ApplicationLogger\Sdk\Log\LogConfig;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\Transport\HttpTransport;
use ApplicationLogger\Sdk\Transport\TransportFactory;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * Regression guard for the silent-telemetry-drop bug (2026-06).
 *
 * Root cause: nyholm/psr7 (the concrete PSR-17 implementation required for
 * Http\Discovery\Psr17FactoryDiscovery to succeed) was listed under require-dev
 * rather than require in sdk-core's composer.json. Consumer apps that did not
 * separately install a PSR-17 implementation got a DiscoveryFailedException at
 * runtime; both TransportFactory and LogClientFactory caught that exception
 * (Rule #1 — degrade silently) and returned NullTransport / null, causing
 * every error and every log line to be silently dropped.
 *
 * Fix: nyholm/psr7 moved to require. These two guards detect any regression
 * that pushes it (or all concrete PSR-17 implementations) back out of require.
 */
final class Psr17DiscoveryRegressionTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Guard #1 — Composer-manifest check
    //
    // This test reads the package's own composer.json and asserts that at least
    // one known concrete PSR-17 implementation appears in the "require" section
    // (not "require-dev"). It fails immediately and visibly if nyholm/psr7 (or
    // any substitute) ever slips back to require-dev, long before any runtime
    // test even runs.
    // ---------------------------------------------------------------------------

    /** @var list<non-empty-string> */
    private const array KNOWN_PSR17_IMPLEMENTATIONS = [
        'nyholm/psr7',
        'guzzlehttp/psr7',
        'laminas/laminas-diactoros',
        'slim/psr7',
        'httpsoft/http-message',
    ];

    /**
     * @throws \JsonException
     */
    public function testComposerJsonRequiresSectionContainsAConcretePsr17Implementation(): void
    {
        // This test file lives at tests/Regression/Psr17DiscoveryRegressionTest.php.
        // dirname(__DIR__, 2) walks two levels up from tests/Regression → package root.
        $composerJsonPath = \dirname(__DIR__, 2).'/composer.json';

        self::assertFileExists($composerJsonPath, 'composer.json not found at expected package root path');

        $contents = file_get_contents($composerJsonPath);
        self::assertIsString($contents, 'Could not read composer.json');

        $manifest = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest, 'composer.json decoded to a non-array value');

        $require = $manifest['require'] ?? [];
        self::assertIsArray($require, '"require" section in composer.json is not an array');

        $requireKeys = array_keys($require);
        $found = array_filter(
            self::KNOWN_PSR17_IMPLEMENTATIONS,
            static fn (string $pkg): bool => \in_array($pkg, $requireKeys, true),
        );

        self::assertNotEmpty(
            $found,
            \sprintf(
                "sdk-core's composer.json \"require\" section contains NO concrete PSR-17 HTTP-message implementation.\n"
                ."Checked for: %s\n"
                ."Found in require: %s\n\n"
                ."Without a concrete PSR-17 implementation in \"require\", Http\\Discovery\\Psr17FactoryDiscovery\n"
                ."throws DiscoveryFailedException in consumer apps that do not install one independently.\n"
                ."TransportFactory and LogClientFactory silently catch that exception and degrade to\n"
                ."NullTransport / null, dropping ALL telemetry without any visible error.\n\n"
                ."Fix: add one of the checked packages to \"require\" (not \"require-dev\").\n"
                .'The canonical choice is nyholm/psr7 ^1.8.',
                implode(', ', self::KNOWN_PSR17_IMPLEMENTATIONS),
                implode(', ', array_keys($require)) ?: '(none)',
            ),
        );
    }

    // ---------------------------------------------------------------------------
    // Guard #2 — Runtime factory build check
    //
    // These tests call the real factory create() methods with valid, non-mocked
    // inputs and assert that the returned objects are real implementations, not
    // the silent no-op fallbacks. Because nyholm/psr7 is now in require, PSR-17
    // discovery succeeds and both factories wire up properly.
    //
    // They guard against:
    //   • DSN parsing regressions (NullTransport returned for a valid DSN)
    //   • PSR-17/18 discovery regressions (NullTransport / null on DiscoveryFailedException)
    //   • LogConfig "isEnabled" regressions (null returned despite valid config)
    // ---------------------------------------------------------------------------

    public function testTransportFactoryBuildsRealHttpTransportNotNullTransport(): void
    {
        // DSN must NOT contain user-info ("key@host") — Dsn::tryParse rejects those.
        // The project-id segment is the full path component (no trailing slash, no sub-paths).
        $options = Options::fromArray([
            'dsn' => 'https://collector.example.test/proj-abc123',
            'cache_dir' => sys_get_temp_dir(),
        ]);

        $transport = TransportFactory::create($options);

        self::assertInstanceOf(
            HttpTransport::class,
            $transport,
            'TransportFactory::create() returned a NullTransport instead of HttpTransport. '
            .'This means PSR-17 or PSR-18 discovery failed at runtime, which indicates that a '
            .'concrete PSR-17 implementation is missing from composer.json "require" or that '
            .'the DSN was not parsed correctly (check Dsn::tryParse — no userinfo allowed).',
        );
    }

    public function testLogClientFactoryBuildsRealLogClientNotNull(): void
    {
        // LogConfig::isEnabled() returns true only when BOTH endpoint AND token are set.
        // log_endpoint is normalised to scheme://authority (path/query stripped).
        $config = LogConfig::fromArray([
            'log_endpoint' => 'https://logs.collector.example.test',
            'log_token' => 'sk_log_regression_test_token',
            'cache_dir' => sys_get_temp_dir(),
        ]);

        $scrubber = new DataScrubber([]);

        $client = LogClientFactory::create($config, $scrubber);

        self::assertInstanceOf(
            LogClient::class,
            $client,
            'LogClientFactory::create() returned null instead of a LogClient. '
            .'This means PSR-17 or PSR-18 discovery failed at runtime (silent degrade), '
            .'which indicates that a concrete PSR-17 implementation is missing from '
            .'composer.json "require", or that the log config was not recognised as enabled '
            .'(both log_endpoint and log_token must be non-empty strings).',
        );
    }
}
