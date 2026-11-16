<?php

namespace App\Http\Controllers;

use App\Exceptions\OperationalSyncEnrollmentException;
use App\Services\OperationalSyncEnrollment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use KernelBridge\LicensingClient\Services\DeploymentProfileException;

final class OperationalSyncEnrollmentController extends Controller
{
    public function __invoke(Request $request, OperationalSyncEnrollment $enrollment): RedirectResponse
    {
        abort_unless(config('operational_sync.management_enabled'), 404);
        $code = $request->input('sync_code');
        if (! is_string($code) || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/', trim($code))) {
            return redirect()->route('admin.installation')->withErrors(['sync_code' => 'Enter the sync enrollment code exactly as shown in the Platform.']);
        }
        try {
            $enrollment->enroll($request->user(), $code);
        } catch (DeploymentProfileException $exception) {
            Log::warning('operational_sync.enrollment_profile_mismatch', ['actor_id' => $request->user()->id, 'reason' => $exception->reason]);

            return redirect()->route('admin.installation')->withErrors(['sync_code' => 'The KernelBridge deployment profile is not ready. Ask the deployment administrator to restore or re-provision it.']);
        } catch (OperationalSyncEnrollmentException $exception) {
            Log::warning('operational_sync.enrollment_failed', ['actor_id' => $request->user()->id]);

            return redirect()->route('admin.installation')->withErrors(['sync_code' => $exception->getMessage()]);
        }

        return redirect()->route('admin.installation')->with('operational_sync_status', 'Sync enrollment saved. The connector must also be enabled and scheduled.');
    }
}
