<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Movimentacao;
use App\Models\NotaFiscal;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelatorioController extends Controller
{
    /**
     * Fechamento mensal: ?mes=YYYY-MM
     * Por item: saldo inicial, entradas (NF), consumo, baixas por dano, ajustes/estornos e saldo final.
     */
    public function mensal(Request $request): JsonResponse
    {
        $request->validate(['mes' => ['nullable', 'date_format:Y-m']]);

        $inicio = CarbonImmutable::createFromFormat('Y-m-d', ($request->query('mes') ?? now()->format('Y-m')).'-01')->startOfDay();
        $fim = $inicio->endOfMonth();

        // Saldo inicial = saldo após o último lançamento anterior ao mês (ids são cronológicos: append-only).
        $saldosIniciais = Movimentacao::query()
            ->whereIn('id', Movimentacao::query()->selectRaw('MAX(id)')->where('created_at', '<', $inicio)->groupBy('item_id'))
            ->pluck('saldo_fisico_apos', 'item_id');

        $agregados = Movimentacao::query()
            ->whereBetween('created_at', [$inicio, $fim])
            ->groupBy('item_id')
            ->selectRaw(
                'item_id,
                SUM(CASE WHEN tipo = ? THEN delta_fisico ELSE 0 END) AS entradas,
                SUM(CASE WHEN tipo = ? AND (motivo IS NULL OR motivo <> ?) THEN -delta_fisico ELSE 0 END) AS consumo,
                SUM(CASE WHEN tipo = ? AND motivo = ? THEN -delta_fisico ELSE 0 END) AS baixas_dano,
                SUM(CASE WHEN tipo IN (?, ?) THEN delta_fisico ELSE 0 END) AS ajustes,
                SUM(CASE WHEN tipo = ? THEN quantidade * COALESCE(valor_unitario, 0) ELSE 0 END) AS valor_entradas',
                [
                    Movimentacao::TIPO_ENTRADA,
                    Movimentacao::TIPO_SAIDA, 'dano',
                    Movimentacao::TIPO_SAIDA, 'dano',
                    Movimentacao::TIPO_ESTORNO, Movimentacao::TIPO_AJUSTE_AUDITORIA,
                    Movimentacao::TIPO_ENTRADA,
                ]
            )
            ->get()->keyBy('item_id');

        $linhas = Item::withTrashed()->with('categoria:id,nome')->orderBy('nome')->get()
            ->map(function (Item $item) use ($saldosIniciais, $agregados) {
                $a = $agregados->get($item->id);
                $inicial = (int) ($saldosIniciais[$item->id] ?? 0);
                $entradas = (int) ($a->entradas ?? 0);
                $consumo = (int) ($a->consumo ?? 0);
                $dano = (int) ($a->baixas_dano ?? 0);
                $ajustes = (int) ($a->ajustes ?? 0);

                return [
                    'item_id' => $item->id,
                    'nome' => $item->nome,
                    'categoria' => $item->categoria?->nome,
                    'unidade_medida' => $item->unidade_medida,
                    'saldo_inicial' => $inicial,
                    'entradas' => $entradas,
                    'consumo' => $consumo,
                    'baixas_dano' => $dano,
                    'ajustes' => $ajustes,
                    'saldo_final' => $inicial + $entradas - $consumo - $dano + $ajustes,
                    'valor_entradas' => round((float) ($a->valor_entradas ?? 0), 2),
                    '_relevante' => $item->deleted_at === null || $a !== null || $inicial > 0,
                ];
            })
            ->filter(fn ($l) => $l['_relevante'])
            ->map(fn ($l) => collect($l)->except('_relevante')->all())
            ->values();

        $notas = NotaFiscal::whereBetween('created_at', [$inicio, $fim])->count();

        return response()->json(['data' => [
            'mes' => $inicio->format('Y-m'),
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()],
            'totais' => [
                'saldo_inicial' => $linhas->sum('saldo_inicial'),
                'entradas' => $linhas->sum('entradas'),
                'consumo' => $linhas->sum('consumo'),
                'baixas_dano' => $linhas->sum('baixas_dano'),
                'ajustes' => $linhas->sum('ajustes'),
                'saldo_final' => $linhas->sum('saldo_final'),
                'valor_entradas' => round($linhas->sum('valor_entradas'), 2),
                'notas_fiscais' => $notas,
            ],
            'itens' => $linhas,
        ]]);
    }

    /**
     * Itens no/abaixo do ponto de pedido.
     * ponto_pedido = quantidade_minima + ceil(consumo_medio_diario(30d) × lead_time_dias)
     */
    public function criticos(Request $request): JsonResponse
    {
        $janela = 30;

        $consumo = Movimentacao::query()
            ->where('tipo', Movimentacao::TIPO_SAIDA)
            ->where('created_at', '>=', now()->subDays($janela))
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(-delta_fisico) AS total')
            ->pluck('total', 'item_id');

        $itens = Item::with(['categoria:id,nome', 'estoque'])->orderBy('nome')->get()
            ->map(function (Item $item) use ($consumo, $janela) {
                $diario = round(((int) ($consumo[$item->id] ?? 0)) / $janela, 2);
                $disponivel = $item->estoque?->saldo_disponivel ?? 0;
                $pontoPedido = $item->quantidade_minima + (int) ceil($diario * $item->lead_time_dias);

                return [
                    'item_id' => $item->id,
                    'nome' => $item->nome,
                    'categoria' => $item->categoria?->nome,
                    'unidade_medida' => $item->unidade_medida,
                    'saldo_fisico' => $item->estoque?->saldo_fisico ?? 0,
                    'saldo_disponivel' => $disponivel,
                    'quantidade_minima' => $item->quantidade_minima,
                    'lead_time_dias' => $item->lead_time_dias,
                    'consumo_medio_diario' => $diario,
                    'ponto_pedido' => $pontoPedido,
                    'dias_cobertura' => $diario > 0 ? (int) floor($disponivel / $diario) : null,
                    'sugestao_compra' => max(0, $pontoPedido * 2 - $disponivel),
                    'status_reposicao' => $item->estoque?->statusReposicao($item->quantidade_minima) ?? 'critico',
                ];
            })
            ->filter(fn ($l) => $l['saldo_disponivel'] <= $l['ponto_pedido'])
            ->sortBy(fn ($l) => [$l['saldo_disponivel'] > 0 ? 1 : 0, $l['dias_cobertura'] ?? PHP_INT_MAX])
            ->values();

        return response()->json(['data' => $itens, 'janela_consumo_dias' => $janela]);
    }
}
