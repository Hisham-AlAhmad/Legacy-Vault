<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mark requests as coming from the SPA frontend so Sanctum treats them as
        // stateful (cookie/session) requests — matches SANCTUM_STATEFUL_DOMAINS in
        // phpunit.xml (docs/05_Security_Architecture.md §3).
        $this->withHeader('Referer', 'http://localhost:5173');
    }
}
