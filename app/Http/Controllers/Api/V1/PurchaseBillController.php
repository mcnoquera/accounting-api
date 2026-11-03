<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseBillRequest;
use App\Models\PurchaseBill;
use App\Services\PaymentService;
use App\Services\PurchaseBillService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PurchaseBillController extends Controller
{
    public function __construct(private PurchaseBillService $bills) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'issued', 'partially_paid', 'paid', 'voided', 'open', 'overdue'])],
            'contact_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $data['status'] ?? null;

        $page = PurchaseBill::query()
            ->with('contact:id,name,tin')
            ->when(in_array($status, ['open', 'overdue'], true), fn ($q) => $q->whereIn('status', ['issued', 'partially_paid']))
            ->when($status === 'overdue', fn ($q) => $q->whereDate('due_date', '<', now()->toDateString()))
            ->when($status && ! in_array($status, ['open', 'overdue'], true), fn ($q) => $q->where('status', $status))
            ->when($data['contact_id'] ?? null, fn ($q, $v) => $q->where('contact_id', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('bill_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('bill_date', '<=', $v))
            ->when($data['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('supplier_invoice_number', 'like', "%{$t}%")))
            ->orderByDesc('bill_date')->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return ApiResponse::ok($page->items(), meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    public function show(PurchaseBill $purchaseBill, PaymentService $payments): JsonResponse
    {
        $bill = $this->bills->load($purchaseBill);
        $bill->setAttribute('payments', $payments->forDocument('purchase_bill', $bill->id));

        return ApiResponse::ok($bill);
    }

    public function store(PurchaseBillRequest $request): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->bills->saveDraft($request->validated(), $request->user()), 201);
    }

    public function update(PurchaseBillRequest $request, PurchaseBill $purchaseBill): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->bills->saveDraft($request->validated(), $request->user(), $purchaseBill));
    }

    public function destroy(PurchaseBill $purchaseBill): JsonResponse
    {
        Gate::authorize('manage-books');
        $this->bills->delete($purchaseBill);

        return ApiResponse::ok(null);
    }

    public function record(Request $request, PurchaseBill $purchaseBill): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->bills->record($purchaseBill, $request->user()));
    }

    public function void(Request $request, PurchaseBill $purchaseBill): JsonResponse
    {
        Gate::authorize('manage-books');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        return ApiResponse::ok($this->bills->void($purchaseBill, $request->user(), $data['reason']));
    }
}
