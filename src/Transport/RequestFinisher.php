<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Transport;

interface RequestFinisher
{
    public function isAvailable(): bool;

    public function finish(): void;
}
