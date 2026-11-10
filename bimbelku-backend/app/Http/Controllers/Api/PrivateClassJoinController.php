<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningPackage;
use App\Models\Notification;
use App\Models\PrivateClassJoin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrivateClassJoinController extends Controller
{
    private function assertOwner(Request $request, LearningPackage $package): void
    {
        abort_unless((int) $package->student_id === (int) $request->user()->id, 403);
        abort_unless((int) $package->participant_count > 1, 422, 'Kode kelas hanya tersedia untuk privat bersama teman.');
    }

    private function capacity(LearningPackage $package): int
    {
        return max(0, (int) $package->participant_count - ($package->purchaser_participates ? 1 : 0));
    }

    public function ownerIndex(Request $request, LearningPackage $learningPackage)
    {
        $this->assertOwner($request, $learningPackage);

        return response()->json([
            'code' => $learningPackage->status === 'active' ? $learningPackage->class_join_code : null,
            'capacity' => $this->capacity($learningPackage),
            'approved_count' => $learningPackage->classJoins()->where('status', 'approved')->count(),
            'can_join' => $learningPackage->status === 'active',
            'requests' => $learningPackage->classJoins()
                ->with('student:id,name,email')
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->latest('id')
                ->get(['id', 'learning_package_id', 'student_id', 'status', 'created_at', 'decided_at'])
                ->map(fn (PrivateClassJoin $join) => [
                    'id' => $join->id,
                    'status' => $join->status,
                    'name' => $join->student?->name,
                    'email' => $join->student?->email,
                    'created_at' => $join->created_at,
                ]),
        ]);
    }

    public function createCode(Request $request, LearningPackage $learningPackage)
    {
        $this->assertOwner($request, $learningPackage);
        abort_unless($learningPackage->status === 'active', 422, 'Kode tersedia setelah semua tutor ditemukan dan paket aktif.');

        $code = DB::transaction(function () use ($learningPackage) {
            $package = LearningPackage::query()->lockForUpdate()->findOrFail($learningPackage->id);
            abort_unless($package->status === 'active', 422, 'Paket tidak lagi aktif.');
            if (! $package->class_join_code) {
                do {
                    $code = strtoupper(Str::random(12));
                } while (LearningPackage::query()->where('class_join_code', $code)->exists());
                $package->update(['class_join_code' => $code]);
            }

            return $package->class_join_code;
        });

        return response()->json(['code' => $code]);
    }

    public function mine(Request $request)
    {
        return response()->json(PrivateClassJoin::query()
            ->where('student_id', $request->user()->id)
            ->with(['package:id,student_id,package_code,status,participant_count', 'package.student:id,name'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (PrivateClassJoin $join) => [
                'id' => $join->id,
                'status' => $join->status,
                'package_code' => $join->package?->package_code,
                'package_status' => $join->package?->status,
                'owner_name' => $join->package?->student?->name,
            ]));
    }

    public function requestJoin(Request $request)
    {
        $validated = $request->validate(['code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{12}$/']]);
        $code = strtoupper($validated['code']);

        $join = DB::transaction(function () use ($request, $code) {
            $package = LearningPackage::query()->where('class_join_code', $code)->lockForUpdate()->first();
            abort_unless($package && $package->status === 'active', 422, 'Kode kelas tidak ditemukan atau sudah tidak aktif.');
            abort_unless((int) $package->student_id !== (int) $request->user()->id, 422, 'Kamu sudah menjadi pemesan kelas ini.');
            abort_unless($package->classJoins()->where('status', 'approved')->count() < $this->capacity($package), 422, 'Kelas ini sudah penuh.');

            $existing = $package->classJoins()->where('student_id', $request->user()->id)->first();
            if ($existing) {
                abort_if($existing->status === 'rejected', 422, 'Permintaanmu sebelumnya ditolak pemesan. Minta pemesan menghubungi bantuan bila perlu.');

                return $existing;
            }

            abort_unless($package->classJoins()->where('status', 'pending')->count() < 20, 422, 'Antrean permintaan bergabung sudah penuh. Coba lagi nanti.');
            $join = $package->classJoins()->create(['student_id' => $request->user()->id, 'status' => 'pending']);
            Notification::create([
                'user_id' => $package->student_id,
                'title' => 'Permintaan bergabung kelas',
                'message' => $request->user()->name.' meminta bergabung ke paket '.$package->package_code.'. Periksa dan setujui di Proses Pesanan.',
                'type' => 'info',
                'target_url' => '/student/packages',
            ]);

            return $join;
        });

        return response()->json([
            'message' => $join->status === 'approved' ? 'Kamu sudah tergabung di kelas ini.' : 'Permintaan dikirim. Jadwal muncul setelah pemesan menyetujui.',
            'status' => $join->status,
        ]);
    }

    public function decide(Request $request, LearningPackage $learningPackage, PrivateClassJoin $classJoin)
    {
        $this->assertOwner($request, $learningPackage);
        abort_unless((int) $classJoin->learning_package_id === (int) $learningPackage->id, 404);
        $validated = $request->validate(['decision' => ['required', 'in:approve,reject']]);

        DB::transaction(function () use ($learningPackage, $classJoin, $validated) {
            $package = LearningPackage::query()->lockForUpdate()->findOrFail($learningPackage->id);
            abort_unless($package->status === 'active', 422, 'Paket tidak lagi aktif.');
            $join = PrivateClassJoin::query()->lockForUpdate()->findOrFail($classJoin->id);
            abort_unless($join->status === 'pending', 422, 'Permintaan ini sudah diputuskan.');
            if ($validated['decision'] === 'approve') {
                abort_unless($package->classJoins()->where('status', 'approved')->count() < $this->capacity($package), 422, 'Semua tempat peserta sudah terisi.');
            }
            $approved = $validated['decision'] === 'approve';
            $join->update(['status' => $approved ? 'approved' : 'rejected', 'decided_at' => now()]);
            Notification::create([
                'user_id' => $join->student_id,
                'title' => $approved ? 'Kamu bergabung ke kelas privat' : 'Permintaan bergabung ditolak',
                'message' => $approved
                    ? 'Pemesan menyetujui permintaanmu. Jadwal kelas sekarang ada di Kelas Saya. Absen dan konfirmasi sesi dilakukan pemesan.'
                    : 'Pemesan tidak menyetujui permintaan bergabung ke paket '.$package->package_code.'.',
                'type' => $approved ? 'success' : 'info',
                'target_url' => '/student/my-classes',
            ]);
        });

        return response()->json(['message' => $validated['decision'] === 'approve' ? 'Peserta disetujui dan jadwalnya sudah terhubung.' : 'Permintaan ditolak.']);
    }
}
