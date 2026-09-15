<?php

declare(strict_types=1);

namespace ConductorAppOrchestrationTest\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Deploy\ApplicationSkeletonDeployer;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use PHPUnit\Framework\TestCase;
use Twig\Error\RuntimeError;

use function base64_encode;
use function chunk_split;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * CTAP-1724. Skeleton files render the values they are given, verbatim.
 *
 * Two of these are about what a rendered secret looks like. Twig autoescaped skeleton output as
 * HTML, so a password with an apostrophe came out of `var_export` as `&#039;` and produced a PHP
 * file that did not parse. Skeleton files are never HTML. And a PEM key travels as one base64
 * environment variable, decoded in the template, and has to come out byte for byte.
 */
class ApplicationSkeletonDeployerTest extends TestCase
{
    private string $appRoot;
    private string $sourcePath;

    protected function setUp(): void
    {
        $base             = sys_get_temp_dir() . '/conductor-skeleton-' . uniqid();
        $this->appRoot    = $base . '/app';
        $this->sourcePath = $base . '/files';
        foreach ([$this->appRoot, $this->sourcePath] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
    }

    protected function tearDown(): void
    {
        (new LocalShellAdapter())->runShellCommand('rm -rf ' . escapeshellarg(dirname($this->appRoot)));
    }

    /** @param array<string, mixed> $templateVars */
    private function deploy(string $template, array $templateVars, string $target = 'config/autoload/rendered.php'): string
    {
        file_put_contents($this->sourcePath . '/rendered.twig', $template);

        $config = new ApplicationConfig([
            'app_name'          => 'Test App',
            'app_root'          => $this->appRoot,
            'repo_url'          => 'git@example.test:test/app.git',
            'environment'       => 'production',
            'source_file_paths' => [$this->sourcePath],
            'skeleton'          => [
                'files' => [
                    $target => [
                        'location'      => 'code',
                        'source'        => 'rendered.twig',
                        'mode'          => '0640',
                        'template_vars' => $templateVars,
                    ],
                ],
            ],
        ]);

        (new ApplicationSkeletonDeployer($config, new LocalShellAdapter()))->deploySkeleton();

        return (string) file_get_contents($this->appRoot . '/' . $target);
    }

    public function testValuesAreWrittenVerbatimNotHtmlEscaped(): void
    {
        $url = "mysql://prod:p&ss'w<rd@db.internal/noco_warranty";

        $rendered = $this->deploy(
            "<?php\nreturn ['url' => {{ data.url|var_export }}, 'raw' => '{{ data.url }}'];\n",
            ['data' => ['url' => $url]],
        );

        $this->assertStringContainsString("'url' => 'mysql://prod:p&ss\\'w<rd@db.internal/noco_warranty'", $rendered);
        $this->assertStringContainsString("'raw' => 'mysql://prod:p&ss'w<rd@db.internal/noco_warranty'", $rendered);
        $this->assertStringNotContainsString('&amp;', $rendered);
        $this->assertStringNotContainsString('&#039;', $rendered);
    }

    /** The base64 convention for multi-line values: env var holds base64, template decodes it. */
    public function testAPemKeyRoundTripsThroughBase64ByteForByte(): void
    {
        $pem = "-----BEGIN PRIVATE KEY-----\n"
            . "MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKj\n"
            . "MzEfYyjiWA4R4/M2bS1GB4t7NXp98C3SC6dVMvDuictGeurT8jNbvJZHtCSuYEvu\n"
            . "-----END PRIVATE KEY-----\n";

        $rendered = $this->deploy('{{ key|b64decode }}', ['key' => base64_encode($pem)]);

        $this->assertSame($pem, $rendered);
    }

    /** `base64` wraps at 76 columns and appends a newline unless told otherwise; both are tolerated. */
    public function testWrappedBase64WithATrailingNewlineDecodes(): void
    {
        $pem     = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n";
        $wrapped = chunk_split(base64_encode($pem), 76, "\n");

        $this->assertSame($pem, $this->deploy('{{ key|b64decode }}', ['key' => $wrapped]));
    }

    public function testInvalidBase64IsAnErrorNotATruncatedKey(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/not valid base64/');

        $this->deploy('{{ key|b64decode }}', ['key' => 'not*base64!']);
    }

    public function testDecodedValueCanBeVarExportedIntoPhp(): void
    {
        $secret   = "multi\nline 'quoted'";
        $rendered = $this->deploy("<?php return {{ secret|b64decode|var_export }};", ['secret' => base64_encode($secret)]);

        $this->assertSame("<?php return 'multi\nline \\'quoted\\'';", $rendered);
    }
}
