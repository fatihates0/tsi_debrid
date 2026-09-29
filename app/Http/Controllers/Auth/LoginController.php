<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\XenForoAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(protected XenForoAuthService $xenForoAuthService) {}

    /**
     * Display the XenForo login view.
     */
    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request via XenForo REST API.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Kullanıcı adı veya e-posta adresi gereklidir.',
            'password.required' => 'Şifre gereklidir.',
        ]);

        try {
            $user = $this->xenForoAuthService->authenticate(
                $credentials['login'],
                $credentials['password']
            );

            Auth::login($user, $request->boolean('remember'));

            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'))
                ->with('status', 'turkcesesindir.com hesabınızla başarıyla giriş yaptınız.');

        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'login' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
