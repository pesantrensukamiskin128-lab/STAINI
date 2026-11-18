<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssignmentTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssignmentTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = AssignmentTemplate::query()
            ->where('user_id', auth()->id())
            ->orderBy('name')
            ->get();

        return response()->json($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('assignment_templates', 'name')
                    ->where(fn ($query) => $query->where('user_id', auth()->id())),
            ],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'type' => 'required|in:INDIVIDU,KELOMPOK',
            'max_score' => 'required|integer|min:1',
            'allow_late' => 'required|boolean',
        ]);

        $template = AssignmentTemplate::create([
            ...$validated,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Template tugas berhasil disimpan.',
            'data' => $template,
        ], 201);
    }

    public function destroy(AssignmentTemplate $assignmentTemplate): JsonResponse
    {
        abort_unless($assignmentTemplate->user_id === auth()->id(), 404);

        $assignmentTemplate->delete();

        return response()->json(['message' => 'Template tugas berhasil dihapus.']);
    }
}
