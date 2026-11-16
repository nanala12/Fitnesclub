<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 50);
            $table->string('path', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('alt_text')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['disk', 'path']);
        });
        Schema::create('offer_tiles', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->text('short_description')->nullable();
            $table->foreignId('media_asset_id')->nullable()->unique()->constrained('media_assets');
            $table->string('link_url', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->index(['is_published', 'sort_order']);
        });
        Schema::create('hero_slides', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('media_asset_id')->unique()->constrained('media_assets');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->index(['is_published', 'sort_order']);
        });
        Schema::create('news_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->foreignId('cover_media_id')->nullable()->unique()->constrained('media_assets');
            $table->boolean('is_published')->default(false);
            $table->dateTime('published_at', 6)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_published', 'published_at']);
        });
        Schema::create('gallery_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('short_description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });
        Schema::create('gallery_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gallery_category_id')->constrained('gallery_categories');
            $table->foreignId('media_asset_id')->unique()->constrained('media_assets');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['gallery_category_id', 'sort_order']);
        });
        Schema::create('regulation_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version_number')->unique();
            $table->longText('body');
            $table->foreignId('media_asset_id')->nullable()->unique()->constrained('media_assets');
            $table->dateTime('published_at', 6)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });
        Schema::create('club_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            foreach (['club_name', 'contact_email', 'contact_phone', 'street_address', 'postal_code', 'city'] as $field) {
                $table->string($field)->nullable();
            }
            $table->string('map_url', 1000)->nullable();
            $table->timestamps();
        });
        Schema::create('opening_hours', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('weekday')->unique();
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
        });
        Schema::create('class_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });
        Schema::create('class_schedule_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_type_id')->constrained('class_types');
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('instructor_name', 150)->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->index(['weekday', 'starts_at']);
        });
    }

    public function down(): void
    {
        foreach (['class_schedule_slots', 'class_types', 'opening_hours', 'club_settings', 'regulation_versions', 'gallery_images', 'gallery_categories', 'news_posts', 'hero_slides', 'offer_tiles', 'media_assets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
