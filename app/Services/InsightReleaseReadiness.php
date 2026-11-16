<?php

namespace App\Services;

final class InsightReleaseReadiness
{
    /**
     * There is no aggregate release implementation; this status cannot be
     * changed by configuration or an administrator action.
     *
     * @return array{status: 'pending_privacy_review', can_release: false, blockers: list<string>}
     */
    public function status(): array
    {
        return [
            'status' => 'pending_privacy_review',
            'can_release' => false,
            'blockers' => [
                'A privacy specialist must review the release design and set the lifetime privacy budget.',
                'Privacy accounting, noise generation and correction and overlapping-report protections are not implemented.',
                'Hospital consent, withdrawal and Platform deletion are not implemented.',
                'Aggregate delivery, Platform ingestion and reporting are not implemented.',
            ],
        ];
    }
}
