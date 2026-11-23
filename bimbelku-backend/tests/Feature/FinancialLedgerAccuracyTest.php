<?php

namespace Tests\Feature;

use App\Models\FinancialJournal;
use App\Models\FinancialLedgerEntry;
use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\Payout;
use App\Models\User;
use App\Services\FinancialLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class FinancialLedgerAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_gateway_payment_posts_platform_cash_instead_of_the_manual_clearing_account(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = $this->order($student, 150000);

        $journal = app(FinancialLedgerService::class)->recordPaymentReceived($order->fresh());

        $this->assertSame(150000.0, $this->amount($journal, 'platform_cash'));
        $this->assertSame(0.0, $this->amount($journal, 'customer_payment_deposits'));
        $this->assertSame(150000.0, $this->amount($journal, 'customer_funds', 'credit'));
        $this->assertSame(0, FinancialLedgerEntry::query()
            ->where('account_code', 'customer_payment_deposits')
            ->count());
        $this->assertSame(0.0, $this->debitBalance('customer_payment_deposits'));
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_manual_transfer_receipt_nets_the_clearing_account_to_zero(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = $this->order($student, 150000);
        $ledger = app(FinancialLedgerService::class);

        // Rp100.000 masuk lewat transfer manual yang sudah diverifikasi admin.
        $submission = PaymentSubmission::create([
            'order_id' => $order->id,
            'sequence' => 1,
            'status' => 'verified',
            'received_amount' => 100000,
        ]);
        $ledger->recordExternalPaymentReceipt($submission);

        // Sisanya Rp50.000 masuk lewat gateway.
        $journal = $ledger->recordPaymentReceived($order->fresh());

        $this->assertSame(50000.0, $this->amount($journal, 'platform_cash'));
        $this->assertSame(100000.0, $this->amount($journal, 'customer_payment_deposits'));
        $this->assertSame(150000.0, $this->amount($journal, 'customer_funds', 'credit'));
        // Akun clearing hanya boleh menyimpan uang yang belum jelas asalnya.
        $this->assertSame(0.0, $this->debitBalance('customer_payment_deposits'));
        $this->assertSame(150000.0, $this->debitBalance('platform_cash'));
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_withholding_tax_payout_clears_the_tutor_payable_and_books_a_tax_liability(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $fixture['first']['booking']->forceFill(['payout_status' => 'ready'])->save();

        $payout = $this->completedPayout($fixture['teacher'], 40000, 2000, 38000);
        $journal = $this->journal("payout:{$payout->id}:completed");

        $this->assertSame(40000.0, $this->amount($journal, 'tutor_payable'));
        $this->assertSame(38000.0, $this->amount($journal, 'platform_cash', 'credit'));
        $this->assertSame(2000.0, $this->amount($journal, 'tax_payable', 'credit'));
        // Debit sebesar hak bruto membuat kewajiban ke tutor benar-benar nol.
        $this->assertSame(0.0, $this->creditBalance('tutor_payable'));
        $this->assertSame(2000.0, $this->creditBalance('tax_payable'));
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_reversed_payout_mirrors_the_tax_entries_and_restores_the_payable(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $fixture['first']['booking']->forceFill(['payout_status' => 'ready'])->save();

        $payout = $this->completedPayout($fixture['teacher'], 40000, 2000, 38000);
        $payout->forceFill(['status' => 'reversed'])->save();

        $journal = $this->journal("payout:{$payout->id}:reversed");
        $this->assertSame(38000.0, $this->amount($journal, 'platform_cash'));
        $this->assertSame(2000.0, $this->amount($journal, 'tax_payable'));
        $this->assertSame(40000.0, $this->amount($journal, 'tutor_payable', 'credit'));

        $this->assertSame(40000.0, $this->creditBalance('tutor_payable'));
        $this->assertSame(0.0, $this->creditBalance('tax_payable'));
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_legacy_payout_without_tax_breakdown_is_not_charged_a_phantom_withholding(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $payout = Payout::create($this->payoutAttributes($teacher, 'processing', 120000));
        $payout->forceFill(['status' => 'completed'])->save();

        $journal = $this->journal("payout:{$payout->id}:completed");
        $this->assertSame(2, $journal->entries->count());
        $this->assertSame(120000.0, $this->amount($journal, 'tutor_payable'));
        $this->assertSame(120000.0, $this->amount($journal, 'platform_cash', 'credit'));
    }

    public function test_finance_report_nets_reversed_payouts_and_reports_cumulative_tutor_balances(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $fixture['first']['booking']->forceFill(['payout_status' => 'ready'])->save();
        $payout = $this->completedPayout($fixture['teacher'], 40000, 2000, 38000);

        Sanctum::actingAs($fixture['admin']);
        $summary = $this->getJson('/api/admin/finance/report')->assertOk()->json('summary');
        $this->assertSame(40000.0, (float) $summary['payouts_completed']);
        $this->assertSame(0.0, (float) $summary['tutor_payable_balance']);

        $payout->forceFill(['status' => 'reversed'])->save();

        $reversed = $this->getJson('/api/admin/finance/report')->assertOk()->json('summary');
        // Payout yang dibalik bank tidak boleh tetap dihitung sebagai pencairan.
        $this->assertSame(0.0, (float) $reversed['payouts_completed']);
        $this->assertSame(40000.0, (float) $reversed['tutor_payable_balance']);

        // Saldo kewajiban adalah akumulasi, bukan angka periode.
        $future = $this->getJson('/api/admin/finance/report?date_from=2027-01-01&date_to=2027-01-31')
            ->assertOk()
            ->json('summary');
        $this->assertSame(0.0, (float) $future['payouts_completed']);
        $this->assertSame(40000.0, (float) $future['tutor_payable_balance']);
    }

    private function order(User $student, float $amount): Order
    {
        return Order::create([
            'user_id' => $student->id,
            'order_id' => 'ORD-LEDGER-'.uniqid(),
            'amount' => $amount,
            'subtotal_amount' => $amount,
            'discount_amount' => 0,
            'status' => 'paid',
            'external_received_amount' => $amount,
            'wallet_applied_amount' => 0,
        ]);
    }

    private function completedPayout(User $teacher, float $requested, float $tax, float $net): Payout
    {
        $payout = Payout::create([
            ...$this->payoutAttributes($teacher, 'processing', $net),
            'requested_amount' => $requested,
            'tax_amount' => $tax,
        ]);
        $payout->forceFill(['status' => 'completed'])->save();

        return $payout;
    }

    private function payoutAttributes(User $teacher, string $status, float $amount): array
    {
        return [
            'user_id' => $teacher->id,
            'amount' => $amount,
            'period' => 'November 2026',
            'total_classes' => 1,
            'status' => $status,
            'payment_provider' => 'manual',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'bank_name' => 'BCA',
            'payout_channel_code' => 'BCA',
            'booking_ids' => [],
            'allocation_breakdown' => [],
        ];
    }

    private function journal(string $eventKey): FinancialJournal
    {
        return FinancialJournal::query()->where('event_key', $eventKey)->with('entries')->firstOrFail();
    }

    private function amount(FinancialJournal $journal, string $account, string $side = 'debit'): float
    {
        return round((float) $journal->entries
            ->where('account_code', $account)
            ->where('side', $side)
            ->sum('amount'), 2);
    }

    private function debitBalance(string $account): float
    {
        return $this->balance($account, 'credit');
    }

    private function creditBalance(string $account): float
    {
        return $this->balance($account, 'debit');
    }

    private function balance(string $account, string $subtractedSide): float
    {
        $balance = FinancialLedgerEntry::query()
            ->where('account_code', $account)
            ->selectRaw('SUM(CASE WHEN side = ? THEN -amount ELSE amount END) AS balance', [$subtractedSide])
            ->value('balance');

        return round((float) $balance, 2);
    }
}
