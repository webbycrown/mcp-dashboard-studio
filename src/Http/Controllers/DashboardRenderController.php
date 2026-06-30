<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers;

class DashboardRenderController extends Controller
{
    public function render(string $slug)
    {
        return app(DashboardStudioController::class)->show($slug);
    }
}
