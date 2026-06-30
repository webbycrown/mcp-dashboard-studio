<?php

use Webbycrown\McpDashboardStudio\Http\Middleware\VerifyMcpToken;
use Webbycrown\McpDashboardStudio\Mcp\Servers\DashboardServer;
use Webbycrown\McpDashboardStudio\Support\RoutePaths;
use Laravel\Mcp\Facades\Mcp;

Mcp::web(RoutePaths::mcpPath(), DashboardServer::class)
    ->middleware(VerifyMcpToken::class);
