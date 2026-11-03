<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts money is received into or paid from.
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_cash')->default(false)->after('control_type');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();               // RC-000001 (receipt) / PY-000001 (payment)
            $table->string('direction', 10)->index();             // receipt | payment
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->date('payment_date')->index();
            $table->foreignId('cash_account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('amount');                         // cents of cash moved
            $table->foreignId('ewt_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete(); // receipts: customer's ATC (Form 2307)
            $table->bigInteger('withholding_base')->default(0);   // cents, receipts only
            $table->bigInteger('withholding_amount')->default(0); // cents, receipts only
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 10)->index();                // posted | voided
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('document_type', 20);                  // sales_invoice | purchase_bill
            $table->unsignedBigInteger('document_id');
            $table->bigInteger('amount');                         // cents applied
            $table->timestamps();
            $table->index(['document_type', 'document_id']);
        });

        // The Philippine starter chart's cash and bank accounts.
        foreach ([
            '1110' => 'Cash on Hand', '1120' => 'Petty Cash Fund',
            '1130' => 'Cash in Bank - Current Account', '1140' => 'Cash in Bank - Savings Account',
        ] as $code => $name) {
            DB::table('accounts')->where('code', $code)->where('name', $name)->update(['is_cash' => true]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_cash');
        });
    }
};
