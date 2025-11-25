<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ClientMobileAppController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('clientmobileapp::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('clientmobileapp::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        //
        return redirect()->route('clientmobileapp.index');
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('clientmobileapp::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('clientmobileapp::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        //
        return redirect()->route('clientmobileapp.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
    }
}
