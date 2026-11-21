<?php

namespace App\Http\Middleware;

use App\Models\LogAcesso;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proteção anti-bot e anti-scanner:
 *  1. Bloqueia User-Agents de scanners/ferramentas de ataque automatizado.
 *  2. Valida campo Honeypot (`website` / `_hp`) em requisições de mutação (POST/PUT/PATCH).
 */
class BotProtection
{
    private const BLOCKED_AGENTS = [
        'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'gobuster',
        'dirbuster', 'wfuzz', 'nuclei', 'acunetix', 'nessus', 'openvas',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $ua = strtolower((string) $request->userAgent());

        foreach (self::BLOCKED_AGENTS as $assinatura) {
            if ($ua !== '' && str_contains($ua, $assinatura)) {
                LogAcesso::registrar('acesso_negado', $request->user());

                return response()->json(['message' => 'Acesso bloqueado pela proteção anti-bot.'], 403);
            }
        }

        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            if ($request->filled('website') || $request->filled('_hp')) {
                LogAcesso::registrar('acesso_negado', $request->user());

                return response()->json(['message' => 'Submissão automatizada bloqueada.'], 422);
            }
        }

        return $next($request);
    }
}
