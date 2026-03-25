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
        Schema::table('leaves', function (Blueprint $table) {
            $table->decimal('approved_days_with_pay', 5, 3)->nullable()->change();
            $table->decimal('approved_days_without_pay', 5, 3)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->integer('approved_days_with_pay')->nullable()->change();
            $table->integer('approved_days_without_pay')->nullable()->change();
        });
    }
};
