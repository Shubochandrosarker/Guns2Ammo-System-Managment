<?php

namespace G2A\POS\Wholesalers;

/**
 * Thrown when a sync item must resolve a wholesaler by provider code alone
 * but multiple accounts exist for that code. Callers abort the item loudly
 * instead of guessing an account (which would mirror one account's feed
 * into another account's catalog).
 */
final class AmbiguousWholesalerAccountException extends \RuntimeException {
}
