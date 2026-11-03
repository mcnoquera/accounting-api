<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 10);                  // customer | supplier | both
            $table->string('tin', 20)->nullable();       // 123-456-789-000
            $table->string('address', 500)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['type', 'is_active']);
        });

        // Receivable/payable control accounts: lines on them must name a customer/supplier.
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('control_type', 12)->nullable()->after('tax_role'); // receivable | payable
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('ewt_code_id')->constrained('contacts')->restrictOnDelete();
            $table->index(['contact_id', 'account_id']);
        });

        // Installations that loaded the Philippine starter chart get its trade accounts tagged.
        foreach ([
            '1210' => ['Accounts Receivable - Trade', 'receivable'],
            '2110' => ['Accounts Payable - Trade', 'payable'],
        ] as $code => [$name, $type]) {
            DB::table('accounts')->where('code', $code)->where('name', $name)->update(['control_type' => $type]);
        }
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex(['contact_id', 'account_id']);
            $table->dropConstrainedForeignId('contact_id');
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('control_type');
        });
        Schema::dropIfExists('contacts');
    }
};
