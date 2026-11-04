<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cash accounts are cash on hand, bank accounts or e-wallets: reconciled and reported differently.
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('cash_kind', 10)->nullable()->after('is_cash'); // cash | bank | ewallet
            $table->string('provider', 100)->nullable()->after('cash_kind'); // e.g. BPI, BDO, GCash
            $table->string('account_number_last4', 4)->nullable()->after('provider');
        });

        // Existing cash accounts: "bank" if the name says so, otherwise cash on hand.
        DB::table('accounts')->where('is_cash', true)->whereRaw('LOWER(name) LIKE ?', ['%bank%'])->update(['cash_kind' => 'bank']);
        DB::table('accounts')->where('is_cash', true)->whereRaw('LOWER(name) LIKE ?', ['%gcash%'])->update(['cash_kind' => 'ewallet', 'provider' => 'GCash']);
        DB::table('accounts')->where('is_cash', true)->whereNull('cash_kind')->update(['cash_kind' => 'cash']);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['cash_kind', 'provider', 'account_number_last4']);
        });
    }
};
