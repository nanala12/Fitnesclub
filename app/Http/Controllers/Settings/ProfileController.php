<?php

namespace App\Http\Controllers\Settings;

use App\Actions\UpdateUserProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        Gate::authorize('view', $request->user());

        return Inertia::render('settings/Profile', [
            'profile' => $request->user()->only(['first_name', 'last_name', 'email', 'phone']),
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UpdateUserProfile $updateProfile): RedirectResponse
    {
        /** @var array{first_name: string, last_name: string, email: string, phone: string} $data */
        $data = $request->safe()->only(['first_name', 'last_name', 'email', 'phone']);
        $emailChanged = $updateProfile->handle($request->user(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => $emailChanged
            ? 'Dane zapisane. Potwierdź nowy adres e-mail, aby korzystać z panelu.'
            : __('Profile updated.')]);

        return to_route('profile.edit')->with('status', $emailChanged ? 'profile-email-changed' : 'profile-updated');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
