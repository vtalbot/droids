<?php

namespace App\Http\Middleware;

use App\Models\Worker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Lets a worker in with its bearer token and makes it available as $request->attributes->get('worker'). */
class AuthenticateWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $worker = Worker::findByToken($request->bearerToken());
        if (! $worker) {
            return response()->json(['message' => 'Unknown worker token.'], 401);
        }

        $request->attributes->set('worker', $worker);

        return $next($request);
    }
}
