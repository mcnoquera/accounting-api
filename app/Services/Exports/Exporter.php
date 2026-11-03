<?php

namespace App\Services\Exports;

use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Renders a ReportTables table as CSV or PDF and records the export in the audit trail.
 * Amounts are pesos with 2 decimals (the API's JSON uses cents).
 */
class Exporter
{
    public function __construct(private AuditLogger $audit) {}

    public function download(array $table, string $format, string $slug, array $filters, User $user): Response
    {
        $filename = sprintf('ledgerly-%s-%s.%s', $slug, now()->format('Y-m-d'), $format);
        $body = $format === 'pdf' ? $this->pdf($table, $user) : $this->csv($table);

        $this->audit->record('exported', null, null, [
            'export' => $slug,
            'format' => $format,
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'rows' => count($table['rows']),
        ], $user->id);

        return new Response($body, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function csv(array $table): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ and accented names correctly
        fputcsv($out, [$table['title']], escape: '');
        fputcsv($out, [$table['period']], escape: '');
        fputcsv($out, [], escape: '');
        fputcsv($out, $table['columns'], escape: '');
        foreach ($table['rows'] as $row) {
            $cells = [];
            foreach ($row['cells'] as $i => $cell) {
                $cells[] = in_array($i, $table['money'], true) && is_int($cell) ? self::pesos($cell) : self::safeText($cell);
            }
            fputcsv($out, $cells, escape: '');
        }
        foreach ($table['notes'] as $note) {
            fputcsv($out, [], escape: '');
            fputcsv($out, [self::safeText($note)], escape: '');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    public function pdf(array $table, User $user): string
    {
        $html = view('exports.report', [
            'table' => $table,
            'company' => Company::current(),
            'preparedBy' => $user->name,
            'preparedAt' => now()->format('M j, Y g:i A'),
            'money' => fn (int $cents) => self::pesos($cents, true),
        ])->render();

        return $this->renderHtml($html, count($table['columns']) > 5);
    }

    public function renderHtml(string $html, bool $landscape = false): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false); // never fetch external resources while rendering
        $options->setDefaultFont('DejaVu Sans'); // has the ₱ glyph
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
        $pdf->render();

        return $pdf->output();
    }

    /** 123456 → "1234.56" (CSV) or "1,234.56" (PDF); negatives keep a leading minus. */
    public static function pesos(int $cents, bool $grouped = false): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.number_format(intdiv($abs, 100), 0, '.', $grouped ? ',' : '').'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Stops spreadsheet formula injection from text such as descriptions and memos. */
    public static function safeText(mixed $value): string
    {
        $s = (string) $value;

        return Str::startsWith($s, ['=', '+', '-', '@', "\t", "\r"]) ? "'".$s : $s;
    }
}
