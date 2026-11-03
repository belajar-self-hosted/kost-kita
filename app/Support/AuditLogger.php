<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

class AuditLogger
{
    private const SENSITIVE_KEY_PATTERN = '/password|token|secret|credential|identity|ktp|passport|api.?key/i';

    private const SAFE_REASON_CODES = ['owner_requested_archive', 'super_admin_provisioned_via_cli'];

    /** @param array<string, mixed>|null $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        User $actor,
        ?Tenant $tenant,
        string $action,
        string $subjectType,
        ?int $subjectId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => $tenant?->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'reason' => $this->sanitizeReason($reason),
            'occurred_at' => now('UTC'),
            'request_id' => (string) Str::uuid(),
        ]);
    }

    private function sanitizeReason(?string $reason): ?string
    {
        return in_array($reason, self::SAFE_REASON_CODES, true) ? $reason : null;
    }

    /** @param array<string, mixed>|null $values
     * @return array<string, mixed>|null
     */
    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $safe = [];
        foreach ($values as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key) === 1) {
                continue;
            }

            $safe[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $safe;
    }
}
