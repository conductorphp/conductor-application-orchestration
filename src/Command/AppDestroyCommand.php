<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\AppDestroy;
use DevopsToolCore\MonologConsoleHandler;
use Monolog\Logger;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class AppDestroyCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('app:destroy')
            ->setDescription('Destroy application.')
            ->setHelp("This command destroys an application based on configuration in a given application setup repo.")
            ->addOption(
                'app',
                null,
                InputOption::VALUE_OPTIONAL,
                'App id if you want to pull repo_url and environment from ~/.devops/app-setup.yaml'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh assets for all apps in ~/.devops/app-setup.yaml')
            ->addOption(
                'branch',
                null,
                InputArgument::OPTIONAL,
                'The branch instance to destroy. Only relevant when using the \"branch\" file layout.'
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln(
            [
                'App: Destroy',
                '============',
                '',
            ]
        );

        $logger = new Logger('app:destroy');
        $logger->pushHandler(new MonologConsoleHandler($output));
        $this->parseConfigFile();

        $appIds = $this->getAppIds($input);

        foreach ($appIds as $appId) {
            $repo = $this->getRepo($appId);
            $config = $this->getMergedAppConfig($repo, $appId);
            $branch = $input->getOption('branch') ? $input->getOption('branch') : $config->getDefaultBranch();
            $appName = $config->getAppName();

            $destroyBranchOnly = !empty($branch);
            $destroyDescription = ($destroyBranchOnly ? "branch \"$branch\" of " : '') . "application \"$appName\"";
            if (!$input->getOption('force')) {
                /** @var QuestionHelper $helper */
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion(
                    "Are you sure you want to destroy $destroyDescription? [y/N] ",
                    false
                );

                if (!$helper->ask($input, $output, $question)) {
                    return 0;
                }
            }

            $databaseAdapter = null;
            $databases = array_keys($config->getDatabases());
            if ($databases) {
                $databaseAdapter = $this->getDatabaseAdapter($config);
            }
            $appDestroy = new AppDestroy(
                $repo,
                $config->getAppRoot(),
                $config->getFileLayout(),
                $branch,
                $databases,
                $databaseAdapter,
                null,
                $logger
            );

            $output->writeln("Destroying $destroyDescription ...");
            $appDestroy->destroy($destroyBranchOnly);
            $output->writeln('<info>' . ucfirst($destroyDescription) . ' destroyed!</info>');
        }
        return 0;
    }

}
