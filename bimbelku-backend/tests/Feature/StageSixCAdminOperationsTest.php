<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\LearningPackage;
use App\Models\MatchingOperationLog;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\PaymentSetting;
use App\Models\TeacherAvailability;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageSixCAdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_monitor_offer_history_and_synchronize_an_overdue_search(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $bookingRequest = $this->makePackageSearch($student, [
            'status' => 'teacher_pending',
            'matched_teacher_id' => $teacher->id,
            'teacher_response_deadline' => now()->subMinute(),
        ]);
        TeacherOffer::create([
            'booking_request_id' => $bookingRequest->id,
            'teacher_id' => $teacher->id,
            'status' => 'pending',
            'offered_at' => now()->subHours(2),
            'expires_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/tutor-searches?status=attention')
            ->assertOk()
            ->assertJsonPath('summary.attention', 1)
            ->assertJsonPath('data.0.id', $bookingRequest->id)
            ->assertJsonPath('data.0.active_offer.is_overdue', true);

        $this->getJson("/api/admin/tutor-searches/{$bookingRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.available_candidate_count', 0)
            ->assertJsonPath('data.offer_history.0.teacher.id', $teacher->id)
            ->assertJsonPath('data.offer_history.0.is_overdue', true);

        $this->postJson("/api/admin/tutor-searches/{$bookingRequest->id}/synchronize")
            ->assertOk();

        $this->assertDatabaseHas('teacher_offers', [
            'booking_request_id' => $bookingRequest->id,
            'teacher_id' => $teacher->id,
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('booking_requests', [
            'id' => $bookingRequest->id,
            'status' => 'no_teacher',
            'matched_teacher_id' => null,
        ]);
    }

    public function test_admin_can_expand_offline_radius_in_sequence_and_audit_the_reason(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $bookingRequest = $this->makeSearch($student, [
            'status' => 'no_teacher',
            'search_radius_km' => 3,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/tutor-searches/{$bookingRequest->id}/expand-radius", [
            'reason' => 'Kandidat radius awal sudah habis dan jadwal murid semakin dekat.',
        ])
            ->assertOk()
            ->assertJsonPath('radius', 5);

        $bookingRequest->refresh();
        $this->assertSame(5, (int) $bookingRequest->search_radius_km);
        $this->assertSame('no_teacher', $bookingRequest->status);

        $this->postJson("/api/admin/tutor-searches/{$bookingRequest->id}/expand-radius", [
            'reason' => 'Pencarian lima kilometer belum menemukan tutor yang tersedia.',
        ])
            ->assertOk()
            ->assertJsonPath('radius', 8);

        $logs = MatchingOperationLog::query()
            ->where('booking_request_id', $bookingRequest->id)
            ->where('action', 'radius_expanded')
            ->oldest('id')
            ->get();

        $this->assertCount(2, $logs);
        $this->assertSame($admin->id, $logs->first()->actor_id);
        $this->assertSame(3, (int) $logs->first()->before_state['search_radius_km']);
        $this->assertSame(5, (int) $logs->first()->after_state['search_radius_km']);
        $this->assertSame(8, (int) $logs->last()->after_state['search_radius_km']);
    }

    public function test_admin_can_assign_an_eligible_teacher_manually(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = $this->makeEligibleTeacher();
        $bookingRequest = $this->makePackageSearch($student, [
            'status' => 'no_teacher',
            'hourly_rate' => 100000,
            'total_amount' => 100000,
        ]);
        PaymentSetting::create([
            'merchant_name' => 'BimbelKu',
            'bank_name' => 'Bank Uji',
            'account_number' => '1234567890',
            'account_name' => 'BimbelKu Official',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/tutor-searches/{$bookingRequest->id}/candidates")
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $teacher->id);

        $this->postJson("/api/admin/tutor-searches/{$bookingRequest->id}/assign-teacher", [
            'teacher_id' => $teacher->id,
            'reason' => 'Tutor sudah dikonfirmasi bersedia oleh admin operasional.',
            'confirmed_teacher_consent' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.teacher.id', $teacher->id)
            ->assertJsonPath('data.assignment_type', 'package');

        $this->assertDatabaseHas('booking_requests', [
            'id' => $bookingRequest->id,
            'matched_teacher_id' => $teacher->id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('teacher_offers', [
            'booking_request_id' => $bookingRequest->id,
            'teacher_id' => $teacher->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_request_id' => $bookingRequest->id,
            'teacher_id' => $teacher->id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('matching_operation_logs', [
            'booking_request_id' => $bookingRequest->id,
            'actor_id' => $admin->id,
            'action' => 'teacher_assigned_manually',
        ]);
    }

    public function test_manual_assignment_rejects_teacher_with_schedule_conflict(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $otherStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = $this->makeEligibleTeacher();
        $target = $this->makePackageSearch($student, [
            'status' => 'no_teacher',
            'hourly_rate' => 100000,
            'total_amount' => 100000,
        ]);
        $conflictingRequest = $this->makeSearch($otherStudent, [
            'status' => 'confirmed',
            'matched_teacher_id' => $teacher->id,
        ]);
        Booking::create([
            'booking_request_id' => $conflictingRequest->id,
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'start_at' => Carbon::parse('2026-08-02 15:00:00', 'Asia/Jakarta'),
            'end_at' => Carbon::parse('2026-08-02 16:00:00', 'Asia/Jakarta'),
            'duration_hours' => 1,
            'learning_mode' => 'offline',
            'class_type' => 'private',
            'hourly_rate' => 100000,
            'total_amount' => 100000,
            'status' => 'confirmed',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/tutor-searches/{$target->id}/assign-teacher", [
            'teacher_id' => $teacher->id,
            'reason' => 'Admin mencoba penetapan setelah komunikasi dengan tutor.',
            'confirmed_teacher_consent' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Salah satu jadwal paket bertabrakan dengan kelas tutor.');

        $this->assertDatabaseMissing('matching_operation_logs', [
            'booking_request_id' => $target->id,
            'action' => 'teacher_assigned_manually',
        ]);
        $this->assertDatabaseMissing('teacher_offers', [
            'booking_request_id' => $target->id,
            'teacher_id' => $teacher->id,
            'status' => 'accepted',
        ]);
    }

    public function test_admin_class_monitoring_is_server_paginated_and_attention_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);

        foreach (range(1, 25) as $index) {
            $start = now()->addDays($index)->setTime(15, 0);
            $request = $this->makeSearch($student, [
                'subject_name' => $index === 25 ? 'Fisika Monitoring' : 'Matematika',
                'chapter' => $index === 25 ? 'Gerak Lurus' : 'Bilangan',
                'learning_mode' => 'online',
                'status' => 'in_progress',
                'scheduled_date' => $start->toDateString(),
                'start_time' => '15:00:00',
                'end_time' => '16:00:00',
                'matched_teacher_id' => $teacher->id,
            ]);
            Booking::create([
                'booking_request_id' => $request->id,
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'start_at' => $start,
                'end_at' => $start->copy()->addHour(),
                'duration_hours' => 1,
                'learning_mode' => 'online',
                'class_type' => 'private',
                'hourly_rate' => 100000,
                'total_amount' => 100000,
                'status' => 'in_progress',
            ]);
        }

        $attentionRequest = $this->makeSearch($student, [
            'subject_name' => 'Kimia',
            'chapter' => 'Stoikiometri',
            'learning_mode' => 'online',
            'status' => 'admin_review_required',
            'matched_teacher_id' => $teacher->id,
        ]);
        Booking::create([
            'booking_request_id' => $attentionRequest->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'start_at' => now()->subHour(),
            'end_at' => now(),
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'hourly_rate' => 100000,
            'total_amount' => 100000,
            'status' => 'admin_review_required',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/classes')
            ->assertOk()
            ->assertJsonPath('meta.scope', 'attention')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.attention', 1)
            ->assertJsonPath('summary.active', 26)
            ->assertJsonPath('data.0.needs_admin_attention', true);

        $firstPage = $this->getJson('/api/admin/classes?scope=active&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.current_page', 1);
        $this->assertCount(20, $firstPage->json('data'));

        $secondPage = $this->getJson('/api/admin/classes?scope=active&per_page=20&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2);
        $this->assertCount(6, $secondPage->json('data'));

        $this->getJson('/api/admin/classes?scope=active&search=Fisika%20Monitoring')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject', 'Fisika Monitoring')
            ->assertJsonPath('data.0.chapter', 'Gerak Lurus');
    }

    public function test_dashboard_prioritizes_real_admin_work_queues(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        User::factory()->create(['role' => 'teacher', 'status' => 'pending']);
        $this->makePackageSearch($student, ['status' => 'no_teacher']);
        Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-6C-ADMIN-1',
            'amount' => 125000,
            'status' => 'submitted',
            'payment_proof' => 'payment-proofs/testing.jpg',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard-stats')
            ->assertOk()
            ->assertJsonPath('counts.teachers', 1)
            ->assertJsonPath('counts.orders', 1)
            ->assertJsonPath('counts.matching_attention', 1)
            ->assertJsonFragment([
                'key' => 'matching',
                'href' => '/admin/tutor-searches?status=attention',
                'count' => 1,
            ]);
    }

    public function test_overdue_search_on_a_past_date_is_visible_in_attention_and_default_list(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $search = $this->makePackageSearch($student, [
            'status' => 'teacher_pending',
            'scheduled_date' => today()->subDays(2)->toDateString(),
            'teacher_response_deadline' => now()->subDays(2),
            'search_expires_at' => now()->subDay(),
        ]);
        // An old non-package request must not inflate a badge whose link only lists packages.
        $this->makeSearch($student, ['status' => 'no_teacher']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard-stats')->assertOk()
            ->assertJsonPath('counts.matching_attention', 1);
        foreach (['/api/admin/tutor-searches', '/api/admin/tutor-searches?status=attention'] as $url) {
            $this->getJson($url)->assertOk()
                ->assertJsonPath('summary.attention', 1)
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id', $search->id)
                ->assertJsonPath('data.0.needs_attention', true)
                ->assertJsonPath('data.0.attention_reason', 'Batas jawaban tutor telah terlewati dan perlu disinkronkan.');
        }
        $this->getJson("/api/admin/tutor-searches/{$search->id}")->assertOk();

        $search->update(['status' => 'expired']);
        $this->getJson('/api/admin/tutor-searches?status=attention')->assertOk()
            ->assertJsonPath('summary.attention', 0)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/admin/dashboard-stats')->assertOk()
            ->assertJsonPath('counts.matching_attention', 0);
        $this->getJson('/api/admin/tutor-searches?scope=history')->assertOk()
            ->assertJsonPath('data.0.id', $search->id);
    }

    public function test_expired_search_counted_as_attention_is_not_hidden_in_the_active_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $search = $this->makePackageSearch($student, ['status' => 'expired', 'scheduled_date' => today()->addDay()->toDateString()]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/tutor-searches?status=attention')->assertOk()
            ->assertJsonPath('summary.attention', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $search->id)
            ->assertJsonPath('data.0.needs_attention', true);
        $this->getJson('/api/admin/dashboard-stats')->assertOk()
            ->assertJsonPath('counts.matching_attention', 1);
    }

    private function makeEligibleTeacher(): User
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'latitude' => -7.250445,
            'longitude' => 112.768845,
            'max_travel_km' => 12,
            'is_accepting_requests' => true,
            'verified_at' => now(),
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'name' => 'Matematika',
            'levels' => ['SD'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => true,
            'is_private_active' => true,
        ]);
        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => 'Minggu',
            'slots' => ['15:00'],
            'start_time' => '14:00:00',
            'end_time' => '17:00:00',
            'is_active' => true,
        ]);

        return $teacher->fresh('teacherProfile.subjects');
    }

    private function makePackageSearch(User $student, array $overrides = []): BookingRequest
    {
        $request = $this->makeSearch($student, $overrides);
        $amount = (float) ($request->total_amount ?: $request->hourly_rate ?: 100000);
        $unitPrice = (float) ($request->hourly_rate ?: $amount);
        $packageStatus = match ($request->status) {
            'teacher_pending' => 'teacher_pending',
            'no_teacher' => 'no_teacher',
            default => 'matching',
        };

        $plan = PackagePlan::create([
            'name' => 'Paket Admin Matching '.$request->id,
            'slug' => 'admin-matching-'.$request->id,
            'description' => 'Fixture Paket Belajar untuk regresi admin matching.',
            'session_count' => 1,
            'validity_days' => 30,
            'maximum_subjects' => 1,
            'sort_order' => 9999,
            'is_active' => false,
        ]);
        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-ADMIN-MATCH-'.$request->id,
            'education_level' => $request->education_level,
            'grade' => $request->grade,
            'learning_mode' => $request->learning_mode,
            'duration_hours' => (int) $request->duration_hours,
            'status' => $packageStatus,
            'total_sessions' => 1,
            'used_sessions' => 0,
            'subtotal_amount' => $amount,
            'discount_amount' => 0,
            'total_amount' => $amount,
        ]);
        $subject = PackageSubject::create([
            'learning_package_id' => $package->id,
            'assigned_teacher_id' => null,
            'subject_name' => $request->subject_name,
            'chapter' => $request->chapter,
            'curriculum_chapter_ids' => [],
            'allocated_sessions' => 1,
            'unit_price' => $unitPrice,
            'subtotal_amount' => $amount,
            'status' => $packageStatus,
        ]);

        $start = Carbon::parse(
            $request->scheduled_date->toDateString().' '.$request->start_time,
            'Asia/Jakarta'
        );
        $end = Carbon::parse(
            $request->scheduled_date->toDateString().' '.$request->end_time,
            'Asia/Jakarta'
        );
        PackageSession::create([
            'package_subject_id' => $subject->id,
            'sequence' => 1,
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $end,
            'status' => 'scheduled',
        ]);
        Order::create([
            'user_id' => $student->id,
            'learning_package_id' => $package->id,
            'order_id' => 'INV-ADMIN-MATCH-'.$request->id,
            'amount' => $amount,
            'status' => 'paid',
        ]);

        $request->update(['package_subject_id' => $subject->id]);

        return $request->fresh();
    }

    private function makeSearch(User $student, array $overrides = []): BookingRequest
    {
        return BookingRequest::create(array_merge([
            'student_id' => $student->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SD',
            'grade' => 'Kelas 4',
            'learning_mode' => 'offline',
            'class_type' => 'private',
            'scheduled_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'duration_hours' => 1,
            'address' => 'Alamat pengujian privat',
            'latitude' => -7.250445,
            'longitude' => 112.768845,
            'status' => 'matching',
            'matching_attempts' => 1,
            'search_radius_km' => 3,
            'search_started_at' => now()->subHour(),
            'search_expires_at' => now()->addDay(),
        ], $overrides));
    }
}
