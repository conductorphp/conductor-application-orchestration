<?php

namespace DevopsToolAppOrchestration\Command;

interface ApplicationOrchestrationConfigAwareInterface
{
    public function setApplicationOrchestrationConfig(array $config);
}
