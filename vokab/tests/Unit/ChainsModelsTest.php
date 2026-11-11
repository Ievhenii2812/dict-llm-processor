<?php

namespace Tests\Unit;

use App\Services\Import\AIModelUnavailableException;
use App\Services\Import\AIRateLimitException;
use App\Services\Import\Concerns\ChainsModels;
use PHPUnit\Framework\TestCase;

class ChainsModelsTest extends TestCase
{
    private function chain(): object
    {
        return new class {
            use ChainsModels;

            public int $now = 1000;
            public array $slept = [];
            public array $logs = [];

            public function run(array $models, callable $attempt): ?array
            {
                return $this->askWithFallback($models, $attempt);
            }

            public function parse(string $value, string $default): array
            {
                return self::parseModelList($value, $default);
            }

            protected function aiLog(string $level, string $message, array $context = []): void
            {
                $this->logs[] = [$level, $message];
            }

            protected function aiNow(): int
            {
                return $this->now;
            }

            protected function aiSleep(int $seconds): void
            {
                $this->slept[] = $seconds;
                $this->now += $seconds;
            }
        };
    }

    public function test_parses_comma_separated_model_list(): void
    {
        $chain = $this->chain();

        $this->assertSame(['a', 'b', 'c'], $chain->parse(' a, b ,,c,a ', 'x'));
        $this->assertSame(['x'], $chain->parse('', 'x'));
        $this->assertSame(['only'], $chain->parse('only', 'x'));
    }

    public function test_uses_first_model_when_it_works(): void
    {
        $chain = $this->chain();
        $calls = [];

        $result = $chain->run(['m1', 'm2'], function (string $m) use (&$calls) {
            $calls[] = $m;
            return ['ok' => $m];
        });

        $this->assertSame(['ok' => 'm1'], $result);
        $this->assertSame(['m1'], $calls);
    }

    public function test_exhausted_model_is_dropped_for_the_rest_of_the_run(): void
    {
        $chain = $this->chain();
        $calls = [];

        $attempt = function (string $m) use (&$calls) {
            $calls[] = $m;
            if ($m === 'm1') {
                throw new AIRateLimitException('daily quota');
            }
            return ['ok' => $m];
        };

        $this->assertSame(['ok' => 'm2'], $chain->run(['m1', 'm2'], $attempt));
        $this->assertSame(['ok' => 'm2'], $chain->run(['m1', 'm2'], $attempt));

        $this->assertSame(['m1', 'm2', 'm2'], $calls); // m1 asked only once
    }

    public function test_overloaded_model_is_parked_and_retried_after_cooldown(): void
    {
        $chain = $this->chain();
        $calls = [];
        $m1Overloaded = true;

        $attempt = function (string $m) use (&$calls, &$m1Overloaded) {
            $calls[] = $m;
            if ($m === 'm1' && $m1Overloaded) {
                throw new AIModelUnavailableException('503', retryAfterSeconds: 30);
            }
            return ['ok' => $m];
        };

        $this->assertSame(['ok' => 'm2'], $chain->run(['m1', 'm2'], $attempt));
        $this->assertSame(['ok' => 'm2'], $chain->run(['m1', 'm2'], $attempt)); // still parked, not asked
        $this->assertSame(['m1', 'm2', 'm2'], $calls);

        $m1Overloaded = false;
        $chain->now += 31;

        $this->assertSame(['ok' => 'm1'], $chain->run(['m1', 'm2'], $attempt)); // back in rotation
    }

    public function test_permanently_unavailable_model_is_skipped_for_good(): void
    {
        $chain = $this->chain();
        $calls = [];

        $attempt = function (string $m) use (&$calls) {
            $calls[] = $m;
            if ($m === 'm1') {
                throw new AIModelUnavailableException('404', permanent: true);
            }
            return ['ok' => $m];
        };

        $chain->run(['m1', 'm2'], $attempt);
        $chain->now += 100000;
        $chain->run(['m1', 'm2'], $attempt);

        $this->assertSame(['m1', 'm2', 'm2'], $calls);
    }

    public function test_throws_rate_limit_when_every_model_is_exhausted(): void
    {
        $chain = $this->chain();

        try {
            $chain->run(['m1', 'm2'], fn () => throw new AIRateLimitException('quota'));
            $this->fail('expected AIRateLimitException');
        } catch (AIRateLimitException $e) {
            $this->assertStringContainsString('m1 (quota exhausted)', $e->getMessage());
            $this->assertStringContainsString('m2 (quota exhausted)', $e->getMessage());
        }

        $this->assertSame([], $chain->slept); // nothing to wait for
    }

    public function test_waits_out_overload_for_a_few_rounds_then_gives_up(): void
    {
        $chain = $this->chain();
        $attempts = 0;

        try {
            $chain->run(['m1', 'm2'], function () use (&$attempts) {
                $attempts++;
                throw new AIModelUnavailableException('503', retryAfterSeconds: 30);
            });
            $this->fail('expected AIRateLimitException');
        } catch (AIRateLimitException $e) {
            $this->assertStringContainsString('overloaded', $e->getMessage());
        }

        $this->assertSame(8, $attempts);            // 2 models x 4 rounds
        $this->assertSame([30, 30, 30], $chain->slept);
    }

    public function test_overloaded_model_recovers_during_the_wait(): void
    {
        $chain = $this->chain();
        $attempts = 0;

        $result = $chain->run(['m1'], function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new AIModelUnavailableException('503', retryAfterSeconds: 30);
            }
            return ['ok' => true];
        });

        $this->assertSame(['ok' => true], $result);
        $this->assertSame([30], $chain->slept);
    }

    public function test_null_result_is_returned_without_falling_back(): void
    {
        $chain = $this->chain();
        $calls = [];

        $result = $chain->run(['m1', 'm2'], function (string $m) use (&$calls) {
            $calls[] = $m;
            return null; // invalid JSON etc. stays the caller's retry/split business
        });

        $this->assertNull($result);
        $this->assertSame(['m1'], $calls);
    }
}
