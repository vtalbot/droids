<?php

namespace App\Models;

use App\Http\Controllers\Factory\DroidController;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A model a worker found one of its CLI logins can use.
 *
 * @property int $id
 * @property string $provider
 * @property string $model_id
 * @property string $label
 * @property Carbon $seen_at
 */
class EngineModel extends Model
{
    /** A model no worker has seen for this long drops off the pickers. */
    public const FORGET_AFTER_DAYS = 14;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    /**
     * The models to offer per engine: the ones Droids started with, then the
     * ones workers found, newest version first.
     *
     * @return array<string, list<string>>
     */
    public static function labels(): array
    {
        $out = DroidController::MODELS;
        foreach (self::recent()->sortByDesc('label', SORT_NATURAL)->groupBy('provider') as $provider => $models) {
            if (isset($out[$provider])) {
                $found = $models->pluck('label')->all();
                $out[$provider] = array_values(array_unique([...$found, ...$out[$provider]]));
            }
        }

        return $out;
    }

    /** The id the CLI takes for a model label, when a worker found it. */
    public static function cliId(string $provider, string $label): ?string
    {
        return self::where('provider', $provider)->where('label', $label)
            ->where('seen_at', '>', now()->subDays(self::FORGET_AFTER_DAYS))->value('model_id');
    }

    /** @return Collection<int, self> */
    private static function recent(): Collection
    {
        return self::where('seen_at', '>', now()->subDays(self::FORGET_AFTER_DAYS))->get();
    }
}
