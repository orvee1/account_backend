<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_bill_items', function (Blueprint $table) {
            $table->unsignedBigInteger('qty_unit_id')->nullable()->change();
            $table->decimal('qty', 16, 6)->nullable()->change();
            $table->decimal('qty_base', 16, 6)->nullable()->change();
            $table->unsignedBigInteger('rate_unit_id')->nullable()->change();
            $table->decimal('rate_per_unit', 16, 6)->nullable()->change();
            $table->decimal('rate_per_base', 16, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bill_items', function (Blueprint $table) {
            $table->unsignedBigInteger('qty_unit_id')->nullable(false)->change();
            $table->decimal('qty', 16, 6)->nullable(false)->change();
            $table->decimal('qty_base', 16, 6)->nullable(false)->change();
            $table->unsignedBigInteger('rate_unit_id')->nullable(false)->change();
            $table->decimal('rate_per_unit', 16, 6)->nullable(false)->change();
            $table->decimal('rate_per_base', 16, 6)->nullable(false)->change();
        });
    }
};
