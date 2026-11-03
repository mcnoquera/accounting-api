<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\ControlType;
use App\Enums\TaxRole;
use App\Exceptions\DomainRuleException;
use App\Models\Account;
use App\Models\Contact;
use App\Support\Tin;
use Illuminate\Support\Facades\DB;

/**
 * CSV import of the chart of accounts and of contacts. preview() validates every row and
 * says what would happen (create / update / error) without writing; commit() re-validates
 * the same file and writes everything in one transaction, or nothing if any row has an error.
 */
class ImportService
{
    public const COLUMNS = [
        'accounts' => ['code', 'name', 'type', 'parent_code', 'tax_role', 'control_type', 'is_cash', 'description', 'is_active'],
        'contacts' => ['name', 'type', 'tin', 'address', 'email', 'phone', 'payment_terms_days', 'is_active'],
    ];

    private const MAX_ROWS = 5000;

    public function template(string $kind): string
    {
        $examples = [
            'accounts' => [
                ['1100', 'Cash and Cash Equivalents', 'asset', '', '', '', 'no', 'Group account', 'yes'],
                ['1130', 'Cash in Bank - BDO Current', 'asset', '1100', '', '', 'yes', '', 'yes'],
                ['1210', 'Accounts Receivable - Trade', 'asset', '', '', 'receivable', 'no', '', 'yes'],
                ['2210', 'Output VAT', 'liability', '', 'output_vat', '', 'no', '', 'yes'],
            ],
            'contacts' => [
                ['Mabuhay Retail Inc.', 'customer', '200-333-444-000', 'Pasig City', 'ap@example.com', '', '30', 'yes'],
                ['Visayas Supply Co.', 'supplier', '300-111-222-000', 'Cebu City', '', '', '15', 'yes'],
            ],
        ];
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS[$kind], escape: '');
        foreach ($examples[$kind] as $row) {
            fputcsv($out, $row, escape: '');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /** @return array{rows: list<array>, summary: array{create:int, update:int, error:int}} */
    public function preview(string $kind, string $csv): array
    {
        $rows = $kind === 'accounts' ? $this->checkAccounts($this->parse($kind, $csv)) : $this->checkContacts($this->parse($kind, $csv));
        $summary = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0];
        foreach ($rows as $r) {
            $summary[$r['action']]++;
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    public function commit(string $kind, string $csv): array
    {
        $result = $this->preview($kind, $csv);
        if ($result['summary']['error'] > 0) {
            throw new DomainRuleException("{$result['summary']['error']} row(s) have errors. Fix them and preview again; nothing was imported.");
        }

        DB::transaction(function () use ($kind, $result) {
            $kind === 'accounts' ? $this->writeAccounts($result['rows']) : $this->writeContacts($result['rows']);
        });

        return $result;
    }

    /** @return list<array{line:int, values: array<string, string>}> */
    private function parse(string $kind, string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if ($lines === [''] || $lines === false) {
            throw new DomainRuleException('The file is empty.');
        }
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), escape: ''));
        $missing = array_diff(['name', 'type', ...($kind === 'accounts' ? ['code'] : [])], $header);
        if ($missing !== []) {
            throw new DomainRuleException('Missing column(s): '.implode(', ', $missing).'. Download the template for the expected columns.');
        }
        $unknown = array_diff($header, self::COLUMNS[$kind]);
        if ($unknown !== []) {
            throw new DomainRuleException('Unknown column(s): '.implode(', ', $unknown).'.');
        }
        if (count($lines) > self::MAX_ROWS) {
            throw new DomainRuleException('Import up to '.self::MAX_ROWS.' rows at a time.');
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, escape: '');
            $values = [];
            foreach ($header as $j => $col) {
                $values[$col] = trim((string) ($cells[$j] ?? ''));
            }
            $rows[] = ['line' => $i + 2, 'values' => $values];
        }

        return $rows;
    }

    private function bool(string $v, bool $default): ?bool
    {
        $v = strtolower($v);

        return match (true) {
            $v === '' => $default,
            in_array($v, ['yes', 'y', 'true', '1'], true) => true,
            in_array($v, ['no', 'n', 'false', '0'], true) => false,
            default => null,
        };
    }

    private function checkAccounts(array $rows): array
    {
        $existing = Account::query()->withCount('lines')->get()->keyBy('code');
        $inFile = [];
        foreach ($rows as $r) {
            $inFile[$r['values']['code']] = ($inFile[$r['values']['code']] ?? 0) + 1;
        }
        $types = [];
        foreach ($rows as $r) {
            $types[$r['values']['code']] = strtolower($r['values']['type']);
        }

        return array_map(function ($r) use ($existing, $inFile, $types) {
            $v = $r['values'];
            $errors = [];
            $code = $v['code'];
            if (! preg_match('/^[A-Za-z0-9.\-]{1,20}$/', $code)) {
                $errors[] = 'code: letters, digits, dots and dashes, up to 20 characters';
            } elseif ($inFile[$code] > 1) {
                $errors[] = "code {$code} appears more than once in the file";
            }
            if ($v['name'] === '' || mb_strlen($v['name']) > 255) {
                $errors[] = 'name: required, up to 255 characters';
            }
            $type = AccountType::tryFrom(strtolower($v['type']));
            if (! $type) {
                $errors[] = 'type: asset, liability, equity, revenue or expense';
            }
            $parent = $v['parent_code'] ?? '';
            if ($parent !== '') {
                $parentType = $types[$parent] ?? $existing->get($parent)?->type?->value;
                if ($parentType === null) {
                    $errors[] = "parent_code {$parent} isn't in the chart or the file";
                } elseif ($type && $parentType !== $type->value) {
                    $errors[] = "parent_code {$parent} is a {$parentType} account";
                } elseif ($parent === $code) {
                    $errors[] = 'an account cannot be its own parent';
                }
            }
            $taxRole = ($v['tax_role'] ?? '') === '' ? null : TaxRole::tryFrom($v['tax_role']);
            if (($v['tax_role'] ?? '') !== '' && ! $taxRole) {
                $errors[] = 'tax_role: input_vat, output_vat, ewt_payable or cwt_receivable';
            }
            $control = ($v['control_type'] ?? '') === '' ? null : ControlType::tryFrom($v['control_type']);
            if (($v['control_type'] ?? '') !== '' && ! $control) {
                $errors[] = 'control_type: receivable or payable';
            } elseif ($control && $type && $type !== ($control === ControlType::Receivable ? AccountType::Asset : AccountType::Liability)) {
                $errors[] = "a {$control->value} control account must be ".($control === ControlType::Receivable ? 'an asset' : 'a liability');
            }
            $isCash = $this->bool($v['is_cash'] ?? '', false);
            $active = $this->bool($v['is_active'] ?? '', true);
            if ($isCash === null || $active === null) {
                $errors[] = 'is_cash / is_active: yes or no';
            } elseif ($isCash && $type && $type !== AccountType::Asset) {
                $errors[] = 'only asset accounts can be cash or bank accounts';
            }

            $current = $existing->get($code);
            if ($current && $type && $current->type !== $type && $current->lines_count > 0) {
                $errors[] = "account {$code} has entries, so its type can't change";
            }

            $data = [
                'code' => $code, 'name' => $v['name'], 'type' => $type?->value, 'parent_code' => $parent ?: null,
                'tax_role' => $taxRole?->value, 'control_type' => $control?->value, 'is_cash' => (bool) $isCash,
                'description' => ($v['description'] ?? '') ?: null, 'is_active' => (bool) $active,
            ];
            $action = $errors ? 'error' : ($current ? ($this->accountChanged($current, $data) ? 'update' : 'unchanged') : 'create');

            return ['line' => $r['line'], 'key' => $code, 'action' => $action, 'errors' => $errors, 'data' => $data];
        }, $rows);
    }

    private function accountChanged(Account $a, array $d): bool
    {
        return $a->name !== $d['name'] || $a->type->value !== $d['type'] || $a->tax_role?->value !== $d['tax_role']
            || $a->control_type?->value !== $d['control_type'] || $a->is_cash !== $d['is_cash'] || $a->description !== $d['description']
            || $a->is_active !== $d['is_active'] || $a->parent?->code !== $d['parent_code'];
    }

    private function writeAccounts(array $rows): void
    {
        // Parents first so children can point at them.
        $pending = array_filter($rows, fn ($r) => $r['action'] !== 'unchanged');
        $codes = Account::query()->pluck('id', 'code')->all();
        $guard = 0;
        while ($pending && $guard++ < 50) {
            foreach ($pending as $k => $r) {
                $d = $r['data'];
                if ($d['parent_code'] && ! isset($codes[$d['parent_code']])) {
                    continue;
                }
                $attrs = [
                    'name' => $d['name'], 'type' => $d['type'], 'parent_id' => $d['parent_code'] ? $codes[$d['parent_code']] : null,
                    'tax_role' => $d['tax_role'], 'control_type' => $d['control_type'], 'is_cash' => $d['is_cash'],
                    'description' => $d['description'], 'is_active' => $d['is_active'],
                ];
                $account = Account::query()->updateOrCreate(['code' => $d['code']], $attrs);
                $codes[$d['code']] = $account->id;
                unset($pending[$k]);
            }
        }
        if ($pending) {
            throw new DomainRuleException('Parent accounts form a loop: '.implode(', ', array_column(array_column($pending, 'data'), 'code')).'.');
        }
    }

    private function checkContacts(array $rows): array
    {
        $byTin = Contact::query()->whereNotNull('tin')->get()->keyBy('tin');
        $byName = Contact::query()->get()->keyBy(fn ($c) => mb_strtolower($c->name));
        $seen = [];

        return array_map(function ($r) use ($byTin, $byName, &$seen) {
            $v = $r['values'];
            $errors = [];
            if ($v['name'] === '' || mb_strlen($v['name']) > 255) {
                $errors[] = 'name: required, up to 255 characters';
            }
            $type = ContactType::tryFrom(strtolower($v['type']));
            if (! $type) {
                $errors[] = 'type: customer, supplier or both';
            }
            $tin = null;
            if (($v['tin'] ?? '') !== '') {
                $tin = Tin::isValid($v['tin']) ? Tin::normalize($v['tin']) : null;
                if (! $tin) {
                    $errors[] = 'tin: 9 digits plus a 3- or 5-digit branch code';
                }
            }
            if (($v['email'] ?? '') !== '' && ! filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'email: not a valid address';
            }
            $terms = ($v['payment_terms_days'] ?? '') === '' ? 30 : (ctype_digit($v['payment_terms_days']) ? (int) $v['payment_terms_days'] : -1);
            if ($terms < 0 || $terms > 365) {
                $errors[] = 'payment_terms_days: 0 to 365';
            }
            $active = $this->bool($v['is_active'] ?? '', true);
            if ($active === null) {
                $errors[] = 'is_active: yes or no';
            }
            $key = $tin ?? mb_strtolower($v['name']);
            if (isset($seen[$key])) {
                $errors[] = "same contact as line {$seen[$key]}";
            }
            $seen[$key] = $r['line'];

            $current = $tin ? $byTin->get($tin) : $byName->get(mb_strtolower($v['name']));
            $data = [
                'name' => $v['name'], 'type' => $type?->value, 'tin' => $tin, 'address' => ($v['address'] ?? '') ?: null,
                'email' => ($v['email'] ?? '') ?: null, 'phone' => ($v['phone'] ?? '') ?: null, 'payment_terms_days' => $terms, 'is_active' => (bool) $active,
                'id' => $current?->id,
            ];
            $changed = $current && collect($data)->except('id')->some(fn ($val, $k) => ($current->{$k} instanceof \BackedEnum ? $current->{$k}->value : $current->{$k}) !== $val);
            $action = $errors ? 'error' : ($current ? ($changed ? 'update' : 'unchanged') : 'create');

            return ['line' => $r['line'], 'key' => $v['name'], 'action' => $action, 'errors' => $errors, 'data' => $data];
        }, $rows);
    }

    private function writeContacts(array $rows): void
    {
        foreach ($rows as $r) {
            if ($r['action'] === 'unchanged') {
                continue;
            }
            $d = $r['data'];
            $id = $d['id'];
            unset($d['id']);
            $id ? Contact::query()->findOrFail($id)->update($d) : Contact::create($d);
        }
    }
}
