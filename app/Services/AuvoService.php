<?php

namespace App\Services;

use App\Exceptions\RegraNegocioException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AuvoService
{
    private string $baseUrl;
    private ?string $apiKey;
    private ?string $apiToken;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.auvo.url', 'https://api.auvo.com.br/v2'), '/');
        $this->apiKey = config('services.auvo.key');
        $this->apiToken = config('services.auvo.token');
    }

    public function credenciaisConfiguradas(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->apiToken);
    }

    private function http(): PendingRequest
    {
        return Http::timeout(15)->withoutVerifying();
    }

    /** Autentica na API v2 do Auvo e armazena o accessToken em cache por 25 minutos. */
    public function getToken(): string
    {
        return Cache::remember('auvo_access_token', now()->addMinutes(25), function () {
            $response = $this->http()->get("{$this->baseUrl}/login/", [
                'apiKey' => $this->apiKey,
                'apiToken' => $this->apiToken,
            ]);

            $token = $response->json('result.accessToken');
            if ($response->failed() || ! $token) {
                throw new RegraNegocioException('Falha ao autenticar na API do Auvo. Verifique AUVO_API_KEY e AUVO_API_TOKEN.');
            }

            return (string) $token;
        });
    }

    /**
     * Busca um Chamado (/tickets/{id}) ou Tarefa (/tasks/{id}) no Auvo pelo ID.
     */
    public function buscarTarefa(int $id): array
    {
        if (! $this->credenciaisConfiguradas()) {
            $exemplos = [
                0 => ['solicitante' => 'Lucas Almeida', 'resumo' => 'Substituição de periféricos na estação de trabalho'],
                1 => ['solicitante' => 'Mariana Costa', 'resumo' => 'Instalação de ponto de rede e patch cord Cat6'],
                2 => ['solicitante' => 'Roberto Mendes', 'resumo' => 'Upgrade de SSD NVMe e memória RAM'],
                3 => ['solicitante' => 'Fernanda Oliveira', 'resumo' => 'Troca de headset USB para atendimento'],
            ];
            $exemplo = $exemplos[$id % count($exemplos)];

            return [
                'task_id' => $id,
                'solicitante' => "{$exemplo['solicitante']} (Chamado Auvo #{$id})",
                'orientacao' => $exemplo['resumo'],
                'tecnico' => 'Suporte TI',
                'data_tarefa' => now()->toDateString(),
                'modo_demo' => true,
            ];
        }

        $token = $this->getToken();

        // 1. Tenta primeiro buscar como Chamado / Ticket (/tickets/{id}) do Auvo Desk
        $ticketResp = $this->http()
            ->withToken($token)
            ->get("{$this->baseUrl}/tickets/{$id}");

        if ($ticketResp->successful() && $ticketResp->json('result.id')) {
            $t = $ticketResp->json('result');
            $solicitanteNome = trim((string) ($t['requesterName'] ?: $t['customerName'] ?: $t['userResponsableName'] ?: 'Solicitante Auvo'));
            $titulo = trim((string) ($t['title'] ?? ''));

            return [
                'task_id' => (int) $t['id'],
                'solicitante' => "{$solicitanteNome} (Chamado Auvo #{$id})",
                'orientacao' => mb_substr($titulo, 0, 250),
                'tecnico' => $t['userResponsableName'] ?? null,
                'data_tarefa' => $t['creationDate'] ?? null,
                'modo_demo' => false,
            ];
        }

        // 2. Fallback: busca como Tarefa (/tasks/{id})
        $taskResp = $this->http()
            ->withToken($token)
            ->get("{$this->baseUrl}/tasks/{$id}");

        if ($taskResp->successful() && $taskResp->json('result.taskID')) {
            $r = $taskResp->json('result');
            $cliente = trim((string) ($r['customerDescription'] ?: ($r['customerName'] ?? '') ?: ($r['userToName'] ?? 'Solicitante Auvo')));

            return [
                'task_id' => (int) $r['taskID'],
                'solicitante' => "{$cliente} (Tarefa Auvo #{$id})",
                'orientacao' => mb_substr((string) ($r['orientation'] ?? ''), 0, 250),
                'tecnico' => $r['userToName'] ?? null,
                'data_tarefa' => $r['taskDate'] ?? null,
                'modo_demo' => false,
            ];
        }

        throw new RegraNegocioException("Chamado ou Tarefa #{$id} não encontrado no Auvo.", 404);
    }
}
