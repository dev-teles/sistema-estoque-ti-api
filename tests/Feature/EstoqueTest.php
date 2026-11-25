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

        $resp = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertCookie('estoque_ti_jwt')
            ->assertJsonStructure(['token', 'token_type', 'expires_in', 'user' => ['id', 'role', 'role_label']]);

        $token = $resp->json('token');
        $this->assertSame(2, substr_count($token, '.'));

        // Autenticação via cookie HttpOnly server-side
        $this->withHeader('Cookie', "estoque_ti_jwt={$token}")->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.role', 'operador');

        $this->withHeader('Cookie', "estoque_ti_jwt={$token}")->postJson('/api/auth/logout')
            ->assertOk()->assertCookieExpired('estoque_ti_jwt');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Cookie', "estoque_ti_jwt={$token}")->getJson('/api/auth/me')->assertUnauthorized();
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
        $this->postJson('/api/catalogo', ['nome' => 'Teclado ABNT2', 'numero_serie' => '2136', 'categoria_id' => $this->categoria->id, 'quantidade_minima' => 1, 'lead_time' => 3])
            ->assertCreated()
            ->assertJsonPath('data.numero_serie', '2136')
            ->assertJsonPath('data.lead_time_dias', 3)
            ->assertJsonPath('data.saldo_fisico', 0);

        $this->postJson('/api/catalogo', ['nome' => 'Inválido', 'numero_serie' => '12345', 'categoria_id' => $this->categoria->id, 'quantidade_minima' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['numero_serie']);

        $this->getJson('/api/estoque?search=2136')->assertOk()->assertJsonPath('data.0.item.numero_serie', '2136');
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
        $mov->quantidade = 999;
        $mov->save();
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

    public function test_dados_sensiveis_e_payload_jwt_sao_criptografados_em_repouso(): void
    {
        $gestor = $this->gestor();
        $this->actingAs($gestor, 'jwt');

        $fornecedor = \App\Models\Fornecedor::create([
            'nome' => 'Fornecedor Seguro Ltda',
            'cnpj' => '11222333000188',
            'contato_nome' => 'João Silva',
            'email' => 'segredo@fornecedor.com.br',
            'telefone' => '(11) 99999-8888',
        ]);

        $this->postJson('/api/estoque/entrada', ['item_id' => $this->item->id, 'quantidade' => 5]);
        $this->postJson('/api/estoque/saida', [
            'item_id' => $this->item->id,
            'quantidade' => 1,
            'motivo' => 'dano',
            'destino' => 'Bancada Secreta TI',
            'justificativa' => 'Placa queimada por surto elétrico',
        ])->assertCreated();

        $rawFornecedor = \Illuminate\Support\Facades\DB::table('fornecedores')->where('id', $fornecedor->id)->first();
        $this->assertNotSame('segredo@fornecedor.com.br', $rawFornecedor->email);
        $this->assertStringNotContainsString('segredo@fornecedor.com.br', (string) $rawFornecedor->email);
        $this->assertNotSame('(11) 99999-8888', $rawFornecedor->telefone);
        $this->assertSame('segredo@fornecedor.com.br', $fornecedor->fresh()->email);
        $this->assertSame('(11) 99999-8888', $fornecedor->fresh()->telefone);

        $rawMov = \Illuminate\Support\Facades\DB::table('movimentacoes')->where('tipo', 'saida')->first();
        $this->assertStringNotContainsString('Bancada Secreta TI', (string) $rawMov->destino);
        $this->assertStringNotContainsString('Placa queimada', (string) $rawMov->justificativa);
        $movModel = Movimentacao::findOrFail($rawMov->id);
        $this->assertSame('Bancada Secreta TI', $movModel->destino);
        $this->assertSame('Placa queimada por surto elétrico', $movModel->justificativa);

        // Verifica que o payload do JWT não expõe sub, role ou jti em texto aberto
        $token = $this->postJson('/api/auth/login', ['email' => $gestor->email, 'password' => 'password'])
            ->assertOk()->json('token');
        [, $payloadB64] = explode('.', $token);
        $payload = json_decode((string) base64_decode(strtr($payloadB64, '-_', '+/')), true);
        $this->assertArrayHasKey('enc', $payload);
        $this->assertArrayNotHasKey('sub', $payload);
        $this->assertArrayNotHasKey('role', $payload);
        $this->assertArrayNotHasKey('jti', $payload);
    }

    public function test_mass_assignment_e_bloqueado_para_campos_protegidos(): void
    {
        $operador = $this->operador();
        $this->actingAs($operador, 'jwt');

        // 1. User: role e ativo estão fora de $fillable
        try {
            $operador->update(['role' => 'admin', 'ativo' => false]);
            $this->fail('Esperava MassAssignmentException ao tentar alterar role/ativo em User.');
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException) {
            $this->addToAssertionCount(1);
        }

        // 2. Estoque: bloqueado contra mass assignment ($guarded = ['*'])
        $estoque = $this->item->estoque()->firstOrFail();
        try {
            $estoque->update(['saldo_fisico' => 999]);
            $this->fail('Esperava MassAssignmentException ao tentar alterar saldo_fisico em Estoque.');
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException) {
            $this->addToAssertionCount(1);
        }

        // 3. Movimentacao: bloqueada contra mass assignment ($guarded = ['*'])
        try {
            Movimentacao::create([
                'item_id' => $this->item->id,
                'user_id' => $operador->id,
                'tipo' => Movimentacao::TIPO_ENTRADA,
                'quantidade' => 10,
                'delta_fisico' => 10,
                'delta_reservado' => 0,
                'saldo_fisico_apos' => 10,
                'saldo_reservado_apos' => 0,
            ]);
            $this->fail('Esperava MassAssignmentException ao tentar criar Movimentacao via mass assignment.');
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException) {
            $this->addToAssertionCount(1);
        }

        // 4. API: enviar campos de sistema extras em POST /api/reservas não sobrescreve status nem user_id
        $this->postJson('/api/estoque/entrada', ['item_id' => $this->item->id, 'quantidade' => 5])->assertCreated();
        $reservaId = $this->postJson('/api/reservas', [
            'item_id' => $this->item->id,
            'quantidade' => 1,
            'solicitante' => 'Ana',
            'status' => 'entregue',
            'user_id' => 999,
        ])->assertCreated()->json('data.id');

        $reserva = \App\Models\Reserva::findOrFail($reservaId);
        $this->assertSame(\App\Models\Reserva::STATUS_RESERVADO, $reserva->status);
        $this->assertSame($operador->id, $reserva->user_id);
    }

    public function test_cookie_protegido_e_senha_com_hash_bcrypt(): void
    {
        $admin = User::factory()->admin()->create();

        $resp = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk();

        $cookie = collect($resp->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'estoque_ti_jwt');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertNotSame($resp->json('token'), $cookie->getValue());

        // Autentica com o valor cifrado do cookie e cria um novo usuário
        $novoId = $this->withHeader('Cookie', 'estoque_ti_jwt='.urlencode((string) $cookie->getValue()))
            ->postJson('/api/usuarios', [
                'name' => 'Novo Técnico TI',
                'email' => 'tecnico.novo@estoque.local',
                'password' => 'SenhaForte123',
                'role' => 'operador',
            ])->assertCreated()->json('data.id');

        $rawHash = (string) \Illuminate\Support\Facades\DB::table('users')->where('id', $novoId)->value('password');
        $this->assertNotSame('SenhaForte123', $rawHash);
        $this->assertStringStartsWith('$2y$', $rawHash);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('SenhaForte123', $rawHash));
    }

    public function test_rate_limit_e_bot_protection_bloqueiam_abuso(): void
    {
        // 1. BotProtection: Honeypot preenchido é bloqueado com 422
        $this->postJson('/api/auth/login', [
            'email' => 'admin@estoque.local',
            'password' => 'password',
            'website' => 'http://spam-bot.invalid',
        ])->assertStatus(422);

        // 2. BotProtection: User-Agent de scanner malicioso é bloqueado com 403
        $this->withHeader('User-Agent', 'sqlmap/1.7')->getJson('/api/auth/me')
            ->assertForbidden();
        $this->flushHeaders();

        // 3. Rate Limit: após 5 tentativas de login no mesmo minuto para o mesmo e-mail, a 6ª retorna 429
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'alvo.bruteforce@estoque.local',
                'password' => 'errada',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'alvo.bruteforce@estoque.local',
            'password' => 'errada',
        ])->assertStatus(429);
    }

    public function test_queries_parametrizadas_e_sanitizacao_xss_bloqueiam_injecao(): void
    {
        $gestor = $this->gestor();
        $this->actingAs($gestor, 'jwt');

        // 1. Tentativa de XSS no cadastro de item: tags <script>, event handlers e javascript: são removidos
        $resp = $this->postJson('/api/catalogo', [
            'nome' => 'Monitor 24 <script>alert("xss")</script> onload=alert(1)',
            'descricao' => '<img src=x onerror=alert(1)>javascript:evil() Full HD',
            'categoria_id' => $this->categoria->id,
            'quantidade_minima' => 2,
        ])->assertCreated();

        $resp->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');

        $nomeSalvo = (string) $resp->json('data.nome');
        $descSalva = (string) $resp->json('data.descricao');
        $this->assertStringNotContainsString('<script>', $nomeSalvo);
        $this->assertStringNotContainsString('onload=', $nomeSalvo);
        $this->assertStringNotContainsString('<img', $descSalva);
        $this->assertStringNotContainsString('onerror=', $descSalva);
        $this->assertStringNotContainsString('javascript:', $descSalva);
        $this->assertStringContainsString('Monitor 24', $nomeSalvo);

        // 2. Tentativa de SQL Injection e Wildcard Injection (%) em busca parametrizada LIKE
        $this->getJson('/api/catalogo?search='.urlencode("' OR 1=1 --"))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Buscar literal "%" não deve retornar todos os itens (curinga escapado via addcslashes)
        $this->getJson('/api/catalogo?search='.urlencode('%'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_validacao_de_input_e_bloqueio_de_uploads_indevidos(): void
    {
        $gestor = $this->gestor();
        $this->actingAs($gestor, 'jwt');

        // 1. Tentativa de upload de arquivo executável/script em rota da API é bloqueada com 422
        $arquivoMalicioso = \Illuminate\Http\UploadedFile::fake()->create('shell.php', 10, 'application/x-php');
        $this->post('/api/catalogo', [
            'nome' => 'Item com anexo indevido',
            'categoria_id' => $this->categoria->id,
            'quantidade_minima' => 1,
            'arquivo' => $arquivoMalicioso,
        ], ['Accept' => 'application/json'])->assertStatus(422)
            ->assertJsonPath('message', 'Upload de ficheiros não é permitido nesta rota.');

        // 2. Validação de input: CNPJ com dígitos repetidos e telefone com caracteres inválidos são rejeitados
        $this->postJson('/api/fornecedores', [
            'nome' => 'Fornecedor Inválido',
            'cnpj' => '11.111.111/1111-11',
            'telefone' => 'abc-invalido',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['cnpj', 'telefone']);
    }

    public function test_trim_nas_respostas_security_headers_e_force_https(): void
    {
        $gestor = $this->gestor();
        $this->actingAs($gestor, 'jwt');

        // 1. Trim nas respostas de API: remove links/path internos da paginação e metadados como updated_at/deleted_at
        $resp = $this->getJson('/api/catalogo')->assertOk();
        $resp->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta.links')
            ->assertJsonMissingPath('meta.path')
            ->assertJsonMissingPath('data.0.updated_at')
            ->assertJsonMissingPath('data.0.deleted_at');

        // 2. Security Headers completos na resposta
        $resp->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-XSS-Protection', '0')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy');

        // 3. Force HTTPS: quando FORCE_HTTPS=true, requisições HTTP sem TLS são bloqueadas com 403
        putenv('FORCE_HTTPS=true');
        $_ENV['FORCE_HTTPS'] = 'true';
        try {
            $this->getJson('http://localhost/api/catalogo')->assertForbidden();
            $this->withHeader('X-Forwarded-Proto', 'https')->getJson('http://localhost/api/catalogo')->assertOk();
        } finally {
            putenv('FORCE_HTTPS=false');
            $_ENV['FORCE_HTTPS'] = 'false';
        }
    }
}
