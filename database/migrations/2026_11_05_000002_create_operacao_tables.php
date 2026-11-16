<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operação do Estoque de TI: notas fiscais, reservas, auditorias e o livro-razão imutável (movimentacoes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_fiscais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('numero', 30);
            $table->string('serie', 10)->default(''); // NOT NULL para que a unique key funcione
            $table->date('data_emissao');
            $table->decimal('valor_total', 12, 2)->nullable();
            $table->string('observacao')->nullable();
            $table->timestamps();

            $table->unique(['fornecedor_id', 'numero', 'serie']);
        });

        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('itens')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // quem registou
            $table->string('solicitante', 150);
            $table->unsignedInteger('quantidade');
            $table->enum('status', ['reservado', 'entregue', 'cancelado'])->default('reservado');
            $table->string('observacao')->nullable();
            $table->foreignId('finalizada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalizada_em')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });

        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->enum('status', ['em_andamento', 'finalizada'])->default('em_andamento');
            $table->foreignId('iniciada_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('finalizada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalizada_em')->nullable();
            $table->string('observacao')->nullable();
            $table->timestamps();

            $table->index(['categoria_id', 'status']);
        });

        Schema::create('auditoria_contagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auditoria_id')->constrained('auditorias')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('itens')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quantidade_contada');
            // Preenchidos apenas na finalização (contagem cega).
            $table->unsignedInteger('saldo_sistema')->nullable();
            $table->integer('diferenca')->nullable();
            $table->timestamps();

            $table->unique(['auditoria_id', 'item_id']);
        });

        // LIVRO-RAZÃO: append-only. Sem updated_at; UPDATE/DELETE bloqueados por trigger (MySQL).
        Schema::create('movimentacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('itens')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('tipo', [
                'entrada',              // chegada de material de TI
                'saida',                // consumo / transferência / dano
                'reserva',              // bloqueia saldo disponível
                'cancelamento_reserva', // devolve saldo disponível
                'estorno',              // correção justificada
                'ajuste_auditoria',     // gerado na reconciliação do inventário cíclico
            ]);
            $table->enum('motivo', ['consumo', 'transferencia', 'dano'])->nullable(); // apenas para saídas
            $table->unsignedInteger('quantidade');        // valor absoluto
            $table->integer('delta_fisico');              // efeito sinalizado no saldo físico
            $table->integer('delta_reservado');           // efeito sinalizado no saldo reservado
            $table->unsignedInteger('saldo_fisico_apos');
            $table->unsignedInteger('saldo_reservado_apos');
            $table->decimal('valor_unitario', 12, 2)->nullable();
            $table->foreignId('nota_fiscal_id')->nullable()->constrained('notas_fiscais')->restrictOnDelete();
            $table->foreignId('reserva_id')->nullable()->constrained('reservas')->restrictOnDelete();
            $table->foreignId('auditoria_id')->nullable()->constrained('auditorias')->restrictOnDelete();
            $table->foreignId('movimentacao_estornada_id')->nullable()->unique()->constrained('movimentacoes')->restrictOnDelete();
            $table->string('destino', 150)->nullable();
            $table->string('justificativa', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at']);
            $table->index(['item_id', 'created_at']);
            $table->index(['tipo']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("
                CREATE TRIGGER movimentacoes_bloqueia_update BEFORE UPDATE ON movimentacoes
                FOR EACH ROW SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Livro-razao imutavel: UPDATE nao permitido em movimentacoes';
            ");
            DB::unprepared("
                CREATE TRIGGER movimentacoes_bloqueia_delete BEFORE DELETE ON movimentacoes
                FOR EACH ROW SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Livro-razao imutavel: DELETE nao permitido em movimentacoes';
            ");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS movimentacoes_bloqueia_update');
            DB::unprepared('DROP TRIGGER IF EXISTS movimentacoes_bloqueia_delete');
        }
        Schema::dropIfExists('movimentacoes');
        Schema::dropIfExists('auditoria_contagens');
        Schema::dropIfExists('auditorias');
        Schema::dropIfExists('reservas');
        Schema::dropIfExists('notas_fiscais');
    }
};
