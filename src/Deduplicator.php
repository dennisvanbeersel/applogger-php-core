<?php

declare(strict_types=1);

namespace ApplicationLogger\Sdk;

final class Deduplicator
{
    /**
     * @param list<Event> $events
     *
     * @return list<Event>
     */
    public function dedupe(array $events): array
    {
        try {
            /** @var array<string, Event> $kept */
            $kept = [];
            /** @var array<string, int> $counts */
            $counts = [];

            foreach ($events as $event) {
                $fp = $event->fingerprint();
                if (!isset($kept[$fp])) {
                    $kept[$fp] = $event;
                    $counts[$fp] = 0;
                }
                ++$counts[$fp];
            }

            foreach ($kept as $fp => $event) {
                if ($counts[$fp] > 1) {
                    $event->tags['duplicate_count'] = $counts[$fp];
                }
            }

            return array_values($kept);
        } catch (\Throwable) {
            return $events;
        }
    }
}
