<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditFindingRequest;
use App\Http\Requests\DateRangeRequest;
use App\Models\AuditFinding;
use App\Models\AuditLog;
use App\Services\AuditExceptionService;
use App\Services\AuditFindingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditController extends Controller
{
    public function logs(Request $request): JsonResponse
    {
        Gate::authorize('view-audit');
        $request->validate([
            'action' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $page = AuditLog::with('user:id,name,email,role')
            ->when($request->query('action'), fn ($q, $v) => $q->where('action', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('id')
            ->paginate(50);

        return ApiResponse::ok($page->items(), meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    public function exceptions(DateRangeRequest $request, AuditExceptionService $service): JsonResponse
    {
        Gate::authorize('view-audit');

        return ApiResponse::ok($service->run($request->validated('from'), $request->validated('to')));
    }

    public function findings(Request $request, AuditFindingService $service): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:open,resolved'],
            'severity' => ['nullable', 'in:low,medium,high'],
        ]);
        $page = $service->list($request->query('status'), $request->query('severity'));

        return ApiResponse::ok($page->items(), meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    public function raise(AuditFindingRequest $request, AuditFindingService $service): JsonResponse
    {
        Gate::authorize('manage-findings');

        return ApiResponse::ok($service->raise($request->validated(), $request->user()), 201);
    }

    public function resolve(Request $request, AuditFinding $finding, AuditFindingService $service): JsonResponse
    {
        Gate::authorize('resolve-findings');
        $data = $request->validate(['resolution' => ['required', 'string', 'min:5', 'max:5000']]);

        return ApiResponse::ok($service->resolve($finding, $data['resolution'], $request->user()));
    }
}
