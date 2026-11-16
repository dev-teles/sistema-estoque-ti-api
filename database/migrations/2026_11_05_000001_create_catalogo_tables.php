<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de TI: categorias, fornecedores, itens (o "dicionário") e o saldo físico por item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100)->unique();
            $table->string('descricao')->nullable();
            $table->timestamps();
        });

        Schema::create('fornecedores', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 150);
            $table->string('cnpj', 14)->unique(); // apenas dígitos
            $table->string('contato_nome', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->string('nome', 150);
            $table->string('descricao')->nullable();
            $table->string('unidade_medida', 20)->default('un');
            $table->unsignedInteger('quantidade_minima')->default(0);
            $table->unsignedSmallInteger('lead_time_dias')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['nome']);
        });

        // Uma linha por item. Saldo disponível = saldo_fisico - saldo_reservado.
        // Atualizado exclusivamente pelo LedgerService, dentro de transações com lock pessimista.
        Schema::create('estoques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->unique()->constrained('itens')->restrictOnDelete();
            $table->unsignedInteger('saldo_fisico')->default(0);
            $table->unsignedInteger('saldo_reservado')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoques');
        Schema::dropIfExists('itens');
        Schema::dropIfExists('fornecedores');
        Schema::dropIfExists('categorias');
    }
};
