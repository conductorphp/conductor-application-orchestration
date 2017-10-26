<?php

namespace DevopsToolAppOrchestration\BuildCommand;

use MatthiasMullie\Minify;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class MinifyCssCommand implements BuildCommandInterface
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
     * MinifyCssCommand constructor.
     */
    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function setOptions(array $options)
    {
        $this->paths = !empty($options['paths']) ? $options['paths'] : ['./'];
        $this->excludes = !empty($options['excludes']) ? $options['excludes'] : [];
        return $this;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        return $this;
    }

    public function run()
    {
        $this->logger->error(__METHOD__ . ' not yet implemented. Skipping...');
//        return;
        foreach ($this->paths as $path) {
            $dirIterator = new RecursiveDirectoryIterator($path);
            $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!($file->isFile() && 'css' == $file->getExtension())) {
                    continue;
                }

                $filename = $file->getPath() . '/' . $file->getFilename();
                if ($this->excludes && in_array($filename, $this->excludes)) {
                    continue;
                }

                $filename = $file->getPath() . '/' . $file->getFilename();
                $minifiedFilename = substr($filename, 0, -3) . '.min.css';
                if (file_exists($minifiedFilename)) {
                    copy($minifiedFilename, $filename);
                    echo "Minified $filename by replacing with $minifiedFilename\n";
                } else {
                    $minifier = new Minify\CSS($filename);
                    $minifier->minify($filename);
                    echo "Minified $filename\n";
                }

                // Append newline to the end of all css files
                file_put_contents($filename, "\n", FILE_APPEND);
            }
        }
    }
}
