<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 22px 26px; }
  body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 9px; }
  h1 { font-size: 19px; margin: 0 0 4px; }
  .muted { color: #64748b; }
  .summary { width: 100%; margin: 16px 0; border-collapse: separate; border-spacing: 6px; }
  .summary td { border: 1px solid #dbe4ef; background: #f8fafc; border-radius: 8px; padding: 10px; }
  .summary strong { display: block; margin-top: 5px; font-size: 12px; color: #0f172a; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data th { background: #0f172a; color: white; text-align: left; padding: 7px; }
  table.data td { border-bottom: 1px solid #e2e8f0; vertical-align: top; padding: 7px; }
  .amount { text-align: right; white-space: nowrap; }
  .entry { display: block; margin-bottom: 2px; }
  footer { position: fixed; bottom: -12px; left: 0; right: 0; color: #94a3b8; text-align: right; font-size: 8px; }
</style>
</head>
<body>
<header>
  <h1>Laporan Keuangan BimbelKu</h1>
  <div class="muted">Dibuat {{ $generatedAt->timezone(config('app.timezone'))->format('d M Y H:i') }} WIB · Maksimal 2.000 jurnal per ekspor</div>
  <div class="muted">Periode: {{ $filters['date_from'] ?? 'awal data' }} sampai {{ $filters['date_to'] ?? 'sekarang' }} · Jenis: {{ $filters['event_type'] ?? 'semua' }} · Akun: {{ $filters['account_code'] ?? 'semua' }}</div>
</header>
<table class="summary"><tr>
  <td>Pembayaran diterima<strong>Rp {{ number_format($summary['payments_received'], 0, ',', '.') }}</strong></td>
  <td>Pendapatan admin<strong>Rp {{ number_format($summary['platform_revenue'], 0, ',', '.') }}</strong></td>
  <td>Refund selesai<strong>Rp {{ number_format($summary['refunds_completed'], 0, ',', '.') }}</strong></td>
  <td>Pencairan tutor<strong>Rp {{ number_format($summary['payouts_completed'], 0, ',', '.') }}</strong></td>
  <td>Hak tutor tersisa<strong>Rp {{ number_format($summary['tutor_payable_balance'], 0, ',', '.') }}</strong></td>
</tr></table>
<table class="data">
<thead><tr><th>Waktu</th><th>Jenis / referensi</th><th>Pengguna</th><th>Referensi Xendit</th><th>Keterangan</th><th>Baris jurnal</th><th class="amount">Nilai</th></tr></thead>
<tbody>
@forelse ($rows as $row)
<tr>
  <td>{{ $row->occurred_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
  <td><strong>{{ $row->event_type }}</strong><br><span class="muted">{{ $row->reference_label ?: (class_basename($row->reference_type ?? '').' #'.($row->reference_id ?? '-')) }}</span></td>
  <td>
    @if ($row->user_name)
      <strong>{{ $row->user_name }}</strong><br><span class="muted">{{ $row->user_email }}</span>
    @else
      <span class="muted">-</span>
    @endif
  </td>
  <td>
    @forelse ($row->external_references ?? [] as $reference)
      <span class="entry">{{ $reference['label'] }}: {{ $reference['value'] }}</span>
    @empty
      <span class="muted">-</span>
    @endforelse
  </td>
  <td>{{ $row->description }}<br><span class="muted">{{ $row->uuid }}</span></td>
  <td>@foreach ($row->entries as $entry)<span class="entry">{{ $entry->account_code }} · {{ strtoupper($entry->side) }} · Rp {{ number_format((float) $entry->amount, 0, ',', '.') }}</span>@endforeach</td>
  <td class="amount"><strong>Rp {{ number_format((float) $row->total_debit, 0, ',', '.') }}</strong></td>
</tr>
@empty
<tr><td colspan="7">Tidak ada data untuk filter ini.</td></tr>
@endforelse
</tbody>
</table>
<footer>Laporan sistem BimbelKu · Data jurnal bersifat append-only</footer>
</body>
</html>