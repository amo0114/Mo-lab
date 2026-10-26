<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('faka_protocol_nonces', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->bigInteger('expires_at_ms')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faka_protocol_nonces');
    }
};
