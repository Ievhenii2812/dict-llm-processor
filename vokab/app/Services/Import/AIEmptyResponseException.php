<?php

namespace App\Services\Import;

/**
 * A model answered HTTP 200 but returned no usable text and the cause does not
 * look deterministic (no safety block, no token limit): usually a transient
 * provider glitch. ChainsModels lets the next model try the same request,
 * without parking or dropping the model that returned nothing.
 */
class AIEmptyResponseException extends \RuntimeException
{
}
