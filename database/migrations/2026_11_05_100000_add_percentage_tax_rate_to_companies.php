<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Percentage tax for non-VAT businesses (BIR 2551Q). Basis points: 300 = 3%.
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('percentage_tax_rate_bp')->default(300)->after('vat_registered');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('percentage_tax_rate_bp');
        });
    }
};
