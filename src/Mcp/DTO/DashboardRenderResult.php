<?php

namespace Webbycrown\McpDashboardStudio\Mcp\DTO;

use JsonSerializable;

/**
 * DashboardRenderResult
 *
 * Immutable output of HtmlRenderer::render().
 * Bundles the rendered HTML, static CSS, and the raw dashboard spec
 * into a single transport object for MCP tool responses.
 *
 * DashboardSpec → HtmlRenderer → DashboardRenderResult
 */
class DashboardRenderResult implements JsonSerializable
{
    public function __construct(
        public readonly string $html,
        public readonly string $css,
        public readonly array  $dashboard,
    ) {}

    public function toArray(): array
    {
        return [
            'html'      => $this->html,
            'css'       => $this->css,
            'dashboard' => $this->dashboard,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
