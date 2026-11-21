<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $triggers = [
        'rls_logs_acesso_no_update',
        'rls_logs_acesso_no_delete',
        'rls_users_before_insert',
        'rls_users_before_update',
        'rls_users_before_delete',
        'rls_itens_before_insert',
        'rls_itens_before_update',
        'rls_itens_before_delete',
        'rls_categorias_before_insert',
        'rls_categorias_before_update',
        'rls_fornecedores_before_insert',
        'rls_fornecedores_before_update',
        'rls_notas_fiscais_before_insert',
        'rls_notas_fiscais_before_update',
        'rls_movimentacoes_before_insert',
        'rls_reservas_before_insert',
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->down();

        // 1. Logs de acesso imutáveis (append-only)
        DB::unprepared("
            CREATE TRIGGER rls_logs_acesso_no_update BEFORE UPDATE ON logs_acesso
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'RLS: logs_acesso e imutavel (UPDATE bloqueado)';
        ");
        DB::unprepared("
            CREATE TRIGGER rls_logs_acesso_no_delete BEFORE DELETE ON logs_acesso
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'RLS: logs_acesso e imutavel (DELETE bloqueado)';
        ");

        // 2. Users: apenas admin cria/remove ou edita outras contas; não-admin só atualiza a própria linha (ex: ultimo_login_em) sem alterar role
        DB::unprepared("
            CREATE TRIGGER rls_users_before_insert BEFORE INSERT ON users
            FOR EACH ROW
            BEGIN
                IF @rls_user_role IS NOT NULL AND @rls_user_role <> 'admin' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RLS: apenas admin pode criar utilizadores';
                END IF;
            END
        ");
        DB::unprepared("
            CREATE TRIGGER rls_users_before_update BEFORE UPDATE ON users
            FOR EACH ROW
            BEGIN
                IF @rls_user_role IS NOT NULL AND @rls_user_role <> 'admin' THEN
                    IF OLD.id <> @rls_user_id OR NEW.role <> OLD.role OR NEW.ativo <> OLD.ativo THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RLS: proibido alterar outro utilizador ou elevar privilegios';
                    END IF;
                END IF;
            END
        ");
        DB::unprepared("
            CREATE TRIGGER rls_users_before_delete BEFORE DELETE ON users
            FOR EACH ROW
            BEGIN
                IF @rls_user_role IS NOT NULL AND @rls_user_role <> 'admin' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RLS: apenas admin pode remover utilizadores';
                END IF;
            END
        ");

        // 3. Catálogo, Categorias, Fornecedores e Notas Fiscais: escrita restrita a admin/gestor
        foreach (['itens', 'categorias', 'fornecedores', 'notas_fiscais'] as $tabela) {
            DB::unprepared("
                CREATE TRIGGER rls_{$tabela}_before_insert BEFORE INSERT ON {$tabela}
                FOR EACH ROW
                BEGIN
                    IF @rls_user_role = 'operador' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RLS: operador sem permissao de INSERT em {$tabela}';
                    END IF;
                END
            ");
            DB::unprepared("
                CREATE TRIGGER rls_{$tabela}_before_update BEFORE UPDATE ON {$tabela}
                FOR EACH ROW
                BEGIN
                    IF @rls_user_role = 'operador' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RLS: operador sem permissao de UPDATE em {$tabela}';
                    END IF;
                END
            ");
        }

        DB::unprepared("
            CREATE TRIGGER rls_itens_before_delete BEFORE DELETE ON itens
            FOR EACH ROW
            BEGIN
                IF @rls_user_role = 'operador' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RLS: operador sem permissao de DELETE em itens';
                END IF;
            END
        ");

        // 4. Movimentações e Reservas: user_id da linha deve corresponder ao @rls_user_id da sessão
        DB::unprepared("
            CREATE TRIGGER rls_movimentacoes_before_insert BEFORE INSERT ON movimentacoes
            FOR EACH ROW
            BEGIN
                IF @rls_user_id IS NOT NULL AND NEW.user_id <> @rls_user_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RLS: user_id da movimentacao diverge do utilizador autenticado';
                END IF;
                IF @rls_user_role = 'operador' AND NEW.tipo = 'estorno' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RLS: operador nao pode inserir linha de estorno';
                END IF;
            END
        ");
        DB::unprepared("
            CREATE TRIGGER rls_reservas_before_insert BEFORE INSERT ON reservas
            FOR EACH ROW
            BEGIN
                IF @rls_user_id IS NOT NULL AND NEW.user_id <> @rls_user_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RLS: user_id da reserva diverge do utilizador autenticado';
                END IF;
            END
        ");
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->triggers as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
