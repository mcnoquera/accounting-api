<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per month that has ever been closed. Periods close in order and only the
        // latest can be reopened, so the closed months always form one run from the start.
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique(); // YYYY-MM
            $table->string('status', 10);        // closed | open (reopened)
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
