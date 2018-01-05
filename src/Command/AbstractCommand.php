<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\ApplicationConfig;
use DevopsToolAppOrchestration\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

abstract class AbstractCommand extends Command implements ApplicationConfigAwareInterface
{
    /**
     * @var ApplicationConfig[]
     */
    protected $applicationConfig;

    /**
     * @param InputInterface $input
     *
     * @return ApplicationConfig[]
     * @throws Exception\RuntimeException if --app not given and there is more than one app specified in configuration
     */
    protected function getApplications(InputInterface $input)
    {
        if ($input->hasOption('all') && $input->getOption('all')) {
            return $this->applicationConfig;
        }

        if ($input->hasOption('app') && $input->getOption('app')) {
            $invalidApplicationCodes = array_diff($input->getOption('app'), array_keys($this->applicationConfig));
            if ($invalidApplicationCodes) {
                throw new Exception\DomainException(
                    'Invalid application code(s) passed: ' . implode(', ', $invalidApplicationCodes)
                );
            }

            return array_intersect_key($this->applicationConfig, array_flip($input->getOption('app')));
        }

        // Special case if app not specified and only one app exists, return that app
        if (count($this->applicationConfig) == 1) {
            return $this->applicationConfig;
        }

        $message
            = "Must specify --app since there is more than one app specified in configuration.\n\nConfigured applications:\n";
        foreach ($this->applicationConfig as $appCode => $app) {
            $message .= "$appCode\n";
        }
        throw new Exception\RuntimeException($message);
    }

    /**
     * @param array $applicationConfig
     */
    public function setApplicationConfig(array $applicationConfig)
    {
        $this->applicationConfig = [];
        foreach ($applicationConfig as $code => $application) {
            $this->applicationConfig[$code] = new ApplicationConfig($application);
        }
    }
}
