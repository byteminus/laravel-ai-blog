<?php

namespace ByteMinus\AiBlog;

use ByteMinus\AiBlog\Ai\Contracts\Assistant;
use ByteMinus\AiBlog\Ai\FakeAssistant;
use ByteMinus\AiBlog\Bus\Actor;
use ByteMinus\AiBlog\Bus\CommandRegistry;
use ByteMinus\AiBlog\Commands\AuditCommand;
use ByteMinus\AiBlog\Commands\PublishScheduledCommand;
use ByteMinus\AiBlog\Fetching\HostResolver;
use ByteMinus\AiBlog\Fetching\SystemHostResolver;
use ByteMinus\AiBlog\Mcp\BlogServer;
use ByteMinus\AiBlog\Seo\LinkSuggester;
use ByteMinus\AiBlog\Seo\SeoAnalyzer;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Facades\Mcp;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AiBlogServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-ai-blog')
            ->hasConfigFile('ai-blog')
            ->hasViews('ai-blog')
            ->hasMigrations(['create_ai_blog_tables', 'create_ai_blog_command_tables', 'create_ai_blog_media_table'])
            ->hasCommands([
                PublishScheduledCommand::class,
                AuditCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(SeoAnalyzer::class);
        $this->app->singleton(LinkSuggester::class);
        $this->app->bindIf(HostResolver::class, SystemHostResolver::class);

        $this->app->singleton(Assistant::class, function ($app) {
            if (! config('ai-blog.ai.enabled', true)) {
                return new FakeAssistant;
            }

            return $app->make(config('ai-blog.ai.assistant', FakeAssistant::class));
        });

        $this->app->singleton(AiBlog::class);

        $this->app->singleton(CommandRegistry::class, fn (): CommandRegistry => new CommandRegistry(
            (array) config('ai-blog.commands.handlers', [])
        ));
    }

    public function packageBooted(): void
    {
        // Nobody manages the blog until the host says who may; the host's own
        // Gate::define for this ability replaces this one.
        if (! Gate::has(Actor::GATE)) {
            Gate::define(Actor::GATE, fn (): bool => false);
        }

        if (config('ai-blog.routes.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if (config('ai-blog.mcp.enabled') && class_exists(Mcp::class)) {
            Mcp::web(
                config('ai-blog.mcp.path', '/mcp/ai-blog'),
                BlogServer::class
            )->middleware(config('ai-blog.mcp.middleware', ['auth:sanctum']));
        }
    }
}
