<?php

namespace App\Http\Controllers\Factory;

use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubscriptionController extends Controller
{
    public const PLANS = [
        'claude' => ['Claude Pro', 'Claude Max', 'Claude Team', 'Anthropic API key'],
        'codex' => ['ChatGPT Plus', 'ChatGPT Pro', 'ChatGPT Business', 'Codex API key'],
    ];

    private const COLORS = [
        'claude' => ['oklch(0.72 0.12 60)', 'oklch(0.55 0.13 20)', 'oklch(0.64 0.14 40)'],
        'codex' => ['oklch(0.68 0.13 240)', 'oklch(0.5 0.16 300)', 'oklch(0.6 0.17 275)'],
    ];

    public function store(Request $request): JsonResponse
    {
        $provider = $request->string('provider')->toString();
        $data = $request->validate([
            'provider' => ['required', Rule::in(['claude', 'codex'])],
            'plan' => ['required', Rule::in(self::PLANS[$provider] ?? [])],
            'account' => ['nullable', 'string', 'max:80'],
            'api_key' => ['nullable', 'string', 'min:8', 'max:500'],
            'spend_cap' => ['nullable', 'numeric', 'min:1', 'max:100000'],
        ]);
        $api = str_contains($data['plan'], 'API');
        if ($api && empty($data['api_key'])) {
            return response()->json(['message' => 'Paste the API key.', 'errors' => ['api_key' => ['Paste the API key.']]], 422);
        }

        $slug = Str::slug($data['plan'].' '.($data['account'] ?? '')).'-'.Str::lower(Str::random(4));
        $sub = Subscription::create([
            'slug' => $slug,
            'provider' => $data['provider'],
            'name' => $data['plan'],
            'account' => $data['account'] ?: 'Untitled',
            'auth' => $api ? 'API key ••••'.substr((string) $data['api_key'], -4) : 'Sign-in pending',
            'api_key' => $api ? $data['api_key'] : null,
            'color' => self::COLORS[$data['provider']][Subscription::where('provider', $data['provider'])->count() % 3],
            'windows' => $api ? [] : [
                ['label' => '5-hour window', 'used' => 0, 'resets' => 'not started'],
                ['label' => 'Weekly limit', 'used' => 0, 'resets' => 'not started'],
            ],
            'week' => [0, 0, 0, 0, 0, 0, 0],
            'spend_used' => $api ? 0 : null,
            'spend_cap' => $api ? ($data['spend_cap'] ?? 100) : null,
        ]);

        return $this->state(['subscription' => $sub->slug], 201);
    }

    public function update(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $subscription->update($data);

        return $this->state();
    }

    /** Replace an engine's weekly grid: 7 days x 24 hours of subscription slugs ("" = paused). */
    public function schedule(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ['claude', 'codex'], true), 404);
        $slugs = Subscription::where('provider', $provider)->pluck('slug')->all();

        $data = $request->validate([
            'grid' => ['required', 'array', 'size:7'],
            'grid.*' => ['required', 'array', 'size:24'],
            'grid.*.*' => ['present', 'nullable', 'string'],
        ]);
        validator($data)->after(function (Validator $v) use ($data, $slugs) {
            foreach ($data['grid'] as $day) {
                foreach ($day as $slug) {
                    if ($slug !== null && $slug !== '' && ! in_array($slug, $slugs, true)) {
                        $v->errors()->add('grid', "Unknown subscription {$slug} for this engine.");

                        return;
                    }
                }
            }
        })->validate();

        $grid = array_map(fn (array $day) => array_map(fn (?string $s) => $s ?? '', array_values($day)), array_values($data['grid']));
        SubscriptionSchedule::updateOrCreate(['provider' => $provider], ['grid' => $grid]);

        return $this->state();
    }
}
