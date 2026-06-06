<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $t) {
                $t->id();
                $t->string('external_id')->nullable()->unique();
                $t->string('name');
                $t->string('category')->nullable();
                $t->string('image_url')->nullable();
                $t->text('description')->nullable();
                $t->boolean('is_active')->default(true);
                $t->string('source')->default('local');
                $t->timestamps();
            });
        } else {
            Schema::table('products', function (Blueprint $t) {
                if (!Schema::hasColumn('products','external_id'))  $t->string('external_id')->nullable()->unique();
                if (!Schema::hasColumn('products','source'))       $t->string('source')->default('local');
            });
        }

        if (!Schema::hasTable('product_packages')) {
            Schema::create('product_packages', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->string('external_id')->nullable()->unique();
                $t->string('name');
                $t->decimal('price', 12, 2);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        } else {
            Schema::table('product_packages', function (Blueprint $t) {
                if (!Schema::hasColumn('product_packages','external_id')) $t->string('external_id')->nullable()->unique();
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $t) {
                if (!Schema::hasColumn('orders','external_ref'))  $t->string('external_ref')->nullable()->index();
                if (!Schema::hasColumn('orders','admin_note'))    $t->text('admin_note')->nullable();
                if (!Schema::hasColumn('orders','source'))        $t->string('source')->default('local');
            });
        }
    }

    public function down(): void {}
};
