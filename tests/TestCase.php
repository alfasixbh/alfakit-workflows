<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitWorkflows\AlfakitWorkflowsServiceProvider;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Document;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Invoice;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\WfUser;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Panels\WorkflowsPanelProvider;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Panels\WorkflowsTenantPanelProvider;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Policies\InvoicePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use LogicException;
use Pest\TestSuite;

abstract class TestCase extends AlfakitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::policy(Invoice::class, InvoicePolicy::class);
        Document::define(null);
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AlfakitWorkflowsServiceProvider::class,
            WorkflowsPanelProvider::class,
            WorkflowsTenantPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', WfUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }

    /**
     * The running test (Pest binds `$this` to it; this keeps static analysis typed).
     */
    public static function current(): static
    {
        $test = TestSuite::getInstance()->test;

        if (! $test instanceof static) {
            throw new LogicException('No test of this suite is running.');
        }

        return $test;
    }

    public function user(string $name = 'Ada', ?string $role = null): WfUser
    {
        return WfUser::query()->create([
            'name' => $name,
            'email' => strtolower($name).uniqid().'@example.com',
            'password' => 'password',
            'role' => $role,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function invoice(float $total = 500, ?Team $team = null, array $attributes = []): Invoice
    {
        return Invoice::query()->create([
            'number' => 'INV-'.random_int(1000, 9999),
            'total' => $total,
            'team_id' => $team?->getKey(),
            ...$attributes,
        ]);
    }

    public function team(string $name): Team
    {
        return Team::query()->create(['name' => $name, 'slug' => strtolower($name)]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public function inertiaGet(string $url): TestResponse
    {
        return $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
    }

    /**
     * @return array<string, mixed>
     */
    public function pageProps(string $url): array
    {
        return $this->inertiaGet($url)->assertOk()->json('props.page');
    }

    /**
     * Run a page header action as the renderer does.
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public function runPageAction(string $url, string $action, array $data = [], string $panel = 'workflows', ?string $token = null): TestResponse
    {
        $token ??= $this->pageProps($url)['token'];
        $base = '/'.trim(explode('/invoices', $url)[0], '/');

        return $this->postJson($base.'/_alfakit/actions', ['token' => $token, 'action' => $action, 'data' => $data, 'state' => []]);
    }
}
