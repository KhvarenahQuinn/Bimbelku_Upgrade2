<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\PrivateParticipantPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrivateParticipantPricingController extends Controller
{
    public function show(PrivateParticipantPricing $pricing)
    {
        return response()->json([
            'tiers' => $pricing->tiers(),
            'maximum_participants' => $pricing->maximumParticipants(),
            'multi_participant_ready' => $pricing->isReady(),
        ]);
    }

    public function adminShow(PrivateParticipantPricing $pricing)
    {
        return response()->json([
            'tiers' => $pricing->tiers(),
            'maximum_participants' => $pricing->maximumParticipants(),
            'multi_participant_ready' => $pricing->isReady(),
            'tutor_share_percent' => 80,
            'admin_share_percent' => 20,
        ]);
    }

    public function update(Request $request, PrivateParticipantPricing $pricing)
    {
        $data = $request->validate([
            'tiers' => ['required', 'array'],
            'maximum_participants' => ['sometimes', 'integer', 'between:2,'.PrivateParticipantPricing::HARD_MAXIMUM],
            'tiers.*.discount_percent' => ['nullable', 'numeric', 'between:0,90'],
        ]);

        $maximum = (int) ($data['maximum_participants'] ?? $pricing->maximumParticipants());
        $tiers = [];
        $previousDiscount = 0;
        $incomplete = false;
        foreach (range(2, $maximum) as $count) {
            $discount = $data['tiers'][(string) $count]['discount_percent'] ?? null;
            if ($discount === null) {
                $incomplete = true;

                continue;
            }
            $discount = (float) $discount;
            if ($discount < $previousDiscount) {
                throw ValidationException::withMessages([
                    'tiers' => 'Diskon tidak boleh turun ketika jumlah peserta bertambah.',
                ]);
            }
            $tiers[(string) $count] = ['discount_percent' => $discount];
            $previousDiscount = $discount;
        }

        DB::transaction(function () use ($tiers, $maximum) {
            Setting::query()->updateOrCreate(
                ['key' => PrivateParticipantPricing::KEY],
                ['value' => json_encode($tiers, JSON_THROW_ON_ERROR)]
            );
            Setting::query()->updateOrCreate(
                ['key' => PrivateParticipantPricing::MAXIMUM_KEY],
                ['value' => (string) $maximum]
            );
            Setting::query()->updateOrCreate(['key' => 'admin_fee'], ['value' => '20']);
        });

        return response()->json([
            'message' => $incomplete ? 'Pengaturan tersimpan sebagai draf. Pesanan multi-peserta belum aktif.' : 'Diskon peserta aktif; hasil transaksi otomatis dibagi 80% tutor dan 20% admin.',
            'tiers' => $tiers,
            'maximum_participants' => $maximum,
            'multi_participant_ready' => ! $incomplete,
            'tutor_share_percent' => 80,
            'admin_share_percent' => 20,
        ]);
    }
}
