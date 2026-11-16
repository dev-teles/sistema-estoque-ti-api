<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Categoria;
use App\Models\Fornecedor;
use App\Models\Item;
use App\Models\NotaFiscal;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Dados de demonstração do Estoque de TI. Senha de todos os utilizadores: "password".
 * O histórico é gerado pelo próprio LedgerService (simulando os últimos ~45 dias),
 * garantindo que os saldos são consistentes com o livro-razão.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::create(['name' => 'Admin TI', 'email' => 'admin@estoque.local', 'password' => 'password', 'role' => Role::Admin]);
        $gestorTi = User::create(['name' => 'Gestora de TI', 'email' => 'gestor.ti@estoque.local', 'password' => 'password', 'role' => Role::Gestor]);
        $operTi = User::create(['name' => 'Operador de Suporte TI', 'email' => 'operador.ti@estoque.local', 'password' => 'password', 'role' => Role::Operador]);

        $fornTech = Fornecedor::create(['nome' => 'TechDistribuidora Hardware Ltda', 'cnpj' => '12345678000190', 'contato_nome' => 'Carla Mendes', 'email' => 'vendas@techdist.com.br', 'telefone' => '(11) 4002-8922']);
        $fornCabo = Fornecedor::create(['nome' => 'Conecta Cabos e Redes', 'cnpj' => '98765432000110', 'contato_nome' => 'Rui Alves', 'email' => 'comercial@conectacabos.com.br', 'telefone' => '(11) 3555-0199']);
        $fornInfra = Fornecedor::create(['nome' => 'InfraServer Componentes S.A.', 'cnpj' => '11222333000144', 'contato_nome' => 'Marcos Rocha', 'email' => 'corporativo@infraserver.com.br', 'telefone' => '(21) 3333-1000']);

        $catalogoTi = [
            'Periféricos' => [
                ['Mouse USB óptico', 10, 7, 60, 2.0],
                ['Teclado ABNT2 USB', 8, 7, 40, 1.2],
                ['Headset USB com microfone', 5, 15, 12, 0.6],
                ['Webcam Full HD 1080p', 3, 20, 6, 0.2],
            ],
            'Cabos e Conectividade' => [
                ['Cabo de rede Cat6 2m', 30, 10, 150, 4.5],
                ['Cabo HDMI 2.0 1,8m', 10, 10, 25, 0.9],
                ['Conector RJ45 Cat6 (pct 50un)', 10, 5, 40, 0.8],
                ['Switch Gigabit 8 portas', 2, 15, 5, 0.1],
            ],
            'Componentes e Armazenamento' => [
                ['Memória RAM DDR4 8GB 3200MHz', 6, 25, 14, 0.4],
                ['SSD NVMe M.2 512GB', 5, 25, 10, 0.35],
                ['Fonte ATX 500W 80 Plus', 3, 30, 4, 0.1],
                ['Pasta Térmica Silver 5g', 5, 10, 15, 0.4],
            ],
        ];

        $solicitantes = [
            'Ana Souza (Financeiro - Chamado #1042)',
            'Bruno Lima (RH - Chamado #1058)',
            'Carlos Dias (Comercial - Chamado #1071)',
            'Sala de Reuniões 02 (Expansão)',
        ];

        $fornecedores = [$fornTech, $fornCabo, $fornInfra];
        $ledger = app(LedgerService::class);
        mt_srand(crc32('ESTOQUE_TI')); // determinístico

        Carbon::setTestNow(now()->subDays(45)->setTime(9, 0));
        auth()->setUser($gestorTi);

        $itens = [];
        foreach ($catalogoTi as $nomeCategoria => $linhas) {
            $categoria = Categoria::create(['nome' => $nomeCategoria]);
            foreach ($linhas as [$nome, $minimo, $lead, $entrada, $consumo]) {
                $item = Item::create([
                    'categoria_id' => $categoria->id,
                    'nome' => $nome,
                    'quantidade_minima' => $minimo,
                    'lead_time_dias' => $lead,
                    'unidade_medida' => str_contains($nome, '(pct') ? 'pct' : 'un',
                ]);
                $itens[] = ['item' => $item, 'entrada' => $entrada, 'consumo' => $consumo];
            }
        }

        // Entrada inicial via NF.
        $nf = NotaFiscal::create([
            'fornecedor_id' => $fornecedores[0]->id,
            'user_id' => $gestorTi->id,
            'numero' => (string) mt_rand(10000, 99999),
            'serie' => '1',
            'data_emissao' => now()->subDays(2)->toDateString(),
        ]);
        foreach ($itens as $i) {
            $ledger->entrada($i['item'], $i['entrada'], [
                'nota_fiscal_id' => $nf->id,
                'valor_unitario' => mt_rand(25, 450),
            ]);
        }

        // ~45 dias de operação: saídas diárias e reposição.
        for ($dia = 1; $dia <= 44; $dia++) {
            Carbon::setTestNow(now()->addDay()->setTime(mt_rand(8, 17), mt_rand(0, 59)));
            if (now()->isWeekend()) {
                continue;
            }
            auth()->setUser($operTi);

            foreach ($itens as $i) {
                $qtd = (int) round($i['consumo'] * (mt_rand(50, 150) / 100) * 0.75);
                $saldo = $i['item']->estoque()->first()->saldo_disponivel;
                if ($qtd > 0 && $saldo >= $qtd) {
                    $motivo = mt_rand(1, 40) === 1 ? 'dano' : 'consumo';
                    $ledger->saida($i['item'], $qtd, $motivo, [
                        'destino' => $solicitantes[array_rand($solicitantes)],
                        'justificativa' => $motivo === 'dano' ? 'Equipamento avariado em bancada' : null,
                    ]);
                }
            }

            if ($dia === 20) {
                auth()->setUser($gestorTi);
                $nf2 = NotaFiscal::create([
                    'fornecedor_id' => $fornecedores[1]->id,
                    'user_id' => $gestorTi->id,
                    'numero' => (string) mt_rand(10000, 99999),
                    'serie' => '1',
                    'data_emissao' => now()->toDateString(),
                ]);
                foreach (array_slice($itens, 0, 5) as $i) {
                    $ledger->entrada($i['item'], (int) ceil($i['entrada'] / 2), [
                        'nota_fiscal_id' => $nf2->id,
                        'valor_unitario' => mt_rand(25, 450),
                    ]);
                }
            }
        }

        // Reservas pendentes no balcão de TI (hoje).
        Carbon::setTestNow();
        auth()->setUser($operTi);
        foreach (array_slice($itens, 0, 3) as $k => $i) {
            $disp = $i['item']->estoque()->first()->saldo_disponivel;
            if ($disp >= 2) {
                $ledger->reservar(
                    $i['item'],
                    min(2 + $k, $disp),
                    $solicitantes[$k % count($solicitantes)],
                    'Retirada no balcão de suporte TI'
                );
            }
        }

        auth()->forgetUser();
    }
}
