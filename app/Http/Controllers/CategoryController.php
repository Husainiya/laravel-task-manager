<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    /**
     * Display a listing of the categories.
     */
    public function index()
    {
        // Only admin can access
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        $categories = Category::latest()->get();

        return Inertia::render('categories/Index', [
            'categories' => $categories
        ]);
    }

    /**
     * Show the form for creating a new category.
     */
    public function create()
    {
        // Only admin can access
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        return Inertia::render('categories/Create');
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request)
    {
        // Only admin can access
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string',
            'status' => 'required|in:Active,Inactive'
        ]);

        Category::create($data);

        return redirect()->route('categories.index')->with('message', 'Category created successfully!');
    }

    /**
     * Show the form for editing the specified category.
     */
    public function edit(Category $category)
    {
        // Only admin can access
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        return Inertia::render('categories/Edit', [
            'category' => $category
        ]);
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, Category $category)
    {
        // Only admin can access
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $category->id,
            'description' => 'nullable|string',
            'status' => 'required|in:Active,Inactive'
        ]);

        $category->update($data);

        return redirect()->route('categories.index')->with('message', 'Category updated successfully!');
    }

    /**
     * Remove the specified category.
     */
    public function destroy(Category $category)
    {
        // Only admin can access
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        // Check if category is being used by any tasks
        if ($category->tasks()->count() > 0) {
            return redirect()->route('categories.index')->with('error', 'Cannot delete category. It is being used by tasks.');
        }

        $category->delete();

        return redirect()->route('categories.index')->with('message', 'Category deleted successfully!');
    }
}
