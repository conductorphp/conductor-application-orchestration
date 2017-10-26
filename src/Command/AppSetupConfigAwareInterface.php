<?php

namespace DevopsToolAppOrchestration\Command;

interface AppSetupConfigAwareInterface
{
    public function setAppSetupConfig(array $config);
}
