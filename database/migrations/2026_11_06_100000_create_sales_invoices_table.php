<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique(); // SI-000001, assigned when issued
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->date('invoice_date')->index();
            $table->date('due_date')->index();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->index(); // draft | issued | partially_paid | paid | voided
            $table->bigInteger('net_amount')->default(0);   // cents, before VAT
            $table->bigInteger('vat_amount')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->bigInteger('amount_paid')->default(0);  // filled by receipts (US-18)
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->string('description', 500);
            $table->unsignedBigInteger('quantity_milli'); // quantity × 1000, so 1.5 = 1500
            $table->bigInteger('unit_price');             // cents
            $table->bigInteger('amount');                 // cents = quantity × unit price, rounded half up
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('vat_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
        });

        // Which document produced a journal entry; such entries are changed only through the document.
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('source_type', 30)->nullable()->after('is_closing');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });
        Schema::dropIfExists('sales_invoice_lines');
        Schema::dropIfExists('sales_invoices');
    }
};
