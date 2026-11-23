<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cms\ManagePriceList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PriceListRequest;
use App\Models\PriceListCategory;
use App\Models\PriceListService;
use App\Support\PlanPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PriceListController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage', PriceListCategory::class);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'category_page' => ['sometimes', 'integer', 'min:1']]);

        return Inertia::render('admin/pricing/Index', [
            'categories' => PriceListCategory::query()->orderBy('sort_order')->orderBy('id')->paginate(20, ['*'], 'category_page')->withQueryString()->through(fn (PriceListCategory $item): array => $this->present($item)),
            'services' => PriceListService::query()->with('priceCategory')->orderBy('sort_order')->orderBy('id')->paginate(20)->withQueryString()->through(fn (PriceListService $item): array => $this->present($item)),
        ]);
    }

    public function create(string $type): Response
    {
        Gate::authorize('manage', PriceListCategory::class);

        return $this->form($type, null);
    }

    public function edit(string $type, int $item): Response
    {
        Gate::authorize('manage', PriceListCategory::class);
        $model = match ($type) {
            'kategorie' => PriceListCategory::class, default => PriceListService::class
        };
        $record = $model::findOrFail($item);

        return $this->form($type, $record);
    }

    public function store(PriceListRequest $request, string $type, ManagePriceList $action): RedirectResponse
    {
        return $this->save($request, $type, null, $action);
    }

    public function update(PriceListRequest $request, string $type, int $item, ManagePriceList $action): RedirectResponse
    {
        return $this->save($request, $type, $item, $action);
    }

    public function visibility(PriceListRequest $request, string $type, int $item, ManagePriceList $action): RedirectResponse
    {
        $action->visibility($request->user(), $type === 'kategorie' ? 'category' : 'service', $item, $request->integer('revision'), $request->boolean('is_published'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Widoczność pozycji cennika została zmieniona.']);

        return to_route('admin.pricing.index', $request->safe()->only(['page', 'category_page']));
    }

    public function destroy(PriceListRequest $request, string $type, int $item, ManagePriceList $action): RedirectResponse
    {
        $action->delete($request->user(), match ($type) {
            'kategorie' => 'category', default => 'service'
        }, $item, $request->integer('revision'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pozycja cennika została usunięta.']);

        return to_route('admin.pricing.index');
    }

    private function save(PriceListRequest $request, string $type, ?int $id, ManagePriceList $action): RedirectResponse
    {
        $action->save($request->user(), match ($type) {
            'kategorie' => 'category', default => 'service'
        }, $id, [...$request->validated(), 'is_published' => $request->boolean('is_published'), 'sort_order' => $request->integer('sort_order'), 'revision' => $request->integer('revision')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zmiany cennika zostały zapisane.']);

        return to_route('admin.pricing.index');
    }

    private function form(string $type, PriceListCategory|PriceListService|null $item): Response
    {
        return Inertia::render('admin/pricing/Form', ['type' => $type, 'item' => $item === null ? null : $this->present($item), 'categories' => PriceListCategory::options()]);
    }

    /** @return array<string,mixed> */
    private function present(PriceListCategory|PriceListService $item): array
    {
        $data = ['id' => $item->id, 'name' => $item->name, 'is_published' => $item->is_published, 'sort_order' => $item->sort_order, 'revision' => $item->revision];
        if ($item instanceof PriceListCategory) {
            return [...$data, 'key' => $item->key];
        }

        return [...$data, 'category' => $item->category, 'categoryLabel' => $item->priceCategory?->name, 'categoryPublished' => $item->priceCategory->is_published ?? false,
            'price' => PlanPrice::decimal($item->price_grosz), 'priceLabel' => PlanPrice::label($item->price_grosz)];
    }
}
