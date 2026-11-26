<?php

namespace App\Http\Controllers\Factory;

use App\Models\ChatMessage;
use App\Models\Droid;
use App\Models\Project;
use App\Models\Run;
use App\Runs\RunQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Conversations with droids: questions go to a worker, answers come back as chat items. */
class ChatController extends Controller
{
    public function ask(Request $request, Droid $droid, RunQueue $queue): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(Run::ASK_KINDS)],
            'project' => ['nullable', 'string', Rule::requiredIf(in_array($request->input('kind'), ['plan', 'propose'], true))],
            'text' => ['required', 'string', 'max:8000'],
            'intro' => ['nullable', 'string', 'max:4000'],
        ]);
        $project = isset($data['project']) ? Project::where('slug', $data['project'])->firstOrFail() : null;

        $queue->ask($droid, $data['kind'], $project, trim($data['text']), $data['intro'] ?? null);

        return $this->state([], 201);
    }

    /** Remember what the person did with a card: issues created, proposals added, rules applied. */
    public function update(Request $request, ChatMessage $message): JsonResponse
    {
        $data = $request->validate([
            'created' => ['sometimes', 'array', 'max:50'],
            'created.*' => ['integer'],
            'added' => ['sometimes', 'array', 'max:20'],
            'added.*' => ['string', 'max:200'],
            'applied' => ['sometimes', 'boolean'],
        ]);
        $message->update(['payload' => [...$message->payload, ...$data]]);

        return $this->state();
    }
}
