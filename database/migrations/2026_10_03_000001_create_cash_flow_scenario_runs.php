<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {Schema::create('cash_flow_scenario_runs',function(Blueprint $table){$table->bigIncrements('id');$table->unsignedInteger('company_id');$table->unsignedInteger('native_user_id');$table->string('operation_key',64);$table->char('input_sha256',64);$table->longText('input_json');$table->longText('report_json');$table->unsignedInteger('created_at');$table->unique(['company_id','native_user_id','operation_key'],'cash_flow_run_operation');$table->index(['company_id','native_user_id','id'],'cash_flow_run_owner');});}
    public function down(): void {Schema::dropIfExists('cash_flow_scenario_runs');}
};
