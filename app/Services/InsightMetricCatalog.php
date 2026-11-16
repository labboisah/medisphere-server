<?php

namespace App\Services;

use InvalidArgumentException;

final class InsightMetricCatalog
{
    public const SCHEMA_VERSION = 1;

    /**
     * Definitions describe local previews, not approved releases or transport payloads.
     *
     * @return array<string, array{metric_key: string, module: string, source: string, event_time: string, filters: string, unit: string}>
     */
    public function definitions(): array
    {
        return [
            'patients' => ['metric_key' => 'patient_records.registrations', 'module' => 'patient_records', 'source' => 'patients', 'event_time' => 'registration_date', 'filters' => 'Exclude deleted patients.', 'unit' => 'count'],
            'visits' => ['metric_key' => 'patient_records.visits', 'module' => 'patient_records', 'source' => 'patient_visits', 'event_time' => 'visit_date', 'filters' => 'Include all statuses; exclude deleted visits and patients.', 'unit' => 'count'],
            'care' => ['metric_key' => 'clinical_care.drug_charts', 'module' => 'clinical_care', 'source' => 'drug_charts', 'event_time' => 'created_at', 'filters' => 'Exclude charts linked to deleted visits or patients.', 'unit' => 'count'],
            'admissions' => ['metric_key' => 'clinical_care.admissions', 'module' => 'clinical_care', 'source' => 'patient_admissions', 'event_time' => 'admission_date', 'filters' => 'Include all statuses; exclude deleted admissions and patients.', 'unit' => 'count'],
            'labour_episodes' => ['metric_key' => 'maternity.labour_episodes', 'module' => 'maternity', 'source' => 'labours', 'event_time' => 'labour_onset_time', 'filters' => 'Include all statuses; exclude deleted episodes and patients.', 'unit' => 'count'],
            'deliveries' => ['metric_key' => 'maternity.deliveries', 'module' => 'maternity', 'source' => 'deliveries', 'event_time' => 'delivery_date_time', 'filters' => 'Include all statuses; exclude deleted deliveries and patients.', 'unit' => 'count'],
            'postnatal_examinations' => ['metric_key' => 'maternity.postnatal_examinations', 'module' => 'maternity', 'source' => 'postnatal_examinations', 'event_time' => 'examination_date_time', 'filters' => 'Exclude deleted examinations and patients.', 'unit' => 'count'],
        ];
    }

    /** @return array{metric_key: string, module: string, source: string, event_time: string, filters: string, unit: string} */
    public function definition(string $category): array
    {
        return $this->definitions()[$category] ?? throw new InvalidArgumentException('Unsupported insight category.');
    }
}
