<?php

namespace ConductorAppOrchestration\BuildCommand;

interface BuildCommandInterface
{
    /**
     * @param array $options
     */
    public function run(array $options): void;
}
