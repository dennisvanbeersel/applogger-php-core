<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk\Transport;

final class FastCgiRequestFinisher implements RequestFinisher
{
    public function isAvailable(): bool
    {
        return \function_exists('fastcgi_finish_request');
    }

    public function finish(): void
    {
        if ($this->isAvailable()) {
            fastcgi_finish_request();
        }
    }
}
