<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Subjects, users and tenants are stored as string morphs, so integer, UUID and ULID keys
 * all work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alfakit_workflow_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow');
            $table->string('transition');
            $table->string('subject_type');
            $table->string('subject_id');
            $table->string('tenant_type')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('panel')->nullable();
            $table->string('requester_type')->nullable();
            $table->string('requester_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->string('from_state')->nullable();
            $table->json('data')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'status'], 'alfakit_wf_requests_subject');
            $table->index(['tenant_type', 'tenant_id', 'status'], 'alfakit_wf_requests_tenant');
        });

        Schema::create('alfakit_workflow_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('alfakit_workflow_approval_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('step')->default(0);
            $table->string('step_name');
            $table->string('approver_type');
            $table->string('approver_id');
            $table->string('status', 16)->default('pending');
            $table->foreignId('replaces_id')->nullable()->constrained('alfakit_workflow_approvals')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['approver_type', 'approver_id', 'status'], 'alfakit_wf_approvals_approver');
            $table->index(['request_id', 'step', 'status'], 'alfakit_wf_approvals_step');
        });

        Schema::create('alfakit_workflow_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow');
            $table->string('subject_type');
            $table->string('subject_id');
            $table->string('tenant_type')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('event', 32);
            $table->string('transition')->nullable();
            $table->string('from_state')->nullable();
            $table->string('to_state')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->foreignId('request_id')->nullable()->constrained('alfakit_workflow_approval_requests')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('data')->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id'], 'alfakit_wf_logs_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_workflow_logs');
        Schema::dropIfExists('alfakit_workflow_approvals');
        Schema::dropIfExists('alfakit_workflow_approval_requests');
    }
};
