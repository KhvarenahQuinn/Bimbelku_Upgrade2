<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\FinancialJournal;
use App\Models\Payout;
use App\Models\TeacherPayoutRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\TeacherPayoutService;
use App\Services\XenditMoneyMovementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class PayoutLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ?string $lastPayoutReference = null;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_test');
        config()->set('xendit.webhook_token', 'callback-test');
        config()->set('xendit.base_url', 'https://api.xendit.test');
        config()->set('xendit.payout_api_version', '2025-09-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_create_payout_sends_destination_amount_only(): void
    {
        Http::fake(['https://api.xendit.test/v3/payouts' => Http::response(['payout_id' => 'po-payload-1', 'status' => 'ACCEPTED'])]);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'address' => 'Yogyakarta']);
        TeacherProfile::create([
            'user_id' => $teacher->id, 'bank_name' => 'Bank Central Asia (BCA)', 'payout_channel_code' => 'BCA',
            'account_number' => '1234567890', 'account_name' => $teacher->name, 'location' => 'Yogyakarta',
        ]);
        $payout = Payout::create([
            'user_id' => $teacher->id, 'amount' => 120000, 'period' => 'November 2026', 'total_classes' => 0,
            'status' => 'processing', 'payment_provider' => 'xendit', 'account_number' => '1234567890',
            'account_name' => $teacher->name, 'bank_name' => 'BCA', 'payout_channel_code' => 'BCA', 'booking_ids' => [],
        ]);

        app(XenditMoneyMovementService::class)->createPayout($payout, $teacher->load('teacherProfile'));

        Http::assertSent(function (Request $request) {
            $totals = $request->data()['payout_details'] ?? [];

            return $request->url() === 'https://api.xendit.test/v3/payouts'
                && $totals['destination_amount'] === 120000
                && $totals['destination_currency'] === 'IDR'
                && $totals['source_currency'] === 'IDR'
                && ! array_key_exists('source_amount', $totals)
                && ! array_key_exists('receipt_notification', $request->data());
        });
        $this->assertDatabaseHas('payouts', ['id' => $payout->id, 'gateway_payout_id' => 'po-payload-1', 'gateway_status' => 'ACCEPTED']);
    }

    public function test_create_payout_sends_a_valid_idempotency_key(): void
    {
        Http::fake(['https://api.xendit.test/v3/payouts' => Http::response(['payout_id' => 'po-idem', 'status' => 'ACCEPTED'])]);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'address' => 'Yogyakarta']);
        TeacherProfile::create([
            'user_id' => $teacher->id, 'bank_name' => 'Bank Central Asia (BCA)', 'payout_channel_code' => 'BCA',
            'account_number' => '1234567890', 'account_name' => $teacher->name, 'location' => 'Yogyakarta',
        ]);
        $payout = Payout::create([
            'user_id' => $teacher->id, 'amount' => 120000, 'period' => 'November 2026', 'total_classes' => 0,
            'status' => 'processing', 'payment_provider' => 'xendit', 'account_number' => '1234567890',
            'account_name' => $teacher->name, 'bank_name' => 'BCA', 'payout_channel_code' => 'BCA', 'booking_ids' => [],
        ]);

        app(XenditMoneyMovementService::class)->createPayout($payout, $teacher->load('teacherProfile'));

        $captured = null;
        Http::assertSent(function (Request $request) use (&$captured) {
            if ($request->url() !== 'https://api.xendit.test/v3/payouts') {
                return false;
            }
            $captured = $request->header('idempotency-key');
            $captured = is_array($captured) ? implode(',', $captured) : $captured;

            return true;
        });

        $this->assertNotNull($captured, 'Payout request carried no idempotency-key header at all.');
        $this->assertGreaterThanOrEqual(10, strlen((string) $captured), 'idempotency-key was: '.(string) $captured);
        $this->assertSame('BKU-PAY-'.str_pad((string) $payout->id, 8, '0', STR_PAD_LEFT), (string) $captured);
    }

    public function test_payout_sends_source_currency_and_a_short_description(): void
    {
        Http::fake(['https://api.xendit.test/v3/payouts' => Http::response(['payout_id' => 'po-payload', 'status' => 'ACCEPTED'])]);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'address' => 'Jl. Rungkut Asri Timur No. 14']);
        TeacherProfile::create([
            'user_id' => $teacher->id, 'bank_name' => 'Bank Central Asia (BCA)', 'payout_channel_code' => 'BCA',
            'account_number' => '1234567890', 'account_name' => $teacher->name, 'location' => 'Surabaya',
        ]);
        $payout = Payout::create([
            'user_id' => $teacher->id, 'amount' => 50000, 'period' => 'November 2026', 'total_classes' => 0,
            'status' => 'processing', 'payment_provider' => 'xendit', 'account_number' => '1234567890',
            'account_name' => $teacher->name, 'bank_name' => 'BCA', 'payout_channel_code' => 'BCA', 'booking_ids' => [],
        ]);

        app(XenditMoneyMovementService::class)->createPayout($payout, $teacher->load('teacherProfile'));

        Http::assertSent(function (Request $request) {
            $data = $request->data();
            $details = $data['payout_details'] ?? [];
            $address = $data['recipient']['address'] ?? [];

            // Xendit rejects the request without source_currency and city even
            // though neither appears as required in the published schema.
            return ($details['source_currency'] ?? null) === 'IDR'
                && ($details['destination_currency'] ?? null) === 'IDR'
                && array_key_exists('destination_amount', $details)
                && ! array_key_exists('source_amount', $details)
                && ($address['city'] ?? null) === 'Surabaya'
                && ($address['street_line_1'] ?? null) === 'Jl. Rungkut Asri Timur No. 14'
                && ($data['description'] ?? null) === 'BimbelKu';
        });
        $this->assertSame(1, Http::recorded()->count(), 'Payout must be sent exactly once, with no amount fallback.');
    }

    public function test_payout_is_rejected_locally_when_the_city_is_missing(): void
    {
        Http::fake();
        [$teacher] = $this->reservedPayout(30000);
        $teacher->forceFill(['address' => 'Jl. Rungkut Asri Timur No. 14'])->save();
        $teacher->teacherProfile->forceFill([
            'location' => null,
            'bank_name' => 'Bank Central Asia (BCA)',
            'payout_channel_code' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
        ])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kota pada profil tutor');

        try {
            app(TeacherPayoutService::class)->request($teacher->fresh('teacherProfile'), 30000);
        } finally {
            // The city gate must stop the request before it reaches Xendit.
            Http::assertNothingSent();
        }
    }

    public function test_pending_compliance_assessment_sets_on_hold_and_keeps_reservation(): void
    {
        [$teacher, $booking, $payout] = $this->reservedPayout(30000);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $baseline = (float) $booking->fresh()->teacher_reserved_amount;

        // The webhook event is v3_payout.pending_compliance, but the status in
        // the payload is the longer PayoutStatus enum value. Asserting the
        // short form here was what hid the dead branch.
        $this->webhook('v3_payout.pending_compliance', 'hold-event-1', ['payout_id' => 'po-hold', 'status' => 'PENDING_COMPLIANCE_ASSESSMENT']);

        $this->assertSame('on_hold', $payout->fresh()->status);
        $this->assertSame('PENDING_COMPLIANCE_ASSESSMENT', $payout->fresh()->gateway_status);
        $this->assertSame($baseline, (float) $booking->fresh()->teacher_reserved_amount);
        $this->assertSame('on_hold', TeacherPayoutRequest::query()->where('payout_id', $payout->id)->value('status'));
        $this->assertDatabaseHas('notifications', ['unique_key' => "payout-on-hold:{$payout->id}"]);
        $this->assertDatabaseHas('notifications', ['unique_key' => "payout-compliance:{$payout->id}:{$admin->id}"]);
    }

    public function test_on_hold_can_still_succeed(): void
    {
        [$teacher, $booking, $payout] = $this->reservedPayout(30000);
        $this->webhook('v3_payout.pending_compliance', 'hold-event-2', ['payout_id' => 'po-hold-succeed', 'status' => 'PENDING_COMPLIANCE_ASSESSMENT']);
        $this->assertSame('on_hold', $payout->fresh()->status);

        $this->webhook('v3_payout.succeeded', 'success-event-1', ['payout_id' => 'po-hold-succeed', 'status' => 'SUCCEEDED']);

        $payout->refresh();
        $booking->refresh();
        $this->assertSame('completed', $payout->status);
        $this->assertSame(30000.0, (float) $booking->teacher_paid_amount);
        $this->assertSame(0.0, (float) $booking->teacher_reserved_amount);
        $this->assertSame(1, FinancialJournal::query()->where('event_key', "payout:{$payout->id}:completed")->count());
    }

    public function test_compliance_rejected_releases_reservation(): void
    {
        [$teacher, $booking, $payout] = $this->reservedPayout(30000);

        // COMPLIANCE_REJECTED is in the lifecycle table but not the OpenAPI
        // enum; if Xendit ever sends it, the funds must not stay reserved.
        $this->webhook('v3_payout.rejected', 'rejected-event-1', ['payout_id' => 'po-rejected', 'status' => 'COMPLIANCE_REJECTED']);

        $payout->refresh();
        $booking->refresh();
        $this->assertSame('failed', $payout->status);
        $this->assertSame(0.0, (float) $booking->teacher_reserved_amount);
        $this->assertSame('ready', $booking->payout_status);
        $this->assertSame('failed', TeacherPayoutRequest::query()->where('payout_id', $payout->id)->value('status'));
        $this->assertSame(40000.0, app(TeacherPayoutService::class)->availableAmount($teacher->id));
        $this->assertDatabaseHas('notifications', ['unique_key' => "payout-failed:{$payout->id}:COMPLIANCE_REJECTED"]);
    }

    public function test_rejected_status_after_on_hold_releases_reservation(): void
    {
        [$teacher, $booking, $payout] = $this->reservedPayout(30000);
        $this->webhook('v3_payout.pending_compliance', 'hold-event-3', ['payout_id' => 'po-hold-reject', 'status' => 'PENDING_COMPLIANCE_ASSESSMENT']);
        $this->assertSame('on_hold', $payout->fresh()->status);

        $this->webhook('v3_payout.rejected', 'rejected-event-2', ['payout_id' => 'po-hold-reject', 'status' => 'REJECTED']);

        $payout->refresh();
        $booking->refresh();
        $this->assertSame('failed', $payout->status);
        $this->assertSame(0.0, (float) $booking->teacher_reserved_amount);
        $this->assertSame(40000.0, app(TeacherPayoutService::class)->availableAmount($teacher->id));
    }

    public function test_single_word_name_omits_surname(): void
    {
        Http::fake(['https://api.xendit.test/v3/payouts' => Http::response(['payout_id' => 'po-name', 'status' => 'ACCEPTED'])]);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'address' => 'Yogyakarta', 'name' => 'Rina']);
        TeacherProfile::create([
            'user_id' => $teacher->id, 'bank_name' => 'Bank Central Asia (BCA)', 'payout_channel_code' => 'BCA',
            'account_number' => '1234567890', 'account_name' => $teacher->name, 'location' => 'Yogyakarta',
        ]);
        $payout = Payout::create([
            'user_id' => $teacher->id, 'amount' => 120000, 'period' => 'November 2026', 'total_classes' => 0,
            'status' => 'processing', 'payment_provider' => 'xendit', 'account_number' => '1234567890',
            'account_name' => $teacher->name, 'bank_name' => 'BCA', 'payout_channel_code' => 'BCA', 'booking_ids' => [],
        ]);

        app(XenditMoneyMovementService::class)->createPayout($payout, $teacher->load('teacherProfile'));

        Http::assertSent(function (Request $request) {
            $recipient = $request->data()['recipient'] ?? [];

            return $recipient['given_name'] === 'Rina'
                && ! array_key_exists('surname', $recipient);
        });
    }

    public function test_two_word_name_sends_surname(): void
    {
        Http::fake(['https://api.xendit.test/v3/payouts' => Http::response(['payout_id' => 'po-name-2', 'status' => 'ACCEPTED'])]);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'address' => 'Yogyakarta', 'name' => 'Rina Matematika']);
        TeacherProfile::create([
            'user_id' => $teacher->id, 'bank_name' => 'Bank Central Asia (BCA)', 'payout_channel_code' => 'BCA',
            'account_number' => '1234567890', 'account_name' => $teacher->name, 'location' => 'Yogyakarta',
        ]);
        $payout = Payout::create([
            'user_id' => $teacher->id, 'amount' => 120000, 'period' => 'November 2026', 'total_classes' => 0,
            'status' => 'processing', 'payment_provider' => 'xendit', 'account_number' => '1234567890',
            'account_name' => $teacher->name, 'bank_name' => 'BCA', 'payout_channel_code' => 'BCA', 'booking_ids' => [],
        ]);

        app(XenditMoneyMovementService::class)->createPayout($payout, $teacher->load('teacherProfile'));

        Http::assertSent(function (Request $request) {
            $recipient = $request->data()['recipient'] ?? [];

            return $recipient['given_name'] === 'Rina'
                && $recipient['surname'] === 'Matematika';
        });
    }

    public function test_reversed_after_completed_reverts_allocation_and_books_journal(): void
    {
        [$teacher, $booking, $payout] = $this->reservedPayout(30000);
        $this->webhook('v3_payout.succeeded', 'success-event-2', ['payout_id' => 'po-reverse', 'status' => 'SUCCEEDED']);
        $this->assertSame('completed', $payout->fresh()->status);
        $this->assertSame(30000.0, (float) $booking->fresh()->teacher_paid_amount);

        $this->webhook('v3_payout.reversed', 'reversed-event-1', ['payout_id' => 'po-reverse', 'status' => 'REVERSED']);

        $payout->refresh();
        $booking->refresh();
        $this->assertSame('reversed', $payout->status);
        $this->assertSame(0.0, (float) $booking->teacher_paid_amount);
        $this->assertSame(0.0, (float) $booking->teacher_reserved_amount);
        $this->assertSame('ready', $booking->payout_status);

        $journal = FinancialJournal::query()->where('event_key', "payout:{$payout->id}:reversed")->with('entries')->firstOrFail();
        $this->assertSame(1, FinancialJournal::query()->where('event_key', "payout:{$payout->id}:completed")->count());
        $this->assertSame(
            ['platform_cash', 'tutor_payable'],
            $journal->entries->pluck('account_code')->sort()->values()->all()
        );
        $paidEntry = $journal->entries->firstWhere('account_code', 'tutor_payable');
        $cashEntry = $journal->entries->firstWhere('account_code', 'platform_cash');
        $this->assertSame('credit', $paidEntry->side);
        $this->assertSame('debit', $cashEntry->side);
        $this->assertSame(30000.0, (float) $paidEntry->amount);

        $service = app(TeacherPayoutService::class);
        $this->assertSame(40000.0, $service->availableAmount($teacher->id));
        $this->assertDatabaseHas('notifications', ['unique_key' => "payout-reversed:{$payout->id}"]);
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_reversed_before_completed_only_releases(): void
    {
        [$teacher, $booking, $payout] = $this->reservedPayout(30000);

        $this->webhook('v3_payout.reversed', 'reversed-event-2', ['payout_id' => 'po-reverse-early', 'status' => 'REVERSED']);

        $payout->refresh();
        $booking->refresh();
        $this->assertSame('reversed', $payout->status);
        $this->assertSame(0.0, (float) $booking->teacher_paid_amount);
        $this->assertSame(0.0, (float) $booking->teacher_reserved_amount);
        $this->assertSame('ready', $booking->payout_status);
        $this->assertSame(1, FinancialJournal::query()->where('event_key', "payout:{$payout->id}:reversed")->count());
        $this->assertSame(0, FinancialJournal::query()->where('event_key', "payout:{$payout->id}:completed")->count());
        $this->assertSame(40000.0, app(TeacherPayoutService::class)->availableAmount($teacher->id));
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_late_failure_does_not_override_reversed(): void
    {
        [$teacher, $booking, $payout] = $this->reservedPayout(30000);
        $this->webhook('v3_payout.succeeded', 'success-event-3', ['payout_id' => 'po-reverse-late', 'status' => 'SUCCEEDED']);
        $this->webhook('v3_payout.reversed', 'reversed-event-3', ['payout_id' => 'po-reverse-late', 'status' => 'REVERSED']);

        $this->webhook('v3_payout.failed', 'failed-event-1', ['payout_id' => 'po-reverse-late', 'status' => 'FAILED']);

        $payout->refresh();
        $this->assertSame('reversed', $payout->status);
        $this->assertSame('REVERSED', $payout->gateway_status);
        $this->assertSame(1, FinancialJournal::query()->where('event_type', 'payout_reversed')->where('reference_id', $payout->id)->count());
    }

    public function test_reconcile_command_completes_a_stuck_payout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00'));
        [$teacher, $booking, $payout] = $this->reservedPayout(30000);
        $payout->forceFill(['gateway_payout_id' => 'po-stuck', 'created_at' => now()->subMinutes(10)])->save();
        Http::fake(['https://api.xendit.test/v3/payouts/po-stuck' => Http::response([
            'payout_id' => 'po-stuck', 'status' => 'SUCCEEDED', 'reference_id' => $payout->gateway_reference_id,
        ])]);

        $this->artisan('payments:reconcile-payouts')->assertSuccessful();

        $payout->refresh();
        $this->assertSame('completed', $payout->status);
        $this->assertNotNull($payout->gateway_last_checked_at);
        $this->assertSame(30000.0, (float) $booking->fresh()->teacher_paid_amount);
    }

    public function test_reconcile_command_skips_recent_or_just_checked_payouts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00'));
        [, , $recent] = $this->reservedPayout(30000);
        $recent->forceFill(['gateway_payout_id' => 'po-recent', 'created_at' => now()->subMinute()])->save();
        [, , $checked] = $this->reservedPayout(30000, 'second');
        $checked->forceFill([
            'gateway_payout_id' => 'po-checked',
            'created_at' => now()->subMinutes(30),
            'gateway_last_checked_at' => now(),
        ])->save();
        Http::fake();

        $this->artisan('payments:reconcile-payouts')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_admin_can_reconcile_payout_manually(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        [, , $payout] = $this->reservedPayout(30000);
        $payout->forceFill(['gateway_payout_id' => 'po-manual'])->save();
        Http::fake(['https://api.xendit.test/v3/payouts/po-manual' => Http::response([
            'payout_id' => 'po-manual', 'status' => 'ACCEPTED',
        ])]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/finance/payouts/{$payout->id}/reconcile")
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('gateway_status', 'ACCEPTED');

        // A faked response ignores headers, so assert the header explicitly:
        // without api-version the live GET returns 400 and reconcile always fails.
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.xendit.test/v3/payouts/po-manual'
            && $request->hasHeader('api-version', '2025-09-01'));

        $payout->refresh();
        $this->assertSame('processing', $payout->status);
        $this->assertSame('ACCEPTED', $payout->gateway_status);

        $final = Payout::create([
            'user_id' => $payout->user_id, 'amount' => 10000, 'period' => 'Final', 'total_classes' => 0,
            'status' => 'completed', 'payment_provider' => 'xendit', 'gateway_payout_id' => 'po-final',
            'booking_ids' => [], 'allocation_breakdown' => [],
        ]);
        $this->postJson("/api/admin/finance/payouts/{$final->id}/reconcile")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pencairan ini sudah final.');
    }

    /**
     * @return array{0:User,1:Booking,2:Payout}
     */
    private function reservedPayout(float $amount, string $suffix = ''): array
    {
        $fixture = SessionFinanceFixture::make($this, 2, 0);
        $teacher = $fixture['teacher'];
        ['booking' => $booking] = $fixture['first'];
        $booking->forceFill(['status' => 'completed', 'completed_at' => now(), 'payout_status' => 'ready'])->save();
        $booking->refresh();

        $reference = 'BKU-PAY-TEST'.($suffix ? '-'.$suffix : '');
        $request = TeacherPayoutRequest::create([
            'teacher_id' => $teacher->id,
            'booking_ids' => [$booking->id],
            'allocation_breakdown' => [['booking_id' => $booking->id, 'amount' => $amount]],
            'gross_amount' => $amount * 1.25,
            'commission_amount' => $amount * 0.25,
            'requested_amount' => $amount,
            'tax_amount' => 0,
            'net_amount' => $amount,
            'bank_name' => 'BCA',
            'payout_channel_code' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'status' => 'processing',
            'requested_at' => now(),
        ]);
        $payout = Payout::create([
            'user_id' => $teacher->id,
            'amount' => $amount,
            'period' => 'November 2026',
            'total_classes' => 1,
            'status' => 'processing',
            'payment_provider' => 'xendit',
            'gateway_reference_id' => $reference,
            'gateway_status' => 'ACCEPTED',
            'booking_ids' => [$booking->id],
            'allocation_breakdown' => [['booking_id' => $booking->id, 'amount' => $amount]],
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'payout_channel_code' => 'BCA',
        ]);
        $request->update(['payout_id' => $payout->id]);
        $booking->forceFill([
            'teacher_reserved_amount' => $amount,
            'payout_status' => 'requested',
            'payout_request_id' => $request->id,
        ])->save();

        $this->lastPayoutReference = $reference;

        return [$teacher, $booking->fresh(), $payout->fresh()];
    }

    private function webhook(string $event, string $eventId, array $data)
    {
        // The production matcher requires a reference_id or a known payout_id;
        // this fixture only seeds gateway_reference_id.
        if (! array_key_exists('reference_id', $data) && $this->lastPayoutReference !== null) {
            $data['reference_id'] = $this->lastPayoutReference;
        }

        return $this->postJson('/api/webhooks/xendit', [
            'id' => $eventId,
            'event' => $event,
            'data' => $data,
        ], ['x-callback-token' => 'callback-test']);
    }
}
