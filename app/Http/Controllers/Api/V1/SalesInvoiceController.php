<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesInvoiceRequest;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\AuditLogger;
use App\Services\Exports\Exporter;
use App\Services\PaymentService;
use App\Services\SalesInvoiceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SalesInvoiceController extends Controller
{
    public function __construct(private SalesInvoiceService $invoices) {}

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

        $page = SalesInvoice::query()
            ->with('contact:id,name,tin')
            ->when(in_array($status, ['open', 'overdue'], true), fn ($q) => $q->whereIn('status', ['issued', 'partially_paid']))
            ->when($status === 'overdue', fn ($q) => $q->whereDate('due_date', '<', now()->toDateString()))
            ->when($status && ! in_array($status, ['open', 'overdue'], true), fn ($q) => $q->where('status', $status))
            ->when($data['contact_id'] ?? null, fn ($q, $v) => $q->where('contact_id', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
            ->when($data['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('reference', 'like', "%{$t}%")))
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return ApiResponse::ok($page->items(), meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    public function show(SalesInvoice $salesInvoice, PaymentService $payments): JsonResponse
    {
        $invoice = $this->invoices->load($salesInvoice);
        $invoice->setAttribute('payments', $payments->forDocument('sales_invoice', $invoice->id));

        return ApiResponse::ok($invoice);
    }

    public function store(SalesInvoiceRequest $request): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->invoices->saveDraft($request->validated(), $request->user()), 201);
    }

    public function update(SalesInvoiceRequest $request, SalesInvoice $salesInvoice): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->invoices->saveDraft($request->validated(), $request->user(), $salesInvoice));
    }

    public function destroy(SalesInvoice $salesInvoice): JsonResponse
    {
        Gate::authorize('manage-books');
        $this->invoices->delete($salesInvoice);

        return ApiResponse::ok(null);
    }

    public function issue(Request $request, SalesInvoice $salesInvoice): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->invoices->issue($salesInvoice, $request->user()));
    }

    public function void(Request $request, SalesInvoice $salesInvoice): JsonResponse
    {
        Gate::authorize('manage-books');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        return ApiResponse::ok($this->invoices->void($salesInvoice, $request->user(), $data['reason']));
    }

    /** Customer-facing PDF of an issued invoice (drafts print with a DRAFT watermark). */
    public function pdf(Request $request, SalesInvoice $salesInvoice, Exporter $exporter, AuditLogger $audit): Response
    {
        $invoice = $this->invoices->load($salesInvoice);
        $html = view('exports.invoice', [
            'invoice' => $invoice,
            'company' => Company::current(),
            'money' => fn (int $c) => Exporter::pesos($c, true),
        ])->render();
        $audit->record('printed', $invoice, null, ['number' => $invoice->number, 'status' => $invoice->status->value], $request->user()->id);
        $name = $invoice->number ?? 'draft-'.$invoice->id;

        return new Response($exporter->renderHtml($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
