<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('faka_orders', function (Blueprint $t) {
            // Existing orders remain v1. Never change the protocol of a retry.
            $t->unsignedTinyInteger('protocol_version')->default(1);
            $t->string('client_id', 64)->nullable();
            $t->string('xboard_user_id', 19)->nullable();
            $t->string('semantic_hash', 64)->nullable();
            $t->longText('result_payload')->nullable();
        });
    }
    public function down(): void {
        Schema::table('faka_orders', function (Blueprint $t) {
            $t->dropColumn(['protocol_version','client_id','xboard_user_id','semantic_hash','result_payload']);
        });
    }
};
