<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\CheapClass;
use App\Models\CheapClassSession;
use App\Models\Order;
use App\Models\PackageSession;
use App\Services\CheapClassService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoJourneyTime extends Command
{
    protected $signature = 'demo:journey-time
        {order : ID angka atau kode tagihan yang sudah lunas lewat checkout sandbox}
        {stage=status : status|start|end}
        {--session= : ID sesi bila ingin memilih sesi tertentu}';

    protected $description = 'Geser jadwal sesi tagihan sandbox lokal tanpa melompati aksi tutor, murid, atau admin';

    public function handle(CheapClassService $cheapClasses): int
    {
        if (! app()->environment(['local', 'testing'])
            || (app()->environment('local') && ! str_starts_with((string) config('xendit.secret_key'), 'xnd_development_'))) {
            $this->error('Perintah ini hanya tersedia di local/testing dengan kunci Xendit development.');

            return self::FAILURE;
        }

        $stage = (string) $this->argument('stage');
        if (! in_array($stage, ['status', 'start', 'end'], true)) {
            $this->error('Tahap harus status, start, atau end.');

            return self::FAILURE;
        }

        $orderId = trim((string) $this->argument('order'));
        $order = ctype_digit($orderId)
            ? Order::query()->find((int) $orderId)
            : Order::query()->where('order_id', $orderId)->first();
        if (! $order || $order->status !== 'paid' || $order->payment_provider !== 'xendit') {
            $this->error('Tagihan tidak ditemukan atau belum lunas melalui checkout sandbox. Tidak ada jadwal yang diubah.');

            return self::FAILURE;
        }

        if ($order->cheap_class_enrollment_id) {
            $class = $order->cheapClassEnrollment?->cheapClass;

            return $class ? $this->advanceSharedClass($class, $stage, $cheapClasses) : self::FAILURE;
        }

        $package = $order->learningPackage;
        if (! $package) {
            $this->error('Tagihan ini tidak terhubung ke paket atau Kelas Bersama.');

            return self::FAILURE;
        }

        $sessions = PackageSession::query()
            ->whereHas('subject', fn ($query) => $query->where('learning_package_id', $package->id))
            ->with(['subject', 'booking.order'])
            ->orderBy('scheduled_start_at')->orderBy('id')->get();
        if ($sessions->isEmpty()) {
            $this->error('Sesi belum tersedia. Tunggu tutor menerima penawaran setelah pembayaran.');

            return self::FAILURE;
        }

        $this->table(['Sesi', 'Mapel', 'Booking', 'Status', 'Jadwal'], $sessions->map(fn (PackageSession $session) => [
            $session->id,
            $session->subject?->subject_name ?? $session->subject?->name ?? '-',
            $session->booking_id ?: '-',
            $session->booking?->status ?? $session->status,
            $session->scheduled_start_at?->format('d-m-Y H:i') ?? '-',
        ])->all());
        if ($stage === 'status') {
            return self::SUCCESS;
        }

        $session = $this->chooseSession($sessions);
        if (! $session) {
            return self::FAILURE;
        }
        $booking = $session->booking;
        if (! $booking || $booking->order?->status !== 'paid') {
            $this->error('Sesi belum punya booking lunas. Selesaikan matching dan minta tutor menerima penawaran dahulu.');

            return self::FAILURE;
        }

        if ($stage === 'start') {
            if ($booking->status !== 'confirmed' || $booking->tutor_ready_at || $booking->student_confirmed_at) {
                $this->error('Sesi tidak dalam kondisi menunggu mulai. Periksa status sebelum memajukan waktu.');

                return self::FAILURE;
            }
            $start = now()->subMinutes(2)->startOfMinute();
            $end = $start->copy()->addMinutes(max(30, (int) $booking->duration_hours * 60));
        } else {
            if ($booking->status !== 'in_progress' || ! $booking->student_confirmed_at) {
                $this->error('Tutor harus menyatakan siap dan murid harus mengonfirmasi hadir sebelum sesi diakhiri.');

                return self::FAILURE;
            }
            $start = $booking->start_at;
            $end = now()->subMinute()->startOfMinute();
            if (! $start || $start->gte($end)) {
                $this->error('Jadwal mulai belum berada sebelum waktu akhir.');

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($session, $booking, $start, $end) {
            Booking::query()->whereKey($booking->id)->update(['start_at' => $start, 'end_at' => $end]);
            PackageSession::query()->whereKey($session->id)->update([
                'scheduled_start_at' => $start, 'scheduled_end_at' => $end,
            ]);
            $booking->bookingRequest?->update([
                'scheduled_date' => $start->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
            ]);
        });

        $this->info($stage === 'start'
            ? "Sesi {$session->id} siap dimulai. Tutor tekan Siap Mengajar, lalu murid konfirmasi hadir."
            : "Waktu sesi {$session->id} selesai. Tutor lakukan check-out dan isi laporan; murid menyetujui hasilnya.");
        $this->comment('Refresh halaman browser. Ulangi status/start/end untuk setiap sesi sampai paket selesai.');

        return self::SUCCESS;
    }

    private function advanceSharedClass(CheapClass $class, string $stage, CheapClassService $cheapClasses): int
    {
        $sessions = $class->sessions()->orderBy('session_number')->get();
        $this->table(['Sesi', 'Status', 'Jadwal'], $sessions->map(fn (CheapClassSession $session) => [
            $session->id, $session->status, $session->starts_at?->format('d-m-Y H:i') ?? '-',
        ])->all());
        if ($stage === 'status') {
            return self::SUCCESS;
        }
        if ($class->status !== 'confirmed' || ! $class->teacher_id) {
            $this->error('Kelas belum confirmed dan memiliki tutor.');

            return self::FAILURE;
        }
        $session = $this->chooseSession($sessions);
        if (! $session) {
            return self::FAILURE;
        }

        if ($stage === 'start') {
            if ($session->status !== 'scheduled' || $session->teacher_started_at) {
                $this->error('Sesi ini tidak sedang menunggu mulai.');

                return self::FAILURE;
            }
            $start = now()->subMinutes(2)->startOfMinute();
            $duration = max(30, (int) ($session->starts_at?->diffInMinutes($session->ends_at) ?? 60));
            $end = $start->copy()->addMinutes($duration);
        } else {
            if ($session->status !== 'in_progress' || ! $session->teacher_started_at) {
                $this->error('Tutor harus memulai sesi dari browser sebelum waktu sesi diakhiri.');

                return self::FAILURE;
            }
            $start = $session->starts_at;
            $end = now()->subMinute()->startOfMinute();
            if (! $start || $start->gte($end)) {
                $this->error('Jadwal mulai belum berada sebelum waktu akhir.');

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($class, $session, $start, $end) {
            CheapClassSession::query()->whereKey($session->id)->update(['starts_at' => $start, 'ends_at' => $end]);
            CheapClass::query()->whereKey($class->id)->update(['starts_at' => $start, 'ends_at' => $end]);
        });
        if ($stage === 'end') {
            $cheapClasses->refreshLifecycle(false);
        }

        $this->info($stage === 'start'
            ? "Sesi {$session->id} siap dimulai. Tutor tekan Mulai Kelas di browser."
            : "Waktu sesi {$session->id} selesai. Tutor kirim laporan, lalu admin verifikasi.");
        $this->comment('Refresh halaman browser. Ulangi status/start/end untuk setiap sesi sampai kelas selesai.');

        return self::SUCCESS;
    }

    private function chooseSession($sessions): PackageSession|CheapClassSession|null
    {
        $requested = $this->option('session');
        if ($requested !== null && (! ctype_digit((string) $requested) || (int) $requested < 1)) {
            $this->error('--session harus ID angka sesi yang tampil pada status.');

            return null;
        }
        $session = $requested !== null
            ? $sessions->firstWhere('id', (int) $requested)
            : $sessions->first(fn ($item) => $item instanceof PackageSession
                ? $item->booking?->status !== 'completed' && $item->status !== 'completed'
                : $item->status !== 'completed');
        if (! $session) {
            $this->error('Tidak ada sesi tersisa pada tagihan ini.');
        }

        return $session;
    }
}
