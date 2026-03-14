<?php

declare(strict_types=1);

namespace Demo\Pipeline;

final class Runner
{
    public function __construct(
        private readonly Greeter $greeter,
    ) {
    }

    public function run(string $name): string
    {
        return $this->greeter->greet($name);
    }
}
