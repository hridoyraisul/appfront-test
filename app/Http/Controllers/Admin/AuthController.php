<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Display the login page
     *
     * @return \Illuminate\View\View The view for the login page.
     */
    public function loginPage()
    {
        return view('login');
    }

    /**
     * Handle the login request
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {
        if (Auth::attempt($request->except('_token'))) {
            return redirect()->route('products.index');
        }
        return redirect()->back()->with('error', 'Invalid login credentials');
    }

    /**
     * Logout the user
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout()
    {
        Auth::logout();
        return redirect()->route('login');
    }
}
