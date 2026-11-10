<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\RefundSettlementService;
use App\Support\RefundStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentRefundController extends Controller
{
    public function sync(Request $request, Refund $refund, RefundSettlementService $settlement)
    {
        abort_unless((int) $refund->user_id === (int) $request->user()->id, 403);
        $refund->refresh()->loadMissing('order');

        if ($settlement->isAutomatic($refund) && in_array($refund->status, ['pending', 'failed'], true)) {
            if ($refund->destination_method === 'xendit_original' && filled($refund->gateway_refund_id)) {
                // Do not hammer the provider on every page load.
                if (! $refund->last_reconciled_at || $refund->last_reconciled_at->lt(now()->subMinute())) {
                    $settlement->reconcile($refund);
                }
            } elseif ($refund->status === 'pending') {
                $settlement->startAutomatic($refund);
            }
        }

        $fresh = $refund->fresh()->loadMissing('order');
        $presented = RefundStatusPresenter::present($fresh);

        return response()->json([
            'message' => $presented['status_message'],
            'refund' => array_merge($fresh->toArray(), $presented),
        ]);
    }

    public function selectDestination(Request $request, Refund $refund, RefundSettlementService $settlement)
    {
        abort_unless((int) $refund->user_id === (int) $request->user()->id, 403);

        $rawMethod = $request->input('destination_method') ?? $request->input('destination');
        if ($rawMethod !== null) {
            $normalized = match (mb_strtolower(trim((string) $rawMethod))) {
                'wallet', 'saldo', 'bimbelku_balance', 'saldo_bimbelku', 'saldo bimbelku' => 'bimbelku_balance',
                'bank', 'bank_transfer' => 'bank_transfer',
                'original_payment', 'original', 'xendit_original', 'metode_asal' => 'xendit_original',
                default => $rawMethod,
            };
            $request->merge(['destination_method' => $normalized]);
        }

        $validated = $request->validate([
            'destination_method' => ['required', Rule::in(['bank_transfer', 'bimbelku_balance', 'xendit_original'])],
            'bank_name' => ['nullable', 'required_if:destination_method,bank_transfer', 'string', 'max:100', 'regex:/\pL/u'],
            'account_name' => ['nullable', 'required_if:destination_method,bank_transfer', 'string', 'max:150', 'regex:/\pL/u', 'not_regex:/\d/u'],
            'account_number' => ['nullable', 'required_if:destination_method,bank_transfer', 'string', 'min:8', 'max:20', 'regex:/^[0-9]+$/'],
        ], [
            'bank_name.regex' => 'Nama bank atau e-wallet wajib mengandung huruf.',
            'account_name.regex' => 'Nama pemilik rekening wajib mengandung huruf.',
            'account_name.not_regex' => 'Nama pemilik rekening tidak boleh memuat angka.',
            'account_number.min' => 'Nomor rekening atau e-wallet minimal 8 digit.',
            'account_number.max' => 'Nomor rekening atau e-wallet maksimal 20 digit.',
            'account_number.regex' => 'Nomor rekening atau e-wallet hanya boleh berisi angka.',
        ]);

        $selected = DB::transaction(function () use ($refund, $request, $validated) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            abort_unless((int) $locked->user_id === (int) $request->user()->id, 403);
            abort_unless(in_array($locked->status, ['pending', 'failed'], true), 422, 'Refund ini sudah diproses dan tujuan tidak dapat diubah.');

            $locked->loadMissing('order');
            $gatewayFailed = $locked->status === 'failed' || $locked->gatewayFailed();
            $alreadySent = filled($locked->gateway_refund_id) || filled($locked->gateway_submitted_at);
            $switchingToBalance = $validated['destination_method'] === 'bimbelku_balance';

            if ($gatewayFailed) {
                abort_unless(
                    $switchingToBalance,
                    422,
                    'Pengembalian ke metode pembayaran asal gagal. Pilih Saldo BimbelKu agar dana tetap dapat digunakan.'
                );
            } else {
                abort_if($alreadySent, 409, 'Refund sudah dikirim ke metode pembayaran asal dan tujuannya tidak dapat diubah.');
            }

            $breakdown = $locked->tenderBreakdown();
            if ($validated['destination_method'] === 'xendit_original') {
                abort_unless($locked->order?->payment_provider === 'xendit' && $breakdown['external_funded_amount'] > 0.009, 422, 'Pengembalian ke metode pembayaran asal tidak tersedia untuk transaksi ini.');
                $locked->update([
                    'destination_method' => 'xendit_original',
                    'destination_bank_name' => null,
                    'destination_account_name' => null,
                    'destination_account_number' => null,
                    'destination_selected_at' => now(),
                    'destination_selection_version' => ((int) $locked->destination_selection_version) + 1,
                ]);

                return $locked->fresh();
            }
            if ($validated['destination_method'] === 'bank_transfer' && $breakdown['external_funded_amount'] <= 0.009) {
                abort(422, 'Pembayaran ini seluruhnya berasal dari Saldo BimbelKu. Refund harus kembali ke Saldo BimbelKu dan tidak dapat ditarik ke rekening/e-wallet.');
            }
            if ($validated['destination_method'] === 'bank_transfer' && $locked->order?->payment_provider === 'xendit') {
                abort(422, 'Untuk pembayaran ini, pilih metode pembayaran asal agar pengembalian diproses otomatis, atau pilih Saldo BimbelKu.');
            }

            $wallet = $validated['destination_method'] === 'bimbelku_balance';
            $locked->update([
                'destination_method' => $validated['destination_method'],
                'destination_bank_name' => $wallet ? null : $validated['bank_name'],
                'destination_account_name' => $wallet ? null : $validated['account_name'],
                'destination_account_number' => $wallet ? null : $validated['account_number'],
                'destination_selected_at' => now(),
                'destination_selection_version' => ((int) $locked->destination_selection_version) + 1,
            ]);

            return $locked->fresh();
        }, 3);

        if ($settlement->isAutomatic($selected)) {
            $settlement->startAutomatic($selected);
        }
        $selected = $selected->fresh()->loadMissing('order');
        $presented = RefundStatusPresenter::present($selected);

        return response()->json([
            'message' => $presented['status_message'],
            'refund' => array_merge($selected->toArray(), $presented, [
                'wallet_funded_amount' => $selected->tenderBreakdown()['wallet_funded_amount'],
                'external_funded_amount' => $selected->tenderBreakdown()['external_funded_amount'],
            ]),
        ]);
    }
}
