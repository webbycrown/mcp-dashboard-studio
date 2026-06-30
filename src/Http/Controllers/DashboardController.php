<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers;

use Webbycrown\McpDashboardStudio\Http\Requests\DashboardRequest;
use Webbycrown\McpDashboardStudio\Mcp\Services\DashboardGenerator;
use Webbycrown\McpDashboardStudio\Support\RoutePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardController extends Controller
{
    public function chat(DashboardRequest $request): JsonResponse
    {
        try {
            $prompt = $request->input('prompt');
            $specArray = app(DashboardGenerator::class)->generateDashboardConfig($prompt);

            $definition = app(\Webbycrown\McpDashboardStudio\Mcp\Services\DashboardStorageService::class)->storeSpec($prompt, $specArray);

            $url = RoutePaths::dashboardShowUrl($definition->slug);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Dashboard generated and stored successfully.',
                'data' => [
                    'url' => $url,
                    'slug' => $definition->slug,
                    'uuid' => $definition->uuid,
                    'name' => $definition->name,
                ],
                'errors' => [],
            ], 200);
        } catch (Throwable $exception) {
            Log::error('DashboardController encountered an unexpected exception.', [
                'exception' => $exception->getMessage(),
                'stack' => $exception->getTraceAsString(),
                'prompt' => $request->input('prompt'),
            ]);

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'An unexpected error occurred while generating the dashboard.',
                'errors' => [$exception->getMessage(), 'Please try again later.'],
            ], 500);
        }
    }


}


