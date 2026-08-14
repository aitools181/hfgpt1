<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\StickyNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StickyNoteController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('support/sticky-notes', [
            'notes' => StickyNote::query()->where('user_id', $request->user()->id)->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderByDesc('pinned_at')->orderByDesc('updated_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['nullable', 'string', 'max:5000']]);
        StickyNote::query()->create([...$data, 'user_id' => $request->user()->id, 'status' => 'open', 'pinned_at' => now()]);
        return back()->with('success', 'Sticky note created.');
    }

    public function update(Request $request, StickyNote $note): RedirectResponse
    {
        abort_unless((int) $note->user_id === (int) $request->user()->id, 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'in:open,done,archived'],
        ]);
        $note->update([...$data, 'pinned_at' => $data['status'] === 'open' ? ($note->pinned_at ?? now()) : $note->pinned_at]);
        return back()->with('success', 'Sticky note updated.');
    }

    public function destroy(Request $request, StickyNote $note): RedirectResponse
    {
        abort_unless((int) $note->user_id === (int) $request->user()->id, 403);
        $note->delete();
        return back()->with('success', 'Sticky note removed.');
    }
}
