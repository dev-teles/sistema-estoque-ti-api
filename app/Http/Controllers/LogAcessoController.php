<?php

namespace App\Http\Controllers;

use App\Models\LogAcesso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** Logs de segurança (apenas Admin Global). */
class LogAcessoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = LogAcesso::with('user:id,name')->latest('id');

        if ($evento = $request->query('evento')) {
            $query->where('evento', $evento);
        }

        return JsonResource::collection($query->paginate($this->perPage($request, 25)));
    }
}
