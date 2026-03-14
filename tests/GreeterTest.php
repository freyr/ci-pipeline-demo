<?php

declare(strict_types=1);

namespace Demo\Pipeline\Tests;

use Demo\Pipeline\Greeter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GreeterTest extends TestCase
{
    #[Test]
    public function itGreetsByName(): void
    {
        $greeter = new Greeter();

        $result = $greeter->greet('World');

        self::assertSame('Hello, World!', $result);
    }
}
