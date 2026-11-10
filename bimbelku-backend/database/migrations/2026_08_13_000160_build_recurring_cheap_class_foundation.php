<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendTemplates();
        $this->extendPackages();
        $this->extendFinancialReferences();
        $this->backfillTemplates();
        $this->backfillPackages();
        $this->backfillFinancialReferences();
        $this->addUniqueIndexes();
    }

    private function extendTemplates(): void
    {
        if (! Schema::hasTable('cheap_class_templates')) {
            return;
        }

        Schema::table('cheap_class_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('cheap_class_templates', 'template_code')) {
                $table->string('template_code', 32)->nullable()->after('id');
            }
            if (! Schema::hasColumn('cheap_class_templates', 'recurrence_enabled')) {
                $table->boolean('recurrence_enabled')->default(false)->after('recurrence_days');
            }
            if (! Schema::hasColumn('cheap_class_templates', 'recurrence_anchor_at')) {
                $table->dateTime('recurrence_anchor_at')->nullable()->after('recurrence_enabled');
            }
            if (! Schema::hasColumn('cheap_class_templates', 'next_publish_at')) {
                $table->dateTime('next_publish_at')->nullable()->after('recurrence_anchor_at');
            }
            if (! Schema::hasColumn('cheap_class_templates', 'last_published_at')) {
                $table->dateTime('last_published_at')->nullable()->after('next_publish_at');
            }
            if (! Schema::hasColumn('cheap_class_templates', 'settings_version')) {
                $table->unsignedInteger('settings_version')->default(1)->after('last_published_at');
            }
        });
    }

    private function extendPackages(): void
    {
        if (! Schema::hasTable('cheap_classes')) {
            return;
        }

        Schema::table('cheap_classes', function (Blueprint $table) {
            if (! Schema::hasColumn('cheap_classes', 'package_code')) {
                $table->string('package_code', 40)->nullable()->after('id');
            }
            if (! Schema::hasColumn('cheap_classes', 'occurrence_week_start')) {
                $table->date('occurrence_week_start')->nullable()->after('cheap_class_template_id');
            }
            if (! Schema::hasColumn('cheap_classes', 'generation_source')) {
                $table->string('generation_source', 20)->default('manual')->after('occurrence_week_start');
            }
            if (! Schema::hasColumn('cheap_classes', 'template_settings_version')) {
                $table->unsignedInteger('template_settings_version')->default(1)->after('generation_source');
            }
            if (! Schema::hasColumn('cheap_classes', 'template_snapshot')) {
                $table->json('template_snapshot')->nullable()->after('template_settings_version');
            }
        });
    }

    private function extendFinancialReferences(): void
    {
        if (Schema::hasTable('refunds') && ! Schema::hasColumn('refunds', 'refund_code')) {
            Schema::table('refunds', function (Blueprint $table) {
                $table->string('refund_code', 40)->nullable()->after('id');
            });
        }
    }

    private function backfillTemplates(): void
    {
        if (! Schema::hasTable('cheap_class_templates') || ! Schema::hasColumn('cheap_class_templates', 'template_code')) {
            return;
        }

        DB::table('cheap_class_templates')->orderBy('id')->chunkById(200, function ($templates) {
            foreach ($templates as $template) {
                DB::table('cheap_class_templates')->where('id', $template->id)->update([
                    'template_code' => $template->template_code ?: 'KMT-'.str_pad((string) $template->id, 10, '0', STR_PAD_LEFT),
                    'recurrence_enabled' => (bool) ($template->recurrence_enabled ?? false),
                    'settings_version' => max(1, (int) ($template->settings_version ?? 1)),
                ]);
            }
        });
    }

    private function backfillPackages(): void
    {
        if (! Schema::hasTable('cheap_classes') || ! Schema::hasColumn('cheap_classes', 'package_code')) {
            return;
        }

        $seenPeriods = [];
        DB::table('cheap_classes')->orderBy('id')->chunkById(200, function ($packages) use (&$seenPeriods) {
            foreach ($packages as $package) {
                $periodSource = $package->registration_opens_at ?? $package->starts_at ?? $package->created_at;
                $periodStart = $periodSource
                    ? Carbon::parse($periodSource, config('app.timezone', 'Asia/Jakarta'))->startOfWeek()->toDateString()
                    : null;
                $periodKey = $package->cheap_class_template_id && $periodStart
                    ? $package->cheap_class_template_id.':'.$periodStart
                    : null;
                if ($periodKey && isset($seenPeriods[$periodKey])) {
                    // Histori lama yang sudah memiliki lebih dari satu paket dalam minggu sama
                    // tetap dipertahankan, tetapi tidak dimasukkan ke kunci periode baru.
                    $periodStart = null;
                } elseif ($periodKey) {
                    $seenPeriods[$periodKey] = true;
                }

                $template = $package->cheap_class_template_id
                    ? DB::table('cheap_class_templates')->where('id', $package->cheap_class_template_id)->first()
                    : null;
                $codeDate = $periodSource ? Carbon::parse($periodSource)->format('Ymd') : now()->format('Ymd');

                DB::table('cheap_classes')->where('id', $package->id)->update([
                    'package_code' => $package->package_code ?: 'KMP-'.$codeDate.'-'.str_pad((string) $package->id, 8, '0', STR_PAD_LEFT),
                    'occurrence_week_start' => $package->occurrence_week_start ?? $periodStart,
                    'generation_source' => $package->generation_source ?? 'manual',
                    'template_settings_version' => max(1, (int) ($package->template_settings_version ?? $template->settings_version ?? 1)),
                    'template_snapshot' => $package->template_snapshot ?? ($template ? json_encode($template, JSON_UNESCAPED_UNICODE) : null),
                ]);
            }
        });
    }

    private function backfillFinancialReferences(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'order_id')) {
            $seen = [];
            DB::table('orders')->orderBy('id')->chunkById(200, function ($orders) use (&$seen) {
                foreach ($orders as $order) {
                    $code = trim((string) ($order->order_id ?? ''));
                    if ($code === '' || isset($seen[$code])) {
                        $code = 'ORD-'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
                        while (isset($seen[$code])) {
                            $code .= '-'.$order->id;
                        }
                    }
                    $seen[$code] = true;
                    DB::table('orders')->where('id', $order->id)->update(['order_id' => $code]);
                }
            });
        }

        if (Schema::hasTable('refunds') && Schema::hasColumn('refunds', 'refund_code')) {
            DB::table('refunds')->orderBy('id')->chunkById(200, function ($refunds) {
                foreach ($refunds as $refund) {
                    $isCheapClass = DB::table('orders')
                        ->where('id', $refund->order_id)
                        ->whereNotNull('cheap_class_enrollment_id')
                        ->exists();
                    DB::table('refunds')->where('id', $refund->id)->update([
                        'refund_code' => $refund->refund_code
                            ?: ($isCheapClass ? 'RFD-KM-' : 'RFD-').str_pad((string) $refund->id, 8, '0', STR_PAD_LEFT),
                    ]);
                }
            });
        }
    }

    private function addUniqueIndexes(): void
    {
        if (Schema::hasTable('cheap_class_templates') && ! Schema::hasIndex('cheap_class_templates', 'cheap_class_templates_template_code_unique')) {
            Schema::table('cheap_class_templates', fn (Blueprint $table) => $table->unique('template_code'));
        }
        if (Schema::hasTable('cheap_classes') && ! Schema::hasIndex('cheap_classes', 'cheap_classes_package_code_unique')) {
            Schema::table('cheap_classes', fn (Blueprint $table) => $table->unique('package_code'));
        }
        if (Schema::hasTable('cheap_classes') && ! Schema::hasIndex('cheap_classes', 'cheap_class_template_period_unique')) {
            Schema::table('cheap_classes', fn (Blueprint $table) => $table->unique(
                ['cheap_class_template_id', 'occurrence_week_start'],
                'cheap_class_template_period_unique'
            ));
        }
        if (Schema::hasTable('orders') && ! Schema::hasIndex('orders', 'orders_order_id_unique')) {
            Schema::table('orders', fn (Blueprint $table) => $table->unique('order_id'));
        }
        if (Schema::hasTable('refunds') && ! Schema::hasIndex('refunds', 'refunds_refund_code_unique')) {
            Schema::table('refunds', fn (Blueprint $table) => $table->unique('refund_code'));
        }
    }

    public function down(): void
    {
        // Identitas publik dan snapshot dipertahankan saat rollback agar riwayat
        // paket serta transaksi tidak kehilangan referensi yang sudah diterbitkan.
    }
};
