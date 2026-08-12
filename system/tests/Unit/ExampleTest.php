<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_that_true_is_true(): void
    {
        $this->assertTrue(true);
    }

    public function test_norwegian_pagination_labels_are_available(): void
    {
        $this->assertSame('Forrige', __('pagination.previous'));
        $this->assertSame('Neste', __('pagination.next'));
    }
}
