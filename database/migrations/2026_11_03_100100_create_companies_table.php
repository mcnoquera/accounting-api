<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row table: one company per installation.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('tin', 20);
            $table->string('rdo_code', 3)->nullable();
            $table->string('address', 500);
            $table->string('zip_code', 4)->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->char('currency', 3)->default('PHP');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
