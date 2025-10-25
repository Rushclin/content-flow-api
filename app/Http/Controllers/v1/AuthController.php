<?php

namespace App\Http\Controllers\v1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request): JsonResponse
    {

        $validated = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users|lowercase|email:rfc,dns',
            'password' => 'required|string|min:8',
        ]);

        if ($validated->fails()) {
            return response()->json(['errors' => $validated->errors()], 422);
        }

        try {
            $user = User::create(array_merge(
                $validated->validate(),
                [
                    'password' => bcrypt($request->password),
                    'role' => Role::USER,
                ]
            ));

            $token = $user->createToken('auth_token')->accessToken;

            return response()->json([
                'message' => 'User registered successfully',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Registration error:', ['error' => $th->getMessage()]);
            return response()->json([
                'message' => $th->getMessage() ?? 'Registration failed',
            ], $th->getCode() ?: 500);
        }
    }

    /**
     * Login user
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credendtials = $request->only(['email', 'password']);

        if (auth()->attempt($credendtials)) {
            $user = auth()->user();
            $token = $user->createToken('auth_token')->accessToken;

            return response()->json([
                'message' => 'Login successful',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);
        } else {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()
        ]);
    }
}
