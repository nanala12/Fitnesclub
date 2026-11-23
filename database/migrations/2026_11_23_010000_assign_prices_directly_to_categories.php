<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->rawColumn('category', 'VARCHAR(30) REFERENCES price_list_categories(key) ON DELETE RESTRICT')->nullable();
            } else {
                $table->string('category', 30)->nullable();
                $table->foreign('category', 'plans_price_category_fk')->references('key')->on('price_list_categories')->restrictOnDelete();
            }
        });
        $groups = DB::table('price_list_groups')->get()->keyBy('id');
        DB::table('membership_plans')->orderBy('id')->chunkById(100, function ($plans) use ($groups): void {
            foreach ($plans as $plan) {
                $group = $groups->get($plan->price_list_group_id);
                DB::table('membership_plans')->where('id', $plan->id)->update([
                    'category' => $group->category ?? 'gym',
                    'is_public' => $plan->is_public && ($group === null || $group->is_published),
                    'revision' => $plan->revision + 1,
                ]);
            }
        });
        $this->createServices('price_list_services_direct', true);
        DB::table('price_list_services')->orderBy('id')->chunkById(100, function ($services) use ($groups): void {
            foreach ($services as $service) {
                $group = $groups->get($service->price_list_group_id);
                DB::table('price_list_services_direct')->insert([
                    'id' => $service->id, 'category' => $group->category ?? 'gym', 'name' => $service->name, 'price_grosz' => $service->price_grosz,
                    'is_published' => $service->is_published && ($group === null || $group->is_published),
                    'sort_order' => $service->sort_order, 'revision' => $service->revision + 1,
                    'created_at' => $service->created_at, 'updated_at' => $service->updated_at,
                ]);
            }
        });
        Schema::drop('price_list_services');
        Schema::rename('price_list_services_direct', 'price_list_services');
    }

    public function down(): void
    {
        $this->createServices('price_list_services_grouped', false);
        DB::table('price_list_services')->orderBy('id')->chunkById(100, function ($services): void {
            foreach ($services as $service) {
                DB::table('price_list_services_grouped')->insert([
                    'id' => $service->id, 'price_list_group_id' => $this->legacyGroup($service->category), 'name' => $service->name, 'price_grosz' => $service->price_grosz,
                    'is_published' => $service->is_published, 'sort_order' => $service->sort_order, 'revision' => $service->revision + 1,
                    'created_at' => $service->created_at, 'updated_at' => $service->updated_at,
                ]);
            }
        });
        Schema::drop('price_list_services');
        Schema::rename('price_list_services_grouped', 'price_list_services');
        DB::table('membership_plans')->orderBy('id')->chunkById(100, function ($plans): void {
            foreach ($plans as $plan) {
                DB::table('membership_plans')->where('id', $plan->id)->update(['price_list_group_id' => $this->legacyGroup($plan->category ?? 'gym'), 'revision' => $plan->revision + 1]);
            }
        });
        Schema::table('membership_plans', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign('plans_price_category_fk');
            }
            $table->dropColumn('category');
        });
    }

    private function createServices(string $name, bool $direct): void
    {
        Schema::create($name, function (Blueprint $table) use ($direct): void {
            $table->id();
            if ($direct) {
                $table->string('category', 30);
                $table->foreign('category')->references('key')->on('price_list_categories')->restrictOnDelete();
            } else {
                $table->foreignId('price_list_group_id')->constrained()->restrictOnDelete();
            }
            $table->string('name', 180);
            $type = DB::getDriverName() === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED';
            $integer = DB::getDriverName() === 'sqlite' ? "typeof(price_grosz) = 'integer' AND " : '';
            $table->rawColumn('price_grosz', "$type CHECK ({$integer}price_grosz >= 0 AND price_grosz <= 9223372036854775807)");
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps(6);
            $table->index([$direct ? 'category' : 'price_list_group_id', 'is_published', 'sort_order'], $direct ? 'price_services_category_order' : 'price_services_group_order');
        });
    }

    private function legacyGroup(string $category): int
    {
        $id = DB::table('price_list_groups')->where('category', $category)->orderBy('id')->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return DB::table('price_list_groups')->insertGetId(['name' => DB::table('price_list_categories')->where('key', $category)->value('name') ?? $category,
            'category' => $category, 'is_published' => true, 'sort_order' => 0, 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
};
