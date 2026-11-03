<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $dataset->title }}</title>
    <style>
        @page { margin: 24px 24px 34px; }
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h1 { font-size: 18px; margin: 0 0 5px; }
        .meta { color: #536174; margin-bottom: 14px; }
        .summary { margin: 0 0 15px; width: 100%; }
        .summary td { border: 0; padding: 3px 12px 3px 0; }
        table.report { border-collapse: collapse; width: 100%; }
        .report th, .report td { border: 1px solid #d8dee9; padding: 5px; text-align: left; vertical-align: top; }
        .report th { background: #172554; color: #fff; font-weight: bold; }
        .report tr { page-break-inside: avoid; }
        .money { text-align: right !important; white-space: nowrap; }
        .footer { bottom: -20px; color: #64748b; font-size: 8px; position: fixed; right: 0; }
    </style>
</head>
<body>
    <h1>KostKita · {{ $dataset->title }}</h1>
    <div class="meta">{{ $tenantName }} · Periode {{ $dataset->period['from'] }} sampai {{ $dataset->period['to'] }} · Dibuat {{ $dataset->generatedAt }} · Filter: {{ $filterText }}</div>

    <table class="summary">
        @foreach ($dataset->summary as $key => $value)
            <tr>
                <td><strong>{{ $dataset->summaryLabels[$key] ?? $key }}</strong></td>
                <td>{{ is_numeric($value) ? number_format((int) $value, 0, ',', '.') : $value }}</td>
            </tr>
        @endforeach
    </table>

    <table class="report">
        <thead>
            <tr>
                @foreach ($dataset->columns as $column)
                    <th>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($dataset->rows as $row)
                <tr>
                    @foreach ($dataset->columns as $column)
                        @php($value = $row[$column['key']] ?? '')
                        <td class="{{ ($column['type'] ?? '') === 'money' ? 'money' : '' }}">
                            {{ ($column['type'] ?? '') === 'money' && is_numeric($value) ? number_format((int) $value, 0, ',', '.') : $value }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($dataset->columns) }}">Tidak ada data pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($dataset->key === 'cash-flow')
        <div class="footer">Arus Kas Bersih adalah ringkasan arus kas operasional, bukan laporan laba rugi akuntansi.</div>
    @endif
</body>
</html>
