<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('mobile_popups', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('show_decline_button')->default(false);
            $table->string('accept_label', 50)->nullable();
            $table->string('decline_label', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::connection('mysql')->create('mobile_popup_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mobile_popup_id')->constrained('mobile_popups')->cascadeOnDelete();
            $table->longText('html_content')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['mobile_popup_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('mobile_popup_messages');
        Schema::connection('mysql')->dropIfExists('mobile_popups');
    }
};
