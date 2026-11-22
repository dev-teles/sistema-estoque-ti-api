<?php

namespace App\Http\Middleware;

use App\Models\LogAcesso;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validação de entrada, restrição de uploads indevidos e proteção contra XSS / Content Sniffing:
 *  1. Bloqueia uploads de ficheiros em rotas que não aceitam anexos e valida extensão, dupla extensão,
 *     tamanho (máx. 5 MB) e assinatura MIME real (magic bytes) contra ficheiros executáveis/scripts.
 *  2. Limita o tamanho do corpo JSON (máx. 512 KB) para evitar Payload DoS.
 *  3. Remove tags HTML, scripts, protocolos `javascript:` e caracteres de controlo dos inputs do utilizador.
 *  4. Codifica `<`, `>`, `&`, `'` e `"` em respostas JSON e aplica cabeçalhos de segurança HTTP.
 */
class SanitizeInput
{
    private const SKIP_KEYS = ['password', 'current_password'];
    private const STRIP_RESPONSE_KEYS = ['password', 'deleted_at', 'updated_at'];
    private const MAX_JSON_BYTES = 524288; // 512 KB
    private const MAX_FILE_BYTES = 5242880; // 5 MB
    private const ALLOWED_UPLOAD_ROUTES = []; // Nenhuma rota atual aceita upload direto de ficheiros
    private const ALLOWED_MIMES = ['application/pdf', 'image/png', 'image/jpeg', 'application/xml', 'text/xml'];
    private const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'xml'];
    private const FORBIDDEN_PATTERN = '/\.(php\d*|phtml|phar|exe|sh|bat|cmd|ps1|vbs|js|mjs|jsp|asp|aspx|cgi|pl|py|rb|dll|scr|msi|jar|svg|html?|htaccess)(\.|$)/i';

    public function handle(Request $request, Closure $next): Response
    {
        $forceHttps = app()->isProduction() || filter_var(env('FORCE_HTTPS', false), FILTER_VALIDATE_BOOLEAN);
        if ($forceHttps && ! $request->secure() && strtolower((string) $request->header('X-Forwarded-Proto')) !== 'https') {
            return response()->json(['message' => 'Conexão insegura bloqueada: utilize HTTPS.'], 403);
        }

        if ($erroUpload = $this->validateUploadsAndPayload($request)) {
            LogAcesso::registrar('acesso_negado', $request->user());

            return response()->json(['message' => $erroUpload], 422);
        }

        $request->merge($this->sanitizeArray($request->all()));
        $request->query->replace($this->sanitizeArray($request->query->all()));

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $payload = $response->getData(true);
            if (is_array($payload)) {
                $response->setData($this->trimResponsePayload($payload));
            }
            $response->setEncodingOptions(
                $response->getEncodingOptions() | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
        }

        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'"
        );

        return $response;
    }

    private function trimResponsePayload(array $payload, bool $isRoot = true): array
    {
        if ($isRoot) {
            unset($payload['links']);
            if (isset($payload['meta']) && is_array($payload['meta'])) {
                unset($payload['meta']['links'], $payload['meta']['path']);
            }
        }

        $trimmed = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::STRIP_RESPONSE_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $trimmed[$key] = $this->trimResponsePayload($value, false);
            } elseif (is_string($value)) {
                $trimmed[$key] = trim($value);
            } else {
                $trimmed[$key] = $value;
            }
        }

        return $trimmed;
    }

    private function validateUploadsAndPayload(Request $request): ?string
    {
        $files = $request->allFiles();

        if ($files !== []) {
            if (! in_array($request->path(), self::ALLOWED_UPLOAD_ROUTES, true)) {
                return 'Upload de ficheiros não é permitido nesta rota.';
            }

            foreach ( new \RecursiveIteratorIterator(new \RecursiveArrayIterator($files)) as $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    return 'Ficheiro enviado é inválido ou está corrompido.';
                }

                $originalName = $file->getClientOriginalName();
                $ext = strtolower($file->getClientOriginalExtension());
                $realMime = (string) $file->getMimeType();

                if (
                    str_contains($originalName, '..') ||
                    str_contains($originalName, "\0") ||
                    preg_match(self::FORBIDDEN_PATTERN, $originalName) ||
                    ! in_array($ext, self::ALLOWED_EXTENSIONS, true) ||
                    ! in_array($realMime, self::ALLOWED_MIMES, true)
                ) {
                    return 'Tipo ou extensão de ficheiro não permitido por motivos de segurança.';
                }

                if ($file->getSize() > self::MAX_FILE_BYTES) {
                    return 'O ficheiro excede o tamanho máximo permitido (5 MB).';
                }
            }
        }

        if (strlen((string) $request->getContent()) > self::MAX_JSON_BYTES) {
            return 'O tamanho da requisição excede o limite máximo permitido (512 KB).';
        }

        return null;
    }

    private function sanitizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, self::SKIP_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->sanitizeString($value);
            }
        }

        return $data;
    }

    private function sanitizeString(string $value): string
    {
        // 1. Remove null-bytes e caracteres de controlo invisíveis
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        // 2. Remove blocos <script>/<style> completos (incluindo o seu conteúdo interno) e quaisquer outras tags HTML
        $clean = (string) preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $clean);
        $clean = strip_tags($clean);

        // 3. Neutraliza pseudo-protocolos executáveis e event handlers inline
        $clean = (string) preg_replace('/\b(?:javascript|vbscript|data)\s*:/i', '', $clean);
        $clean = (string) preg_replace('/\bon[a-z]+\s*=/i', '', $clean);

        return trim($clean);
    }
}
