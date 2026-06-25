<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Context;

interface ContextCollectorInterface
{
    /** @return array<string, mixed> */
    public function collect(): array;
}
