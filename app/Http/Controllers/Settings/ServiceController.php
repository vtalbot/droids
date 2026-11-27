<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Integrations\Cloudflare;
use App\Models\ServiceKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Settings > DNS: the Cloudflare API token projects manage their DNS records with. */
class ServiceController extends Controller
{
    private const NAMES = ['cloudflare' => 'Cloudflare'];

    public function edit(): Response
    {
        $keys = [];
        foreach (ServiceKey::SERVICES as $service) {
            $key = ServiceKey::for($service);
            $keys[$service] = $key ? ['label' => $key->label, 'since' => $key->created_at?->toFormattedDateString()] : null;
        }

        return Inertia::render('settings/services', ['keys' => $keys]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service' => ['required', Rule::in(ServiceKey::SERVICES)],
            'token' => ['required', 'string', 'max:2000'],
        ]);
        $token = trim($data['token']);
        $name = self::NAMES[$data['service']];

        try {
            $label = $this->cloudflare($token);
        } catch (RequestException $e) {
            throw ValidationException::withMessages(['token' => in_array($e->response->status(), [400, 401, 403], true)
                ? "{$name} did not accept this key. Check that you copied all of it and that it has the permissions listed below."
                : "{$name} answered with error {$e->response->status()}."]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['token' => "Could not reach {$name}. Try again."]);
        }

        ServiceKey::updateOrCreate(['service' => $data['service']], ['token' => $token, 'label' => $label]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$name} connected."]);

        return to_route('services.edit');
    }

    public function destroy(string $service): RedirectResponse
    {
        abort_unless(in_array($service, ServiceKey::SERVICES, true), 404);
        ServiceKey::where('service', $service)->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => self::NAMES[$service].' disconnected.']);

        return to_route('services.edit');
    }

    private function cloudflare(string $token): string
    {
        if (! (new Cloudflare($token))->verify()) {
            throw ValidationException::withMessages(['token' => 'This Cloudflare token is not active.']);
        }

        return 'API token';
    }
}
