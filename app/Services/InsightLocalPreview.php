<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class InsightLocalPreview
{
    public function __construct(
        private readonly InsightCategoryCatalog $categories,
        private readonly InsightMetricCatalog $metrics,
        private readonly InsightDailyAggregateSource $source,
    ) {}

    /**
     * This result is for local preview only; it is not safe for synchronization.
     *
     * @return array{category: string, date: string, status: 'available'|'suppressed', value: int|null, label: string, metric_key: string, schema_version: int, unit: string, source: string, event_time: string, filters: string}
     */
    public function calculate(string $category, string $date, bool $distinctPatients = false): array
    {
        $definition = $this->metrics->definition($category);
        $available = $this->categories->availableForInstallation();
        if ($available === null) {
            throw new RuntimeException('A usable verified license is required for local insight previews.');
        }
        $item = collect($available)->firstWhere('key', $category);
        if ($item === null) {
            throw new InvalidArgumentException('This insight category is not available for the installation.');
        }

        $result = $distinctPatients
            ? $this->source->calculateDistinctPatients($category, $date)
            : $this->source->calculate($category, $date);

        return $result + [
            'label' => $item['label'],
            'metric_key' => $definition['metric_key'],
            'schema_version' => InsightMetricCatalog::SCHEMA_VERSION,
            'unit' => $distinctPatients ? 'patients' : $definition['unit'],
            'source' => $definition['source'],
            'event_time' => $definition['event_time'],
            'filters' => $definition['filters'],
        ];
    }
}
