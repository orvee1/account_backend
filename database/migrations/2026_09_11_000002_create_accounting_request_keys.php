<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('accounting_request_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('request_key', 128);
            $table->string('fingerprint', 64);
            $table->unsignedSmallInteger('status')->nullable();
            $table->longText('response')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id','request_key']);
        });
    }
    public function down(): void { Schema::dropIfExists('accounting_request_keys'); }
};
