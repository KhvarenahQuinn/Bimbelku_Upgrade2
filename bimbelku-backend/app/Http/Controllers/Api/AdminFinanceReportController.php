<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\FinancialJournal;
use App\Models\FinancialLedgerEntry;
use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\Payout;
use App\Models\Refund;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class AdminFinanceReportController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $rows = $this->query($filters)
            ->with(['entries:id,financial_journal_id,account_code,side,amount,currency'])
            ->withSum(['entries as total_debit' => fn ($query) => $query->where('side', 'debit')], 'amount')
            ->paginate($perPage)
            ->withQueryString();
        $this->decorateRows($rows->getCollection());

        return response()->json([
            'summary' => $this->summary($filters),
            'filters' => $filters,
            'event_types' => FinancialJournal::query()->distinct()->orderBy('event_type')->pluck('event_type')->values(),
            'account_codes' => FinancialLedgerEntry::query()->distinct()->orderBy('account_code')->pluck('account_code')->values(),
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function pdf(Request $request)
    {
        $filters = $this->filters($request);
        $rows = $this->query($filters)
            ->with(['entries:id,financial_journal_id,account_code,side,amount,currency'])
            ->withSum(['entries as total_debit' => fn ($query) => $query->where('side', 'debit')], 'amount')
            ->limit(2000)
            ->get();
        $this->decorateRows($rows);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml(view('reports.finance', [
            'rows' => $rows,
            'summary' => $this->summary($filters),
            'filters' => $filters,
            'generatedAt' => now(),
        ])->render());
        $dompdf->render();

        $filename = 'laporan-keuangan-bimbelku-'.now()->format('Ymd-His').'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Tambahkan identitas pengguna dan referensi eksternal Xendit pada setiap
     * baris jurnal. Nilai ini tidak disimpan di tabel jurnal agar hash ledger
     * tetap stabil; pencocokan dilakukan saat laporan dibaca.
     */
    private function decorateRows(Collection $rows): void
    {
        $ids = [];
        foreach ($rows as $row) {
            $type = class_basename((string) $row->reference_type);
            if ($row->reference_id) {
                $ids[$type][] = (int) $row->reference_id;
            }
        }

        $orders = $this->loadReferenceMap(Order::class, $ids['Order'] ?? [], ['user:id,name,email']);
        $refunds = $this->loadReferenceMap(Refund::class, $ids['Refund'] ?? [], ['user:id,name,email', 'order:id,order_id,payment_provider']);
        $payouts = $this->loadReferenceMap(Payout::class, $ids['Payout'] ?? [], ['user:id,name,email']);
        $bookings = $this->loadReferenceMap(Booking::class, $ids['Booking'] ?? [], ['student:id,name,email']);
        $submissions = $this->loadReferenceMap(PaymentSubmission::class, $ids['PaymentSubmission'] ?? [], ['order:id,order_id,user_id,payment_provider', 'order.user:id,name,email']);

        foreach ($rows as $row) {
            $type = class_basename((string) $row->reference_type);
            $id = (int) ($row->reference_id ?? 0);
            $user = null;
            $references = [];
            $label = null;
            $channel = null;

            if ($type === 'Order' && ($order = $orders->get($id))) {
                $user = $order->user;
                $label = 'Pesanan '.($order->order_id ?: '#'.$order->id);
                $channel = $this->paymentChannel($order->payment_provider);
                $references = array_values(array_filter([
                    $this->externalReference('Payment Request', $order->xendit_payment_request_id ?? $order->gateway_payment_request_id),
                    $this->externalReference('Payment ID', $order->gateway_payment_id),
                    $this->externalReference('Session ID', $order->gateway_session_id),
                    $this->externalReference('Xendit Ref', $order->gateway_reference_id),
                    $this->externalReference('Provider Ref', $order->provider_reference),
                ]));
            } elseif ($type === 'Refund' && ($refund = $refunds->get($id))) {
                $user = $refund->user;
                $label = 'Refund '.($refund->refund_code ?: '#'.$refund->id);
                $channel = $refund->destination_method === 'bimbelku_balance'
                    ? 'Saldo BimbelKu'
                    : ($refund->destination_method === 'xendit_original' ? 'Xendit' : null);
                $references = array_values(array_filter([
                    $this->externalReference('Refund ID', $refund->gateway_refund_id),
                    $this->externalReference('Xendit Ref', $refund->gateway_reference_id),
                ]));
            } elseif ($type === 'Payout' && ($payout = $payouts->get($id))) {
                $user = $payout->user;
                $label = 'Pencairan #'.$payout->id;
                $channel = 'Xendit';
                $references = array_values(array_filter([
                    $this->externalReference('Disbursement ID', $payout->gateway_payout_id),
                    $this->externalReference('Xendit Ref', $payout->gateway_reference_id),
                ]));
            } elseif ($type === 'Booking' && ($booking = $bookings->get($id))) {
                $user = $booking->student;
                $label = 'Sesi #'.$booking->id;
            } elseif ($type === 'PaymentSubmission' && ($submission = $submissions->get($id))) {
                $user = $submission->order?->user;
                $label = 'Bukti transfer #'.$submission->id;
                $channel = $this->paymentChannel($submission->order?->payment_provider);
            } elseif ($row->reference_id) {
                $label = ($type ?: 'Referensi').' #'.$row->reference_id;
            }

            $row->setAttribute('reference_label', $label);
            $row->setAttribute('user_name', $user?->name);
            $row->setAttribute('user_email', $user?->email);
            $row->setAttribute('external_references', $references);
            $row->setAttribute('payment_channel', $channel);
        }
    }

    private function loadReferenceMap(string $model, array $ids, array $with = []): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return $model::query()
            ->with($with)
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->keyBy('id');
    }

    private function externalReference(string $label, mixed $value): ?array
    {
        return filled($value) ? ['label' => $label, 'value' => (string) $value] : null;
    }

    private function paymentChannel(?string $provider): ?string
    {
        return match ($provider) {
            'bimbelku_wallet' => 'Saldo BimbelKu',
            'xendit' => 'Xendit',
            'wallet_plus_xendit' => 'Saldo BimbelKu + Xendit',
            'manual_transfer', 'wallet_plus_manual_transfer' => 'Transfer manual',
            default => null,
        };
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'event_type' => ['nullable', 'string', 'max:80'],
            'account_code' => ['nullable', 'string', 'max:60'],
            'side' => ['nullable', Rule::in(['debit', 'credit'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:120'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'amount_desc', 'amount_asc'])],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function query(array $filters): Builder
    {
        $query = FinancialJournal::query();
        if (filled($filters['event_type'] ?? null)) {
            $query->where('event_type', $filters['event_type']);
        }
        if (filled($filters['date_from'] ?? null)) {
            $query->where('occurred_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('occurred_at', '<=', $filters['date_to'].' 23:59:59');
        }
        if (filled($filters['q'] ?? null)) {
            $needle = trim((string) $filters['q']);
            $query->where(function (Builder $items) use ($needle) {
                $items->where('description', 'like', '%'.$needle.'%')
                    ->orWhere('event_key', 'like', '%'.$needle.'%')
                    ->orWhere('uuid', 'like', '%'.$needle.'%')
                    // Kode transaksi lokal (PKG-.../INV-...) dan ID Xendit
                    // (pr-.../py-.../ps-.../BKU-...) harus dapat dicari langsung.
                    ->orWhere(function (Builder $orderJournals) use ($needle) {
                        $orderJournals->where('reference_type', Order::class)
                            ->whereIn('reference_id', Order::query()
                                ->where(function (Builder $orders) use ($needle) {
                                    $orders->where('order_id', 'like', '%'.$needle.'%')
                                        ->orWhere('gateway_reference_id', 'like', '%'.$needle.'%')
                                        ->orWhere('xendit_payment_request_id', 'like', '%'.$needle.'%')
                                        ->orWhere('gateway_payment_request_id', 'like', '%'.$needle.'%')
                                        ->orWhere('gateway_payment_id', 'like', '%'.$needle.'%')
                                        ->orWhere('gateway_session_id', 'like', '%'.$needle.'%');
                                })
                                ->select('id'));
                    });
            });
        }
        if (filled($filters['account_code'] ?? null) || filled($filters['side'] ?? null)) {
            $query->whereHas('entries', function (Builder $entries) use ($filters) {
                if (filled($filters['account_code'] ?? null)) {
                    $entries->where('account_code', $filters['account_code']);
                }
                if (filled($filters['side'] ?? null)) {
                    $entries->where('side', $filters['side']);
                }
            });
        }
        if (isset($filters['min_amount'])) {
            $query->whereHas('entries', fn (Builder $entries) => $entries->where('side', 'debit')->where('amount', '>=', $filters['min_amount']));
        }
        if (isset($filters['max_amount'])) {
            $query->whereDoesntHave('entries', fn (Builder $entries) => $entries->where('side', 'debit')->where('amount', '>', $filters['max_amount']));
        }

        return match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->oldest('occurred_at')->oldest('id'),
            'amount_desc' => $query->withSum(['entries as sort_amount' => fn ($entry) => $entry->where('side', 'debit')], 'amount')->orderByDesc('sort_amount')->orderByDesc('id'),
            'amount_asc' => $query->withSum(['entries as sort_amount' => fn ($entry) => $entry->where('side', 'debit')], 'amount')->orderBy('sort_amount')->orderBy('id'),
            default => $query->latest('occurred_at')->latest('id'),
        };
    }

    private function summary(array $filters): array
    {
        $sum = function (array $events, string $account, string $side) use ($filters): float {
            return round((float) FinancialLedgerEntry::query()
                ->join('financial_journals', 'financial_journals.id', '=', 'financial_ledger_entries.financial_journal_id')
                ->whereIn('financial_journals.event_type', $events)
                ->where('financial_ledger_entries.account_code', $account)
                ->where('financial_ledger_entries.side', $side)
                ->when(filled($filters['date_from'] ?? null), fn ($q) => $q->where('financial_journals.occurred_at', '>=', $filters['date_from'].' 00:00:00'))
                ->when(filled($filters['date_to'] ?? null), fn ($q) => $q->where('financial_journals.occurred_at', '<=', $filters['date_to'].' 23:59:59'))
                ->sum('financial_ledger_entries.amount'), 2);
        };
        $allAccount = function (string $account, string $side) use ($filters): float {
            return round((float) FinancialLedgerEntry::query()
                ->join('financial_journals', 'financial_journals.id', '=', 'financial_ledger_entries.financial_journal_id')
                ->where('financial_ledger_entries.account_code', $account)
                ->where('financial_ledger_entries.side', $side)
                ->when(filled($filters['date_from'] ?? null), fn ($q) => $q->where('financial_journals.occurred_at', '>=', $filters['date_from'].' 00:00:00'))
                ->when(filled($filters['date_to'] ?? null), fn ($q) => $q->where('financial_journals.occurred_at', '<=', $filters['date_to'].' 23:59:59'))
                ->sum('financial_ledger_entries.amount'), 2);
        };

        $tutorCredits = $allAccount('tutor_payable', 'credit');
        $tutorDebits = $allAccount('tutor_payable', 'debit');

        return [
            'payments_received' => $sum(['payment_received'], 'customer_funds', 'credit'),
            'platform_revenue' => $sum(['booking_settled'], 'platform_revenue', 'credit'),
            'refunds_completed' => $sum(['refund_paid', 'refund_wallet_credited'], 'refunds_payable', 'debit'),
            'payouts_completed' => $sum(['payout_completed'], 'tutor_payable', 'debit'),
            'tutor_payable_balance' => round($tutorCredits - $tutorDebits, 2),
        ];
    }
}
