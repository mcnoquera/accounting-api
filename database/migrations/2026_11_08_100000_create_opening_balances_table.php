<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One per installation: the balances the books start from on the cut-over date.
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->date('cutover_date');
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('equity_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Opening invoices/bills carry the customer's or supplier's balance from before cut-over.
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false)->after('status');
        });
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bills', fn (Blueprint $t) => $t->dropColumn('is_opening'));
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn('is_opening'));
        Schema::dropIfExists('opening_balances');
    }
};
