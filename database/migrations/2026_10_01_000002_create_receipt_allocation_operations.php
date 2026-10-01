<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('receipt_allocation_operations', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('native_user_id');
        $t->unsignedBigInteger('payment_id'); $t->unsignedBigInteger('invoice_id');
        $t->string('operation_key',64); $t->char('payload_sha256',64); $t->char('beforeimage_sha256',64);
        $t->longText('result_json')->nullable();
        $t->string('callback_state',32); $t->longText('callback_json');
        $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('updated_at');
        $t->unique(['company_id','native_user_id','operation_key'],'receipt_allocation_scoped_key');
    }); }
    public function down(): void { Schema::dropIfExists('receipt_allocation_operations'); }
};
