<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Transport;

final class NoopRequestFinisher implements RequestFinisher
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function finish(): void
    {
    }
}
