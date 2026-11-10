<?php

namespace App\Observers;

use App\Models\Notification;
use App\Models\Refund;
use App\Models\User;
use App\Services\FinancialLedgerService;

class RefundObserver
{
    public function created(Refund $refund): void
    {
        app(FinancialLedgerService::class)->recordRefundQueued($refund);
        if ($this->isAutomatic($refund)) {
            return;
        }
        User::query()->where('role', 'admin')->where('status', 'active')->pluck('id')->each(
            fn (int $adminId) => Notification::updateOrCreate(
                ['unique_key' => "refund-action:{$refund->id}:{$adminId}"],
                [
                    'user_id' => $adminId,
                    'title' => 'Refund menunggu tindakan',
                    'message' => 'Refund '.$refund->refund_code.' menunggu tujuan atau proses pengembalian dana.',
                    'type' => 'warning',
                    'target_url' => '/admin/finance?tab=refunds',
                    'is_read' => false,
                ]
            )
        );
    }

    public function updated(Refund $refund): void
    {
        if (! $this->isAutomatic($refund) && $refund->wasChanged('destination_selected_at') && $refund->status === 'pending' && $refund->destination_selected_at) {
            User::query()->where('role', 'admin')->where('status', 'active')->pluck('id')->each(
                fn (int $adminId) => Notification::updateOrCreate(
                    ['unique_key' => "refund-action:{$refund->id}:{$adminId}"],
                    [
                        'user_id' => $adminId,
                        'title' => 'Refund siap diproses',
                        'message' => 'Tujuan refund '.$refund->refund_code.' sudah lengkap.',
                        'type' => 'warning',
                        'target_url' => '/admin/finance?tab=refunds',
                        'is_read' => false,
                    ]
                )
            );
        }
        if ($refund->wasChanged('status') && $refund->status === 'paid') {
            Notification::query()->where('unique_key', 'like', "refund-action:{$refund->id}:%")
                ->orWhere('unique_key', 'like', "refund-review:{$refund->id}:%")
                ->update(['is_read' => true, 'read_at' => now(), 'invalidated_at' => now()]);
            $ledger = app(FinancialLedgerService::class);
            if ($refund->destination_method === 'bimbelku_balance') {
                $ledger->recordRefundCreditedToWallet($refund);
            } else {
                $ledger->recordRefundPaid($refund);
            }
        }
    }

    private function isAutomatic(Refund $refund): bool
    {
        return $refund->isAutomatic();
    }
}
