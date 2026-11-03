<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ControlType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use App\Services\ContactService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    public function __construct(private ContactService $contacts) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in(['customer', 'supplier'])],
            'active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $list = Contact::query()
            ->when($request->input('type') === 'customer', fn ($q) => $q->whereIn('type', ['customer', 'both']))
            ->when($request->input('type') === 'supplier', fn ($q) => $q->whereIn('type', ['supplier', 'both']))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('tin', 'like', "%{$t}%")))
            ->orderBy('name')
            ->get();

        return ApiResponse::ok($list);
    }

    public function store(ContactRequest $request): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok(Contact::create($request->normalized()), 201);
    }

    public function show(Contact $contact): JsonResponse
    {
        return ApiResponse::ok($contact);
    }

    public function update(ContactRequest $request, Contact $contact): JsonResponse
    {
        Gate::authorize('manage-books');
        $contact->update($request->normalized());

        return ApiResponse::ok($contact->refresh());
    }

    public function destroy(Contact $contact): JsonResponse
    {
        Gate::authorize('manage-books');
        $this->contacts->delete($contact);

        return ApiResponse::ok(null);
    }

    public function ledger(Request $request, Contact $contact): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::enum(ControlType::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $control = isset($data['type']) ? ControlType::from($data['type']) : $this->contacts->defaultControl($contact);

        return ApiResponse::ok($this->contacts->ledger($contact, $control, $data['from'] ?? null, $data['to'] ?? null));
    }

    public function aging(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(ControlType::class)],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return ApiResponse::ok($this->contacts->aging(ControlType::from($data['type']), $data['as_of'] ?? null));
    }
}
