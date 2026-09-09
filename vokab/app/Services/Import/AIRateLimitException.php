<?php

namespace App\Services\Import;

/**
 * Thrown by any AIProviderInterface implementation on a confirmed 429 —
 * signals the caller (command) should stop the run cleanly rather than
 * keep retrying into more 429s. Shared across Gemini/Mistral/Groq so
 * commands only need one catch block regardless of active provider.
 */
class AIRateLimitException extends \RuntimeException {}
