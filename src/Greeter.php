<?php

declare(strict_types=1);

namespace Demo\Pipeline;

final class Greeter
{
    public function greet(string $name): string
    {
        return sprintf('Hello, %s!', $name);
    }

    public function farewell(string $name): string
    {
        return sprintf('Goodbye, %s!', $name);
    }
}
