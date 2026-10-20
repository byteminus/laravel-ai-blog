<?php

namespace ByteMinus\AiBlog\Tests;

use ByteMinus\AiBlog\AiBlogServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // laravel/ai and laravel/mcp are optional; when installed, boot them so their tests can run.
        return array_merge(
            class_exists(AiServiceProvider::class) ? [AiServiceProvider::class] : [],
            class_exists(McpServiceProvider::class) ? [McpServiceProvider::class] : [],
            [AiBlogServiceProvider::class],
        );
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('ai-blog.ai.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['create_ai_blog_tables', 'create_ai_blog_command_tables', 'create_ai_blog_media_table'] as $stub) {
            $migration = include __DIR__."/../database/migrations/{$stub}.php.stub";
            $migration->up();
        }
    }
}
