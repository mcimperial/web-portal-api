<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Http\Traits\UppercaseInput;
use App\Http\Traits\PasswordDeleteValidation;

class CompanyController extends Controller
{
    use UppercaseInput, PasswordDeleteValidation;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Return only non-deleted companies
        return \App\Models\Company::whereNull('deleted_at')->get();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:50|unique:company,company_code',
            'company_name' => 'required|string|max:255|unique:company,company_name',
            'address' => 'nullable|string|max:255',
            'phone1' => 'nullable|string|max:255',
            'phone2' => 'nullable|string|max:255',
            'email1' => 'nullable|email|max:255',
            'email2' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:255',
            'logo' => 'nullable|string|max:255',
            'status' => 'required|string|in:ACTIVE,INACTIVE',
        ]);
        // Convert all string fields to uppercase using trait
        $data = $this->uppercaseStrings($data);
        $data['uuid'] = \Illuminate\Support\Str::uuid()->toString();
        $company = \App\Models\Company::create($data);
        return response()->json($company, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $company = \App\Models\Company::findOrFail($id);
        return response()->json($company);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $company = \App\Models\Company::findOrFail($id);
        $data = $request->validate([
            'company_code' => 'sometimes|required|string|max:50|unique:company,company_code,' . $id,
            'company_name' => 'sometimes|required|string|max:255|unique:company,company_name,' . $id,
            'address' => 'nullable|string|max:255',
            'phone1' => 'nullable|string|max:255',
            'phone2' => 'nullable|string|max:255',
            'email1' => 'nullable|email|max:255',
            'email2' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:255',
            'logo' => 'nullable|string|max:255',
            'status' => 'sometimes|required|string|in:ACTIVE,INACTIVE',
        ]);
        // Convert all string fields to uppercase using trait
        $data = $this->uppercaseStrings($data);
        $company->update($data);
        return response()->json($company);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $this->validateDeletePassword($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        $company = \App\Models\Company::findOrFail($id);
        $company->deleted_by = $user ? $user->id : null;
        $company->save();
        $company->delete();
        return response()->json(['message' => 'Company deleted']);
    }
}
