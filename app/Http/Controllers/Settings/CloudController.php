<?php

namespace App\Http\Controllers\Settings;

use App\Cloud\LaravelCloud;
use App\Http\Controllers\Controller;
use App\Models\CloudConnection;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Settings > Laravel Cloud: the API token Droids reads deployments, health and logs with. */
class CloudController extends Controller
{
    public function edit(): Response
    {
        $connection = CloudConnection::current();

        return Inertia::render('settings/cloud', [
            'connection' => $connection ? [
                'organization' => $connection->organization_name,
                'since' => $connection->created_at?->toFormattedDateString(),
            ] : null,
            'projects' => Project::whereNotNull('cloud_application_id')->orderBy('name')->get()
                ->map(fn (Project $p) => ['name' => $p->name, 'app' => $p->cloud_snapshot['app'] ?? '', 'synced' => $p->cloud_synced_at?->diffForHumans()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:2000']]);
        $token = trim($data['token']);

        try {
            $organization = (new LaravelCloud($token))->organization();
        } catch (RequestException $e) {
            throw ValidationException::withMessages(['token' => $e->response->status() === 401
                ? 'Laravel Cloud did not accept this token. Check that you copied all of it and that it has not expired.'
                : 'Laravel Cloud answered with error '.$e->response->status().'.']);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['token' => 'Could not reach Laravel Cloud. Try again.']);
        }

        CloudConnection::query()->delete();
        CloudConnection::create(['token' => $token, 'organization_name' => $organization['name'], 'organization_slug' => $organization['slug']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Connected to {$organization['name']} on Laravel Cloud."]);

        return to_route('cloud.edit');
    }

    public function destroy(): RedirectResponse
    {
        CloudConnection::query()->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Disconnected from Laravel Cloud. Linked projects keep their last snapshot.']);

        return to_route('cloud.edit');
    }
}
