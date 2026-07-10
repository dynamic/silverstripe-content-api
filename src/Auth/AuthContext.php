<?php

namespace Dynamic\ContentApi\Auth;

use SilverStripe\Security\Member;

/**
 * The resolved authentication state for a single API request.
 */
readonly class AuthContext
{
    public function __construct(
        public Member $member,
        public int $expires,
    ) {
    }
}
