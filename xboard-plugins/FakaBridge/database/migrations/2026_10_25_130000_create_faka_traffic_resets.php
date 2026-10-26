<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('faka_traffic_reset_operations', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->string('client_id', 64); $t->uuid('operation_id'); $t->string('payload_hash', 64);
            $t->string('actor_ref', 19); $t->string('user_id', 19); $t->string('benefit_code', 64);
            $t->string('status', 16); $t->string('error_code', 64)->nullable();
            $t->text('before_json')->nullable(); $t->text('after_json')->nullable(); $t->timestamp('completed_at')->nullable();
            $t->timestamps(); $t->primary(['client_id', 'operation_id']);
        });
        Schema::create('faka_traffic_reset_consumptions', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->string('client_id', 64); $t->string('benefit_code', 64); $t->string('actor_ref', 19);
            $t->string('user_id', 19); $t->uuid('operation_id'); $t->timestamps();
            $t->primary(['client_id', 'benefit_code', 'actor_ref']);
            $t->unique(['client_id', 'benefit_code', 'user_id'], 'faka_reset_user_benefit_unique');
        });
    }
    public function down(): void {
        Schema::dropIfExists('faka_traffic_reset_consumptions'); Schema::dropIfExists('faka_traffic_reset_operations');
    }
};
