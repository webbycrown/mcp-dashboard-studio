<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database\Renderer;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardSpec;

/**
 * DashboardBladeRenderer
 *
 * Assembles the complete Blade file content by combining:
 *   - DashboardCssRenderer  → <style>
 *   - DashboardHtmlRenderer → <body>
 *   - DashboardJsRenderer   → <script>
 *
 * The output is a complete, self-contained HTML document that can be
 * copy-pasted directly into resources/views/dashboard.blade.php
 * and rendered immediately by Laravel.
 *
 * IMPORTANT: The Blade output is a STATIC snapshot. It does not contain
 * Blade directives or PHP code — it is pure HTML/CSS/JS ready to save.
 */
class DashboardBladeRenderer
{
    public function __construct(
        protected DashboardHtmlRenderer $htmlRenderer,
        protected DashboardCssRenderer  $cssRenderer,
        protected DashboardJsRenderer   $jsRenderer,
    ) {}

    /**
     * Render a complete Blade-ready HTML document from a DashboardSpec.
     *
     * @return array{filename: string, content: string}
     */
    public function render(DashboardSpec $spec): array
    {
        $title = htmlspecialchars($spec->title, ENT_QUOTES, 'UTF-8');
        $css   = $this->cssRenderer->render();
        $html  = $this->htmlRenderer->render($spec);
        $js    = $this->jsRenderer->render($spec);

        $hasCharts = ! empty($spec->charts);
        $chartCdn  = $hasCharts
            ? '    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>' . "\n"
            : '';

        $content = <<<BLADE
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MCP Dashboard — {$title}">
    <title>{$title}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
{$css}
    </style>
</head>
<body>

{$html}

{$chartCdn}    <script>
{$js}
    </script>
</body>
</html>
BLADE;

        return [
            'filename' => 'dashboard.blade.php',
            'content'  => $content,
        ];
    }
}
