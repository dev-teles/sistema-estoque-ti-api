<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Violação de regra de negócio (saldo insuficiente, auditoria em curso, etc.).
 * Renderizada como JSON com status 422 (ou o status informado).
 */
class RegraNegocioException extends Exception
{
    public function __construct(string $message, private int $status = 422, private array $detalhes = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'detalhes' => $this->detalhes ?: null,
        ]), $this->status);
    }
}
