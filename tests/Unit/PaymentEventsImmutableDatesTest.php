<?php

declare(strict_types=1);

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Runs every test of PaymentEventsTest with immutable dates, as an Application that calls
 * Date::use(CarbonImmutable::class) does (the Laravel starter kit default).
 */
class PaymentEventsImmutableDatesTest extends PaymentEventsTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Date::use(CarbonImmutable::class);
    }

    protected function tearDown(): void
    {
        Date::useDefault();

        parent::tearDown();
    }
}
