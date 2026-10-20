<?php

return [
    /*
     * The URL prefix for the admin panel. Kept out of the obvious "/admin"
     * path on purpose — same idea as Xboard's random per-install admin
     * slug — so the backend entry point isn't guessable from the outside.
     * Change this in .env (ADMIN_PANEL_PATH) per install — the real per-install
     * value lives only in .env (never committed), this default is a placeholder.
     */
    'path' => env('ADMIN_PANEL_PATH', 'admin'),

    // Every backend session must originate from the dedicated admin login.
    // This prevents customer login and magic-link sessions from bypassing 2FA.
    'secure_session' => env('ADMIN_SECURE_SESSION', true),

    // Accounts without 2FA may only open the admin profile to finish setup.
    'require_two_factor' => env('ADMIN_REQUIRE_TWO_FACTOR', true),

    'two_factor_pending_ttl' => (int) env('ADMIN_2FA_PENDING_TTL', 300),
    'verified_session_ttl' => (int) env('ADMIN_VERIFIED_SESSION_TTL', 43200),
    'sensitive_session_ttl' => (int) env('ADMIN_SENSITIVE_SESSION_TTL', 900),

    // Opening these sections after the short verification window forces a
    // fresh password + 2FA login before destructive or financial operations.
    'sensitive_routes' => [
        'admin.system',
        'admin.plugins',
        'admin.payments',
        'admin.servers',
        'admin.groups',
        'admin.routing',
        'admin.plans',
        'admin.orders',
        'admin.coupons',
        'admin.giftcards',
        'admin.tenants',
        'admin.users',
        'admin.invites',
    ],
];
