<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $table['title'] }}</title>
<style>
  @page { margin: 22mm 14mm 20mm 14mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #111; }
  header { border-bottom: 1px solid #999; padding-bottom: 6px; margin-bottom: 10px; }
  .company { font-size: 12pt; font-weight: bold; }
  .muted { color: #555; }
  h1 { font-size: 13pt; margin: 8px 0 2px; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; border-bottom: 1px solid #333; padding: 4px; font-size: 8pt; text-transform: uppercase; color: #333; }
  td { padding: 3px 4px; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
  .num { text-align: right; white-space: nowrap; font-family: 'DejaVu Sans Mono', monospace; }
  tr.section td { font-weight: bold; background: #f2f2f2; }
  tr.total td { font-weight: bold; border-top: 1px solid #333; }
  .notes { margin-top: 10px; font-size: 8pt; }
  footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #555; border-top: 1px solid #ccc; padding-top: 3px; }
</style>
</head>
<body>
<footer>Prepared by {{ $preparedBy }} on {{ $preparedAt }} · Ledgerly · {{ $table['title'] }} · {{ $table['period'] }}</footer>
<header>
  @if ($company)
    <div class="company">{{ $company->name }}</div>
    @if ($company->trade_name)<div class="muted">{{ $company->trade_name }}</div>@endif
    <div class="muted">TIN {{ $company->tin }}@if ($company->rdo_code) · RDO {{ $company->rdo_code }}@endif · {{ $company->vat_registered ? 'VAT-registered' : 'Non-VAT' }}</div>
    <div class="muted">{{ $company->address }}@if ($company->zip_code) {{ $company->zip_code }}@endif</div>
  @else
    <div class="company">Company profile not set up</div>
  @endif
  <h1>{{ $table['title'] }}</h1>
  <div class="muted">{{ $table['period'] }} · Amounts in Philippine pesos (₱)</div>
</header>
<table>
  <thead><tr>
    @foreach ($table['columns'] as $i => $col)
      <th class="{{ in_array($i, $table['money'], true) ? 'num' : '' }}">{{ $col }}</th>
    @endforeach
  </tr></thead>
  <tbody>
    @foreach ($table['rows'] as $row)
      <tr class="{{ $row['kind'] }}">
        @foreach ($row['cells'] as $i => $cell)
          @php $isMoney = in_array($i, $table['money'], true) && is_int($cell); @endphp
          <td class="{{ in_array($i, $table['money'], true) ? 'num' : '' }}">{{ $isMoney ? $money($cell) : $cell }}</td>
        @endforeach
      </tr>
    @endforeach
  </tbody>
</table>
@if ($table['notes'])
  <div class="notes">@foreach ($table['notes'] as $n)<p>{{ $n }}</p>@endforeach</div>
@endif
</body>
</html>
