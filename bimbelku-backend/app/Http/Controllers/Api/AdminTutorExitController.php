<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningPackage;
use App\Services\PartialPackageRefundService;
use Illuminate\Http\Request;

class AdminTutorExitController extends Controller
{
    public function __construct(
        private readonly PartialPackageRefundService $refunds,
    ) {}

    public function preview(Request $request, LearningPackage $learningPackage)
    {
        return response()->json([
            'data' => $this->refunds->previewTutorExit($learningPackage),
        ]);
    }

    public function store(Request $request, LearningPackage $learningPackage)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:500'],
            'evidence_note' => ['required', 'string', 'min:50', 'max:3000'],
            'confirm_token' => ['required', 'string', 'in:REFUND_SISA_PAKET'],
            'booking_ids' => ['required', 'array', 'min:1'],
            'booking_ids.*' => ['integer'],
        ], [
            'evidence_note.min' => 'Catatan bukti tutor berhenti wajib ditulis minimal 50 karakter.',
            'confirm_token.in' => 'Ketik REFUND_SISA_PAKET untuk mengonfirmasi refund seluruh sisa paket.',
        ]);

        $refund = $this->refunds->queueTutorExit(
            $learningPackage,
            $request->user(),
            $validated['reason'],
            $validated['evidence_note'],
            $validated['booking_ids'],
        );

        return response()->json([
            'message' => 'Refund sisa paket dibuat dan menunggu tujuan refund dari murid.',
            'data' => [
                'id' => $refund->id,
                'amount' => (float) $refund->amount,
                'status' => $refund->status,
            ],
        ], 201);
    }
}
