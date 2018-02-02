<?php

namespace DevopsToolAppOrchestration\BuildCommand;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class MinifyJsCommand implements BuildCommandInterface, LoggerAwareInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var array
     */
    private $paths;
    /**
     * @var array
     */
    private $excludes;

    /**
     * MinifyJsCommand constructor.
     */
    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function run(array $options = null): void
    {
        $this->logger->error(__METHOD__ . ' not yet implemented. Skipping...');
        $this->paths = $options['paths'] ?? ['./'];
        $this->excludes = $options['excludes'] ?? [];

        return;
        foreach ($this->paths as $path) {
            $dirIterator = new RecursiveDirectoryIterator($path);
            $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!($file->isFile() && 'js' == $file->getExtension())) {
                    continue;
                }

                $filename = $file->getPath() . '/' . $file->getFilename();
                if ($this->excludes && in_array($filename, $this->excludes)) {
                    continue;
                }

                $filename = $file->getPath() . '/' . $file->getFilename();
                $minifiedFilename = substr($filename, 0, -3) . '.min.js';
                if (file_exists($minifiedFilename)) {
                    copy($minifiedFilename, $filename);
                    echo "Minified $filename by replacing with $minifiedFilename\n";
                } else {
                    $minifier = new Minify\JS($filename);
                    $minifier->minify($filename);
                    echo "Minified $filename\n";
                }

                // Append semicolon and newline to the end of all js files
                // @see http://stackoverflow.com/questions/23370269/jquery-autosize-plugin-error-intermediate-value-is-not-a-function
                file_put_contents($filename, ";\n", FILE_APPEND);
            }
        }
    }
}
