<?php

namespace App\Services;

use RuntimeException;

/** An export we decline to produce; the message is shown to the user and points at what to do instead. */
class ExportRefused extends RuntimeException {}
