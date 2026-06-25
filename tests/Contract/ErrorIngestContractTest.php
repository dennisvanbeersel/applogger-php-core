<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Tests\Contract;

use ApplicationLogger\Sdk\ErrorPayloadFactory;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Severity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ErrorIngestContractTest extends TestCase
{
    private const DTO_PATH = __DIR__.'/../../../../../../src/Dto/ErrorIngestDto.php';

    protected function setUp(): void
    {
        if (!is_file(self::DTO_PATH)) {
            self::markTestSkipped('Server DTO not present (split-published checkout).');
        }
        require_once self::DTO_PATH;
    }

    public function testFactoryOutputPassesServerValidation(): void
    {
        $event = Event::fromThrowable(
            new \RuntimeException('boom'),
            Severity::Error,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'production',
            'app@1.0.0',
        );
        $payload = (new ErrorPayloadFactory())->fromEvent($event);

        /** @phpstan-ignore class.notFound */
        $dto = \App\Dto\ErrorIngestDto::fromArray($payload);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate($dto);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testOomMinimalEventPassesServerValidation(): void
    {
        // Mirrors the Phase-3 OOM minimal event: blank-ish + line 0 must still be accepted.
        $event = new Event(
            type: '', message: '', file: '', line: 0,
            level: Severity::Fatal,
            environment: 'production', release: null,
            timestamp: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            partial: true,
        );
        $payload = (new ErrorPayloadFactory())->fromEvent($event);

        /** @phpstan-ignore class.notFound */
        $dto = \App\Dto\ErrorIngestDto::fromArray($payload);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate($dto);

        self::assertCount(0, $violations, (string) $violations);
    }
}
