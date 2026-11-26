<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkerController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/workers', [
            'workers' => Worker::orderBy('name')->get()->map(fn (Worker $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'online' => $w->online(),
                'lastSeen' => $w->last_seen_at?->diffForHumans(),
                'subscriptions' => $w->subscriptions ?? [],
            ]),
            'newToken' => $request->session()->get('worker_token'),
            'server' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        [, $token] = Worker::issue($data['name']);

        return to_route('workers.edit')->with('worker_token', ['name' => $data['name'], 'token' => $token]);
    }

    public function destroy(Worker $worker): RedirectResponse
    {
        $worker->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Removed {$worker->name}. Its token no longer works."]);

        return to_route('workers.edit');
    }
}
