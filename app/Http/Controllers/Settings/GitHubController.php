<?php

namespace App\Http\Controllers\Settings;

use App\GitHub\GitHub;
use App\GitHub\RepositorySync;
use App\Http\Controllers\Controller;
use App\Models\GithubApp;
use App\Models\GithubInstallation;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class GitHubController extends Controller
{
    public function edit(Request $request): Response
    {
        $app = GithubApp::current();

        return Inertia::render('settings/github', [
            'app' => $app ? [
                'name' => $app->name,
                'slug' => $app->slug,
                'url' => $app->html_url,
                'owner' => $app->owner_login,
                'installUrl' => $app->installUrl(),
            ] : null,
            'installations' => GithubInstallation::orderBy('account_login')->get()->map(fn (GithubInstallation $i) => [
                'id' => $i->installation_id,
                'account' => $i->account_login,
                'type' => $i->account_type,
                'selection' => $i->repository_selection,
                'suspended' => $i->suspended_at !== null,
            ]),
            'projects' => Project::whereNotNull('github_installation_id')->orderBy('name')->get()->map(fn (Project $p) => [
                'slug' => $p->slug,
                'repo' => $p->repo,
                'syncedAt' => $p->synced_at?->diffForHumans(),
            ]),
            'webhookUrl' => route('github.webhook'),
            'publicUrl' => ! $this->isLocal(),
        ]);
    }

    /** Start the App manifest flow: GitHub creates the App and calls us back with its credentials. */
    public function create(Request $request): View
    {
        $state = Str::random(40);
        $request->session()->put('github_manifest_state', $state);
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        $manifest = [
            'name' => Str::limit('Droids '.Str::before($host, '.'), 34, '').' '.Str::lower(Str::random(4)),
            'url' => config('app.url'),
            'hook_attributes' => ['url' => route('github.webhook'), 'active' => ! $this->isLocal()],
            'redirect_url' => route('github.app.created'),
            'setup_url' => route('github.installed'),
            'setup_on_update' => true,
            'public' => false,
            'default_permissions' => [
                'metadata' => 'read',
                'contents' => 'write',
                'issues' => 'write',
                'pull_requests' => 'write',
                'checks' => 'read',
                'statuses' => 'read',
                'actions' => 'write',
            ],
            'default_events' => ['issues', 'milestone', 'pull_request', 'pull_request_review', 'check_run', 'check_suite'],
        ];

        return view('github.manifest', [
            'action' => 'https://github.com/settings/apps/new?state='.$state,
            'manifest' => json_encode($manifest, JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function created(Request $request, GitHub $github): RedirectResponse
    {
        $expected = $request->session()->pull('github_manifest_state');
        abort_unless(is_string($expected) && hash_equals($expected, (string) $request->query('state')), 403, 'This GitHub App setup link has expired. Start again from Settings.');

        try {
            $data = $github->convertManifest((string) $request->query('code'));
        } catch (RequestException|ConnectionException $e) {
            report($e);

            return $this->failed('GitHub did not hand over the new App\'s keys ('.$this->reason($e).'). If the App was created on GitHub, delete it under Developer settings > GitHub Apps, then create it again here.');
        }

        $app = GithubApp::updateOrCreate(['app_id' => $data['id']], [
            'slug' => $data['slug'],
            'name' => $data['name'],
            'owner_login' => $data['owner']['login'] ?? null,
            'html_url' => $data['html_url'],
            'client_id' => $data['client_id'] ?? null,
            'client_secret' => $data['client_secret'] ?? null,
            'private_key' => $data['pem'],
            'webhook_secret' => $data['webhook_secret'] ?? null,
        ]);

        return redirect()->away($app->installUrl());
    }

    /** GitHub sends people here after they install (or update) the App. */
    public function installed(Request $request, GitHub $github): RedirectResponse
    {
        if (! $github->configured()) {
            return $this->failed('Droids has no GitHub App saved yet, so it cannot record this installation. Delete the App under GitHub Developer settings > GitHub Apps, then create it again here.');
        }

        $id = (int) $request->query('installation_id');
        if ($id > 0) {
            try {
                $installation = $github->asApp()->get("/app/installations/{$id}")->json();
            } catch (RequestException|ConnectionException $e) {
                report($e);

                return $this->failed('GitHub did not confirm the installation ('.$this->reason($e).'). Try Change repository access again.');
            }
            GithubInstallation::updateOrCreate(['installation_id' => $id], [
                'account_login' => $installation['account']['login'],
                'account_type' => $installation['account']['type'] ?? 'User',
                'repository_selection' => $installation['repository_selection'] ?? 'selected',
                'suspended_at' => null,
            ]);
            Cache::forget('github.repos');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'GitHub App installed. Connect a repository from the Hangar.']);

        return to_route('github.edit');
    }

    public function sync(RepositorySync $sync): RedirectResponse
    {
        $failed = [];
        foreach (Project::whereNotNull('github_installation_id')->get() as $project) {
            try {
                $sync->sync($project);
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $project->repo;
            }
        }

        Inertia::flash('toast', $failed
            ? ['type' => 'error', 'message' => 'Sync failed for '.implode(', ', $failed).'.']
            : ['type' => 'success', 'message' => 'Synced with GitHub.']);

        return back();
    }

    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('github.edit');
    }

    private function reason(RequestException|ConnectionException $e): string
    {
        return $e instanceof RequestException
            ? $e->response->status().' '.($e->response->json('message') ?? 'error')
            : 'could not reach api.github.com';
    }

    private function isLocal(): bool
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', ''], true) || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    }
}
