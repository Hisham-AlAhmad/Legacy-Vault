<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Vault\RecordIntegrityEventsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportIntegrityEventsRequest;
use Illuminate\Http\JsonResponse;

/**
 * Client-reported integrity failures (docs/19_Decision_Log.md Decision #31). The browser
 * is the only place vault content is opened, so it is the only place a failed GCM check
 * can be seen; this records the report, bounded and labelled as a client claim.
 */
class IntegrityEventController extends Controller
{
    /** POST /api/v1/vault/integrity-events — Audit: integrity.check_failed. */
    public function store(ReportIntegrityEventsRequest $request, RecordIntegrityEventsAction $action): JsonResponse
    {
        $recorded = $action->execute($request->user(), $request->toEvents(), $request->ip());

        return response()->json(['recorded' => $recorded], 202);
    }
}
