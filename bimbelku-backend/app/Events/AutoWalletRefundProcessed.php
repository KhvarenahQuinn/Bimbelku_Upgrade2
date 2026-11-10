<?php

namespace App\Events;

use App\Models\CustomerWalletTransaction;
use App\Models\Refund;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AutoWalletRefundProcessed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Refund $refund,
        public ?CustomerWalletTransaction $walletTransaction = null
    ) {
    }
}

