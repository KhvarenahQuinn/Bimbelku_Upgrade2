<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Services\XenditPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class DemoGatewayConnectivity extends Command
{
    protected $signature = 'demo:gateway-connectivity {stage=start : start|status} {order_id? : ID tagihan dari tahap start}';

    protected $description = 'Menguji koneksi nyata ke Xendit test mode tanpa menyentuh pesanan pelanggan';

    public function handle(XenditPaymentService $gateway): int
    {
        if (! app()->environment(['local', 'testing'])
            || ! str_starts_with((string) config('xendit.secret_key'), 'xnd_development_')) {
            $this->error('Demo hanya boleh memakai environment local/testing dan Xendit development key.');

            return self::FAILURE;
        }
        if (! $gateway->configured()) {
            $this->error('Konfigurasi Xendit test mode belum lengkap.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('stage')) {
            'start' => $this->start($gateway),
            'status' => $this->status($gateway),
            default => $this->invalidStage(),
        };
    }

    private function start(XenditPaymentService $gateway): int
    {
        $customer = User::query()->firstOrCreate(
            ['email' => 'demo.gateway@bimbelku.local'],
            [
                'name' => 'Demo Koneksi Pembayaran',
                'password' => Hash::make(Str::random(40)),
                'role' => 'student',
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );
        $order = Order::query()->create([
            'user_id' => $customer->id,
            'order_id' => 'DEMO-XENDIT-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)),
            'amount' => 15000,
            'status' => 'pending',
        ]);

        try {
            $session = $gateway->createSession($order, $customer);
        } catch (Throwable $exception) {
            $this->error('Sesi sandbox gagal dibuat: '.$exception->getMessage());
            $this->line('Tagihan demo ID '.$order->id.' tetap pending; tidak ada pembayaran yang dicatat.');

            return self::FAILURE;
        }

        $this->info('Koneksi Xendit test mode berhasil; ini bukan pembayaran nyata.');
        $this->line('Tagihan demo ID: '.$order->id);
        $this->line('Session ID: '.$session['session_id']);
        $this->line('Checkout sandbox: '.$session['checkout_url']);
        $this->line('Periksa status: php artisan demo:gateway-connectivity status '.$order->id);
        $this->warn('Demo ini hanya menguji pembuatan checkout dan status di Xendit. Jalankan tes PaymentJourneyDemoTest untuk validasi seluruh transisi paket/webhook.');

        return self::SUCCESS;
    }

    private function status(XenditPaymentService $gateway): int
    {
        $id = filter_var($this->argument('order_id'), FILTER_VALIDATE_INT);
        if (! $id) {
            $this->error('Isi ID tagihan demo dari hasil perintah start.');

            return self::FAILURE;
        }
        $order = Order::query()->where('order_id', 'like', 'DEMO-XENDIT-%')->find($id);
        if (! $order) {
            $this->error('Tagihan demo tidak ditemukan.');

            return self::FAILURE;
        }

        try {
            $remote = $gateway->fetchSession($order);
        } catch (Throwable $exception) {
            $this->error('Status gateway gagal diperiksa: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Status di Xendit: '.($remote['status'] ?? 'tidak diketahui'));
        $this->line('Status di BimbelKu: '.$order->fresh()->status);
        $this->line('Session ID: '.$order->gateway_session_id);
        $this->warn('Status lokal baru lunas setelah webhook diterima atau sinkronisasi status dari halaman pembayaran.');

        return self::SUCCESS;
    }

    private function invalidStage(): int
    {
        $this->error('Stage tersedia: start atau status.');

        return self::FAILURE;
    }
}
