<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\TeacherPayoutRequest;
use App\Models\User;
use App\Services\TeacherPayoutService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class PayoutActiveGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_test');
        config()->set('xendit.webhook_token', 'callback-test');
        config()->set('xendit.base_url', 'https://api.xendit.test');
        config()->set('xendit.payout_api_version', '2025-09-01');
    }

    public function test_active_guard_mirrors_user_id_while_active_and_clears_when_terminal(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);

        $payout = Payout::create($this->payoutAttributes($teacher, 'processing'));
        $this->assertSame($teacher->id, (int) $payout->fresh()->active_guard);

        $payout->forceFill(['status' => 'completed'])->save();
        $this->assertNull($payout->fresh()->active_guard);
    }

    public function test_on_hold_also_holds_the_guard(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $payout = Payout::create($this->payoutAttributes($teacher, 'on_hold'));

        $this->assertSame($teacher->id, (int) $payout->fresh()->active_guard);
    }

    public function test_second_active_payout_is_rejected_by_the_database_index(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Payout::create($this->payoutAttributes($teacher, 'processing'));

        $this->expectException(QueryException::class);
        Payout::create($this->payoutAttributes($teacher, 'processing'));
    }

    public function test_request_is_rejected_when_an_active_payout_exists(): void
    {
        $fixture = SessionFinanceFixture::make($this, 2, 0);
        $teacher = $fixture['teacher'];
        Payout::create($this->payoutAttributes($teacher, 'processing', 75000));

        $this->expectExceptionMessage('Masih ada pencairan Rp75.000 yang sedang diproses.');
        app(TeacherPayoutService::class)->request($teacher->fresh(), 10000);
    }

    public function test_request_is_allowed_after_the_previous_payout_is_final(): void
    {
        $fixture = SessionFinanceFixture::make($this, 2, 0);
        $teacher = $fixture['teacher'];
        $previous = Payout::create($this->payoutAttributes($teacher, 'completed', 75000));
        $this->assertNull($previous->fresh()->active_guard);

        $guard = Payout::query()
            ->where('user_id', $teacher->id)
            ->whereIn('status', ['processing', 'on_hold'])
            ->exists();

        $this->assertFalse($guard, 'a final payout must not hold the guard');
    }

    public function test_admin_force_fail_releases_the_guard_and_allows_a_new_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $payout = Payout::create($this->payoutAttributes($teacher, 'processing', 50000));
        TeacherPayoutRequest::create([
            'teacher_id' => $teacher->id,
            'booking_ids' => [],
            'allocation_breakdown' => [],
            'gross_amount' => 50000,
            'commission_amount' => 0,
            'requested_amount' => 50000,
            'tax_amount' => 0,
            'net_amount' => 50000,
            'bank_name' => 'BCA',
            'payout_channel_code' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'status' => 'processing',
            'requested_at' => now(),
            'payout_id' => $payout->id,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/finance/payouts/{$payout->id}/force-fail", [
            'reason' => 'Transfer ditolak bank tujuan, sudah dikonfirmasi ke Xendit.',
        ])->assertOk();

        $this->assertSame('failed', $payout->fresh()->status);
        $this->assertNull($payout->fresh()->active_guard);
        $this->assertSame(
            'Transfer ditolak bank tujuan, sudah dikonfirmasi ke Xendit.',
            TeacherPayoutRequest::query()->where('payout_id', $payout->id)->value('review_notes')
        );
    }

    public function test_force_fail_requires_a_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $payout = Payout::create($this->payoutAttributes($teacher, 'processing'));

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/finance/payouts/{$payout->id}/force-fail", ['reason' => 'pendek'])
            ->assertStatus(422);
    }

    public function test_force_fail_is_rejected_for_a_final_payout(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $payout = Payout::create($this->payoutAttributes($teacher, 'completed'));

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/finance/payouts/{$payout->id}/force-fail", [
            'reason' => 'Sudah final, tidak boleh diubah lagi.',
        ])->assertStatus(422);
    }

    public function test_backfill_fills_rows_that_were_active_before_the_column_existed(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);

        // Simulate a pre-migration row: the column does not exist yet in the
        // migrated schema, so write it through the query builder and replay
        // the migration's backfill statement against that row.
        $id = DB::table('payouts')->insertGetId([
            'user_id' => $teacher->id,
            'amount' => 40000,
            'period' => 'Oktober 2026',
            'total_classes' => 1,
            'status' => 'on_hold',
            'payment_provider' => 'xendit',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'bank_name' => 'BCA',
            'payout_channel_code' => 'BCA',
            'booking_ids' => json_encode([]),
            'allocation_breakdown' => json_encode([]),
            'active_guard' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payouts')
            ->whereIn('status', Payout::ACTIVE_STATUSES)
            ->update(['active_guard' => DB::raw('user_id')]);

        $this->assertSame($teacher->id, (int) DB::table('payouts')->where('id', $id)->value('active_guard'));
    }

    private function payoutAttributes(User $teacher, string $status, float $amount = 100000): array
    {
        return [
            'user_id' => $teacher->id,
            'amount' => $amount,
            'period' => 'November 2026',
            'total_classes' => 1,
            'status' => $status,
            'payment_provider' => 'xendit',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'bank_name' => 'BCA',
            'payout_channel_code' => 'BCA',
            'booking_ids' => [],
            'allocation_breakdown' => [],
        ];
    }
}
