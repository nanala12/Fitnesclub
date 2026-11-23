<?php

namespace App\Actions\Cms;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\PriceListCategory;
use App\Models\PriceListService;
use App\Models\User;
use App\Support\PlanPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagePriceList
{
    public function __construct(private RecordAudit $audit) {}

    /** @param array<string,mixed> $data */
    public function save(User $actor, string $type, ?int $id, array $data): PriceListCategory|PriceListService
    {
        $model = $this->model($type);
        Gate::forUser($actor)->authorize('manage', $model);

        return DB::transaction(function () use ($actor, $type, $id, $data, $model): PriceListCategory|PriceListService {
            DB::table('membership_catalog_states')->where('id', 1)->lockForUpdate()->sole();
            $item = $id === null ? new $model : $model::query()->lockForUpdate()->findOrFail($id);
            if ($id !== null && $item->revision !== $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Ta pozycja została zmieniona. Wczytaj aktualne dane i ponów edycję.']);
            }
            $values = ['name' => $data['name'], 'is_published' => $data['is_published'], 'sort_order' => $data['sort_order']];
            $before = $item instanceof PriceListService ? ($id === null ? null : ['price_grosz' => $item->price_grosz]) : null;
            if ($type === 'category') {
                if ($id === null) {
                    $values['key'] = 'cat-'.strtolower((string) Str::ulid());
                }
            } else {
                $category = PriceListCategory::query()->where('key', $data['category'])->lockForUpdate()->first();
                if ($category === null) {
                    throw ValidationException::withMessages(['category' => 'Wybierz istniejącą kategorię cennika.']);
                }
                $price = PlanPrice::parse($data['price']);
                if ($price === null) {
                    throw ValidationException::withMessages(['price' => 'Wpisz poprawną cenę w złotych.']);
                }
                $values += ['category' => $category->key, 'price_grosz' => $price];
            }
            $item->forceFill($values);
            $changed = array_keys($item->getDirty());
            $item->forceFill(['revision' => $id === null ? 0 : $item->revision + 1])->save();
            $action = $type === 'category' ? ($id === null ? AuditAction::PriceCategoryCreated : AuditAction::PriceCategoryUpdated) : ($id === null ? AuditAction::PriceServiceCreated : AuditAction::PriceServiceUpdated);
            $this->audit->handle($action, $item->id, $actor, $before, $item instanceof PriceListService ? ['price_grosz' => $item->price_grosz] : null, ['changed_fields' => $changed]);

            return $item;
        }, 3);
    }

    public function visibility(User $actor, string $type, int $id, int $revision, bool $visible): void
    {
        $model = $this->model($type);
        Gate::forUser($actor)->authorize('manage', $model);
        DB::transaction(function () use ($actor, $type, $id, $revision, $visible, $model): void {
            DB::table('membership_catalog_states')->where('id', 1)->lockForUpdate()->sole();
            $item = $model::query()->lockForUpdate()->findOrFail($id);
            if ($item->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'Ta pozycja została zmieniona. Wczytaj aktualne dane.']);
            }
            $item->forceFill(['is_published' => $visible, 'revision' => $item->revision + 1])->save();
            $this->audit->handle($type === 'category' ? AuditAction::PriceCategoryUpdated : AuditAction::PriceServiceUpdated, $id, $actor, null, null, ['changed_fields' => ['is_published']]);
        }, 3);
    }

    public function delete(User $actor, string $type, int $id, int $revision): void
    {
        $model = $this->model($type);
        Gate::forUser($actor)->authorize('manage', $model);
        DB::transaction(function () use ($actor, $type, $id, $revision, $model): void {
            DB::table('membership_catalog_states')->where('id', 1)->lockForUpdate()->sole();
            $item = $model::query()->lockForUpdate()->findOrFail($id);
            if ($item->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'Ta pozycja została zmieniona. Wczytaj aktualne dane.']);
            }
            if ($item instanceof PriceListCategory && ($item->key === 'gym' || $item->services()->exists() || $item->plans()->exists())) {
                throw ValidationException::withMessages(['item' => $item->key === 'gym' ? 'To domyślna kategoria karnetów. Możesz zmienić jej nazwę lub widoczność.' : 'Kategoria zawiera karnety lub usługi. Przenieś je do innej kategorii albo ukryj kategorię.']);
            }
            $item->delete();
            $this->audit->handle($type === 'category' ? AuditAction::PriceCategoryDeleted : AuditAction::PriceServiceDeleted, $id, $actor, null, null);
        }, 3);
    }

    /** @return class-string<PriceListCategory|PriceListService> */
    private function model(string $type): string
    {
        return match ($type) {
            'category' => PriceListCategory::class, 'service' => PriceListService::class,
            default => throw new \InvalidArgumentException('Unknown price list item type.'),
        };
    }
}
