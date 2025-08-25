<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('roles.permissions'); // Eager load roles and their permissions

        return response()->json($user);
    }
}
