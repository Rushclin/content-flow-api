<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ContentGenerationController extends Controller
{
    /**
     * Generate content using external webhook
     */
    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'details' => 'required|string',
            "theme" => 'required|string',
            "platform" => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $response = Http::timeout(60)
                ->post(env("WEBHOOK_URL"), array_merge(
                    $validator->validate()
                ));

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'data' => $response->json(),
                    'status' => $response->status()
                ]);
            }

            Log::debug($response);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate content',
                'error' => $response->body(),
                'status' => $response->status()
            ], $response->status());

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Content generation connection error:', [
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Connection timeout or network error',
                'error' => 'Unable to reach content generation service'
            ], 504);

        } catch (\Throwable $e) {
            Log::error('Content generation error:', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while generating content',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
