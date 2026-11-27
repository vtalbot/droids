<?php

namespace App\Http\Controllers\Factory\Concerns;

use App\Cloud\CloudNotConnected;
use App\Integrations\NotConnected;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Calls to Laravel Cloud, Cloudflare and Resend, with failures turned into a message the UI shows. */
trait CallsServices
{
    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    protected function attempt(string $service, callable $call): mixed
    {
        try {
            return $call();
        } catch (CloudNotConnected|NotConnected $e) {
            $this->fail($e->getMessage());
        } catch (RequestException|ConnectionException $e) {
            $this->fail($this->explain($service, $e));
        }
    }

    /**
     * The same, but a failure becomes the returned message instead of a 422.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return array{0: T|null, 1: string|null}
     */
    protected function quietly(string $service, callable $call): array
    {
        try {
            return [$call(), null];
        } catch (CloudNotConnected|NotConnected $e) {
            return [null, $e->getMessage()];
        } catch (RequestException|ConnectionException $e) {
            return [null, $this->explain($service, $e)];
        }
    }

    protected function explain(string $service, RequestException|ConnectionException $e): string
    {
        if ($e instanceof ConnectionException) {
            return "Could not reach {$service}.";
        }
        $settings = $service === 'Laravel Cloud' ? 'Settings > Laravel Cloud' : 'Settings > DNS & email';
        $json = $e->response->json();
        $detail = $json['errors'][0]['message'] ?? $this->firstError($json['errors'] ?? null) ?: ($json['message'] ?? null);

        return match ($e->response->status()) {
            401 => "{$service} rejected the API key. Add a new one in {$settings}.",
            403 => "The {$service} key is not allowed to do that. Check its permissions.",
            429 => "{$service} is rate limiting requests. Try again in a minute.",
            default => "{$service}: ".(is_string($detail) && $detail !== '' ? $detail : "error {$e->response->status()}"),
        };
    }

    private function firstError(mixed $errors): string
    {
        foreach (is_array($errors) ? $errors : [] as $messages) {
            $first = is_array($messages) ? ($messages[0] ?? '') : $messages;
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return '';
    }

    protected function fail(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], 422));
    }
}
