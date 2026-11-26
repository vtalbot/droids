<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Run;
use App\Models\Worker;
use App\Runs\Design;
use App\Runs\Memory;
use App\Runs\RunQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkerController extends Controller
{
    public function __construct(private RunQueue $queue) {}

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscriptions' => ['present', 'array'],
            'subscriptions.*' => ['string', 'max:120'],
            'version' => ['nullable', 'string', 'max:40'],
        ]);
        $worker = $this->worker($request);
        $worker->forceFill(['version' => $data['version'] ?? null])->save();

        $job = $this->queue->claim($worker, array_values($data['subscriptions']));

        return $job ? response()->json(['run' => $job]) : response()->json(['run' => null], 200);
    }

    public function progress(Request $request, Run $run): JsonResponse
    {
        $this->own($request, $run);
        $data = $request->validate([
            'step' => ['nullable', 'string', 'max:500'],
            'progress' => ['nullable', 'numeric', 'between:0,1'],
            'log' => ['nullable', 'string', 'max:200000'],
        ]);
        $this->queue->progress($run, $data['step'] ?? null, isset($data['progress']) ? (float) $data['progress'] : null, $data['log'] ?? null);

        return response()->json(['cancelled' => false]);
    }

    public function token(Request $request, Run $run): JsonResponse
    {
        $this->own($request, $run);

        return response()->json(['token' => $this->queue->token($run)]);
    }

    public function finish(Request $request, Run $run): JsonResponse
    {
        $this->own($request, $run);
        $data = $request->validate([
            'status' => ['required', Rule::in(['succeeded', 'failed', 'limited'])],
            'pushed' => ['boolean'],
            'summary' => ['nullable', 'string', 'max:60000'],
            'error' => ['nullable', 'string', 'max:5000'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'tokens_in' => ['nullable', 'integer', 'min:0'],
            'tokens_out' => ['nullable', 'integer', 'min:0'],
            'limited_until' => ['nullable', 'date'],
            'log' => ['nullable', 'string', 'max:200000'],
            'design' => ['nullable', 'array', 'max:'.Design::MAX_FILES],
            'design.*.path' => ['required', 'string', 'max:200'],
            'design.*.content' => ['present', 'nullable', 'string', 'max:'.Design::MAX_BYTES],
            'memory' => ['nullable', 'string', 'max:'.(Memory::MAX_BYTES * 2)],
            'memory_base' => ['nullable', 'string', 'max:'.(Memory::MAX_BYTES * 2)],
        ]);
        if (isset($data['design'])) {
            $data['design'] = array_values(array_map(fn (array $f) => ['path' => (string) $f['path'], 'content' => (string) ($f['content'] ?? '')], $data['design']));
        }

        $run = $this->queue->finish($run, $data);

        return response()->json(['status' => $run->status, 'pr' => $run->pr_number]);
    }

    private function worker(Request $request): Worker
    {
        /** @var Worker */
        return $request->attributes->get('worker');
    }

    /** A worker may only report on runs it claimed, and only while they run. */
    private function own(Request $request, Run $run): void
    {
        if ($run->worker_id !== $this->worker($request)->id) {
            abort(404);
        }
        if ($run->status !== 'running') {
            abort(response()->json(['cancelled' => true, 'message' => "This run is {$run->status}."], 409));
        }
    }
}
