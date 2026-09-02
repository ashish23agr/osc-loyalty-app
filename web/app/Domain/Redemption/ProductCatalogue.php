<?php

namespace App\Domain\Redemption;

/**
 * The shop's products, as the exclusion sync needs to see them (V6a).
 *
 * Only the three things that decide eligibility: the product's GID, its tags and
 * the collections it sits in. Deliberately not a general product reader — a
 * wider interface would invite someone to use it for something that should go
 * through the Admin API directly.
 *
 * An interface so the sync can be tested over a known catalogue rather than a
 * live shop, and so the paging lives in one place.
 */
interface ProductCatalogue
{
    /**
     * Every product on the shop, in pages.
     *
     * A generator rather than an array: OSC's catalogue is not enormous, but a
     * sync that loads a whole catalogue into memory is a sync that stops working
     * at some size nobody predicted.
     *
     * @return iterable<array{gid:string, tags:list<string>, collections:list<string>}>
     */
    public function each(string $shopDomain): iterable;
}
