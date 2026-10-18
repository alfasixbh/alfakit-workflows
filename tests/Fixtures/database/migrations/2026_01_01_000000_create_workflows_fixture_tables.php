<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The fixture `users` table already has a `role` column.
        Schema::create('wf_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->nullable();
            $table->string('number');
            $table->decimal('total', 12, 2)->default(0);
            $table->string('state')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wf_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }
};
