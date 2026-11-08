<?php

use PHPUnit\Framework\TestCase;
use RsMachine\TagSync;

/**
 * Which tags cleanup.php copies between bills with the same summary.
 */
class TagSyncTest extends TestCase
{
    public function testAnUntaggedBillGetsItsTwinsTags(): void
    {
        $this->assertSame([2 => ['fees', 'healthcare']], TagSync::missing([1 => ['fees', 'healthcare'], 2 => []]));
        $this->assertSame([1 => ['fees', 'healthcare']], TagSync::missing([1 => [], 2 => ['fees', 'healthcare']]));
    }

    public function testDifferentTagsAreCombined(): void
    {
        $this->assertSame(
            [1 => ['penalties'], 2 => ['fees']],
            TagSync::missing([1 => ['fees', 'regulation'], 2 => ['penalties', 'regulation']])
        );
    }

    public function testIdenticalTagsNeedNothing(): void
    {
        $this->assertSame([], TagSync::missing([1 => ['fees'], 2 => ['fees']]));
        $this->assertSame([], TagSync::missing([1 => [], 2 => []]));
    }

    /**
     * Three identical bills once had the third bill's own tags inserted again on every run.
     */
    public function testThreeOrMoreBillsAreSynchronized(): void
    {
        $this->assertSame([], TagSync::missing([1 => ['fees'], 2 => ['fees'], 3 => ['fees']]));
        $this->assertSame(
            [2 => ['healthcare'], 3 => ['fees', 'healthcare']],
            TagSync::missing([1 => ['fees', 'healthcare'], 2 => ['fees'], 3 => []])
        );
    }

    /**
     * The tags table's unique key ignores case and trailing spaces, so these are the same tag.
     */
    public function testTagsDifferingInCaseOrTrailingSpaceAreTheSame(): void
    {
        $this->assertSame([], TagSync::missing([1 => ['Assisted Living'], 2 => ['assisted living ']]));
    }

    public function testATagListedTwiceIsAddedOnce(): void
    {
        $this->assertSame([2 => ['fees']], TagSync::missing([1 => ['fees', 'fees'], 2 => []]));
    }

    public function testALoneBillNeedsNothing(): void
    {
        $this->assertSame([], TagSync::missing([1 => ['fees']]));
        $this->assertSame([], TagSync::missing([]));
    }
}
