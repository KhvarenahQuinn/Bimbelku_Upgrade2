<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Booking;
use App\Models\BookingDispute;
use App\Models\BookingRequest;
use App\Models\Classroom;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\DynamicBanner;
use App\Models\HourlyRate;
use App\Models\LearningTimeSlot;
use App\Models\Note;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PayoutApproval;
use App\Models\Promotion;
use App\Models\Rating;
use App\Models\Refund;
use App\Models\SessionReport;
use App\Models\Setting;
use App\Models\SocialMedia;
use App\Models\TeacherReplacementRequest;
use App\Models\Tutorial;
use App\Models\TutorialStep;
use App\Models\User;
use App\Support\AdminPermissionCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminAuditService
{
    public function resolveTarget(Request $request): array
    {
        $routeCandidates = [
            'bookingRequest', 'teacherReplacement', 'refund', 'payoutApproval', 'booking', 'bookingDispute',
            'teacherAppeal', 'sessionReport', 'curriculumSubject', 'curriculumChapter',
            'hourlyRate', 'learningTopic', 'packagePlan', 'learningTimeSlot', 'promotion',
            'dynamicBanner', 'tutorial', 'tutorialStep', 'user', 'admin',
        ];

        foreach ($routeCandidates as $parameter) {
            $value = $request->route($parameter);
            if ($value) {
                return $this->normalizeTarget($value, $parameter);
            }
        }

        $genericId = $request->route('id');
        if (is_numeric($genericId)) {
            $genericModel = $this->modelForGenericIdPath($request->path());
            if ($genericModel) {
                $model = $genericModel::query()->find((int) $genericId);

                return [
                    'type' => $model ? class_basename($model) : class_basename($genericModel),
                    'id' => (int) $genericId,
                    'model' => $model,
                    'before' => $this->snapshot($model),
                ];
            }
        }

        $bodyTargets = [
            'order_id' => Order::class,
            'user_id' => User::class,
            'teacher_id' => User::class,
            'booking_request_id' => BookingRequest::class,
            'refund_id' => Refund::class,
            'booking_id' => Booking::class,
            'classroom_id' => Classroom::class,
        ];
        foreach ($bodyTargets as $field => $modelClass) {
            if ($request->filled($field)) {
                $model = $modelClass::query()->find($request->input($field));
                if ($model) {
                    return $this->normalizeTarget($model, class_basename($modelClass));
                }
            }
        }

        return [
            'type' => null,
            'id' => null,
            'model' => null,
            'before' => null,
        ];
    }

    public function targetFromModel(Model $model, bool $includeBefore = true): array
    {
        return [
            'type' => class_basename($model),
            'id' => (int) $model->getKey(),
            'model' => $model,
            'before' => $includeBefore ? $this->snapshot($model) : null,
        ];
    }

    public function snapshot(?Model $model): ?array
    {
        if (! $model) {
            return null;
        }

        try {
            $model->refresh();
        } catch (\Throwable) {
            // Model dapat terhapus oleh aksi DELETE; gunakan atribut terakhir yang tersedia.
        }

        return $this->sanitize($model->getAttributes());
    }

    public function record(
        Request $request,
        int $responseStatus,
        string $action,
        array $target,
        ?array $afterState,
        ?array $metadata = null
    ): AdminAuditLog {
        $actor = $request->user();
        $requestId = (string) Str::uuid();
        $createdAt = now()->startOfSecond();
        $payload = $this->sanitize($request->except([
            'password', 'password_confirmation', 'current_password', 'super_admin_password', 'code', 'current_code',
            'finance_totp_secret', 'token', 'access_token',
        ]));
        $reason = trim((string) ($request->input('reason') ?? $request->input('notes') ?? '')) ?: null;
        $category = AdminPermissionCatalog::categoryFor($request);
        $permissionCode = $request->attributes->get('required_admin_permission');
        $routePath = $request->path();
        $ipAddress = $request->ip();
        $userAgent = mb_substr((string) $request->userAgent(), 0, 1000);

        return DB::transaction(function () use (
            $request,
            $responseStatus,
            $action,
            $target,
            $afterState,
            $metadata,
            $actor,
            $requestId,
            $createdAt,
            $payload,
            $reason,
            $category,
            $permissionCode,
            $routePath,
            $ipAddress,
            $userAgent
        ) {
            Setting::query()
                ->where('key', 'admin_audit_chain_lock')
                ->lockForUpdate()
                ->firstOrCreate(['key' => 'admin_audit_chain_lock'], ['value' => '1']);

            $previousHash = AdminAuditLog::query()->latest('id')->value('entry_hash');
            $canonical = [
                'previous_hash' => $previousHash,
                'request_id' => $requestId,
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email,
                'action' => $action,
                'category' => $category,
                'permission_code' => $permissionCode,
                'method' => $request->method(),
                'path' => $routePath,
                'status' => $responseStatus,
                'target_type' => $target['type'],
                'target_id' => $target['id'],
                'reason' => $reason,
                'request_payload' => $payload,
                'before_state' => $target['before'],
                'after_state' => $afterState,
                'metadata' => $metadata,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => $createdAt->toISOString(),
            ];
            $entryHash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return AdminAuditLog::create([
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email,
                'request_id' => $requestId,
                'action' => $action,
                'category' => $category,
                'permission_code' => $permissionCode,
                'route_name' => $routePath,
                'method' => $request->method(),
                'response_status' => $responseStatus,
                'target_type' => $target['type'],
                'target_id' => $target['id'],
                'reason' => $reason,
                'request_payload' => $payload,
                'before_state' => $target['before'],
                'after_state' => $afterState,
                'metadata' => $metadata,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'previous_hash' => $previousHash,
                'entry_hash' => $entryHash,
                'created_at' => $createdAt,
            ]);
        }, 3);
    }

    public function verifyChain(iterable $logs): array
    {
        $previousHash = null;
        $valid = true;
        $checked = 0;

        foreach ($logs as $log) {
            $checked++;
            $canonical = [
                'previous_hash' => $previousHash,
                'request_id' => $log->request_id,
                'actor_id' => $log->actor_id,
                'actor_name' => $log->actor_name,
                'actor_email' => $log->actor_email,
                'action' => $log->action,
                'category' => $log->category,
                'permission_code' => $log->permission_code,
                'method' => $log->method,
                'path' => $log->route_name,
                'status' => (int) $log->response_status,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'reason' => $log->reason,
                'request_payload' => $log->request_payload,
                'before_state' => $log->before_state,
                'after_state' => $log->after_state,
                'metadata' => $log->metadata,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at?->toISOString(),
            ];
            $expected = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($log->previous_hash !== $previousHash || ! hash_equals((string) $log->entry_hash, $expected)) {
                $valid = false;
                break;
            }
            $previousHash = $log->entry_hash;
        }

        return ['valid' => $valid, 'checked' => $checked];
    }

    private function normalizeTarget(mixed $value, string $fallbackType): array
    {
        if ($value instanceof Model) {
            return [
                'type' => class_basename($value),
                'id' => (int) $value->getKey(),
                'model' => $value,
                'before' => $this->snapshot($value),
            ];
        }

        if (is_numeric($value)) {
            $model = $this->modelForParameter($fallbackType)?->find((int) $value);

            return [
                'type' => $model ? class_basename($model) : $fallbackType,
                'id' => (int) $value,
                'model' => $model,
                'before' => $this->snapshot($model),
            ];
        }

        return ['type' => $fallbackType, 'id' => null, 'model' => null, 'before' => null];
    }

    private function modelForParameter(string $parameter): ?string
    {
        return match ($parameter) {
            'bookingRequest' => BookingRequest::class,
            'teacherReplacement' => TeacherReplacementRequest::class,
            'refund' => Refund::class,
            'payoutApproval' => PayoutApproval::class,
            'booking' => Booking::class,
            'bookingDispute' => BookingDispute::class,
            'sessionReport' => SessionReport::class,
            'curriculumSubject' => CurriculumSubject::class,
            'curriculumChapter' => CurriculumChapter::class,
            'hourlyRate' => HourlyRate::class,
            'packagePlan' => PackagePlan::class,
            'learningTimeSlot' => LearningTimeSlot::class,
            'promotion' => Promotion::class,
            'dynamicBanner' => DynamicBanner::class,
            'tutorial' => Tutorial::class,
            'tutorialStep' => TutorialStep::class,
            'user', 'admin' => User::class,
            default => null,
        };
    }

    private function modelForGenericIdPath(string $path): ?string
    {
        return match (true) {
            str_starts_with($path, 'api/admin/socials/') => SocialMedia::class,
            str_starts_with($path, 'api/admin/classes/') => Classroom::class,
            str_starts_with($path, 'api/admin/ratings/') => Rating::class,
            str_starts_with($path, 'api/admin/notes/') => Note::class,
            default => null,
        };
    }

    private function sanitize(array $payload): array
    {
        $sensitive = [
            'password', 'password_confirmation', 'current_password', 'super_admin_password', 'finance_totp_secret',
            'token', 'access_token', 'code', 'current_code', 'guardian_phone', 'guardian_name',
            'guardian_relationship', 'phone', 'address', 'maps_link', 'latitude', 'longitude',
            'date_of_birth', 'remember_token', 'consent_ip', 'consent_user_agent',
        ];

        foreach ($payload as $key => $value) {
            if (in_array((string) $key, $sensitive, true)) {
                $payload[$key] = '[REDACTED]';

                continue;
            }
            if ($value instanceof UploadedFile) {
                $payload[$key] = [
                    'file_name' => $value->getClientOriginalName(),
                    'size' => $value->getSize(),
                    'mime' => $value->getMimeType(),
                ];

                continue;
            }
            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value);

                continue;
            }
            if (in_array((string) $key, ['account_number', 'sender_account_number', 'destination_account_number'], true)) {
                $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
                $payload[$key] = str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
            }
        }

        return $payload;
    }
}
