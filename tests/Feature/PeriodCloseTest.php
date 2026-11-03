<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodCloseTest extends TestCase
{
    use RefreshDatabase;

    private array $acc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
    }

    private function entry(string $date, bool $post = true): int
    {
        $this->acc ??= $this->basicAccounts();
        $id = $this->postJson('/api/v1/journal-entries', $this->entryPayload([
            [$this->acc['expense'], '100.00', 0], [$this->acc['asset'], 0, '100.00'],
        ], date: $date))->assertCreated()->json('data.id');
        if ($post) {
            $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();
        }

        return $id;
    }

    private function closeThrough(string ...$periods): void
    {
        foreach ($periods as $p) {
            $this->postJson("/api/v1/periods/{$p}/close")->assertOk();
        }
    }

    public function test_lists_months_from_first_entry_to_now_with_counts(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->entry('2026-08-10');
        $this->entry('2026-08-20', post: false);

        $res = $this->getJson('/api/v1/periods')->assertOk()
            ->assertJsonPath('meta.locked_through', null)
            ->assertJsonPath('meta.next_to_close', '2026-08')
            ->assertJsonPath('meta.fiscal_year_start_month', 1);

        $this->assertSame(['2026-11', '2026-10', '2026-09', '2026-08'], array_column($res->json('data'), 'period'));
        $aug = $res->json('data.3');
        $this->assertSame(['open', 1, 1, true], [$aug['status'], $aug['posted_count'], $aug['draft_count'], $aug['can_close']]);
        $this->assertFalse($res->json('data.2.can_close'));
    }

    public function test_periods_close_in_order_and_not_before_month_end(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->entry('2026-08-10');

        $this->postJson('/api/v1/periods/2026-09/close')->assertStatus(422)
            ->assertJsonPath('message', 'Periods close in order. Close 2026-08 first.');

        $this->closeThrough('2026-08', '2026-09', '2026-10');
        $this->postJson('/api/v1/periods/2026-08/close')->assertStatus(422)->assertJsonPath('message', '2026-08 is already closed.');
        $this->postJson('/api/v1/periods/2026-11/close')->assertStatus(422)
            ->assertJsonPath('message', "2026-11 hasn't ended yet. A month can be closed from its last day.");

        $this->getJson('/api/v1/periods')
            ->assertJsonPath('meta.locked_through', '2026-10-31')
            ->assertJsonPath('meta.first_open_date', '2026-11-01')
            ->assertJsonPath('data.1.status', 'closed')
            ->assertJsonPath('data.1.can_reopen', true)
            ->assertJsonPath('data.2.can_reopen', false);
    }

    public function test_close_reports_drafts_left_in_the_month(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->entry('2026-10-05', post: false);

        $this->postJson('/api/v1/periods/2026-10/close')->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('meta.draft_count', 1)
            ->assertJsonPath('meta.locked_through', '2026-10-31');
    }

    public function test_closed_period_blocks_create_post_and_void(): void
    {
        $this->actingAsRole(Role::Accountant);
        $posted = $this->entry('2026-10-05');
        $draft = $this->entry('2026-10-06', post: false);
        $this->closeThrough('2026-10');

        $this->postJson('/api/v1/journal-entries', $this->entryPayload([
            [$this->acc['expense'], '5', 0], [$this->acc['asset'], 0, '5'],
        ], date: '2026-10-31'))->assertStatus(422)
            ->assertJsonPath('message', 'The entry date is in a closed period (2026-10).')
            ->assertJsonPath('errors.entry_date.0', 'Use a date on or after 2026-11-01.');

        $this->postJson("/api/v1/journal-entries/{$draft}/post")->assertStatus(422)
            ->assertJsonPath('message', 'This entry is dated in a closed period (2026-10). Change its date to an open period before posting.');

        $this->postJson("/api/v1/journal-entries/{$posted}/void", ['reason' => 'Duplicate entry'])->assertStatus(422)
            ->assertJsonPath('message', 'This entry is in a closed period (2026-10) and cannot be voided. Reverse it instead, dated in an open period.');

        // Open dates still work.
        $this->entry('2026-11-01');
    }

    public function test_draft_in_closed_month_can_be_moved_out_but_not_within(): void
    {
        $this->actingAsRole(Role::Accountant);
        $draft = $this->entry('2026-10-06', post: false);
        $this->closeThrough('2026-10');

        $payload = fn ($date) => $this->entryPayload([[$this->acc['expense'], '100.00', 0], [$this->acc['asset'], 0, '100.00']], date: $date);
        $this->putJson("/api/v1/journal-entries/{$draft}", $payload('2026-10-07'))->assertStatus(422);
        $this->putJson("/api/v1/journal-entries/{$draft}", $payload('2026-11-02'))->assertOk();
        $this->postJson("/api/v1/journal-entries/{$draft}/post")->assertOk();
    }

    public function test_draft_in_closed_month_can_still_be_deleted(): void
    {
        $this->actingAsRole(Role::Accountant);
        $draft = $this->entry('2026-10-06', post: false);
        $this->closeThrough('2026-10');

        $this->deleteJson("/api/v1/journal-entries/{$draft}")->assertOk();
    }

    public function test_reversal_must_be_dated_in_an_open_period_and_defaults_to_first_open_date(): void
    {
        $this->actingAsRole(Role::Accountant);
        $id = $this->entry('2026-09-10');
        $this->closeThrough('2026-09', '2026-10');

        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Wrong account', 'entry_date' => '2026-10-31'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The reversal date is in a closed period (2026-10).');

        // Today (2026-11-15) is open, so no date means today.
        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Wrong account'])
            ->assertCreated()
            ->assertJsonPath('data.entry_date', '2026-11-15');
    }

    public function test_default_reversal_date_skips_past_a_closed_today(): void
    {
        $this->actingAsRole(Role::Accountant);
        $id = $this->entry('2026-10-10');
        $this->travelTo('2026-10-31 18:00:00');
        $this->closeThrough('2026-10');

        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Wrong account'])
            ->assertCreated()
            ->assertJsonPath('data.entry_date', '2026-11-01');
    }

    public function test_only_admin_reopens_only_the_latest_period_with_a_reason(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->entry('2026-09-10');
        $this->closeThrough('2026-09', '2026-10');

        $this->postJson('/api/v1/periods/2026-10/reopen', ['reason' => 'Late supplier bill'])->assertForbidden();

        $admin = $this->actingAsRole(Role::Admin);
        $this->postJson('/api/v1/periods/2026-10/reopen')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/periods/2026-09/reopen', ['reason' => 'Late supplier bill'])->assertStatus(422)
            ->assertJsonPath('message', 'Only the most recent closed period (2026-10) can be reopened.');

        $this->postJson('/api/v1/periods/2026-10/reopen', ['reason' => 'Late supplier bill'])->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.reopen_reason', 'Late supplier bill')
            ->assertJsonPath('data.reopened_by', $admin->id)
            ->assertJsonPath('meta.locked_through', '2026-09-30');

        // October takes entries again and can be closed again; November can't skip it.
        $this->entry('2026-10-20');
        $this->postJson('/api/v1/periods/2026-10/close')->assertOk();
        $this->postJson('/api/v1/periods/2026-11/reopen', ['reason' => 'Not closed yet'])->assertStatus(422)
            ->assertJsonPath('message', '2026-11 is not closed.');
    }

    public function test_auditor_cannot_close_and_everyone_can_view(): void
    {
        $this->actingAsRole(Role::Auditor);
        $this->getJson('/api/v1/periods')->assertOk();
        $this->postJson('/api/v1/periods/2026-10/close')->assertForbidden();
    }

    public function test_invalid_period_is_not_found(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->postJson('/api/v1/periods/2026-13/close')->assertNotFound();
    }

    public function test_close_and_reopen_are_audited(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->entry('2026-10-01');
        $this->postJson('/api/v1/periods/2026-10/close')->assertOk();
        $this->postJson('/api/v1/periods/2026-10/reopen', ['reason' => 'Correct accrual'])->assertOk();

        $logs = AuditLog::query()->where('auditable_type', 'AccountingPeriod')->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $logs->pluck('action')->all());
        $this->assertSame('closed', $logs[0]->new_values['status']);
        $this->assertSame(['status' => 'closed'], array_intersect_key($logs[1]->old_values, ['status' => 1]));
        $this->assertSame('Correct accrual', $logs[1]->new_values['reopen_reason']);
    }
}
