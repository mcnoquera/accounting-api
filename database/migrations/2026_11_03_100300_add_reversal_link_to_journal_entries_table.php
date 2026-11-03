<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Set on the reversing entry; the original finds it through the inverse relation.
            // Unique so an entry can only be reversed once.
            $table->foreignId('reversal_of_id')->nullable()->unique()->after('void_reason')
                ->constrained('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique(['reversal_of_id']);
            $table->dropConstrainedForeignId('reversal_of_id');
        });
    }
};
