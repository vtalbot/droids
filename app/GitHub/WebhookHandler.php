<?php

namespace App\GitHub;

use App\Models\GithubInstallation;
use App\Models\Project;

/** Applies GitHub webhook deliveries to the factory. */
class WebhookHandler
{
    public function __construct(private RepositorySync $sync) {}

    /** @param array<string, mixed> $payload */
    public function handle(string $event, array $payload): void
    {
        if ($event === 'installation') {
            $this->installation($payload);

            return;
        }

        $project = isset($payload['repository']['full_name'])
            ? Project::where('repo', $payload['repository']['full_name'])->whereNotNull('github_installation_id')->first()
            : null;
        if (! $project) {
            return;
        }

        match ($event) {
            'issues' => in_array($payload['action'] ?? null, ['deleted', 'transferred'], true)
                ? $project->tasks()->where('number', $payload['issue']['number'])->delete()
                : $this->sync->upsertIssue($project, $payload['issue']),
            'milestone' => $this->sync->upsertMilestone($project, $payload['milestone']),
            'pull_request' => $this->sync->upsertPull($project, $payload['pull_request']),
            'pull_request_review' => $this->sync->refreshPull($project, (int) $payload['pull_request']['number']),
            'check_run' => $this->refreshPulls($project, $payload['check_run']['pull_requests'] ?? []),
            'check_suite' => $this->refreshPulls($project, $payload['check_suite']['pull_requests'] ?? []),
            default => null,
        };
    }

    /** @param array<int, array<string, mixed>> $pulls */
    private function refreshPulls(Project $project, array $pulls): void
    {
        foreach ($pulls as $pull) {
            $this->sync->refreshPull($project, (int) $pull['number']);
        }
    }

    /** @param array<string, mixed> $payload */
    private function installation(array $payload): void
    {
        $id = (int) $payload['installation']['id'];

        match ($payload['action']) {
            'deleted' => GithubInstallation::where('installation_id', $id)->delete(),
            'suspend' => GithubInstallation::where('installation_id', $id)->update(['suspended_at' => now()]),
            'unsuspend' => GithubInstallation::where('installation_id', $id)->update(['suspended_at' => null]),
            default => GithubInstallation::updateOrCreate(['installation_id' => $id], [
                'account_login' => $payload['installation']['account']['login'],
                'account_type' => $payload['installation']['account']['type'] ?? 'User',
                'repository_selection' => $payload['installation']['repository_selection'] ?? 'selected',
            ]),
        };
    }
}
