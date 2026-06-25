<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

final class Scope
{
    /** @var array<string, scalar|null> */
    private array $tags = [];

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    private BreadcrumbCollector $breadcrumbs;

    public function __construct(int $maxBreadcrumbs = 50)
    {
        $this->breadcrumbs = new BreadcrumbCollector($maxBreadcrumbs);
    }

    public function __clone(): void
    {
        $this->breadcrumbs = clone $this->breadcrumbs;
    }

    public function setTag(string $key, string|int|float|bool|null $value): void
    {
        $this->tags[$key] = $value;
    }

    /** @param array<string, mixed> $user */
    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    public function setExtra(string $key, mixed $value): void
    {
        $this->extra[$key] = $value;
    }

    public function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        $this->breadcrumbs->add($breadcrumb);
    }

    public function applyTo(Event $event): void
    {
        $event->tags = array_merge($event->tags, $this->tags);
        if ([] !== $this->user) {
            $event->context['user'] = $this->user;
        }
        if ([] !== $this->extra) {
            $event->context['extra'] = array_merge($event->context['extra'] ?? [], $this->extra);
        }
        $event->breadcrumbs = array_merge($event->breadcrumbs, $this->breadcrumbs->all());
    }

    public function clear(): void
    {
        $this->tags = [];
        $this->user = [];
        $this->extra = [];
        $this->breadcrumbs->clear();
    }
}
