<?php

namespace App\Http\Controllers;

use App\GitHub\GitHub;
use App\GitHub\WebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, GitHub $github, WebhookHandler $handler): JsonResponse
    {
        abort_unless($github->verifySignature($request->getContent(), $request->header('X-Hub-Signature-256')), 401, 'Bad signature.');

        $event = (string) $request->header('X-GitHub-Event');
        if ($event !== 'ping') {
            $handler->handle($event, $request->json()->all());
        }

        return response()->json(['ok' => true], 202);
    }
}
