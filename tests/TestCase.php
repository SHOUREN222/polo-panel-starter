<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite used to run on the array cache store. It is the one driver that
     * supports tags — so it stayed green for weeks while every Cache:: call in
     * a 分站 threw "This cache store does not support tagging" in production,
     * white-screening the 套餐 page. Tests now run on `file`, as production
     * does. See TenantCacheTest.
     *
     * The array store starts empty each test; a file store does not, so it has
     * to be cleared by hand or leftover rate-limit counters make unrelated
     * tests 429.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();

        // Most feature tests exercise an already-authenticated component in
        // isolation. Dedicated admin-auth hardening tests turn these back on
        // and cover the real production gate end to end.
        config([
            'admin.secure_session' => false,
            'admin.require_two_factor' => false,
        ]);
    }
}
