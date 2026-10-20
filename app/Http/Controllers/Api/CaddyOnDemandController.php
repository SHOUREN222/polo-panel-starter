<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Caddy on-demand TLS gate.
 *
 * Caddy asks this endpoint before it will issue a certificate for a host it
 * sees for the first time (`on_demand_tls { ask … }`). We return 200 only for
 * the main site's own domains and for subdomains of a 分站 we actually opened
 * in the admin panel — everything else gets 403 so nobody can point a random
 * `*.example.test` name at us and burn our ACME rate limit / cert quota.
 *
 * Runs on the central app (the api group is not tenancy-aware); the Domain
 * lookup reads the central `domains` table. Fails closed: any unrecognized
 * host, and any throttle/error, results in no certificate being signed.
 */
class CaddyOnDemandController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $domain = strtolower(trim((string) $request->query('domain', '')));

        if ($domain === '') {
            return response('missing domain', 400);
        }

        $centralDomains = array_map('strtolower', config('tenancy.central_domains', []));

        // The main site's own domains (app.example.test, …) — always allowed.
        if (in_array($domain, $centralDomains, true)) {
            return response('ok', 200);
        }

        // Otherwise it must be `<label>.<central-parent>` where <label> is a
        // single segment that maps to a 分站 we registered (its Domain row).
        foreach ($centralDomains as $parent) {
            $suffix = '.'.$parent;
            if (! str_ends_with($domain, $suffix)) {
                continue;
            }
            $label = substr($domain, 0, -strlen($suffix));
            if ($label !== '' && ! str_contains($label, '.') && Domain::where('domain', $label)->exists()) {
                return response('ok', 200);
            }
        }

        return response('unknown domain', 403);
    }
}
