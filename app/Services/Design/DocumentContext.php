<?php

namespace App\Services\Design;

use Closure;

/**
 * What the sanitiser needs to know about where a document is being saved.
 *
 * `allowedAssets` receives every asset id the document references and returns the subset the saving user may place in
 * this project (ownership and workspace visibility are decided by the caller, never by the document). With no
 * resolver, image references are structurally validated only (reads).
 * `strict` false is the read path: nothing throws, invalid elements are dropped and counted instead.
 */
final class DocumentContext
{
    /**
     * @param  (Closure(list<int>): list<int>)|null  $allowedAssets
     */
    public function __construct(
        public readonly ?Closure $allowedAssets = null,
        public readonly bool $strict = true,
    ) {}

    public static function lenient(): self
    {
        return new self(null, false);
    }
}
