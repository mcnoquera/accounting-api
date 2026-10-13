<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\JournalEntryRequest;
use App\Http\Requests\VoidEntryRequest;
use App\Models\JournalEntry;
use App\Services\JournalEntryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class JournalEntryController extends Controller
{
    public function __construct(private JournalEntryService $entries) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:draft,posted,voided'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->entries->list($request->only(['status', 'from', 'to', 'q', 'account_id']), $request->integer('per_page', 20));

        return ApiResponse::ok($page->items(), meta: [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    public function store(JournalEntryRequest $request): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->entries->createDraft($request->validated(), $request->user()), 201);
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        return ApiResponse::ok($this->entries->find($journalEntry->id));
    }

    public function update(JournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->entries->updateDraft($journalEntry, $request->validated()));
    }

    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        Gate::authorize('manage-books');
        $this->entries->deleteDraft($journalEntry);

        return ApiResponse::ok(null);
    }

    public function post(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->entries->post($journalEntry, $request->user()));
    }

    public function void(VoidEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->entries->void($journalEntry, $request->user(), $request->validated('reason')));
    }
}
