<?php

namespace App\Services;

use App\Enums\FindingStatus;
use App\Exceptions\DomainRuleException;
use App\Models\AuditFinding;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AuditFindingService
{
    public function list(?string $status = null, ?string $severity = null): LengthAwarePaginator
    {
        return AuditFinding::with(['entry:id,number,description', 'raiser:id,name', 'resolver:id,name'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($severity, fn ($q) => $q->where('severity', $severity))
            ->latest()
            ->paginate(20);
    }

    public function raise(array $data, User $auditor): AuditFinding
    {
        return AuditFinding::create([...$data, 'status' => FindingStatus::Open, 'raised_by' => $auditor->id])
            ->load(['entry:id,number,description', 'raiser:id,name']);
    }

    public function resolve(AuditFinding $finding, string $resolution, User $user): AuditFinding
    {
        if ($finding->status === FindingStatus::Resolved) {
            throw new DomainRuleException('Finding is already resolved.');
        }

        $finding->update([
            'status' => FindingStatus::Resolved,
            'resolution' => $resolution,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        return $finding->load(['entry:id,number,description', 'raiser:id,name', 'resolver:id,name']);
    }
}
