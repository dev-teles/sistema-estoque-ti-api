<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logs de segurança (logins, falhas de login, acessos negados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs_acesso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('evento', ['login', 'login_falhou', 'logout', 'acesso_negado']);
            $table->string('email', 150)->nullable();
            $table->string('rota', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['evento', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_acesso');
    }
};
