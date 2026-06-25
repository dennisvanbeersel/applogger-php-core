<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

enum Severity: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Fatal = 'fatal';

    public function toServerLevel(): string
    {
        return $this->value;
    }

    public static function fromName(string $name): self
    {
        return match (mb_strtolower(trim($name))) {
            'debug' => self::Debug,
            'info', 'notice' => self::Info,
            'warning', 'warn' => self::Warning,
            'fatal', 'emergency', 'alert' => self::Fatal,
            default => self::Error,
        };
    }
}
