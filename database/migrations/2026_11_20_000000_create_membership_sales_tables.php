<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private array $tableChecks = [];

    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $this->checked($table, 'kind', 'VARCHAR(30)', ["kind IN ('time', 'entries')", "(kind = 'time' AND duration_months IS NOT NULL AND duration_months > 0 AND included_entries IS NULL) OR (kind = 'entries' AND included_entries IS NOT NULL AND included_entries > 0 AND (duration_months IS NULL OR duration_months > 0))"], false);
            $this->money($table, 'price_grosz');
            $table->rawColumn('currency', "CHAR(3) CHECK (currency = 'PLN')")->default('PLN');
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->unsignedInteger('included_entries')->nullable();
            $table->json('terms')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_public')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps(6);
            $table->index(['is_active', 'is_public', 'sort_order']);
            $table->index('is_featured');
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_reference')->unique();
            $table->foreignId('purchaser_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('membership_plan_id')->constrained()->restrictOnDelete();
            foreach (['purchaser_first_name', 'purchaser_last_name', 'holder_first_name', 'holder_last_name'] as $name) {
                $table->string($name, 120);
            }
            $table->string('purchaser_email', 254);
            $table->string('purchaser_phone', 20);
            $table->string('holder_email', 254)->nullable();
            $table->string('holder_phone', 20);
            $table->string('recipient_email', 254);
            $table->json('plan_snapshot');
            $this->money($table, 'amount_grosz');
            $table->rawColumn('currency', "CHAR(3) CHECK (currency = 'PLN')")->default('PLN');
            $this->checked($table, 'sales_channel', 'VARCHAR(20)', ["(sales_channel = 'online' AND payment_method = 'przelewy24') OR (sales_channel = 'reception' AND payment_method IN ('cash', 'card'))"], false);
            $table->string('payment_method', 20);
            $table->string('status', 30)->default('pending');
            $table->dateTime('ordered_at', 6);
            $this->checked($table, 'paid_at', 'DATETIME(6)', ["status <> 'paid' OR paid_at IS NOT NULL"]);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_regulation_version_id')->nullable()->constrained('regulation_versions')->restrictOnDelete();
            $this->checked($table, 'regulation_accepted_at', 'DATETIME(6)', [
                '(accepted_regulation_version_id IS NULL AND regulation_accepted_at IS NULL) OR (accepted_regulation_version_id IS NOT NULL AND regulation_accepted_at IS NOT NULL)',
                "sales_channel <> 'online' OR status <> 'paid' OR (accepted_regulation_version_id IS NOT NULL AND regulation_accepted_at IS NOT NULL)",
            ]);
            $table->timestamps(6);
            $table->index(['purchaser_user_id', 'created_at']);
            $table->index(['holder_user_id', 'created_at']);
            $table->index(['holder_phone', 'created_at']);
            $table->index(['status', 'ordered_at']);
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('method', 20);
            $table->string('status', 30)->default('initiated');
            $this->money($table, 'amount_grosz');
            $table->rawColumn('currency', "CHAR(3) CHECK (currency = 'PLN')")->default('PLN');
            $table->string('provider_session_id', 190)->nullable();
            $table->string('provider_transaction_id', 190)->nullable();
            $this->checked($table, 'confirmed_at', 'DATETIME(6)', ["status <> 'confirmed' OR confirmed_at IS NOT NULL", "provider <> 'reception' OR status <> 'confirmed' OR confirmed_by_user_id IS NOT NULL"]);
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('failure_code', 100)->nullable();
            $table->timestamps(6);
            $table->index(['order_id', 'status']);
            // Provider uniqueness is deliberately deferred until its integration contract is verified.
            $table->index(['provider', 'provider_session_id']);
            $table->index(['provider', 'provider_transaction_id']);
        });
        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('membership_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('renewed_from_id')->nullable()->constrained('memberships')->restrictOnDelete();
            $table->string('holder_first_name', 120);
            $table->string('holder_last_name', 120);
            $table->string('holder_phone', 20);
            $table->string('holder_email', 254)->nullable();
            $table->json('plan_snapshot');
            $table->date('starts_on')->nullable();
            $this->checked($table, 'expires_on', 'DATE', ['(starts_on IS NULL AND expires_on IS NULL) OR (starts_on IS NOT NULL AND expires_on IS NOT NULL AND expires_on >= starts_on)']);
            $this->checked($table, 'activated_at', 'DATETIME(6)', ['(activated_at IS NULL AND activated_by_user_id IS NULL) OR (activated_at IS NOT NULL AND activated_by_user_id IS NOT NULL AND starts_on IS NOT NULL AND expires_on IS NOT NULL)', 'starts_on IS NULL OR activated_at IS NOT NULL OR renewed_from_id IS NOT NULL']);
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('entries_granted')->nullable();
            $this->checked($table, 'entries_remaining', 'INTEGER UNSIGNED', ['(entries_granted IS NULL AND entries_remaining IS NULL) OR (entries_granted IS NOT NULL AND entries_remaining IS NOT NULL AND entries_granted >= 0 AND entries_remaining >= 0 AND entries_remaining <= entries_granted)']);
            $table->timestamps(6);
            $table->index(['holder_user_id', 'starts_on']);
            $table->index(['holder_phone', 'expires_on']);
            $table->index(['holder_phone', 'starts_on']);
        });
        Schema::create('membership_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('used_at', 6);
            $table->uuid('request_id')->unique();
            $table->dateTime('created_at', 6);
            $table->index(['membership_id', 'used_at']);
        });
        Schema::create('membership_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_membership_id')->nullable()->constrained('memberships')->restrictOnDelete();
            $table->string('kind', 30);
            $table->integer('delta_entries');
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 250)->nullable();
            $table->string('idempotency_key', 190)->unique();
            $table->dateTime('created_at', 6);
            $table->index(['membership_id', 'created_at']);
        });
        Schema::create('document_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30)->default('membership_pdf');
            $table->string('recipient_email', 254);
            $table->dateTime('scheduled_for', 6);
            $table->string('status', 20)->default('pending');
            $table->dateTime('sent_at', 6)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->string('idempotency_key', 190)->unique();
            $table->timestamps(6);
            $table->index(['status', 'scheduled_for']);
        });
        Schema::create('document_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_delivery_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 20);
            $table->string('error_code', 100)->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('attempted_at', 6);
            $table->unique(['document_delivery_id', 'attempt_number'], 'delivery_attempt_number_unique');
        });
        if (DB::getDriverName() === 'mysql') {
            foreach ($this->tableChecks as $table => $checks) {
                foreach ($checks as $name => $expression) {
                    DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ($expression)");
                }
            }
        }
    }

    /** @param list<string> $expressions */
    private function checked(Blueprint $table, string $column, string $type, array $expressions, bool $nullable = true): void
    {
        $definition = $type;
        foreach ($expressions as $index => $expression) {
            if (DB::getDriverName() === 'sqlite') {
                $definition .= " CHECK ($expression)";
            }
            $this->tableChecks[$table->getTable()][$table->getTable().'_'.$column.'_check_'.$index] = $expression;
        }
        $table->rawColumn($column, $definition)->nullable($nullable);
    }

    private function money(Blueprint $table, string $column): void
    {
        $type = DB::getDriverName() === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED';
        $integer = DB::getDriverName() === 'sqlite' ? "typeof($column) = 'integer' AND " : '';
        $table->rawColumn($column, "$type CHECK ($integer$column >= 0 AND $column <= 9223372036854775807)");
    }

    public function down(): void
    {
        // A populated renewal chain has a self-referencing RESTRICT FK, including on SQLite.
        Schema::withoutForeignKeyConstraints(function (): void {
            foreach (['document_delivery_attempts', 'document_deliveries', 'membership_adjustments', 'membership_entries', 'memberships', 'payments', 'orders', 'membership_plans'] as $table) {
                Schema::dropIfExists($table);
            }
        });
    }
};
