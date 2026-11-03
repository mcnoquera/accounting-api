<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyRequest;
use App\Models\Company;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CompanyController extends Controller
{
    /** Returns null data until first-run setup is done; the web app uses that to show setup. */
    public function show(): JsonResponse
    {
        return ApiResponse::ok(Company::current());
    }

    public function update(CompanyRequest $request): JsonResponse
    {
        Gate::authorize('manage-company');

        $company = DB::transaction(function () use ($request) {
            $company = Company::query()->lockForUpdate()->orderBy('id')->first();
            if ($company) {
                $company->update($request->normalized());

                return $company->refresh();
            }

            return Company::create($request->normalized());
        });

        return ApiResponse::ok($company, $company->wasRecentlyCreated ? 201 : 200);
    }
}
