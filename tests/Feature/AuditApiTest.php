<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_auditors_and_admins_see_audit_trail(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->getJson('/api/v1/audit/logs')->assertForbidden();
        $this->getJson('/api/v1/audit/exceptions')->assertForbidden();

        $this->actingAsRole(Role::Auditor);
        $this->getJson('/api/v1/audit/logs')->assertOk();
    }

    public function test_audit_log_records_old_and_new_values(): void
    {
        $this->actingAsRole(Role::Admin);
        $acc = $this->basicAccounts();
        $this->putJson("/api/v1/accounts/{$acc['asset']->id}", ['name' => 'Petty Cash'])->assertOk();

        $this->getJson('/api/v1/audit/logs?action=updated&type=Account')
            ->assertOk()
            ->assertJsonPath('data.0.new_values.name', 'Petty Cash')
            ->assertJsonPath('data.0.auditable_id', $acc['asset']->id);
    }

    public function test_audit_logs_are_immutable(): void
    {
        $log = AuditLog::create(['action' => 'test']);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_exceptions_flag_self_approved_and_large_entries(): void
    {
        $preparer = $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();
        $self = $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '60000', 0], [$acc['equity'], 0, '60000']], '2026-03-04'))->json('data.id');
        $this->postJson("/api/v1/journal-entries/{$self}/post")->assertOk();

        // A clean entry: small, recent weekday, posted by someone else.
        $weekday = now()->isWeekend() ? now()->previous(Carbon::FRIDAY) : now();
        $other = $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '12.34', 0], [$acc['revenue'], 0, '12.34']], $weekday->toDateString()))->json('data.id');
        Sanctum::actingAs(User::factory()->role(Role::Accountant)->create());
        $this->postJson("/api/v1/journal-entries/{$other}/post")->assertOk();

        $this->actingAsRole(Role::Auditor);
        $res = $this->getJson('/api/v1/audit/exceptions')->assertOk();

        $flags = collect($res->json('data.flags'));
        $this->assertTrue($flags->contains(fn ($f) => $f['rule'] === 'self_approved' && $f['entry']['id'] === $self));
        $this->assertTrue($flags->contains(fn ($f) => $f['rule'] === 'large_amount' && $f['severity'] === 'high'));
        $this->assertFalse($flags->contains(fn ($f) => $f['entry']['id'] === $other));
        $this->assertSame('high', $flags->first()['severity']);
    }

    public function test_finding_lifecycle(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->postJson('/api/v1/audit/findings', ['title' => 'x', 'description' => 'y', 'severity' => 'low'])->assertForbidden();

        $auditor = $this->actingAsRole(Role::Auditor);
        $id = $this->postJson('/api/v1/audit/findings', [
            'title' => 'Missing invoice', 'description' => 'No support for JE', 'severity' => 'medium',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.raised_by', $auditor->id)->json('data.id');

        $this->actingAsRole(Role::Accountant);
        $this->postJson("/api/v1/audit/findings/{$id}/resolve", ['resolution' => 'Invoice attached to file'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
        $this->postJson("/api/v1/audit/findings/{$id}/resolve", ['resolution' => 'Again please'])->assertStatus(422);

        $this->getJson('/api/v1/audit/findings?status=resolved')->assertOk()->assertJsonCount(1, 'data');
    }
}
