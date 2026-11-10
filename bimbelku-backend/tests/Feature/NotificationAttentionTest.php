<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationAttentionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'student'): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function targeted(User $user, string $targetUrl, array $overrides = []): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'title' => 'Perubahan baru',
            'message' => 'Ada pembaruan pada bagian ini.',
            'type' => 'info',
            'target_url' => $targetUrl,
            'is_read' => false,
            ...$overrides,
        ]);
    }

    public function test_unread_targeted_notification_appears_in_attention_payload(): void
    {
        $student = $this->user();
        $notification = $this->targeted($student, '/student/packages');
        Sanctum::actingAs($student);

        $this->getJson('/api/notifications?per_page=1')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('attention_notifications.0.id', $notification->id)
            ->assertJsonPath('attention_notifications.0.target_url', '/student/packages')
            ->assertJsonPath('attention_notifications.0.attention_key', 'student_order_status_changed')
            ->assertJsonPath('attention_notifications.0.is_read', false);
    }

    public function test_notification_without_target_url_is_not_exposed_as_attention(): void
    {
        $student = $this->user();
        Notification::create([
            'user_id' => $student->id,
            'title' => 'Info umum',
            'message' => 'Tidak menuju halaman tertentu.',
            'type' => 'info',
            'is_read' => false,
        ]);
        Sanctum::actingAs($student);

        $this->getJson('/api/notifications?per_page=1')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(0, 'attention_notifications');
    }

    public function test_read_batch_marks_only_current_users_notifications_read(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $mine = $this->targeted($owner, '/student/packages');
        $foreign = $this->targeted($other, '/student/packages');

        Sanctum::actingAs($owner);

        $this->postJson('/api/notifications/read-batch', [
            'ids' => [$mine->id, $foreign->id],
        ])->assertOk()->assertJsonPath('updated', 1);

        $this->assertTrue($mine->fresh()->is_read);
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertFalse($foreign->fresh()->is_read);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_read_notification_does_not_reappear_after_reload(): void
    {
        $student = $this->user();
        $notification = $this->targeted($student, '/student/packages');
        Sanctum::actingAs($student);

        $this->postJson('/api/notifications/read-batch', ['ids' => [$notification->id]])
            ->assertOk();

        $this->getJson('/api/notifications?per_page=1')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(0, 'attention_notifications');
    }

    public function test_mark_all_read_clears_attention_payload(): void
    {
        $student = $this->user();
        $this->targeted($student, '/student/packages');
        $this->targeted($student, '/student/history');
        Sanctum::actingAs($student);

        $this->postJson('/api/notifications/read-all')->assertOk();

        $this->assertSame(
            0,
            Notification::where('user_id', $student->id)->where('is_read', false)->count()
        );

        $this->getJson('/api/notifications?per_page=1')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(0, 'attention_notifications');
    }

    public function test_same_unique_key_does_not_reopen_a_read_badge(): void
    {
        $student = $this->user();
        $key = "student-payment-required:99:{$student->id}";

        $first = Notification::updateOrCreate(['unique_key' => $key], [
            'user_id' => $student->id,
            'title' => 'Pembayaran diperlukan',
            'message' => 'Lanjutkan pembayaran paket.',
            'type' => 'warning',
            'target_url' => '/student/packages',
            'is_read' => false,
        ]);

        $first->update(['is_read' => true]);
        $first->refresh();
        $readAt = $first->read_at;

        $second = Notification::updateOrCreate(['unique_key' => $key], [
            'user_id' => $student->id,
            'title' => 'Pembayaran diperlukan',
            'message' => 'Lanjutkan pembayaran paket.',
            'type' => 'warning',
            'target_url' => '/student/packages',
            'is_read' => false,
        ]);

        $this->assertSame(1, Notification::count());
        $this->assertTrue($second->is_read);
        $this->assertEquals($readAt, $second->read_at);
    }

    public function test_attention_key_is_derived_for_each_role_target(): void
    {
        $student = $this->user();
        $cases = [
            '/student/packages' => 'student_order_status_changed',
            '/student/history' => 'student_refund_status_changed',
            '/guru/permintaan' => 'teacher_new_offer',
            '/guru/kelas' => 'teacher_class_changed',
            '/guru/jadwal' => 'teacher_schedule_changed',
            '/guru/dompet' => 'teacher_payout_changed',
            '/admin/finance?tab=refunds' => 'admin_refund_exception',
            '/admin/pembayaran' => 'targeted_notification',
        ];

        foreach ($cases as $target => $expectedKey) {
            $notification = $this->targeted($student, $target);
            $this->assertSame(
                $expectedKey,
                $notification->fresh()->attention_key,
                "Key untuk target {$target} tidak sesuai."
            );
        }
    }

    public function test_explicit_attention_key_is_not_overwritten(): void
    {
        $teacher = $this->user('teacher');
        $notification = $this->targeted($teacher, '/guru/kelas', [
            'attention_key' => 'teacher_class_cancelled',
        ]);

        $this->assertSame('teacher_class_cancelled', $notification->fresh()->attention_key);
    }

    public function test_invalidated_notification_is_not_counted_as_unread(): void
    {
        $student = $this->user();
        $notification = $this->targeted($student, '/student/packages');
        $notification->forceFill(['invalidated_at' => now()])->save();
        Sanctum::actingAs($student);

        $this->getJson('/api/notifications?per_page=1')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(0, 'attention_notifications');
    }

    public function test_admin_refund_exception_notification_targets_refund_tab(): void
    {
        $admin = $this->user('admin');
        $notification = $this->targeted($admin, '/admin/finance?tab=refunds', [
            'title' => 'Refund perlu ditangani',
            'type' => 'warning',
        ]);

        $fresh = $notification->fresh();
        $this->assertSame('admin_refund_exception', $fresh->attention_key);
        $this->assertSame('/admin/finance?tab=refunds', $fresh->target_url);

        Sanctum::actingAs($admin);
        $this->getJson('/api/notifications?per_page=1')
            ->assertOk()
            ->assertJsonPath('attention_notifications.0.attention_key', 'admin_refund_exception')
            ->assertJsonPath('attention_notifications.0.target_url', '/admin/finance?tab=refunds');
    }

    public function test_entity_identity_is_extracted_from_unique_key(): void
    {
        $student = $this->user();
        $notification = $this->targeted($student, '/student/packages', [
            'unique_key' => 'student-payment-required:42:7',
        ]);

        $fresh = $notification->fresh();
        $this->assertSame('student_payment_required', $fresh->entity_type);
        $this->assertSame(42, $fresh->entity_id);
    }
}
