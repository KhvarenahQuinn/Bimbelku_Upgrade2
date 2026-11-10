<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            if (! DB::table('settings')->where('key', 'teacher_cover_path')->exists()) {
                DB::table('settings')->insert([
                    'key' => 'teacher_cover_path',
                    'value' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('teacher_profiles')) {
            DB::table('teacher_profiles')
                ->select('user_id', DB::raw('MAX(id) as keep_id'))
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->each(function ($duplicate) {
                    $duplicateIds = DB::table('teacher_profiles')
                        ->where('user_id', $duplicate->user_id)
                        ->where('id', '<>', $duplicate->keep_id)
                        ->pluck('id');

                    if (Schema::hasTable('teacher_subjects')) {
                        DB::table('teacher_subjects')
                            ->whereIn('teacher_profile_id', $duplicateIds)
                            ->update(['teacher_profile_id' => $duplicate->keep_id]);
                    }

                    if (Schema::hasTable('location_teacher')) {
                        $locationIds = DB::table('location_teacher')
                            ->where('teacher_profile_id', $duplicate->keep_id)
                            ->pluck('location_id');
                        if ($locationIds->isNotEmpty()) {
                            DB::table('location_teacher')
                                ->whereIn('teacher_profile_id', $duplicateIds)
                                ->whereIn('location_id', $locationIds)
                                ->delete();
                        }
                        DB::table('location_teacher')
                            ->whereIn('teacher_profile_id', $duplicateIds)
                            ->update(['teacher_profile_id' => $duplicate->keep_id]);
                    }

                    DB::table('teacher_profiles')
                        ->whereIn('id', $duplicateIds)
                        ->delete();
                });

            Schema::table('teacher_profiles', function (Blueprint $table) {
                $table->unique('user_id', 'teacher_profiles_user_unique');
            });
        }

        if (Schema::hasTable('teacher_subjects')) {
            DB::table('teacher_subjects')
                ->select('teacher_profile_id', DB::raw('MAX(id) as keep_id'))
                ->groupBy('teacher_profile_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->each(function ($duplicate) {
                    DB::table('teacher_subjects')
                        ->where('teacher_profile_id', $duplicate->teacher_profile_id)
                        ->where('id', '<>', $duplicate->keep_id)
                        ->delete();
                });

            Schema::table('teacher_subjects', function (Blueprint $table) {
                $table->unique('teacher_profile_id', 'teacher_subjects_profile_unique');
            });
        }

        if (Schema::hasTable('teacher_availabilities')) {
            DB::table('teacher_availabilities')
                ->select('user_id', 'day', DB::raw('MAX(id) as keep_id'))
                ->groupBy('user_id', 'day')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->each(function ($duplicate) {
                    DB::table('teacher_availabilities')
                        ->where('user_id', $duplicate->user_id)
                        ->where('day', $duplicate->day)
                        ->where('id', '<>', $duplicate->keep_id)
                        ->delete();
                });

            Schema::table('teacher_availabilities', function (Blueprint $table) {
                $table->unique(
                    ['user_id', 'day'],
                    'teacher_availability_user_day_unique'
                );
            });
        }

        if (Schema::hasTable('learning_topics')) {
            DB::table('learning_topics')
                ->select(
                    'subject_name',
                    'education_level',
                    'grade',
                    'chapter',
                    'name',
                    DB::raw('MAX(id) as keep_id')
                )
                ->groupBy('subject_name', 'education_level', 'grade', 'chapter', 'name')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->each(function ($duplicate) {
                    DB::table('learning_topics')
                        ->where('subject_name', $duplicate->subject_name)
                        ->where('education_level', $duplicate->education_level)
                        ->where('grade', $duplicate->grade)
                        ->where('chapter', $duplicate->chapter)
                        ->where('name', $duplicate->name)
                        ->where('id', '<>', $duplicate->keep_id)
                        ->delete();
                });

            Schema::table('learning_topics', function (Blueprint $table) {
                $table->unique(
                    ['subject_name', 'education_level', 'grade', 'chapter', 'name'],
                    'learning_topics_catalog_unique'
                );
            });
        }

        if (Schema::hasTable('hourly_rates')) {
            DB::table('hourly_rates')
                ->select(
                    'subject_name',
                    'education_level',
                    'class_type',
                    'learning_mode',
                    DB::raw('MAX(id) as keep_id')
                )
                ->groupBy('subject_name', 'education_level', 'class_type', 'learning_mode')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->each(function ($duplicate) {
                    DB::table('hourly_rates')
                        ->where('subject_name', $duplicate->subject_name)
                        ->when(
                            $duplicate->education_level === null,
                            fn ($query) => $query->whereNull('education_level'),
                            fn ($query) => $query->where('education_level', $duplicate->education_level)
                        )
                        ->where('class_type', $duplicate->class_type)
                        ->when(
                            $duplicate->learning_mode === null,
                            fn ($query) => $query->whereNull('learning_mode'),
                            fn ($query) => $query->where('learning_mode', $duplicate->learning_mode)
                        )
                        ->where('id', '<>', $duplicate->keep_id)
                        ->delete();
                });
        }

        if (Schema::hasTable('payment_settings')) {
            if (! Schema::hasColumn('payment_settings', 'singleton_key')) {
                Schema::table('payment_settings', function (Blueprint $table) {
                    $table->unsignedTinyInteger('singleton_key')->default(1);
                });
            }

            $keepId = DB::table('payment_settings')->max('id');
            if ($keepId !== null) {
                DB::table('payment_settings')->where('id', '<>', $keepId)->delete();
            }

            Schema::table('payment_settings', function (Blueprint $table) {
                $table->unique('singleton_key', 'payment_settings_singleton_unique');
            });
        }

        if (Schema::hasTable('payouts')) {
            Schema::table('payouts', function (Blueprint $table) {
                if (! Schema::hasColumn('payouts', 'booking_ids')) {
                    $table->json('booking_ids')->nullable();
                }
                if (! Schema::hasColumn('payouts', 'bank_name')) {
                    $table->string('bank_name', 100)->nullable();
                }
                if (! Schema::hasColumn('payouts', 'account_number')) {
                    $table->string('account_number', 80)->nullable();
                }
                if (! Schema::hasColumn('payouts', 'account_name')) {
                    $table->string('account_name', 150)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_settings') && Schema::hasColumn('payment_settings', 'singleton_key')) {
            Schema::table('payment_settings', function (Blueprint $table) {
                $table->dropUnique('payment_settings_singleton_unique');
                $table->dropColumn('singleton_key');
            });
        }

        if (Schema::hasTable('teacher_availabilities')) {
            Schema::table('teacher_availabilities', function (Blueprint $table) {
                $table->dropUnique('teacher_availability_user_day_unique');
            });
        }

        if (Schema::hasTable('learning_topics')) {
            Schema::table('learning_topics', function (Blueprint $table) {
                $table->dropUnique('learning_topics_catalog_unique');
            });
        }

        if (Schema::hasTable('teacher_subjects')) {
            Schema::table('teacher_subjects', function (Blueprint $table) {
                $table->dropUnique('teacher_subjects_profile_unique');
            });
        }

        if (Schema::hasTable('teacher_profiles')) {
            Schema::table('teacher_profiles', function (Blueprint $table) {
                $table->dropUnique('teacher_profiles_user_unique');
            });
        }

        if (! Schema::hasTable('payouts')) {
            return;
        }

        $columns = array_values(array_filter(
            ['booking_ids', 'bank_name', 'account_number', 'account_name'],
            static fn (string $column): bool => Schema::hasColumn('payouts', $column)
        ));

        if ($columns !== []) {
            Schema::table('payouts', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
