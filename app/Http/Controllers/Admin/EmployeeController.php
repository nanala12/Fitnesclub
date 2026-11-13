<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\ManageEmployee;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EmployeeAccessRequest;
use App\Http\Requests\Admin\EmployeeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/employees/Index', [
            'employees' => User::query()->whereIn('role', [UserRole::Employee, UserRole::Admin])
                ->select(['id', 'name', 'email', 'phone', 'role', 'suspended_at', 'staff_invited_at', 'staff_invitation_accepted_at'])
                ->orderBy('name')->orderBy('id')->paginate(20)
                ->through(fn (User $user): array => [
                    'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
                    'role' => $user->role->value, 'suspended' => $user->isSuspended(),
                    'invitationPending' => $user->hasPendingStaffInvitation(),
                    'canManage' => $request->user()->can('manageEmployee', $user),
                ]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('createEmployee', User::class);

        return Inertia::render('admin/employees/Form', ['employee' => null]);
    }

    public function store(EmployeeRequest $request, ManageEmployee $manage): RedirectResponse
    {
        /** @var array{first_name: string, last_name: string, email: string, phone: string, role: string} $data */
        $data = $request->safe()->only(['first_name', 'last_name', 'email', 'phone', 'role']);
        $employee = $manage->save($request->user(), null, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Konto zostało utworzone. Zleciliśmy wysłanie zaproszenia do ustawienia hasła.']);

        return to_route('admin.employees.edit', $employee);
    }

    public function edit(User $employee): Response
    {
        Gate::authorize('manageEmployee', $employee);

        return Inertia::render('admin/employees/Form', ['employee' => [
            ...$employee->only(['id', 'name', 'first_name', 'last_name', 'email', 'phone']),
            'role' => $employee->role->value, 'suspended' => $employee->isSuspended(),
            'invitationPending' => $employee->hasPendingStaffInvitation(),
        ]]);
    }

    public function update(EmployeeRequest $request, User $employee, ManageEmployee $manage): RedirectResponse
    {
        /** @var array{first_name: string, last_name: string, email: string, phone: string, role: string} $data */
        $data = $request->safe()->only(['first_name', 'last_name', 'email', 'phone', 'role']);
        $manage->save($request->user(), $employee, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dane konta zostały zapisane.']);

        return to_route('admin.employees.edit', $employee);
    }

    public function suspension(EmployeeAccessRequest $request, User $employee, ManageEmployee $manage): RedirectResponse
    {
        $manage->suspend($request->user(), $employee, $request->boolean('suspended'));
        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('suspended') ? 'Dostęp do konta został zawieszony.' : 'Dostęp do konta został przywrócony.']);

        return to_route('admin.employees.edit', $employee);
    }

    public function invitation(EmployeeAccessRequest $request, User $employee, ManageEmployee $manage): RedirectResponse
    {
        $manage->invite($request->user(), $employee);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zleciliśmy ponowne wysłanie zaproszenia.']);

        return to_route('admin.employees.edit', $employee);
    }
}
