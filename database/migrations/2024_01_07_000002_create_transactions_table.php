<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id');
            $table->string('request_id')->unique();
            $table->string('transaction_id')->nullable();
            $table->string('reference')->unique();
            $table->decimal('amount', 10, 2);
            $table->decimal('commission', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->string('type');
            $table->string('status');
            $table->string('service_id');
            $table->string('phone');
            $table->string('product_name');
            $table->string('platform')->nullable();
            $table->string('channel')->nullable();
            $table->string('method')->nullable();
            $table->string('response_code');
            $table->string('response_message');
            $table->timestamp('transaction_date');
            $table->timestamps();

            $table->index(['user_id', 'type', 'status']);
            $table->index(['request_id', 'transaction_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
