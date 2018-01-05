<?php

namespace DevopsToolAppOrchestration\Command;

interface ApplicationConfigAwareInterface
{
    public function setApplicationConfig(array $config);
}
