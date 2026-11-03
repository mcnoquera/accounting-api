<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaxCodeRequest;
use App\Models\TaxCode;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaxCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['active' => ['nullable', 'boolean'], 'kind' => ['nullable', 'in:vat,ewt']]);

        $codes = TaxCode::query()
            ->withCount(['vatLines', 'ewtLines'])
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->input('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->orderBy('kind')->orderBy('code')
            ->get()
            ->each(fn (TaxCode $c) => $c->setAttribute('lines_count', $c->vat_lines_count + $c->ewt_lines_count)->makeHidden(['vat_lines_count', 'ewt_lines_count']));

        return ApiResponse::ok($codes);
    }

    public function store(TaxCodeRequest $request): JsonResponse
    {
        Gate::authorize('manage-tax-codes');

        return ApiResponse::ok(TaxCode::create($request->modelAttributes()), 201);
    }

    /** Once a code is on any line, its kind, rate and category are fixed; add a new code instead. */
    public function update(TaxCodeRequest $request, TaxCode $taxCode): JsonResponse
    {
        Gate::authorize('manage-tax-codes');
        $data = $request->modelAttributes();

        if ($taxCode->isUsed()) {
            $locked = array_filter(['code', 'kind', 'rate_bp', 'vat_category'], function ($field) use ($data, $taxCode) {
                if (! array_key_exists($field, $data)) {
                    return false;
                }
                $current = $taxCode->getAttribute($field);
                $current = $current instanceof \BackedEnum ? $current->value : $current;

                return $current !== $data[$field];
            });
            if ($locked !== []) {
                throw new DomainRuleException(
                    "{$taxCode->code} is already used on entries, so its code, kind, rate and VAT category can't change. Deactivate it and add a new code.",
                    array_fill_keys(array_map(fn ($f) => $f === 'rate_bp' ? 'rate' : $f, $locked), ['Locked: used on entries.']),
                );
            }
        }

        $taxCode->update($data);

        return ApiResponse::ok($taxCode->refresh());
    }
}
