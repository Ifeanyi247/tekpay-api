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
        Schema::create('transfer_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->references('id')->on('users');
            $table->string('account_name');
            $table->string('account_number');
            $table->decimal('amount', 10, 2);
            $table->string('account_bank');
            $table->string('account_code');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_transactions');
    }
};
