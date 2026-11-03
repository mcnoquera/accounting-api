<?php

namespace App\Services;

use App\Enums\EntryStatus;
use App\Exceptions\DomainRuleException;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Month-end close. Periods are calendar months closed strictly in order, and only the
 * latest closed month can be reopened, so the books are locked through one date: the last
 * day of the latest closed month. Anything dated on or before it is closed.
 */
class PeriodService
{
    /** Last day (Y-m-d) of the latest closed month, or null if nothing is closed. */
    public function lockedThrough(): ?string
    {
        $latest = $this->latestClosed();

        return $latest ? $this->lastDay($latest->period) : null;
    }

    public function isClosed(string $date): bool
    {
        $locked = $this->lockedThrough();

        return $locked !== null && substr($date, 0, 10) <= $locked;
    }

    /** The earliest date that can still be posted to. */
    public function firstOpenDate(): ?string
    {
        $locked = $this->lockedThrough();

        return $locked ? CarbonImmutable::parse($locked)->addDay()->toDateString() : null;
    }

    /**
     * @param  string  $field  request field to attach the error to
     *
     * @throws DomainRuleException when $date is in a closed period
     */
    public function assertOpen(string $date, string $message, string $field = 'entry_date'): void
    {
        if (! $this->isClosed($date)) {
            return;
        }

        $period = substr($date, 0, 7);
        $hint = 'Use a date on or after '.$this->firstOpenDate().'.';

        throw new DomainRuleException(str_replace('{period}', $period, $message), [$field => [$hint]]);
    }

    /**
     * Every month from the first one with activity up to the current month, newest first,
     * with its status and entry counts.
     *
     * @return array{periods: list<array<string, mixed>>, locked_through: ?string, first_open_date: ?string, next_to_close: ?string, fiscal_year_start_month: int}
     */
    public function overview(): array
    {
        $rows = AccountingPeriod::query()->with(['closer:id,name', 'reopener:id,name'])->get()->keyBy('period');
        $locked = $this->lockedThrough();
        $current = now()->format('Y-m');

        $first = collect([$this->earliestEntryMonth(), $rows->keys()->min(), $current])->filter()->min();

        $counts = [];
        JournalEntry::query()
            ->selectRaw('entry_date, status, COUNT(*) as n')
            ->groupBy('entry_date', 'status')
            ->get()
            ->each(function ($r) use (&$counts) {
                $month = substr((string) $r->getRawOriginal('entry_date'), 0, 7);
                $status = $r->status instanceof EntryStatus ? $r->status->value : (string) $r->status;
                $counts[$month][$status] = ($counts[$month][$status] ?? 0) + (int) $r->n;
            });

        $next = $this->nextToClose();
        $latestClosed = $this->latestClosed()?->period;

        $periods = [];
        for ($m = CarbonImmutable::parse($first.'-01'); $m->format('Y-m') <= $current; $m = $m->addMonth()) {
            $key = $m->format('Y-m');
            $row = $rows->get($key);
            $closed = $locked !== null && $this->lastDay($key) <= $locked;
            $periods[] = [
                'period' => $key,
                'start_date' => $m->toDateString(),
                'end_date' => $m->endOfMonth()->toDateString(),
                'status' => $closed ? AccountingPeriod::CLOSED : AccountingPeriod::OPEN,
                'closed_at' => $closed ? $row?->closed_at : null,
                'closed_by' => $closed ? $row?->closer : null,
                'reopened_at' => $row?->reopened_at,
                'reopened_by' => $row?->reopener,
                'reopen_reason' => $row?->reopen_reason,
                'posted_count' => $counts[$key][EntryStatus::Posted->value] ?? 0,
                'draft_count' => $counts[$key][EntryStatus::Draft->value] ?? 0,
                'can_close' => $key === $next,
                'can_reopen' => $key === $latestClosed,
            ];
        }

        return [
            'periods' => array_reverse($periods),
            'locked_through' => $locked,
            'first_open_date' => $this->firstOpenDate(),
            'next_to_close' => $next,
            'fiscal_year_start_month' => Company::current()?->fiscal_year_start_month ?? 1,
        ];
    }

    /**
     * @return array{period: AccountingPeriod, draft_count: int}
     */
    public function close(string $period, User $user): array
    {
        return DB::transaction(function () use ($period, $user) {
            // Serialise closes and reopens so two people can't skip a month between them.
            AccountingPeriod::query()->lockForUpdate()->get(['id']);

            if ($this->isClosed($period.'-01')) {
                throw new DomainRuleException("{$period} is already closed.");
            }
            if ($this->lastDay($period) > now()->toDateString()) {
                throw new DomainRuleException("{$period} hasn't ended yet. A month can be closed from its last day.");
            }
            $next = $this->nextToClose();
            if ($next !== null && $period !== $next) {
                throw new DomainRuleException("Periods close in order. Close {$next} first.");
            }

            $row = AccountingPeriod::query()->firstOrNew(['period' => $period]);
            $row->fill([
                'status' => AccountingPeriod::CLOSED,
                'closed_by' => $user->id,
                'closed_at' => now(),
            ])->save();

            $drafts = JournalEntry::query()
                ->where('status', EntryStatus::Draft)
                ->whereBetween('entry_date', [$period.'-01', $this->lastDay($period)])
                ->count();

            return ['period' => $row->load('closer:id,name'), 'draft_count' => $drafts];
        });
    }

    public function reopen(string $period, User $user, string $reason): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $user, $reason) {
            AccountingPeriod::query()->lockForUpdate()->get(['id']);

            $latest = $this->latestClosed();
            if ($latest === null || ! $this->isClosed($period.'-01')) {
                throw new DomainRuleException("{$period} is not closed.");
            }
            if ($latest->period !== $period) {
                throw new DomainRuleException("Only the most recent closed period ({$latest->period}) can be reopened.");
            }

            $latest->fill([
                'status' => AccountingPeriod::OPEN,
                'reopened_by' => $user->id,
                'reopened_at' => now(),
                'reopen_reason' => $reason,
            ])->save();

            return $latest->load('reopener:id,name');
        });
    }

    /**
     * The only month that may be closed next: the month after the latest closed one, or the
     * month of the earliest entry when nothing has been closed yet (null: any month).
     */
    public function nextToClose(): ?string
    {
        $latest = $this->latestClosed();
        if ($latest) {
            return CarbonImmutable::parse($latest->period.'-01')->addMonth()->format('Y-m');
        }

        return $this->earliestEntryMonth();
    }

    private function latestClosed(): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('status', AccountingPeriod::CLOSED)
            ->orderByDesc('period')
            ->first();
    }

    private function earliestEntryMonth(): ?string
    {
        $min = JournalEntry::query()->min('entry_date');

        return $min ? substr((string) $min, 0, 7) : null;
    }

    private function lastDay(string $period): string
    {
        return CarbonImmutable::parse($period.'-01')->endOfMonth()->toDateString();
    }
}
