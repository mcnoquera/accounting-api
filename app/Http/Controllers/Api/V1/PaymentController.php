<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentRequest;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['nullable', Rule::in(['receipt', 'payment'])],
            'contact_id' => ['nullable', 'integer'],
            'unapplied' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = Payment::query()
            ->with(['contact:id,name', 'cashAccount:id,code,name', 'allocations'])
            ->when($data['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($data['contact_id'] ?? null, fn ($q, $v) => $q->where('contact_id', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '<=', $v))
            ->when($data['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('reference', 'like', "%{$t}%")))
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);
        $items = collect($page->items());
        if ($request->boolean('unapplied')) {
            $items = $items->filter(fn ($p) => $p->unapplied_amount > 0)->values();
        }

        return ApiResponse::ok($items, meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        return ApiResponse::ok($this->payments->load($payment));
    }

    public function store(PaymentRequest $request): JsonResponse
    {
        Gate::authorize('manage-books');
        $data = $request->validated();

        return ApiResponse::ok($this->payments->record($data['direction'], $data, $request->user()), 201);
    }

    public function allocate(Request $request, Payment $payment): JsonResponse
    {
        Gate::authorize('manage-books');
        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.document_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ]);

        return ApiResponse::ok($this->payments->allocate($payment, $data['allocations']));
    }

    public function void(Request $request, Payment $payment): JsonResponse
    {
        Gate::authorize('manage-books');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        return ApiResponse::ok($this->payments->void($payment, $request->user(), $data['reason']));
    }
}
