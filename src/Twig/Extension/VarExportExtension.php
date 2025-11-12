<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class VarExportExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('var_export', [$this, 'varExport']),
        ];
    }

    public function varExport($data): ?string
    {
        return var_export($data, true);
    }
}
