<?php

namespace App\Http\Controllers;

use App\Factory\FactoryState;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FactoryController extends Controller
{
    public const VIEWS = ['hangar', 'projects', 'board', 'droids', 'subscriptions'];

    public function __invoke(Request $request, FactoryState $state, string $view = 'hangar'): Response
    {
        return Inertia::render('factory', [
            'view' => $view,
            'project' => $request->string('project')->toString() ?: 'all',
            'store' => $state->toArray(),
        ]);
    }
}
