<?php

namespace DevopsToolAppOrchestration\BuildCommand;

interface BuildCommandInterface
{
    /**
     * @param array $options
     */
    public function run(array $options): void;
}
