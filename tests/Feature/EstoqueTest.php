<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Item;
use App\Models\Movimentacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EstoqueTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create(['nome' => 'Periféricos']);
        $this->item = Item::create([
            'categoria_id' => $this->categoria->id,
            'nome' => 'Mouse USB',
            'quantidade_minima' => 5,
            'lead_time_dias' => 7,
        ]);
    }

    private function operador(): User
    {
        return User::factory()->operador()->create();
    }

    private function gestor(): User
    {
        return User::factory()->gestor()->create();
    }

    public function test_login_devolve_token_e_me_retorna_usuario(): void
    {
        $user = $this->operador();

        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonStructure(['token', 'token_type', 'expires_in', 'user' => ['id', 'role', 'role_label']])->json('token');

        $this->assertSame(2, substr_count($token, '.'));

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.role', 'operador');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_login_invalido_e_registado(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'x@x.com', 'password' => 'errada'])->assertStatus(422);
        $this->assertDatabaseHas('logs_acesso', ['evento' => 'login_falhou', 'email' => 'x@x.com']);
    }

    public function test_entrada_e_saida_atualizam_saldo_e_livro_razao(): void
    {
        $this->actingAs($this->operador(), 'jwt');

        $this->postJson('/api/estoque/entrada', ['item_id' => $this->item->id, 'quantidade' => 10])
            ->assertCreated()->assertJsonPath('data.saldo_fisico_apos', 10);

        $this->postJson('/api/estoque/saida', ['item_id' => $this->item->id, 'quantidade' => 3, 'motivo' => 'consumo'])
            ->assertCreated()->assertJsonPath('data.delta_fisico', -3)->assertJsonPath('data.saldo_fisico_apos', 7);

        $this->getJson('/api/estoque')->assertOk()
            ->assertJsonPath('data.0.saldo_fisico', 7)
            ->assertJsonPath('data.0.status_reposicao', 'regular')
            ->assertJsonPath('meta.total', 1);

        $this->assertSame(2, Movimentacao::count());
    }

    public function test_saida_maior_que_saldo_e_rejeitada(): void
    {
        $this->actingAs($this->operador(), 'jwt');
        $this->postJson('/api/estoque/entrada', ['item_id' => $this->item->id, 'quantidade' => 2]);

        $this->postJson('/api/estoque/saida', ['item_id' => $this->item->id, 'quantidade' => 3, 'motivo' => 'consumo'])
            ->assertStatus(422);

        $this->assertSame(2, $this->item->estoque()->first()->saldo_fisico);
    }

    public function test_fluxo_de_reserva_confirmar_e_cancelar(): void
    {
        $this->actingAs($this->operador(), 'jwt');
        $this->postJson('/api/estoque/entrada', ['item_id' => $this->item->id, 'quantidade' => 10]);

        $r1 = $this->postJson('/api/reservas', ['item_id' => $this->item->id, 'quantidade' => 4, 'solicitante' => 'Ana'])
            ->assertCreated()->json('data.id');
        $r2 = $this->postJson('/api/reservas', ['item_id' => $this->item->id, 'quantidade' => 6, 'solicitante' => 'Bruno'])
            ->assertCreated()->json('data.id');

        // Disponível = 0: não é possível reservar mais nem dar saída.
        $this->postJson('/api/reservas', ['item_id' => $this->item->id, 'quantidade' => 1, 'solicitante' => 'Caio'])->assertStatus(422);
        $this->postJson('/api/estoque/saida', ['item_id' => $this->item->id, 'quantidade' => 1, 'motivo' => 'consumo'])->assertStatus(422);

        $this->postJson("/api/reservas/{$r1}/confirmar")->assertOk()->assertJsonPath('data.status', 'entregue');
        $this->postJson("/api/reservas/{$r2}/cancelar")->assertOk()->assertJsonPath('data.status', 'cancelado');
        $this->postJson("/api/reservas/{$r2}/confirmar")->assertStatus(422);

        $estoque = $this->item->estoque()->first();
        $this->assertSame(6, $estoque->saldo_fisico);
        $this->assertSame(0, $estoque->saldo_reservado);
    }

    public function test_estorno_reverte_uma_unica_vez_e_exige_gestor(): void
    {
        $operador = $this->operador();
        $gestor = $this->gestor();

        $this->actingAs($operador, 'jwt');
        $mov = $this->postJson('/api/estoque/entrada', ['item_id' => $this->item->id, 'quantidade' => 10])->json('data.id');

        $payload = ['movimentacao_id' => $mov, 'justificativa' => 'Lançamento em duplicado na NF'];
        $this->postJson('/api/estoque/estorno', $payload)->assertForbidden();

        $this->actingAs($gestor, 'jwt');
        $this->postJson('/api/estoque/estorno', $payload)->assertCreated()->assertJsonPath('data.delta_fisico', -10);
        $this->postJson('/api/estoque/estorno', $payload)->assertStatus(422);

        $this->assertSame(0, $this->item->estoque()->first()->saldo_fisico);
        $this->assertDatabaseHas('logs_acesso', ['evento' => 'acesso_negado']);
    }

    public function test_rbac_operador_nao_cria_catalogo(): void
    {
        $operador = $this->operador();
        $gestor = $this->gestor();

        $this->actingAs($operador, 'jwt');
        $this->postJson('/api/catalogo', ['nome' => 'X', 'categoria_id' => $this->categoria->id, 'quantidade_minima' => 1])
            ->assertForbidden();

        $this->actingAs($gestor, 'jwt');
        $this->postJson('/api/catalogo', ['nome' => 'Teclado ABNT2', 'categoria_id' => $this->categoria->id, 'quantidade_minima' => 1, 'lead_time' => 3])
            ->assertCreated()->assertJsonPath('data.lead_time_dias', 3)->assertJsonPath('data.saldo_fisico', 0);
    }

    public function test_auditoria_bloqueia_saidas_e_gera_ajustes(): void
    {
        $gestor = $this->gestor();
        $operador = $this->operador();
        $this->actingAs($gestor, 'jwt');
        $this->postJson('/api/estoque/entrada', ['item_id' => $this->item->id, 'quantidade' => 10]);

        $auditoria = $this->postJson('/api/auditorias/iniciar', ['categoria_id' => $this->categoria->id])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/estoque/saida', ['item_id' => $this->item->id, 'quantidade' => 1, 'motivo' => 'consumo'])
            ->assertStatus(423);

        // Contagem cega: o detalhe não expõe o saldo do sistema.
        $this->actingAs($operador, 'jwt');
        $this->getJson("/api/auditorias/{$auditoria}")->assertOk()->assertJsonMissingPath('data.itens.0.saldo_sistema');
        $this->postJson("/api/auditorias/{$auditoria}/contagem", [
            'contagens' => [['item_id' => $this->item->id, 'quantidade_contada' => 8]],
        ])->assertOk();

        $this->actingAs($gestor, 'jwt');
        $this->getJson("/api/auditorias/{$auditoria}/reconciliacao")->assertJsonPath('data.itens.0.diferenca', -2);
        $this->postJson("/api/auditorias/{$auditoria}/finalizar")->assertOk()->assertJsonPath('ajustes.0.diferenca', -2);

        $this->assertSame(8, $this->item->estoque()->first()->saldo_fisico);
        $this->postJson('/api/estoque/saida', ['item_id' => $this->item->id, 'quantidade' => 1, 'motivo' => 'consumo'])
            ->assertCreated();
    }

    public function test_movimentacoes_sao_imutaveis(): void
    {
        $this->actingAs($this->operador(), 'jwt');
        $id = $this->postJson('/api/estoque/entrada', ['item_id' => $this->item->id, 'quantidade' => 5])->json('data.id');
        $mov = Movimentacao::findOrFail($id);

        $this->expectException(LogicException::class);
        $mov->update(['quantidade' => 999]);
    }

    public function test_relatorios_mensal_e_criticos(): void
    {
        $this->actingAs($this->gestor(), 'jwt');
        $this->postJson('/api/estoque/entrada', ['item_id' => $this->item->id, 'quantidade' => 10]);
        $this->postJson('/api/estoque/saida', ['item_id' => $this->item->id, 'quantidade' => 6, 'motivo' => 'consumo']);
        $this->postJson('/api/estoque/saida', ['item_id' => $this->item->id, 'quantidade' => 1, 'motivo' => 'dano', 'justificativa' => 'Caiu']);

        $this->getJson('/api/relatorios/mensal')->assertOk()
            ->assertJsonPath('data.totais.entradas', 10)
            ->assertJsonPath('data.totais.consumo', 6)
            ->assertJsonPath('data.totais.baixas_dano', 1)
            ->assertJsonPath('data.totais.saldo_final', 3);

        // Disponível 3 <= mínimo 5 (+ consumo × lead time) → crítico para reposição.
        $this->getJson('/api/relatorios/criticos')->assertOk()->assertJsonPath('data.0.item_id', $this->item->id);

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.kpis.itens_alerta', 1);
    }

    public function test_rls_isola_linhas_e_bloqueia_escrita_indevida(): void
    {
        $operador = $this->operador();
        $gestor = $this->gestor();

        \App\Models\LogAcesso::registrar('login', $operador);
        \App\Models\LogAcesso::registrar('login', $gestor);

        $this->actingAs($operador, 'jwt');

        // Global Scope RLS: operador só enxerga os próprios logs de acesso
        $this->assertSame(1, \App\Models\LogAcesso::count());

        // Write Guard RLS: operador não pode alterar outro usuário nem criar item diretamente no Model
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $gestor->update(['name' => 'Hack']);
    }
}
