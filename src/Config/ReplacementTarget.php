<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Config;

/**
 * One `table.column` a replacement rewrites.
 *
 * Config writes these as a single string, `cms_page.content`. Splitting it once here means
 * {@see \ConductorAppOrchestration\Deploy\DatabaseReplacementScript} never explodes a string and
 * counts the parts mid-loop, and a malformed target is reported by the schema alongside every other
 * config problem rather than warned about and skipped at generation time.
 */
final readonly class ReplacementTarget
{
    public function __construct(
        public string $table,
        public string $column,
    ) {
    }

    public function __toString(): string
    {
        return "$this->table.$this->column";
    }
}
