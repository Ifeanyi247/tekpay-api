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
        Schema::create('kycs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender');
            $table->date('date_of_birth');
            $table->string('bvn');
            $table->string('nin');
            $table->string('state');
            $table->string('local_government');
            $table->string('address');
            $table->string('house_number');
            $table->string('utility_bill_type');
            $table->string('bill_image');
            $table->boolean('bvn_verified')->default(false);
            $table->boolean('nin_verified')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kycs');
    }
};
