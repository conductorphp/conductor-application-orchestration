<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\AppConfig;
use DevopsToolAppOrchestration\AppSetupRepository;
use DevopsToolCore\Database\DatabaseAdapter;
use DevopsToolAppOrchestration\Exception\DomainException;
use DevopsToolCore\ShellCommandHelper;
use DevopsToolCore\Database\DatabaseConfig;
use DevopsToolCore\Database\ImportExportAdapter\DatabaseImportExportAdapterInterface;
use DevopsToolCore\Database\ImportExportAdapter\MydumperDatabaseAdapter;
use DevopsToolCore\Database\ImportExportAdapter\MysqldumpDatabaseAdapter;
use DevopsToolCore\Database\ImportExportAdapter\MysqlTabDelimitedDatabaseAdapter;
use Exception;
use GitElephant\Repository;
use LogicException;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractCommand extends Command implements AppSetupConfigAwareInterface
{
    /**
     * @var array
     */
    private $config;

    /**
     * @var string
     */
    private $appConfigFile;

    /**
     * @var string
     */
    private $environment;

    /**
     * @var string
     */
    private $role;

    /**
     * @var array
     */
    private $apps;

    /**
     * Constructor.
     *
     * @param string|null $name The name of the command; passing null means it must be set in configure()
     * @param string|null $appConfigFile
     *
     * @throws LogicException When the command name is empty
     */
    public function __construct($name = null, $appConfigFile = null)
    {
        if (is_null($appConfigFile)) {
            $appConfigFile = getenv("HOME") . '/.devops/app-setup.yaml';
        }

        $this->appConfigFile = $appConfigFile;
        parent::__construct($name);
    }

    /**
     * @throws Exception
     */
    protected function parseConfigFile()
    {
        if (!is_readable($this->appConfigFile)) {
            throw new Exception("App configuration file \"$this->appConfigFile\" does not exist.");
        }

        $config = Yaml::parse(file_get_contents($this->appConfigFile), true);
        if (!$config) {
            throw new Exception("App configuration file \"{$this->appConfigFile}\" is empty.");
        }

        if (!(isset($config['environment']) && isset($config['role']) && isset($config['apps']))) {
            throw new Exception(
                "App configuration file \"{$this->appConfigFile}\" must define \"environment\", \"role\", and \"apps\"."
            );
        }

        $this->environment = $config['environment'];
        $this->role = $config['role'];
        $this->apps = $config['apps'];
    }

    /**
     * @param AppSetupRepository $repo
     * @param string             $appId
     *
     * @return AppConfig
     */
    protected function getMergedAppConfig(AppSetupRepository $repo, $appId)
    {
        $app = $this->getApp($appId);

        // Merge appConfig on top of repo config to allow for local overrides, but unset known 
        // conflicting keys.
        unset($app['repo_url']);
        $config = array_merge($repo->getConfig(), $app);
        return new AppConfig($config);
    }

    /**
     * @param string $appId
     *
     * @return AppSetupRepository
     * @throws Exception
     */
    protected function getRepo($appId)
    {
        $app = $this->getApp($appId);
        if (empty($app['repo_url'])) {
            throw new Exception("App \"$appId\" must define repo_url in ~/.devops/app-setup.yaml");
        }

        $repoUrl = $app['repo_url'];
        $environment = $this->environment;
        $role = $this->role;

        $repo = Repository::createFromRemote($repoUrl);
        return new AppSetupRepository($repo, $environment, $role, $this->config);
    }

    /**
     * @param InputInterface $input
     *
     * @return array
     * @throws Exception if --app or --all not given and there is more than one app specified in ~/.devops/app-setup.yaml
     */
    protected function getAppIds(InputInterface $input)
    {
        if ($input->hasOption('all') && $input->getOption('all')) {
            $appIds = array_keys($this->apps);
        } else {
            $appId = $this->getAppId($input);
            $appIds = [$appId];
        }
        return $appIds;
    }

    /**
     * @param InputInterface $input
     *
     * @return string
     * @throws Exception if --app not given and there is more than one app specified in ~/.devops/app-setup.yaml
     */
    protected function getAppId(InputInterface $input)
    {
        if ($input->hasOption('app') && $input->getOption('app')) {
            $appId = $this->getAppIds($input)[0];
        } else {
            if (count($this->apps) == 1) {
                $keys = array_keys($this->apps);
                $appId = reset($keys);
            } else {
                $message
                    = "Must specify --app since there is more than one app specified in \"{$this->appConfigFile}\".\nConfigured applications:\n";
                foreach ($this->apps as $appCode => $app) {
                    $message .= "$appCode\n";
                }
                throw new Exception($message);
            }
        }
        return $appId;
    }

    /**
     * @param string $appId
     *
     * @return array
     * @throws Exception
     */
    protected function getApp($appId)
    {
        if (!isset($this->apps[$appId])) {
            throw new Exception("App config not found for app \"$appId\" in \"{$this->appConfigFile}\".");
        }

        $app = $this->apps[$appId];
        if (empty($app['repo_url'])) {
            throw new Exception(
                "App config at \"{$this->appConfigFile}\" for app \"$appId\" must have \"repo_url\" defined."
            );
        }
        return $app;
    }

    /**
     * @param AppConfig $config
     *
     * @return DatabaseAdapter
     */
    protected function getDatabaseAdapter(AppConfig $config)
    {
        $host = $config->getMySqlHost();
        $port = $config->getMySqlPort();
        $user = $config->getMySqlUser();
        $password = $config->getMySqlPassword();
        $options = [];

        if ($config->getMySqlSslCa()) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $config->getMySqlSslCa();
        }

        if (!is_null($config->getMySqlSslVerifyPeer())) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $config->getMySqlSslVerifyPeer();
        }

        $pdo = new PDO("mysql:host=$host;port=$port;charset=UTF8;", $user, $password, $options);
        return new DatabaseAdapter($pdo);
    }

    /**
     * @param string             $format
     * @param AppConfig          $config
     * @param ShellCommandHelper $shellCommandHelper
     * @param LoggerInterface    $logger
     *
     * @return MydumperDatabaseAdapter|MysqldumpDatabaseAdapter|MysqlTabDelimitedDatabaseAdapter
     */
    protected function getImportExportDatabaseAdapter(
        $format,
        AppConfig $config,
        ShellCommandHelper $shellCommandHelper,
        LoggerInterface $logger
    ) {
        $databaseConnectionConfig = null;
        if ($config->getMySqlUser() && $config->getMySqlPassword()) {
            $databaseConnectionConfig = new DatabaseConfig(
                $config->getMySqlUser(),
                $config->getMySqlPassword(),
                $config->getMySqlHost(),
                $config->getMySqlPort()
            );
        }
        switch ($format) {
            case DatabaseImportExportAdapterInterface::FORMAT_MYDUMPER:
                $databaseAdapter = new MydumperDatabaseAdapter($databaseConnectionConfig, $shellCommandHelper, $logger);
                break;

            case DatabaseImportExportAdapterInterface::FORMAT_SQL:
                $databaseAdapter = new MysqldumpDatabaseAdapter(
                    $databaseConnectionConfig, $shellCommandHelper, $logger
                );
                break;

            case DatabaseImportExportAdapterInterface::FORMAT_TAB_DELIMITED:
                $databaseAdapter = new MysqlTabDelimitedDatabaseAdapter(
                    $databaseConnectionConfig,
                    $shellCommandHelper,
                    $logger
                );
                break;

            default:
                throw new DomainException(
                    sprintf(
                        'Invalid format "%s" specified.',
                        $format
                    )
                );
        }
        return $databaseAdapter;
    }

    public function setAppSetupConfig(array $config)
    {
        $this->config = $config;
    }
}
