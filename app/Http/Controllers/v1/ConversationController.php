<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    /**
     * Get all conversations for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Conversation::query()->with(['messages' => function ($q) {
                $q->latest()->limit(1);
            }]);

            // If user is authenticated, filter by user_id
            if ($request->user()) {
                $query->where('user_id', $request->user()->id);
            } else {
                // For non-authenticated users, show only conversations without user_id
                $query->whereNull('user_id');
            }

            $conversations = $query->orderBy('updated_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $conversations,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to retrieve conversations:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve conversations',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a specific conversation with all its messages
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $conversation = Conversation::with('messages')->findOrFail($id);

            // Check if user has access to this conversation
            if ($request->user() && $conversation->user_id && $conversation->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this conversation',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $conversation,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Failed to retrieve conversation:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve conversation',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Send a message in a conversation (create new or continue existing)
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'conversation_id' => 'nullable|string|exists:conversations,id',
            'message' => 'required|string',
            'details' => 'required|string',
            'theme' => 'required|string',
            'platform' => 'required|string',
            'title' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $validated = $validator->validated();
            $conversationId = $validated['conversation_id'] ?? null;
            $conversation = null;

            // If conversation_id is provided, get existing conversation
            if ($conversationId) {
                $conversation = Conversation::findOrFail($conversationId);

                // Check if user has access to this conversation
                if ($request->user() && $conversation->user_id && $conversation->user_id !== $request->user()->id) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized to access this conversation',
                    ], 403);
                }
            } else {
                // Create new conversation
                $conversation = Conversation::create([
                    'user_id' => $request->user()?->id,
                    'title' => $validated['title'] ?? substr($validated['message'], 0, 50),
                    'metadata' => [
                        'platform' => $validated['platform'],
                        'theme' => $validated['theme'],
                        'created_at' => now()->toIso8601String(),
                    ],
                ]);
            }

            // Save user message
            $userMessage = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $validated['message'],
                'metadata' => [
                    'details' => $validated['details'],
                    'platform' => $validated['platform'],
                    'theme' => $validated['theme'],
                ],
            ]);

            // Call external webhook to generate content
            $response = Http::timeout(60)
                ->post(env('WEBHOOK_URL'), [
                    'details' => $validated['details'],
                    'theme' => $validated['theme'],
                    'platform' => $validated['platform'],
                ]);

            if (!$response->successful()) {
                DB::rollBack();
                Log::error('Content generation failed:', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate content',
                    'error' => $response->body(),
                    'status' => $response->status(),
                ], $response->status());
            }

            $generatedContent = $response->json();

            // Save assistant response
            $assistantMessage = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => json_encode($generatedContent),
                'metadata' => [
                    'generated_at' => now()->toIso8601String(),
                    'response_status' => $response->status(),
                ],
            ]);

            // Update conversation timestamp
            $conversation->touch();

            DB::commit();

            // Reload conversation with all messages
            $conversation->load('messages');

            return response()->json([
                'success' => true,
                'data' => [
                    'conversation' => $conversation,
                    'user_message' => $userMessage,
                    'assistant_message' => $assistantMessage,
                    'generated_content' => $generatedContent,
                ],
            ], $conversationId ? 200 : 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            DB::rollBack();
            Log::error('Content generation connection error:', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Connection timeout or network error',
                'error' => 'Unable to reach content generation service',
            ], 504);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to send message:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing message',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update conversation title
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $conversation = Conversation::findOrFail($id);

            // Check if user has access to update this conversation
            if ($request->user() && $conversation->user_id && $conversation->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this conversation',
                ], 403);
            }

            $conversation->update([
                'title' => $request->input('title'),
            ]);

            return response()->json([
                'success' => true,
                'data' => $conversation,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Failed to update conversation:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating conversation',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete a conversation
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $conversation = Conversation::findOrFail($id);

            // Check if user has access to delete this conversation
            if ($request->user() && $conversation->user_id && $conversation->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this conversation',
                ], 403);
            }

            $conversation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Conversation deleted successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Failed to delete conversation:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete conversation',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
