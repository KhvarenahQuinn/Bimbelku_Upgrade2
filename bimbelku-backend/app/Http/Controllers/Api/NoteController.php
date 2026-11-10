<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NoteController extends Controller
{
    public function index()
    {
        $notes = Note::where('user_id', Auth::id())
            ->orderBy('is_pinned', 'desc') // Pinned duluan
            ->orderBy('updated_at', 'desc')
            ->limit(200)
            ->get();

        return response()->json($notes);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'required_without:content', 'string', 'max:180'],
            'content' => ['nullable', 'required_without:title', 'string', 'max:5000'],
            'color' => ['nullable', 'in:white,red,orange,yellow,green,teal,blue,indigo,purple,pink'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        // [FIX] Gunakan $request->input() untuk menghindari konflik dengan protected property $content
        $note = Note::create([
            'user_id' => Auth::id(),
            'title' => trim((string) ($validated['title'] ?? '')) ?: null,
            'content' => trim((string) ($validated['content'] ?? '')) ?: null,
            'color' => $validated['color'] ?? 'white',
            'is_pinned' => $validated['is_pinned'] ?? false,
        ]);

        return response()->json(['message' => 'Catatan disimpan.', 'data' => $note]);
    }

    public function update(Request $request, $id)
    {
        $note = Note::where('user_id', Auth::id())->findOrFail($id);
        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:180'],
            'content' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'color' => ['sometimes', 'in:white,red,orange,yellow,green,teal,blue,indigo,purple,pink'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('title', $validated)) {
            $validated['title'] = trim((string) $validated['title']) ?: null;
        }
        if (array_key_exists('content', $validated)) {
            $validated['content'] = trim((string) $validated['content']) ?: null;
        }
        $nextTitle = array_key_exists('title', $validated) ? $validated['title'] : $note->title;
        $nextContent = array_key_exists('content', $validated) ? $validated['content'] : $note->content;
        if (blank($nextTitle) && blank($nextContent)) {
            return response()->json(['message' => 'Judul atau isi catatan wajib tersedia.'], 422);
        }

        $note->update($validated);

        return response()->json(['message' => 'Catatan diperbarui.', 'data' => $note]);
    }

    public function destroy($id)
    {
        $note = Note::where('user_id', Auth::id())->findOrFail($id);
        $note->delete();

        return response()->json(['message' => 'Catatan dihapus.']);
    }
}
