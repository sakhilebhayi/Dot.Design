<?php

namespace App\Services\Design;

use RuntimeException;

/**
 * A structural rule about pages was hit (too many pages, deleting the last one).
 */
final class PageLimitReached extends RuntimeException {}
