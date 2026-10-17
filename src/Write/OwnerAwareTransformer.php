<?php

namespace Dynamic\ContentApi\Write;

use SilverStripe\ORM\DataObject;

/**
 * A ValueTransformer that writes a record of its own for the value and needs
 * to finish it once the owner has been written (and so has an ID), e.g. to
 * point it back at the owner. Every write path that applies fields calls
 * WriteApplicator::afterOwnerWrite() right after the owner's write().
 */
interface OwnerAwareTransformer extends ValueTransformer
{
    /**
     * Finish what transform() started for this owner. Called inside the
     * owner's write transaction.
     */
    public function afterOwnerWrite(DataObject $owner): void;
}
