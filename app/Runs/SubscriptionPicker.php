<?php

namespace App\Runs;

use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use Illuminate\Support\Carbon;

/**
 * Chooses which subscription an engine draws from right now: the one painted
 * in the schedule for this hour, or another of the same engine when that one
 * has hit its usage limit. Paused hours start nothing new.
 */
class SubscriptionPicker
{
    /**
     * @param  list<string>|null  $signedIn  slugs the worker has a CLI login for; null skips the check
     */
    public function pick(string $provider, ?array $signedIn = null, ?Carbon $at = null): ?Subscription
    {
        $scheduled = $this->scheduledSlug($provider, $at);
        if ($scheduled === null) {
            return null;
        }

        $subs = Subscription::where('provider', $provider)->where('enabled', true)->orderBy('id')->get();

        return $subs->sortBy(fn (Subscription $s) => $s->slug === $scheduled ? 0 : 1)
            ->first(fn (Subscription $s) => ! $s->isLimited()
                && ($s->usesApiKey() || $signedIn === null || in_array($s->slug, $signedIn, true)));
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
