<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cheap_class_templates')) {
            return;
        }

        // Template hanya menjadi snapshot internal untuk satu paket. Status aktif
        // dan pola pengulangan lama dimatikan agar paket terhapus tidak dibuat ulang.
        DB::table('cheap_class_templates')->update([
            'is_active' => false,
            'recurrence_days' => null,
            'updated_at' => now(),
        ]);

        if (! Schema::hasTable('cheap_classes')) {
            return;
        }

        // Template tanpa paket merupakan sisa kegagalan simpan atau penghapusan lama.
        DB::table('cheap_class_templates')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('cheap_classes')
                    ->whereColumn('cheap_classes.cheap_class_template_id', 'cheap_class_templates.id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Data aktivasi lama tidak dipulihkan agar paket yang dihapus tetap hilang.
    }
};
