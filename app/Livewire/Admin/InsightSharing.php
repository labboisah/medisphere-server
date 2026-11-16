<?php

namespace App\Livewire\Admin;

use App\Services\InsightCategoryCatalog;
use App\Services\InsightDailyAggregateSource;
use App\Services\InsightLocalPreview;
use App\Services\InsightReleaseReadiness;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.modern')]
class InsightSharing extends Component
{
    public string $previewCategory = '';

    public string $previewDate = '';

    public bool $previewDistinctPatients = false;

    #[Locked]
    public ?array $previewResult = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->is_installation_admin, 403);
    }

    public function mount(): void
    {
        $this->previewDate = now(config('app.timezone'))->subDay()->toDateString();
    }

    public function updatedPreviewCategory(): void
    {
        $this->previewResult = null;
    }

    public function updatedPreviewDate(): void
    {
        $this->previewResult = null;
    }

    public function updatedPreviewDistinctPatients(): void
    {
        $this->previewResult = null;
    }

    public function preview(InsightCategoryCatalog $catalog, InsightLocalPreview $preview): void
    {
        $this->previewResult = null;
        $categories = $this->previewCategories($catalog->availableForInstallation());
        $validated = $this->validate([
            'previewCategory' => ['required', Rule::in(array_column($categories, 'key'))],
            'previewDate' => ['required', 'date_format:Y-m-d', 'before:today'],
        ]);
        $this->previewResult = $preview->calculate($validated['previewCategory'], $validated['previewDate'], $this->previewDistinctPatients);
    }

    /**
     * @param  list<array{key: string, label: string, description: string}>|null  $categories
     * @return list<array{key: string, label: string, description: string}>
     */
    private function previewCategories(?array $categories): array
    {
        $supported = app(InsightDailyAggregateSource::class)->supportedCategories();

        return array_values(array_filter(
            $categories ?? [],
            fn (array $category): bool => in_array($category['key'], $supported, true),
        ));
    }

    public function render()
    {
        $catalog = app(InsightCategoryCatalog::class);
        $categories = $catalog->availableForInstallation();
        $previewCategories = $this->previewCategories($categories);
        if ($this->previewResult !== null && ! in_array($this->previewResult['category'], array_column($previewCategories, 'key'), true)) {
            $this->previewResult = null;
        }

        return view('components.admin.insight-sharing', [
            'categories' => $categories,
            'previewCategories' => $previewCategories,
            'releaseReadiness' => app(InsightReleaseReadiness::class)->status(),
        ]);
    }
}
