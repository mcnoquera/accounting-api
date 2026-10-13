<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_balanced_draft_in_cents(): void
    {
        $user = $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();

        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '1250.5', 0], [$acc['revenue'], 0, '1250.50']]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', 'JE-000001')
            ->assertJsonPath('data.created_by', $user->id)
            ->assertJsonPath('data.lines.0.debit', 125050);
    }

    public function test_rejects_unbalanced_entry(): void
    {
        $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();

        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '100', 0], [$acc['revenue'], 0, '99.99']]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Entry is out of balance: debits 100.00, credits 99.99.');
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_rejects_line_with_both_debit_and_credit(): void
    {
        $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();

        $this->postJson('/api/v1/journal-entries', $this->entryPayload([
            [$acc['asset'], '10', '10'], [$acc['revenue'], '5', 0], [$acc['equity'], 0, '5'],
        ]))->assertStatus(422);
    }

    public function test_rejects_bad_amount_format(): void
    {
        $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();

        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '-5', 0], [$acc['revenue'], 0, '1.234']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.debit', 'lines.1.credit']);
    }

    public function test_post_then_void_lifecycle(): void
    {
        $user = $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();
        $id = $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '10', 0], [$acc['revenue'], 0, '10']]))->json('data.id');

        $this->postJson("/api/v1/journal-entries/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.posted_by', $user->id);

        // Posted entries are immutable.
        $this->putJson("/api/v1/journal-entries/{$id}", $this->entryPayload([[$acc['asset'], '20', 0], [$acc['revenue'], 0, '20']]))
            ->assertStatus(422);
        $this->deleteJson("/api/v1/journal-entries/{$id}")->assertStatus(422);
        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertStatus(422);

        $this->postJson("/api/v1/journal-entries/{$id}/void", ['reason' => 'Duplicate of JE-9'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.void_reason', 'Duplicate of JE-9');

        $this->assertDatabaseHas('audit_logs', ['action' => 'updated', 'auditable_type' => 'JournalEntry', 'auditable_id' => $id]);
    }

    public function test_cannot_post_with_inactive_account(): void
    {
        $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();
        $id = $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '10', 0], [$acc['revenue'], 0, '10']]))->json('data.id');
        $acc['revenue']->update(['is_active' => false]);

        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertStatus(422);
    }

    public function test_draft_can_be_edited_and_deleted(): void
    {
        $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();
        $id = $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '10', 0], [$acc['revenue'], 0, '10']]))->json('data.id');

        $this->putJson("/api/v1/journal-entries/{$id}", $this->entryPayload([[$acc['expense'], '30', 0], [$acc['liability'], 0, '30']], description: 'Edited'))
            ->assertOk()
            ->assertJsonPath('data.description', 'Edited')
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.debit', 3000);

        $this->deleteJson("/api/v1/journal-entries/{$id}")->assertOk();
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_auditor_is_read_only(): void
    {
        $this->actingAsRole(Role::Auditor);
        $acc = $this->basicAccounts();

        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '10', 0], [$acc['revenue'], 0, '10']]))
            ->assertForbidden();
        $this->getJson('/api/v1/journal-entries')->assertOk();
    }

    public function test_list_filters_and_paginates(): void
    {
        $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();
        foreach (['2026-01-05', '2026-02-05', '2026-03-05'] as $d) {
            $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '10', 0], [$acc['revenue'], 0, '10']], $d));
        }

        $this->getJson('/api/v1/journal-entries?from=2026-02-01&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.entry_date', '2026-03-05');
    }
}
