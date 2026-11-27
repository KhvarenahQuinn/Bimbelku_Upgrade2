<?php

namespace Tests\Support;

use App\Models\CurriculumSubject;
use App\Models\TeacherAvailability;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reusable synthetic identities; never seeds the interactive/local database. */
final class StageOneFixtureSet
{
    public static function create(): array
    {
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Stage-one fixtures require testing and SQLite :memory:.');
        }

        $users = [];
        foreach (['owner', 'friend', 'outsider', 'unverified', 'minor'] as $name) {
            $users[$name] = User::factory()->create([
                'name' => 'QA Stage 1 '.$name,
                'email' => 'qa.stage1.'.$name.'@bimbelku.test',
                'role' => 'student', 'status' => 'active',
                'date_of_birth' => $name === 'minor' ? '2015-01-01' : '2000-01-01',
                'student_education_level' => 'SMA', 'grade' => 'Kelas 12',
                'email_verified_at' => $name === 'unverified' ? null : now(),
                'terms_accepted_at' => now(), 'privacy_accepted_at' => now(),
                'policy_version' => 'qa-stage1-only',
                'guardian_name' => $name === 'minor' ? 'Wali QA sintetis' : null,
                'guardian_phone' => $name === 'minor' ? '080000000000' : null,
                'guardian_relationship' => $name === 'minor' ? 'Orang tua' : null,
                'guardian_consent_at' => $name === 'minor' ? now() : null,
            ]);
        }
        foreach (['primary_admin', 'ordinary_admin'] as $name) {
            $users[$name] = User::factory()->create([
                'name' => 'QA Stage 1 '.$name,
                'email' => 'qa.stage1.'.$name.'@bimbelku.test',
                'role' => 'admin', 'status' => 'active', 'email_verified_at' => now(),
            ]);
        }
        config()->set('bimbelku.primary_admin_email', $users['primary_admin']->email);

        $subjects = [];
        foreach (['math' => 'Matematika', 'indonesian' => 'Bahasa Indonesia', 'english' => 'Bahasa Inggris'] as $key => $name) {
            $subjects[$key] = CurriculumSubject::query()->create([
                'name' => 'QA Stage 1 '.$name, 'normalized_name' => 'qa-stage1-'.$key,
                'group_name' => 'Wajib', 'education_levels' => ['SMA'],
                'grades' => ['Kelas 12'], 'is_active' => true,
            ]);
        }

        foreach ([
            'math_teacher' => ['math', 'Senin', true, true],
            'indonesian_teacher' => ['indonesian', 'Senin', true, true],
            'english_teacher' => ['english', 'Senin', true, true],
            'replacement_teacher' => ['math', 'Senin', true, true],
            'unavailable_teacher' => ['math', 'Selasa', true, true],
            'paused_teacher' => ['math', 'Senin', true, false],
            'pending_teacher' => ['math', 'Senin', false, false],
        ] as $key => [$subjectKey, $day, $verified, $accepting]) {
            $teacher = User::factory()->create([
                'name' => 'QA Stage 1 '.$key, 'email' => 'qa.stage1.'.$key.'@bimbelku.test',
                'role' => 'teacher', 'status' => $verified ? 'active' : 'pending',
                'email_verified_at' => now(), 'address' => 'Alamat sintetis untuk pengujian',
                'terms_accepted_at' => now(), 'privacy_accepted_at' => now(), 'policy_version' => 'qa-stage1-only',
            ]);
            $profile = tap(TeacherProfile::query()->create([
            'user_id' => $teacher->id,
            'expertise' => $subjects[$subjectKey]->name,
            'teaching_method' => 'Online',
            'public_degree' => 'Pendidikan QA sintetis',
            'is_accepting_requests' => $accepting,
            'public_profile_enabled' => false,
        ]), fn ($profile) => $profile->forceFill(['verified_at' => $verified ? now() : null])->save());
            TeacherSubject::query()->create([
                'teacher_profile_id' => $profile->id, 'curriculum_subject_id' => $subjects[$subjectKey]->id,
                'name' => $subjects[$subjectKey]->name, 'levels' => ['SMA'], 'is_active' => true,
                'is_online' => true, 'is_offline' => false, 'is_private_active' => true,
            ]);
            TeacherAvailability::query()->create([
                'user_id' => $teacher->id, 'day' => $day, 'is_active' => true,
                'slots' => [['start_time' => '08:00', 'end_time' => '16:00']],
            ]);
            $users[$key] = $teacher;
        }

        return ['users' => $users, 'subjects' => $subjects];
    }
}
