<?php

namespace Omnifilm\Trakt\Tests;

use Omnifilm\Testing\BareTestCase;
use Omnifilm\Trakt\TraktProvider;

/** omnifilm/trakt's classes load with omniscient/omnifilm and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return TraktProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
