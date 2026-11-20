<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index()
    {
        $branches = Branch::with('managers:id,name,branch_id,is_active')
            ->orderBy('name')
            ->paginate(25);

        return view('branches.index', compact('branches'));
    }

    public function create()
    {
        return view('branches.form', ['branch' => new Branch(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        $branch = Branch::create($this->validated($request));

        return redirect()->route('branches.edit', $branch)->with('status', 'Branch created.');
    }

    public function edit(Branch $branch)
    {
        $branch->load('managers');
        $availableManagers = User::where('role', Role::BranchManager)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', '!=', $branch->id))
            ->with('branch:id,name')
            ->orderBy('name')
            ->get();

        return view('branches.form', compact('branch', 'availableManagers'));
    }

    public function update(Request $request, Branch $branch)
    {
        $branch->update($this->validated($request));

        return redirect()->route('branches.index')->with('status', 'Branch updated.');
    }

    public function destroy(Branch $branch)
    {
        if ($branch->users()->exists() || $branch->leads()->exists()) {
            return back()->withErrors(['branch' => 'This branch still has managers or leads. Deactivate it instead.']);
        }

        $branch->delete();

        return redirect()->route('branches.index')->with('status', 'Branch deleted.');
    }

    /** Assign (or move) a Branch Manager to this branch. */
    public function assignManager(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('role', Role::BranchManager->value)],
        ]);

        User::whereKey($data['user_id'])->update(['branch_id' => $branch->id]);

        return back()->with('status', 'Manager assigned.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
