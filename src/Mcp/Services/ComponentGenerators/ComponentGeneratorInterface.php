<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\ComponentGenerators;

interface ComponentGeneratorInterface
{
    /**
     * Return true when this generator can handle the provided hint.
     */
    public function canHandle(array $hint): bool;

    /**
     * Generate a component fragment from hint and context.
     */
    public function generate(array $hint, array $context = []): array;
}
