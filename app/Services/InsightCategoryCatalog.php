<?php

namespace App\Services;

use KernelBridge\LicensingClient\Services\LicenseCacheService;

class InsightCategoryCatalog
{
    public function __construct(
        private readonly LicenseCacheService $cache,
        private readonly OperationalHealthSource $healthSource,
    ) {}

    /**
     * Null means the installation's central license is not available for verification.
     *
     * @return list<array{key: string, label: string, description: string}>|null
     */
    public function availableForInstallation(): ?array
    {
        if (! config('central_licensing.enabled') || ! $this->cache->hasUsableLicense()) {
            return null;
        }

        return $this->forModules($this->healthSource->enabledModules());
    }

    /**
     * These are proposed report categories, not authorization to collect or share data.
     *
     * @param  list<string>  $modules
     * @return list<array{key: string, label: string, description: string}>
     */
    public function forModules(array $modules): array
    {
        $catalog = [
            ['patients', 'Patients', 'New patient registrations by registration date.', ['patient_records']],
            ['visits', 'Patient visits', 'Patient visits by visit date.', ['patient_records']],
            ['prescriptions', 'Prescriptions', 'Prescription activity. The reporting date and source require review.', ['clinical_care']],
            ['care', 'Care', 'Number of drug charts by date recorded.', ['clinical_care']],
            ['consultations', 'Consultations', 'Activity for services named consultation. The date, matching and source require review.', ['doctor', 'specialist']],
            ['investigations', 'Investigation results', 'Finalized investigation results by finalization date. The source requires review.', ['laboratory', 'radiology']],
            ['labour_services', 'Labour services', 'Requested measure: all labour services. The source and counting rules require review.', ['clinical_care']],
            ['surgery', 'Surgery-category services', 'Requested measure: services in the surgery category. The source, category mapping and counting rules require review.', ['clinical_care']],
            ['admissions', 'Admissions', 'PatientAdmission records by admission date; status rules require review.', ['clinical_care']],
            ['labours', 'Labours', 'Activity for services named labor. The date, matching and source require review.', ['maternity']],
            ['labour_episodes', 'Labour episodes', 'Labour records by onset date. Distinct from services named labor.', ['maternity']],
            ['deliveries', 'Deliveries', 'Delivery records by delivery date, without outcome breakdown.', ['maternity']],
            ['postnatal_examinations', 'Postnatal examinations', 'Postnatal examination records by examination date.', ['maternity']],
            ['antenatal', 'ANC consultations', 'Antenatal consultations by consultation date. The source requires review.', ['maternity']],
            ['newborns', 'Newborns', 'Newborn records by birth date. The source requires review.', ['maternity']],
            ['medicines', 'Medicines', 'Count of distinct medicines available in stock. Stock availability requires review.', ['pharmacy']],
            ['medicine_stock', 'Medicine stock', 'Number of medicine units available in stock. Units and stock availability require review.', ['pharmacy']],
            ['consumable_stock', 'Consumable stock', 'Laboratory consumables used and remaining in stock. Units and sources require review.', ['department_management']],
            ['sales', 'Sales', 'Number of pharmacy transactions. The date and eligible transaction types require review.', ['pharmacy']],
            ['finance', 'Finance', 'Registered revenue and expenses by transaction date. Financial definitions require review.', ['finance']],
            ['revenue', 'Revenue', 'Registered revenue by transaction date. Amount and status definitions require review.', ['finance']],
            ['expenses', 'Expenses', 'Registered expenses by transaction date. Amount and status definitions require review.', ['finance']],
            ['bills', 'Bills', 'Number and amounts of registered bills by transaction date. Amount and status definitions require review.', ['billing']],
            ['payments', 'Payments', 'Number and amounts of registered payments by transaction date. Amount and status definitions require review.', ['billing']],
            ['staff', 'Users and staff', 'Number of users. Active status and reporting date require review.', ['access_control']],
        ];

        $result = [];
        $definitions = app(InsightMetricCatalog::class)->definitions();
        foreach ($catalog as [$key, $label, $description, $requiredModules]) {
            if (isset($definitions[$key])) {
                $requiredModules = [$definitions[$key]['module']];
            }
            if (array_intersect($requiredModules, $modules) !== []) {
                $result[] = compact('key', 'label', 'description');
            }
        }

        return $result;
    }
}
