<?php

use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuvoController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EstoqueController;
use App\Http\Controllers\FornecedorController;
use App\Http\Controllers\LogAcessoController;
use App\Http\Controllers\MovimentacaoController;
use App\Http\Controllers\NotaFiscalController;
use App\Http\Controllers\RelatorioController;
use App\Http\Controllers\ReservaController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
| API RESTful — Sistema de Gestão de Estoque de TI.
| Autenticação: Bearer Token JWT (HS256).
| RBAC: middleware `role:` (App\Http\Middleware\EnsureRole).
*/

// 1. Autenticação (pública)
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

// Webhook de integração com o Auvo
Route::post('webhooks/auvo', [AuvoController::class, 'webhook']);

Route::middleware('auth:jwt')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // Integração Auvo: busca de tarefa por ID
    Route::get('auvo/tarefas/{taskId}', [AuvoController::class, 'buscarTarefa'])->whereNumber('taskId');

    Route::get('dashboard', DashboardController::class);

    // 2. Permissões e utilizadores de TI
    Route::middleware('role:admin')->group(function () {
        Route::get('usuarios', [UsuarioController::class, 'index']);
        Route::post('usuarios', [UsuarioController::class, 'store']);
        Route::put('usuarios/{usuario}', [UsuarioController::class, 'update']);

        Route::get('logs-acesso', [LogAcessoController::class, 'index']);
    });

    Route::get('categorias', [CategoriaController::class, 'index']);
    Route::middleware('role:admin,gestor')->group(function () {
        Route::post('categorias', [CategoriaController::class, 'store']);
        Route::put('categorias/{categoria}', [CategoriaController::class, 'update']);
    });

    // 3. Fornecedores e notas fiscais de TI
    Route::get('fornecedores', [FornecedorController::class, 'index']);
    Route::middleware('role:admin,gestor')->group(function () {
        Route::post('fornecedores', [FornecedorController::class, 'store']);
        Route::put('fornecedores/{fornecedor}', [FornecedorController::class, 'update']);

        Route::get('notas-fiscais', [NotaFiscalController::class, 'index']);
        Route::get('notas-fiscais/{notaFiscal}', [NotaFiscalController::class, 'show']);
        Route::post('notas-fiscais', [NotaFiscalController::class, 'store']);
    });

    // 4. Catálogo de itens de TI
    Route::get('catalogo', [CatalogoController::class, 'index']);
    Route::get('catalogo/{id}', [CatalogoController::class, 'show'])->whereNumber('id');
    Route::middleware('role:admin,gestor')->group(function () {
        Route::post('catalogo', [CatalogoController::class, 'store']);
        Route::put('catalogo/{item}', [CatalogoController::class, 'update']);
        Route::delete('catalogo/{item}', [CatalogoController::class, 'destroy']);
        Route::post('catalogo/{id}/restaurar', [CatalogoController::class, 'restore'])->whereNumber('id');
    });

    // 5. Estoque de TI e livro-razão (sem PUT/DELETE — imutável)
    Route::get('estoque', [EstoqueController::class, 'index']);
    Route::post('estoque/entrada', [EstoqueController::class, 'entrada']);
    Route::post('estoque/saida', [EstoqueController::class, 'saida']);
    Route::post('estoque/estorno', [EstoqueController::class, 'estorno'])->middleware('role:admin,gestor');
    Route::get('movimentacoes', [MovimentacaoController::class, 'index']);

    // 6. Balcão de TI e reservas
    Route::get('reservas', [ReservaController::class, 'index']);
    Route::post('reservas', [ReservaController::class, 'store']);
    Route::post('reservas/{reserva}/confirmar', [ReservaController::class, 'confirmar']);
    Route::post('reservas/{reserva}/cancelar', [ReservaController::class, 'cancelar']);

    // 7. Auditoria e inventário cíclico
    Route::get('auditorias', [AuditoriaController::class, 'index']);
    Route::get('auditorias/{auditoria}', [AuditoriaController::class, 'show']);
    Route::post('auditorias/{auditoria}/contagem', [AuditoriaController::class, 'contagem']);
    Route::middleware('role:admin,gestor')->group(function () {
        Route::post('auditorias/iniciar', [AuditoriaController::class, 'iniciar']);
        Route::get('auditorias/{auditoria}/reconciliacao', [AuditoriaController::class, 'reconciliacao']);
        Route::post('auditorias/{auditoria}/finalizar', [AuditoriaController::class, 'finalizar']);
    });

    // 8. Relatórios
    Route::middleware('role:admin,gestor')->prefix('relatorios')->group(function () {
        Route::get('mensal', [RelatorioController::class, 'mensal']);
        Route::get('criticos', [RelatorioController::class, 'criticos']);
    });
});
