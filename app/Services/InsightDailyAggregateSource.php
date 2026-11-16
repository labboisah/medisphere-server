<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class InsightDailyAggregateSource
{
    /** @return list<string> */
    public function supportedCategories(): array
    {
        return array_keys(app(InsightMetricCatalog::class)->definitions());
    }

    /**
     * Calculate a completed local calendar day without returning identifiers.
     * This alone is not sufficient to authorize, persist or transmit a report.
     *
     * @return array{category: string, date: string, status: 'available'|'suppressed', value: int|null}
     */
    public function calculate(string $category, string $date): array
    {
        return $this->calculateCount($category, $date, false);
    }

    /**
     * A local preview of one contribution per patient. This is not a private
     * release: no noise, privacy accounting or correction protection is applied.
     *
     * @return array{category: string, date: string, status: 'available'|'suppressed', value: int|null}
     */
    public function calculateDistinctPatients(string $category, string $date): array
    {
        return $this->calculateCount($category, $date, true);
    }

    /** @return array{category: string, date: string, status: 'available'|'suppressed', value: int|null} */
    private function calculateCount(string $category, string $date, bool $distinctPatients): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        if (! $day || $day->format('Y-m-d') !== $date || $day->greaterThanOrEqualTo(CarbonImmutable::today(config('app.timezone')))) {
            throw new InvalidArgumentException('A completed calendar day is required.');
        }

        $query = match ($category) {
            'patients' => DB::table('patients')
                ->whereNull('patients.deleted_at')
                ->where('patients.registration_date', '>=', $day)
                ->where('patients.registration_date', '<', $day->addDay()),
            'visits' => DB::table('patient_visits')
                ->join('patients', 'patients.id', '=', 'patient_visits.patient_id')
                ->whereNull('patient_visits.deleted_at')
                ->whereNull('patients.deleted_at')
                ->where('patient_visits.visit_date', '>=', $day)
                ->where('patient_visits.visit_date', '<', $day->addDay()),
            'care' => DB::table('drug_charts')
                ->join('prescription_items', 'prescription_items.id', '=', 'drug_charts.prescription_item_id')
                ->join('prescriptions', 'prescriptions.id', '=', 'prescription_items.prescription_id')
                ->join('patient_visits', 'patient_visits.id', '=', 'prescriptions.patient_visit_id')
                ->join('patients', 'patients.id', '=', 'patient_visits.patient_id')
                ->whereNull('patient_visits.deleted_at')
                ->whereNull('patients.deleted_at')
                ->where('drug_charts.created_at', '>=', $day)
                ->where('drug_charts.created_at', '<', $day->addDay()),
            'admissions' => DB::table('patient_admissions')
                ->join('patients', 'patients.id', '=', 'patient_admissions.patient_id')
                ->whereNull('patient_admissions.deleted_at')
                ->whereNull('patients.deleted_at')
                ->where('patient_admissions.admission_date', '>=', $day)
                ->where('patient_admissions.admission_date', '<', $day->addDay()),
            'labour_episodes' => $this->patientEventQuery('labours', 'labour_onset_time', $day),
            'deliveries' => $this->patientEventQuery('deliveries', 'delivery_date_time', $day),
            'postnatal_examinations' => $this->patientEventQuery('postnatal_examinations', 'examination_date_time', $day),
            default => throw new InvalidArgumentException('Unsupported insight category.'),
        };
        $personColumn = match ($category) {
            'visits', 'care' => 'patient_visits.patient_id',
            default => 'patients.id',
        };
        $counts = $this->counts($query, $personColumn);
        $available = $counts->people >= 5;

        return [
            'category' => $category,
            'date' => $date,
            'status' => $available ? 'available' : 'suppressed',
            'value' => $available ? (int) ($distinctPatients ? $counts->people : $counts->events) : null,
        ];
    }

    private function counts(Builder $query, string $personColumn): object
    {
        return $query->selectRaw("COUNT(*) as events, COUNT(DISTINCT {$personColumn}) as people")->first();
    }

    private function patientEventQuery(string $table, string $dateColumn, CarbonImmutable $day): Builder
    {
        return DB::table($table)
            ->join('patients', 'patients.id', '=', $table.'.patient_id')
            ->whereNull($table.'.deleted_at')
            ->whereNull('patients.deleted_at')
            ->where($table.'.'.$dateColumn, '>=', $day)
            ->where($table.'.'.$dateColumn, '<', $day->addDay());
    }
}
