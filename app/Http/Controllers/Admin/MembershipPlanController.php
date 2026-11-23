<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Memberships\ManageMembershipPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MembershipPlanRequest;
use App\Models\MembershipPlan;
use App\Models\PriceListCategory;
use App\Support\PlanPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MembershipPlanController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage', MembershipPlan::class);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return Inertia::render('admin/plans/Index', [
            'plans' => MembershipPlan::query()->withExists(['orders', 'memberships'])->orderBy('sort_order')->orderBy('id')->paginate(20)->through(fn (MembershipPlan $plan): array => $this->present($plan)),
            'featuredCount' => MembershipPlan::query()->where('is_featured', true)->count(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('manage', MembershipPlan::class);

        return Inertia::render('admin/plans/Form', ['plan' => null, 'categories' => PriceListCategory::options()]);
    }

    public function edit(MembershipPlan $membershipPlan): Response
    {
        Gate::authorize('manage', MembershipPlan::class);
        $membershipPlan->loadExists(['orders', 'memberships']);

        return Inertia::render('admin/plans/Form', ['plan' => $this->present($membershipPlan), 'categories' => PriceListCategory::options()]);
    }

    public function store(MembershipPlanRequest $request, ManageMembershipPlan $plans): RedirectResponse
    {
        $plans->save($request->user(), null, $this->data($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plan karnetu został dodany.']);

        return to_route('admin.plans.index');
    }

    public function update(MembershipPlanRequest $request, MembershipPlan $membershipPlan, ManageMembershipPlan $plans): RedirectResponse
    {
        $plans->save($request->user(), $membershipPlan->id, $this->data($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zmiany planu zostały zapisane.']);

        return to_route('admin.plans.index');
    }

    public function visibility(MembershipPlanRequest $request, MembershipPlan $membershipPlan, ManageMembershipPlan $plans): RedirectResponse
    {
        $plans->visibility($request->user(), $membershipPlan->id, $request->integer('revision'), $request->boolean('is_public'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Widoczność karnetu w cenniku została zmieniona.']);

        return to_route('admin.plans.index', $request->safe()->only(['page']));
    }

    public function destroy(MembershipPlanRequest $request, MembershipPlan $membershipPlan, ManageMembershipPlan $plans): RedirectResponse
    {
        $plans->delete($request->user(), $membershipPlan->id, $request->integer('revision'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plan karnetu został usunięty.']);

        return to_route('admin.plans.index', ['page' => min($request->integer('page', 1), max(1, (int) ceil(MembershipPlan::count() / 20)))]);
    }

    /** @return array<string, mixed> */
    private function present(MembershipPlan $plan): array
    {
        return ['id' => $plan->id, 'name' => $plan->name, 'description' => $plan->description, 'kind' => $plan->kind->value, 'category' => $plan->category,
            'price' => PlanPrice::decimal($plan->price_grosz), 'priceLabel' => PlanPrice::label($plan->price_grosz),
            'duration_months' => $plan->duration_months, 'included_entries' => $plan->included_entries,
            'is_active' => $plan->is_active, 'is_public' => $plan->is_public, 'is_featured' => $plan->is_featured,
            'sort_order' => $plan->sort_order, 'revision' => $plan->revision,
            'hasHistory' => (bool) $plan->getAttribute('orders_exists') || (bool) $plan->getAttribute('memberships_exists')];
    }

    /** @return array{category?:string,name:string,description:?string,kind:string,price:string,duration_months:?int,included_entries:?int,is_active:bool,is_public:bool,is_featured:bool,sort_order:int,revision:int} */
    private function data(MembershipPlanRequest $request): array
    {
        return ['name' => $request->validated('name'), 'description' => $request->validated('description'), 'kind' => $request->validated('kind'), ...($request->has('category') ? ['category' => $request->validated('category')] : []),
            'price' => $request->validated('price'), 'duration_months' => $request->filled('duration_months') ? $request->integer('duration_months') : null,
            'included_entries' => $request->filled('included_entries') ? $request->integer('included_entries') : null,
            'is_active' => $request->boolean('is_active'), 'is_public' => $request->boolean('is_public'), 'is_featured' => $request->boolean('is_featured'),
            'sort_order' => $request->integer('sort_order'), 'revision' => $request->integer('revision')];
    }
}
