<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_bills', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();   // PB-000001, assigned when recorded
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('supplier_invoice_number', 100);       // the supplier's own invoice number
            $table->date('bill_date')->index();
            $table->date('due_date')->index();
            $table->text('notes')->nullable();
            $table->string('status', 16)->index();                 // draft | issued (recorded) | partially_paid | paid | voided
            $table->bigInteger('net_amount')->default(0);          // cents, before VAT
            $table->bigInteger('vat_amount')->default(0);
            $table->bigInteger('ewt_amount')->default(0);          // withheld from the supplier
            $table->bigInteger('total_amount')->default(0);        // net + VAT
            $table->bigInteger('amount_due')->default(0);          // total − withholding: what we pay the supplier
            $table->bigInteger('amount_paid')->default(0);         // filled by payments (US-18)
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'supplier_invoice_number']);
        });

        Schema::create('purchase_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_bill_id')->constrained('purchase_bills')->cascadeOnDelete();
            $table->string('description', 500);
            $table->bigInteger('amount'); // cents, before VAT
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('vat_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->foreignId('ewt_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_bill_lines');
        Schema::dropIfExists('purchase_bills');
    }
};
