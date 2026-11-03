<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // System-generated year-end closing entries (and their reversals). The income statement
        // leaves them out so a closed year still shows its profit.
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->boolean('is_closing')->default(false)->after('reversal_of_id')->index();
        });

        Schema::create('fiscal_year_closes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year')->unique(); // calendar year the fiscal year starts in
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10); // closed | reopened
            $table->foreignId('retained_earnings_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('closing_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->bigInteger('net_income')->default(0); // cents moved to retained earnings
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at');
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_year_closes');
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['is_closing']);
            $table->dropColumn('is_closing');
        });
    }
};
