<?php

namespace App\Http\Controllers\Factory;

use App\Factory\FactoryState;
use App\GitHub\GitHubNotConfigured;
use App\Http\Controllers\Controller as BaseController;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
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

    /**
     * Run a GitHub call and turn its failures into a message the UI can show.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    protected function github(callable $call): mixed
    {
        try {
            return $call();
        } catch (GitHubNotConfigured $e) {
            throw new HttpResponseException(response()->json(['message' => $e->getMessage()], 422));
        } catch (RequestException $e) {
            $message = $e->response->json('message') ?: 'GitHub returned an error.';
            throw new HttpResponseException(response()->json(['message' => "GitHub: {$message}"], 502));
        }
    }
}
