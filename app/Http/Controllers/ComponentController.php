<?php

namespace App\Http\Controllers;

use App\Models\Component;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Basic CRUD for the component library. Global/shared resource.
 */
class ComponentController extends Controller
{
    public function index(): View
    {
        $components = Component::latest()->get();

        return view('design-system.components.index', compact('components'));
    }

    public function show(Component $component): View
    {
        return view('design-system.components.show', compact('component'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:components,slug'],
            'category' => ['nullable', 'string', 'max:255'],
            'is_brain_surface' => ['nullable', 'boolean'],
            'version' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
        ]);

        $component = Component::create($data);

        return redirect()->route('design-system.components.show', $component);
    }

    public function update(Request $request, Component $component): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'is_brain_surface' => ['nullable', 'boolean'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
        ]);

        $component->update($data);

        return redirect()->route('design-system.components.show', $component);
    }

    public function destroy(Component $component): RedirectResponse
    {
        $component->delete();

        return redirect()->route('design-system.components.index');
    }
}
