<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::orderBy('price')->get();
        return view('admin.plans.index', compact('plans'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:plans,slug',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        Plan::create($data);
        return redirect()->route('admin.plans.index');
    }

    public function destroy($id)
    {
        Plan::findOrFail($id)->delete();
        return redirect()->route('admin.plans.index');
    }
}
