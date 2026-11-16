<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\User;
use App\Services\AuvoService;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuvoController extends Controller
{
    public function __construct(
        private AuvoService $auvo,
        private LedgerService $ledger,
    ) {}

    /**
     * GET /api/auvo/tarefas/{taskId}
     * Consulta os dados de uma tarefa no Auvo para preencher automaticamente a retirada/reserva.
     */
    public function buscarTarefa(int $taskId): JsonResponse
    {
        return response()->json([
            'data' => $this->auvo->buscarTarefa($taskId),
        ]);
    }

    /**
     * POST /api/webhooks/auvo
     * Recebe notificações de tarefas do Auvo e gera reserva automática no estoque de TI.
     */
    public function webhook(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'taskID' => ['required', 'integer'],
            'customerDescription' => ['nullable', 'string', 'max:150'],
            'orientation' => ['nullable', 'string', 'max:255'],
            'item_id' => ['required', 'exists:itens,id'],
            'quantidade' => ['nullable', 'integer', 'min:1'],
        ]);

        // Em chamadas de webhook sem sessão autenticada, utiliza o primeiro operador/admin ativo
        if (! auth()->check()) {
            $operadorPadrao = User::where('ativo', true)->orderBy('id')->firstOrFail();
            auth()->setUser($operadorPadrao);
        }

        $item = Item::findOrFail($dados['item_id']);
        $cliente = $dados['customerDescription'] ?? 'Solicitante Auvo';
        $solicitante = "{$cliente} (Tarefa Auvo #{$dados['taskID']})";

        $reserva = $this->ledger->reservar(
            $item,
            (int) ($dados['quantidade'] ?? 1),
            $solicitante,
            $dados['orientation'] ?? "Criado via Webhook Auvo (Tarefa #{$dados['taskID']})"
        );

        return response()->json([
            'message' => 'Reserva criada a partir da tarefa do Auvo.',
            'reserva_id' => $reserva->id,
        ], 201);
    }
}
