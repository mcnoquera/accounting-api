<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();      // VAT12, WC158 (BIR ATC for withholding)
            $table->string('name');
            $table->string('kind', 10);                 // vat | ewt
            $table->unsignedInteger('rate_bp');         // basis points: 1200 = 12%
            $table->string('vat_category', 20)->nullable(); // vatable | zero_rated | exempt (vat only)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Tags the accounts that tax amounts post to, so lines can be checked against the codes.
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('tax_role', 20)->nullable()->after('parent_id');
        });

        // Tax codes sit on the base line (the sale or purchase), whose amount is the tax base.
        // A line can be both VAT-coded and subject to withholding (e.g. VAT12 + WC160).
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('vat_code_id')->nullable()->after('memo')->constrained('tax_codes')->restrictOnDelete();
            $table->foreignId('ewt_code_id')->nullable()->after('vat_code_id')->constrained('tax_codes')->restrictOnDelete();
        });

        $now = now();
        DB::table('tax_codes')->insert(array_map(fn ($r) => [
            'code' => $r[0], 'name' => $r[1], 'kind' => $r[2], 'rate_bp' => $r[3], 'vat_category' => $r[4],
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], [
            ['VAT12', 'VAT 12% (vatable)', 'vat', 1200, 'vatable'],
            ['VAT0', 'VAT 0% (zero-rated)', 'vat', 0, 'zero_rated'],
            ['VATEX', 'VAT-exempt', 'vat', 0, 'exempt'],
            ['WC158', 'EWT 1% - goods, corporate payee', 'ewt', 100, null],
            ['WI158', 'EWT 1% - goods, individual payee', 'ewt', 100, null],
            ['WC160', 'EWT 2% - services, corporate payee', 'ewt', 200, null],
            ['WI160', 'EWT 2% - services, individual payee', 'ewt', 200, null],
            ['WI010', 'EWT 5% - professional fees, individual payee', 'ewt', 500, null],
            ['WC010', 'EWT 10% - professional fees, corporate payee', 'ewt', 1000, null],
        ]));

        // Installations that loaded the Philippine starter chart get its tax accounts tagged.
        // Matched on code and name so a custom chart that reuses a code isn't mislabelled.
        foreach ([
            '1420' => ['Input VAT', 'input_vat'],
            '1430' => ['Creditable Withholding Tax', 'cwt_receivable'],
            '2210' => ['Output VAT', 'output_vat'],
            '2230' => ['Withholding Tax Payable - Expanded', 'ewt_payable'],
        ] as $code => [$name, $role]) {
            DB::table('accounts')->where('code', $code)->where('name', $name)->update(['tax_role' => $role]);
        }
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ewt_code_id');
            $table->dropConstrainedForeignId('vat_code_id');
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('tax_role');
        });
        Schema::dropIfExists('tax_codes');
    }
};
