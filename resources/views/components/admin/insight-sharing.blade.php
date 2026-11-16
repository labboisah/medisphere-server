<div>
    <x-ui.page title="Insight synchronization" subtitle="Review proposed aggregate report categories for this installation.">
        <x-ui.card title="Insight synchronization is not available yet">
            <p class="mb-3">Aggregate synchronization and Platform deletion are not implemented. No categories can be enabled and no patient, clinical or financial insights are being sent.</p>
            <p>These are proposed categories, not database models. Individual records will remain on the server. Sensitive clinical reports will require small-group and overlapping-report protections before synchronization is available.</p>
        </x-ui.card>
        <x-ui.card title="Release status: Pending privacy review">
            <p class="mb-3" role="status">Aggregate releases and corrections are disabled. The release has not been reviewed or approved.</p>
            <ul class="list-disc space-y-1 pl-5">
                @foreach($releaseReadiness['blockers'] as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        </x-ui.card>
        <x-ui.card title="Local daily preview" subtitle="Review provisional calculations on this server only. Nothing is saved or sent to the Platform.">
            @if(count($previewCategories) === 0)
                <p>No local previews are available for the installation's verified enabled modules.</p>
            @else
                <p class="mb-3 text-sm text-med-muted">Preview results are not approved for release; disclosure protections are incomplete. Counts exclude deleted patients, visits and admissions. Visits and admissions include all statuses; admissions use PatientAdmission admission dates, and care counts drug charts by creation date. A result is suppressed when it involves fewer than five distinct patients, including zero.</p>
                <p class="mb-3 text-sm text-med-muted">Maternity previews count non-deleted labour episodes by onset, deliveries by delivery date, and postnatal examinations by examination date. They include all statuses and exclude deleted patients; they do not include outcome breakdowns or newborn records. Labour episodes are separate from services named labor.</p>
                <form wire:submit="preview" class="space-y-3">
                    <label class="block">
                        <span class="block text-sm font-medium">Category</span>
                        <select wire:model="previewCategory" required>
                            <option value="">Select a category</option>
                            @foreach($previewCategories as $category)
                                <option value="{{ $category['key'] }}">{{ $category['label'] }}</option>
                            @endforeach
                        </select>
                        @error('previewCategory') <span class="block text-sm text-med-danger" role="alert">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="block text-sm font-medium">Completed local day ({{ config('app.timezone') }})</span>
                        <input type="date" wire:model="previewDate" required>
                        @error('previewDate') <span class="block text-sm text-med-danger" role="alert">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="previewDistinctPatients">
                        Preview distinct patients instead of event count
                    </label>
                    <p class="text-sm text-med-muted">The distinct-patient preview caps each patient's contribution at one for this metric and day. It is exact local data, not a differentially private release.</p>
                    <button type="submit" class="button button-primary" wire:loading.attr="disabled" wire:target="preview">Preview locally</button>
                </form>
                @if($previewResult !== null)
                    <p class="mt-4" role="status"><strong>{{ $previewResult['label'] }} &middot; {{ $previewResult['date'] }}:</strong>
                        @if($previewResult['status'] === 'suppressed')
                            Suppressed (fewer than five distinct patients).
                        @else
                            {{ $previewResult['value'] }}
                        @endif
                    </p>
                    <p class="mt-1 text-sm text-med-muted">Local definition v{{ $previewResult['schema_version'] }} &middot; {{ $previewResult['metric_key'] }} &middot; {{ $previewResult['unit'] }}. Source: {{ $previewResult['source'] }}.{{ $previewResult['event_time'] }}. {{ $previewResult['filters'] }}</p>
                @endif
            @endif
        </x-ui.card>
        <x-ui.card title="Categories for this installation" subtitle="The list is based on verified enabled modules, not the package name alone.">
            @if($categories === null)
                <p role="status">A usable verified KernelBridge license is required to determine the available categories. Verify this installation's license and try again.</p>
                <a href="{{ route('kernelbridge.license.show') }}" class="mt-3 inline-block font-semibold underline">Activate or verify this computer</a>
            @elseif(count($categories) === 0)
                <p>No proposed report categories match the installation's verified enabled modules.</p>
            @else
                <ul class="space-y-3">
                    @foreach($categories as $category)
                        <li wire:key="insight-category-{{ $category['key'] }}" class="flex flex-wrap items-start justify-between gap-3 rounded border border-med-line p-4">
                            <div>
                                <h3 class="font-semibold">{{ $category['label'] }}</h3>
                                <p class="mt-1 text-sm text-med-muted">{{ $category['description'] }}</p>
                                <p id="insight-status-{{ $category['key'] }}" class="mt-1 text-sm text-med-muted">Not available yet; synchronization is off.</p>
                            </div>
                            <label class="inline-flex items-center gap-2 text-sm text-med-muted">
                                <input type="checkbox" disabled aria-describedby="insight-status-{{ $category['key'] }}">
                                Enable synchronization for {{ $category['label'] }}
                            </label>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </x-ui.page>
</div>
