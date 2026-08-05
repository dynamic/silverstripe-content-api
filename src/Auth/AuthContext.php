<?php

namespace Dynamic\ContentApi\Auth;

use SilverStripe\Security\Member;

/**
 * The resolved authentication state for a single API request.
 */
class AuthContext
{
    public function __construct(
        public readonly Member $member,
        public readonly int $expires,
    ) {
    }
}
