<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * Thrown when AI Studio returns HTTP 429 — signals the caller (command)
 * should stop the run cleanly rather than keep retrying into more 429s.
 */
class GeminiRateLimitException extends RuntimeException {}