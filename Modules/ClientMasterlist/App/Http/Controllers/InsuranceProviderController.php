<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\InsuranceProvider;
use App\Http\Traits\UppercaseInput;
use Illuminate\Support\Facades\Schema;
use App\Http\Traits\PasswordDeleteValidation;

class InsuranceProviderController extends Controller
{
    use UppercaseInput, PasswordDeleteValidation;

    public function index()
    {
        return InsuranceProvider::all();
    }


    public function show($id)
    {
        return InsuranceProvider::findOrFail($id);
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'note' => 'nullable|string',
            'status' => 'required|string',
        ]);
        $validated = $this->uppercaseStrings($validated);
        return InsuranceProvider::create($validated);
    }


    public function update(Request $request, $id)
    {
        $insuranceProvider = InsuranceProvider::findOrFail($id);
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'note' => 'nullable|string',
            'status' => 'required|string',
        ]);
        $validated = $this->uppercaseStrings($validated);
        $insuranceProvider->update($validated);
        return $insuranceProvider;
    }

    public function destroy(Request $request, $id)
    {
        $user = $this->validateDeletePassword($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        $insuranceProvider = InsuranceProvider::findOrFail($id);
        // If the model has a deleted_by column, set it
        if (Schema::hasColumn($insuranceProvider->getTable(), 'deleted_by')) {
            $insuranceProvider->deleted_by = $user ? $user->id : null;
            $insuranceProvider->save();
        }
        $insuranceProvider->delete();
        return response()->json(['message' => 'Insurance provider deleted']);
    }
}
