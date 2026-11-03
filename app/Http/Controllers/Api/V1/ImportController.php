<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ImportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** CSV import of accounts and contacts: template, dry-run preview, then commit. */
class ImportController extends Controller
{
    public function __construct(private ImportService $imports) {}

    public function template(string $kind): Response
    {
        return new Response($this->imports->template($kind), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"ledgerly-{$kind}-template.csv\"",
        ]);
    }

    public function preview(Request $request, string $kind): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->imports->preview($kind, $this->csv($request)));
    }

    public function commit(Request $request, string $kind): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->imports->commit($kind, $this->csv($request)));
    }

    private function csv(Request $request): string
    {
        return $request->validate(['csv' => ['required', 'string', 'max:2000000']])['csv'];
    }
}
