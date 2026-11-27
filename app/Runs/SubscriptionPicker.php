<?php

namespace App\Runs;

use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use Illuminate\Support\Carbon;

/**
 * Chooses which subscription an engine draws from right now: the one painted
 * in the schedule for this hour, or another of the same engine when that one
 * has hit its usage limit. Paused hours start no new task runs; a question
 * someone is waiting on still gets an answer from any free subscription.
 */
class SubscriptionPicker
{
    /**
     * @param  list<string>|null  $signedIn  slugs the worker has a CLI login for; null skips the check
     * @param  bool  $anyHour  ignore paused hours, for conversations someone is waiting on
     */
    public function pick(string $provider, ?array $signedIn = null, ?Carbon $at = null, bool $anyHour = false): ?Subscription
    {
        $scheduled = $this->scheduledSlug($provider, $at);
        if ($scheduled === null && ! $anyHour) {
            return null;
        }

        $subs = Subscription::where('provider', $provider)->where('enabled', true)->orderBy('id')->get();

        return $subs->sortBy(fn (Subscription $s) => $s->slug === $scheduled ? 0 : 1)
            ->first(fn (Subscription $s) => ! $s->isLimited() && $this->reachable($s, $signedIn));
    }

    /**
     * Why nothing can run for this engine right now, in words for the person,
     * or null when a subscription is free.
     *
     * @param  list<string>|null  $signedIn  slugs the online workers have a CLI login for
     */
    public function blocker(string $provider, ?array $signedIn, bool $anyHour = false): ?string
    {
        if ($this->pick($provider, $signedIn, null, $anyHour)) {
            return null;
        }
        $engine = $provider === 'claude' ? 'Claude' : 'Codex';
        $subs = Subscription::where('provider', $provider)->where('enabled', true)->get();
        if ($subs->isEmpty()) {
            return "No {$engine} subscription is set up. Add one on the Subscriptions page.";
        }
        if (! $anyHour && $this->scheduledSlug($provider) === null) {
            return "{$engine} is paused this hour on the Subscriptions schedule.";
        }
        if ($subs->every(fn (Subscription $s) => $s->isLimited())) {
            return "Every {$engine} subscription is at its usage limit.";
        }
        $slugs = $subs->filter(fn (Subscription $s) => ! $s->isLimited())->pluck('slug')->implode(', ');

        return "Your worker isn't signed in to a free {$engine} subscription ({$slugs}). Sign in to one as in step 4 of docs/worker.md, then restart the worker.";
    }

    /** @param  list<string>|null  $signedIn */
    private function reachable(Subscription $s, ?array $signedIn): bool
    {
        return $s->usesApiKey() || $signedIn === null || in_array($s->slug, $signedIn, true);
    }

    /** The slug painted for this hour, or null when the hour is paused or nothing is painted. */
    public function scheduledSlug(string $provider, ?Carbon $at = null): ?string
    {
        $at = ($at ?? now())->copy()->setTimezone((string) config('droids.timezone'));
        $grid = SubscriptionSchedule::where('provider', $provider)->value('grid');
        $grid = is_string($grid) ? json_decode($grid, true) : $grid;
        $slug = $grid[$at->dayOfWeekIso - 1][$at->hour] ?? '';

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
