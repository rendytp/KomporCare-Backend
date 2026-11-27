<?php

namespace Tests\Unit;

use App\Services\TechnicianMatcher;
use PHPUnit\Framework\TestCase;

class TechnicianMatcherTest extends TestCase
{
    public function test_rejections_reduce_score_without_double_counting_the_same_event(): void
    {
        $this->assertSame(70.0, TechnicianMatcher::applyRejectionPenalty(85, 1, 15));
        $this->assertSame(70.0, TechnicianMatcher::applyRejectionPenalty(85, 1, 15));
    }

    public function test_rejection_penalty_is_capped_at_zero_and_negative_counts_are_ignored(): void
    {
        $this->assertSame(0.0, TechnicianMatcher::applyRejectionPenalty(10, 2, 15));
        $this->assertSame(85.0, TechnicianMatcher::applyRejectionPenalty(85, -1, 15));
    }
}
