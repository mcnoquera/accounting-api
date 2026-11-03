<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReverseEntryTest extends TestCase
{
    use RefreshDatabase;

    private array $acc;

    private function postedEntry(string $amount = '1500.25'): int
    {
        $this->acc = $this->basicAccounts();
        $id = $this->postJson('/api/v1/journal-entries', $this->entryPayload([
            [$this->acc['expense'], $amount, 0], [$this->acc['asset'], 0, $amount],
        ], date: '2026-10-15'))->json('data.id');
        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();

        return $id;
    }

    public function test_reversal_swaps_lines_links_both_ways_and_nets_ledger_to_zero(): void
    {
        $user = $this->actingAsRole(Role::Accountant);
        $id = $this->postedEntry();

        $rev = $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Wrong expense account', 'entry_date' => '2026-10-31'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.entry_date', '2026-10-31')
            ->assertJsonPath('data.reversal_of_id', $id)
            ->assertJsonPath('data.reversal_of.number', 'JE-000001')
            ->assertJsonPath('data.reference', 'JE-000001')
            ->assertJsonPath('data.description', 'Reversal of JE-000001: Wrong expense account')
            ->assertJsonPath('data.posted_by', $user->id)
            ->json('data');

        $byAccount = collect($rev['lines'])->keyBy('account_id');
        $this->assertSame(150025, $byAccount[$this->acc['expense']->id]['credit']);
        $this->assertSame(150025, $byAccount[$this->acc['asset']->id]['debit']);

        $this->getJson("/api/v1/journal-entries/{$id}")
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.reversed_by.id', $rev['id']);

        $ledger = $this->getJson("/api/v1/accounts/{$this->acc['expense']->id}/ledger")->assertOk()->json('data');
        $this->assertSame(0, $ledger['closing_balance']);
        $this->assertCount(2, $ledger['rows']);
    }

    public function test_reason_is_required_and_date_defaults_to_today(): void
    {
        $this->actingAsRole(Role::Accountant);
        $id = $this->postedEntry();

        $this->postJson("/api/v1/journal-entries/{$id}/reverse", [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Duplicate'])
            ->assertCreated()->assertJsonPath('data.entry_date', now()->toDateString());
    }

    public function test_guards(): void
    {
        $this->actingAsRole(Role::Accountant);
        $id = $this->postedEntry();

        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Too early', 'entry_date' => '2026-10-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['entry_date']);

        $revId = $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Duplicate'])->json('data.id');

        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Again please'])
            ->assertStatus(422)->assertJsonPath('message', 'Entry JE-000001 has already been reversed.');
        $this->postJson("/api/v1/journal-entries/{$revId}/reverse", ['reason' => 'Undo undo'])
            ->assertStatus(422)->assertJsonPath('message', 'A reversing entry cannot itself be reversed.');

        // Neither side of a reversal pair can be voided.
        $this->postJson("/api/v1/journal-entries/{$id}/void", ['reason' => 'Voiding now'])->assertStatus(422);
        $this->postJson("/api/v1/journal-entries/{$revId}/void", ['reason' => 'Voiding now'])->assertStatus(422);
    }

    public function test_drafts_and_voided_entries_cannot_be_reversed(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->acc = $this->basicAccounts();
        $draft = $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$this->acc['asset'], '5', 0], [$this->acc['revenue'], 0, '5']]))->json('data.id');

        $this->postJson("/api/v1/journal-entries/{$draft}/reverse", ['reason' => 'Not posted'])
            ->assertStatus(422)->assertJsonPath('message', 'Only posted entries can be reversed.');

        $this->postJson("/api/v1/journal-entries/{$draft}/post");
        $this->postJson("/api/v1/journal-entries/{$draft}/void", ['reason' => 'Mistake made']);
        $this->postJson("/api/v1/journal-entries/{$draft}/reverse", ['reason' => 'Voided one'])->assertStatus(422);
    }

    public function test_auditor_cannot_reverse(): void
    {
        $this->actingAsRole(Role::Accountant);
        $id = $this->postedEntry();
        Sanctum::actingAs(User::factory()->role(Role::Auditor)->create());

        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Not allowed'])->assertForbidden();
    }
}
