<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

final class BreadcrumbCollector
{
    /** @var list<Breadcrumb> */
    private array $crumbs = [];

    public function __construct(private readonly int $max = 50)
    {
    }

    public function add(Breadcrumb $breadcrumb): void
    {
        $this->crumbs[] = $breadcrumb;
        $overflow = \count($this->crumbs) - $this->max;
        if ($overflow > 0) {
            $this->crumbs = \array_slice($this->crumbs, $overflow);
        }
    }

    /** @return list<Breadcrumb> */
    public function all(): array
    {
        return $this->crumbs;
    }

    public function clear(): void
    {
        $this->crumbs = [];
    }
}
