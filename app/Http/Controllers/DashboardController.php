<?php

namespace App\Http\Controllers;

use App\Http\Resources\MovimentacaoResource;
use App\Http\Resources\ReservaResource;
use App\Models\Auditoria;
use App\Models\Estoque;
use App\Models\Movimentacao;
use App\Models\Reserva;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** KPIs da tela inicial do Estoque de TI. */
class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $porStatus = Estoque::ativos()
            ->select(DB::raw(Estoque::statusSql().' AS status'), DB::raw('COUNT(*) AS total'))
            ->groupByRaw(Estoque::statusSql())
            ->pluck('total', 'status');

        $reservasPendentes = Reserva::where('status', Reserva::STATUS_RESERVADO);

        return response()->json(['data' => [
            'kpis' => [
                'total_itens' => (int) $porStatus->sum(),
                'itens_regulares' => (int) ($porStatus['regular'] ?? 0),
                'itens_alerta' => (int) ($porStatus['alerta'] ?? 0),
                'itens_criticos' => (int) ($porStatus['critico'] ?? 0),
                'reservas_pendentes' => (clone $reservasPendentes)->count(),
                'auditorias_em_andamento' => Auditoria::where('status', Auditoria::STATUS_EM_ANDAMENTO)->count(),
            ],
            'reservas_pendentes' => ReservaResource::collection(
                $reservasPendentes->with(['item', 'user'])->oldest('id')->limit(50)->get()
            ),
            'ultimas_movimentacoes' => MovimentacaoResource::collection(
                Movimentacao::with(['item', 'user'])->latest('id')->limit(25)->get()
            ),
        ]]);
    }
}
