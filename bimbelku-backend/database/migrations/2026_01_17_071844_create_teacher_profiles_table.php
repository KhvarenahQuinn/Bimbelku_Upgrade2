<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Relasi ke User

            // Data profil tutor
            $table->string('expertise')->nullable();       // Keahlian (Matematika, dll)
            $table->string('linkedin')->nullable();        // Link Profil
            $table->string('teaching_method')->nullable(); // Online/Offline
            $table->string('cv_file')->nullable();         // Path upload CV

            // Data Tambahan (Bio & Bank)
            $table->text('bio')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_profiles');
    }
};
