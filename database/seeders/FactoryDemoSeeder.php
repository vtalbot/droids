<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ChatMessage;
use App\Models\Droid;
use App\Models\Feature;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use App\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the sample factory from the approved prototype so every screen has
 * something to show before GitHub, the runners and Laravel Cloud are connected.
 */
class FactoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents(__DIR__.'/data/factory-demo.json'), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data) {
            $projects = $this->projects($data);
            $droids = $this->droids($data, $projects);
            $tasks = $this->tasks($data, $projects, $droids);
            $this->roadmap($data, $projects, $tasks);
            $this->pullRequests($data, $projects);
            $this->subscriptions($data);
            $this->activity($data, $droids);
            $this->chats($data, $droids);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, Project>
     */
    private function projects(array $data): array
    {
        $out = [];
        foreach ($data['projects'] as $p) {
            $stats = $data['stats'][$p['id']] ?? null;
            $out[$p['id']] = Project::updateOrCreate(['slug' => $p['id']], [
                'name' => $p['name'],
                'repo' => $p['repo'],
                'board' => $p['board'],
                'color' => $p['color'],
                'stats' => $stats ? [...$stats, 'weeks' => $data['weeks']] : null,
                'cloud_snapshot' => $data['cloud'][$p['id']] ?? null,
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Project>  $projects
     * @return array<string, Droid>
     */
    private function droids(array $data, array $projects): array
    {
        $out = [];
        foreach ($data['droids'] as $d) {
            $out[$d['id']] = Droid::updateOrCreate(['slug' => $d['id']], [
                'unit' => $d['unit'],
                'name' => $d['name'],
                'provider' => $d['provider'],
                'model' => $d['model'],
                'role' => $d['role'],
                'status' => $d['status'],
                'project_id' => $projects[$d['project']]->id,
                'step' => $d['step'],
                'progress' => $d['progress'],
                'minutes' => $d['minutes'],
                'cost' => $d['cost'],
                'seed' => $d['seed'],
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Project>  $projects
     * @param  array<string, Droid>  $droids
     * @return array<string, Task>
     */
    private function tasks(array $data, array $projects, array $droids): array
    {
        $out = [];
        foreach ($data['issues'] as $i => $issue) {
            $task = Task::updateOrCreate(
                ['project_id' => $projects[$issue['project']]->id, 'number' => $issue['n']],
                [
                    'title' => $issue['title'],
                    'column' => $issue['col'],
                    'labels' => $issue['labels'],
                    'builder_id' => isset($issue['builder']) ? $droids[$issue['builder']]->id : null,
                    'reviewer_id' => isset($issue['reviewer']) ? $droids[$issue['reviewer']]->id : null,
                    'engine' => $issue['engine'] ?? null,
                    'position' => $i,
                ],
            );
            $out[$issue['project'].':'.$issue['n']] = $task;
        }

        foreach ($data['droids'] as $d) {
            if ($d['task'] !== null && isset($out[$d['project'].':'.$d['task']])) {
                $droids[$d['id']]->update(['task_id' => $out[$d['project'].':'.$d['task']]->id]);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Project>  $projects
     * @param  array<string, Task>  $tasks
     */
    private function roadmap(array $data, array $projects, array &$tasks): void
    {
        foreach ($data['roadmap'] as $slug => $milestones) {
            $project = $projects[$slug];
            foreach ($milestones as $mi => $m) {
                $milestone = Milestone::updateOrCreate(
                    ['project_id' => $project->id, 'name' => $m['name']],
                    ['goal' => $m['goal'], 'due_on' => $this->due($m['due']), 'state' => $m['state'], 'position' => $mi],
                );
                foreach ($m['features'] as $fi => $f) {
                    $feature = Feature::updateOrCreate(
                        ['milestone_id' => $milestone->id, 'name' => $f['name']],
                        ['position' => $fi],
                    );
                    foreach ($f['tasks'] as $ti => $t) {
                        $key = $slug.':'.$t['n'];
                        $task = $tasks[$key] ?? Task::updateOrCreate(
                            ['project_id' => $project->id, 'number' => $t['n']],
                            ['title' => $t['title'], 'column' => ! empty($t['done']) ? 'done' : 'backlog', 'labels' => [], 'position' => 1000 + $ti],
                        );
                        $task->update(['feature_id' => $feature->id]);
                        $tasks[$key] = $task;
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Project>  $projects
     */
    private function pullRequests(array $data, array $projects): void
    {
        foreach ($data['prs'] as $key => $pr) {
            [$slug, $number] = explode(':', $key);
            $task = Task::where('project_id', $projects[$slug]->id)->where('number', (int) $number)->firstOrFail();
            PullRequest::updateOrCreate(['task_id' => $task->id, 'number' => $pr['n']], [
                'title' => $pr['title'],
                'branch' => $pr['branch'],
                'state' => $pr['state'],
                'additions' => $pr['add'],
                'deletions' => $pr['del'],
                'commits' => $pr['commits'],
                'files' => $pr['files'],
                'checks' => $pr['checks'],
                'reviews' => $pr['reviews'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function subscriptions(array $data): void
    {
        foreach ($data['subs'] as $s) {
            Subscription::updateOrCreate(['slug' => $s['id']], [
                'provider' => $s['provider'],
                'name' => $s['name'],
                'account' => $s['account'],
                'auth' => $s['auth'],
                'color' => $s['color'],
                'windows' => $s['windows'],
                'week' => $s['week'],
                'spend_used' => $s['spend']['used'] ?? null,
                'spend_cap' => $s['spend']['cap'] ?? null,
            ]);
        }

        foreach ($data['schedule'] as $provider => $grid) {
            SubscriptionSchedule::updateOrCreate(['provider' => $provider], ['grid' => $grid]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Droid>  $droids
     */
    private function activity(array $data, array $droids): void
    {
        Activity::query()->delete();
        foreach (array_reverse($data['feed']) as $f) {
            $at = now()->subMinutes($f['min']);
            Activity::create(['droid_id' => $droids[$f['droid']]->id, 'text' => $f['text'], 'created_at' => $at, 'updated_at' => $at]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Droid>  $droids
     */
    private function chats(array $data, array $droids): void
    {
        ChatMessage::query()->delete();
        foreach ($data['chats'] as $slug => $items) {
            foreach ($items as $item) {
                $kind = $item['kind'];
                unset($item['kind']);
                ChatMessage::create(['droid_id' => $droids[$slug]->id, 'kind' => $kind, 'payload' => $item]);
            }
        }
    }

    /** Turn a sample date like "Dec 12" into the next matching date from Nov 2026. */
    private function due(string $label): Carbon
    {
        $date = Carbon::parse($label.' 2026');

        return $date->month < 6 ? $date->addYear() : $date;
    }
}
