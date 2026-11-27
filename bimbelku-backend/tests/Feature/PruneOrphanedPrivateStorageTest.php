<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Refund;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneOrphanedPrivateStorageTest extends TestCase
{
    use RefreshDatabase;

    private function seedPrivateFiles(): array
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);

        $cvPath = 'cvs/teacher-cv.pdf';
        $identityPath = 'teacher_identity/ktp-1.jpg';
        $paymentProofPath = 'payment_proofs/proof-1.jpg';
        $refundProofPath = 'refund_proofs/refund-1.jpg';
        $oldOrphanPath = 'payment_proofs/orphan-old.jpg';
        $recentOrphanPath = 'refund_proofs/orphan-recent.jpg';

        TeacherProfile::create([
            'user_id' => $teacher->id,
            'cv_file' => $cvPath,
            'identity_document' => $identityPath,
        ]);

        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-PRUNE-TEST-01',
            'amount' => 100000,
            'status' => 'paid',
            'payment_proof' => $paymentProofPath,
        ]);

        $refund = new Refund([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 50000,
            'reason' => 'Test refund',
        ]);
        $refund->forceFill([
            'status' => 'refunded',
            'proof' => $refundProofPath,
        ])->save();

        $disk = Storage::disk('local');
        foreach ([$cvPath, $identityPath, $paymentProofPath, $refundProofPath, $oldOrphanPath, $recentOrphanPath] as $file) {
            $disk->put($file, 'dummy-binary-content');
        }

        // Age referenced files + old orphan past 48h; keep recent orphan at 1h.
        $oldTimestamp = now()->subHours(48)->getTimestamp();
        foreach ([$cvPath, $identityPath, $paymentProofPath, $refundProofPath, $oldOrphanPath] as $file) {
            touch($disk->path($file), $oldTimestamp);
        }
        touch($disk->path($recentOrphanPath), now()->subHour()->getTimestamp());
        clearstatcache();

        return [
            'referenced' => [$cvPath, $identityPath, $paymentProofPath, $refundProofPath],
            'old_orphan' => $oldOrphanPath,
            'recent_orphan' => $recentOrphanPath,
        ];
    }

    public function test_dry_run_default_reports_orphaned_files_without_deleting_anything(): void
    {
        Storage::fake('local');
        $files = $this->seedPrivateFiles();

        $this->artisan('storage:prune-orphaned-private')
            ->expectsOutputToContain('Private storage sweep [dry-run] (hours=24): scanned=6 referenced=4 too_recent=1 orphaned=1 deleted=0')
            ->expectsOutputToContain($files['old_orphan'])
            ->assertExitCode(0);

        $disk = Storage::disk('local');
        foreach ($files['referenced'] as $path) {
            $disk->assertExists($path);
        }
        $disk->assertExists($files['old_orphan']);
        $disk->assertExists($files['recent_orphan']);
    }

    public function test_force_deletes_only_old_orphans_and_preserves_referenced_and_recent_files(): void
    {
        Storage::fake('local');
        $files = $this->seedPrivateFiles();

        $this->artisan('storage:prune-orphaned-private --force --hours=24')
            ->expectsOutputToContain('Private storage sweep [delete] (hours=24): scanned=6 referenced=4 too_recent=1 orphaned=1 deleted=1')
            ->assertExitCode(0);

        $disk = Storage::disk('local');
        foreach ($files['referenced'] as $path) {
            $disk->assertExists($path);
        }
        $disk->assertMissing($files['old_orphan']);
        $disk->assertExists($files['recent_orphan']);
    }

    public function test_delete_alias_with_zero_hours_prunes_all_unreferenced_orphans(): void
    {
        Storage::fake('local');
        $files = $this->seedPrivateFiles();

        $this->artisan('storage:prune-orphaned-private --delete --hours=0')
            ->expectsOutputToContain('Private storage sweep [delete] (hours=0): scanned=6 referenced=4 too_recent=0 orphaned=2 deleted=2')
            ->assertExitCode(0);

        $disk = Storage::disk('local');
        foreach ($files['referenced'] as $path) {
            $disk->assertExists($path);
        }
        $disk->assertMissing($files['old_orphan']);
        $disk->assertMissing($files['recent_orphan']);
    }
}
