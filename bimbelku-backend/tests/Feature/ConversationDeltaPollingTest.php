<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ClassroomMessage;
use App\Models\ClassroomMessageRead;
use App\Models\Notification;
use App\Models\User;
use App\Services\ClassroomSystemMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class ConversationDeltaPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_delta_endpoint_returns_only_new_messages_after_cursor(): void
    {
        [$student, $teacher, $booking] = $this->conversation();
        $ids = $this->messages($booking, [$teacher->id, $student->id, $teacher->id]);
        Sanctum::actingAs($student);

        $response = $this->getJson("/api/conversations/{$booking->id}/messages?after_id={$ids[0]}");

        $response->assertOk();
        $returned = array_column($response->json('data'), 'id');
        $this->assertSame([$ids[1], $ids[2]], $returned);
        $this->assertSame($ids[2], $response->json('meta.last_id'));
        $this->assertFalse($response->json('meta.has_more'));
    }

    public function test_delta_endpoint_does_not_write_read_receipts(): void
    {
        [$student, $teacher, $booking] = $this->conversation();
        $this->messages($booking, [$teacher->id, $teacher->id, $teacher->id]);
        Sanctum::actingAs($student);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson("/api/conversations/{$booking->id}/messages")->assertOk();
        }

        $this->assertSame(0, ClassroomMessageRead::query()->where('user_id', $student->id)->count());
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_delta_endpoint_rejects_non_participant(): void
    {
        [, , $booking] = $this->conversation();
        $outsider = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($outsider);

        $this->getJson("/api/conversations/{$booking->id}/messages")->assertForbidden();
    }

    public function test_foreign_after_id_is_treated_as_window_start(): void
    {
        [$student, $teacher, $booking] = $this->conversation();
        $ids = $this->messages($booking, [$teacher->id]);
        [$otherStudent, $otherTeacher, $otherBooking] = $this->conversation();
        $foreign = $this->messages($otherBooking, [$otherTeacher->id]);

        Sanctum::actingAs($student);
        $response = $this->getJson("/api/conversations/{$booking->id}/messages?after_id={$foreign[0]}");

        $response->assertOk();
        $this->assertSame($ids, array_column($response->json('data'), 'id'));
    }

    public function test_empty_conversation_bootstraps_last_id_zero(): void
    {
        [$student, , $booking] = $this->conversation();
        Sanctum::actingAs($student);

        $response = $this->getJson("/api/conversations/{$booking->id}/messages");

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.last_id'));
    }

    public function test_explicit_read_endpoint_marks_only_through_the_given_id(): void
    {
        [$student, $teacher, $booking] = $this->conversation();
        $ids = $this->messages($booking, [$teacher->id, $teacher->id, $teacher->id]);
        Sanctum::actingAs($student);

        $this->postJson("/api/conversations/{$booking->id}/read", ['through_id' => $ids[1]])
            ->assertOk()
            ->assertJsonPath('data.through_id', $ids[1]);

        $read = ClassroomMessageRead::query()->where('user_id', $student->id)->pluck('classroom_message_id')->sort()->values()->all();
        $this->assertSame([$ids[0], $ids[1]], $read);
    }

    public function test_read_endpoint_requires_membership(): void
    {
        [, , $booking] = $this->conversation();
        $outsider = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($outsider);

        $this->postJson("/api/conversations/{$booking->id}/read", ['through_id' => 1])->assertForbidden();
    }

    /**
     * @return array{0:User,1:User,2:Booking}
     */
    private function conversation(): array
    {
        $this->mock(ClassroomSystemMessageService::class, function ($mock) {
            $mock->shouldReceive('ensureForBookings')->andReturnNull();
            $mock->shouldReceive('ensureOrderConnected')->andReturnNull();
        });
        $fixture = SessionFinanceFixture::make($this, 1);
        $teacher = $fixture['teacher'];
        $student = $fixture['student'];
        $booking = $fixture['first']['booking'];
        $booking->forceFill(['status' => 'confirmed'])->save();

        return [$student, $teacher, $booking->fresh()];
    }

    /**
     * @param  array<int,int>  $senderIds
     * @return array<int,int>
     */
    private function messages(Booking $booking, array $senderIds): array
    {
        $ids = [];
        foreach ($senderIds as $index => $senderId) {
            $ids[] = (int) ClassroomMessage::create([
                'booking_id' => $booking->id,
                'sender_id' => $senderId,
                'body' => 'Pesan '.($index + 1),
            ])->id;
        }

        return $ids;
    }
}
