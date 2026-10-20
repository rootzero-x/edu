<?php

namespace App\Http\Controllers\Api;

use App\Models\Branch;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Branch::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);
        $branch = Branch::create($data);
        Audit::log('branch.create', 'branch', $branch->id, $data);

        return response()->json(['branch' => $branch], 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $branch->update($data);
        Audit::log('branch.update', 'branch', $branch->id, $data);

        return response()->json(['branch' => $branch]);
    }
}
