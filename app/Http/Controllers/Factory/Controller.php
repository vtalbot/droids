<?php

namespace App\Http\Controllers\Factory;

use App\Factory\FactoryState;
use App\Http\Controllers\Controller as BaseController;
use Illuminate\Http\JsonResponse;

abstract class Controller extends BaseController
{
    /**
     * Every write answers with the fresh factory store so the UI can reconcile.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function state(array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json([...$extra, 'store' => app(FactoryState::class)->toArray()], $status);
    }
}
