<?php

use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $demoTeacher = User::query()->where('email', 'demo.tutor@bimbelku.local')->first();
        if (! $demoTeacher) {
            return;
        }

        $profile = TeacherProfile::query()->where('user_id', $demoTeacher->id)->first();
        if (! $profile) {
            return;
        }

        $subjects = TeacherSubject::query()->where('teacher_profile_id', $profile->id)->orderBy('id')->get();
        $keep = $subjects->first();
        if (! $keep) {
            return;
        }

        TeacherSubject::query()
            ->where('teacher_profile_id', $profile->id)
            ->where('id', '<>', $keep->id)
            ->delete();

        $keep->update(['name' => 'Matematika']);
        $profile->update(['expertise' => 'Matematika']);
    }

    public function down(): void
    {
        // Demo data is intentionally normalized and is safe to recreate via demo commands.
    }
};
