<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('referral_code')->unique()->nullable()->after('wallet');
            $table->uuid('referred_by')->nullable()->after('referral_code');
            $table->integer('referral_count')->default(0)->after('referred_by');
            $table->decimal('referral_earnings', 10, 2)->default(0)->after('referral_count');

            $table->foreign('referred_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);
            $table->dropColumn(['referral_code', 'referred_by', 'referral_count', 'referral_earnings']);
        });
    }
};
