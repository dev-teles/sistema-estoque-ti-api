<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->text('contato_nome')->nullable()->change();
            $table->text('email')->nullable()->change();
            $table->text('telefone')->nullable()->change();
        });

        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->text('observacao')->nullable()->change();
        });

        Schema::table('reservas', function (Blueprint $table) {
            $table->text('observacao')->nullable()->change();
        });

        Schema::table('movimentacoes', function (Blueprint $table) {
            $table->text('destino')->nullable()->change();
            $table->text('justificativa')->nullable()->change();
        });

        Schema::table('auditorias', function (Blueprint $table) {
            $table->text('observacao')->nullable()->change();
        });

        Schema::table('logs_acesso', function (Blueprint $table) {
            $table->text('ip')->nullable()->change();
            $table->text('user_agent')->nullable()->change();
        });

        $isMysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        // Suspende triggers de imutabilidade apenas durante a migração única de criptografia dos registos legados
        if ($isMysql) {
            DB::unprepared('DROP TRIGGER IF EXISTS movimentacoes_bloqueia_update');
            DB::unprepared('DROP TRIGGER IF EXISTS rls_logs_acesso_no_update');
        }

        try {
            $this->encryptTableColumns('fornecedores', ['contato_nome', 'email', 'telefone']);
            $this->encryptTableColumns('notas_fiscais', ['observacao']);
            $this->encryptTableColumns('reservas', ['observacao']);
            $this->encryptTableColumns('movimentacoes', ['destino', 'justificativa']);
            $this->encryptTableColumns('auditorias', ['observacao']);
            $this->encryptTableColumns('logs_acesso', ['ip', 'user_agent']);
        } finally {
            if ($isMysql) {
                DB::unprepared("
                    CREATE TRIGGER movimentacoes_bloqueia_update BEFORE UPDATE ON movimentacoes
                    FOR EACH ROW SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Livro-razao imutavel: UPDATE nao permitido em movimentacoes';
                ");
                DB::unprepared("
                    CREATE TRIGGER rls_logs_acesso_no_update BEFORE UPDATE ON logs_acesso
                    FOR EACH ROW SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'RLS: logs_acesso e imutavel (UPDATE bloqueado)';
                ");
            }
        }
    }

    public function down(): void
    {
        // Dados permanecem em formato TEXT para não truncar payloads cifrados.
    }

    private function encryptTableColumns(string $table, array $columns): void
    {
        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns) {
            foreach ($rows as $row) {
                $updates = [];
                foreach ($columns as $col) {
                    $val = $row->{$col};
                    if ($val === null || $val === '') {
                        continue;
                    }
                    try {
                        Crypt::decryptString((string) $val);
                    } catch (DecryptException) {
                        $updates[$col] = Crypt::encryptString((string) $val);
                    }
                }
                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }
        });
    }
};
