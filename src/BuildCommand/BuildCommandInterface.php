<?php

namespace DevopsToolAppOrchestration\BuildCommand;

use Psr\Log\LoggerInterface;

interface BuildCommandInterface
{
    /**
     * @param array $options
     *
     * @return self
     */
    public function setOptions(array $options);

    /**
     * @param LoggerInterface $logger
     *
     * @return self
     */
    public function setLogger(LoggerInterface $logger);

    /**
     * @return void
     */
    public function run();
}
