<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Director;
use App\Models\Generation;
use App\Models\Keyframe;
use App\Models\Media;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Shot;
use App\Models\Tenant;
use App\Models\Upload;
use App\Models\User;
use App\Support\Multitenancy\DatabaseSessionManager;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\ScrambleServiceProvider as BaseScrambleServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\TelescopeServiceProvider as BaseTelescopeServiceProvider;
use Tests\Concerns\CleansUpParallelTestDatabases;

class AppServiceProvider extends ServiceProvider
{
    use CleansUpParallelTestDatabases;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (class_exists(BaseTelescopeServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
            $this->app->register(BaseTelescopeServiceProvider::class);
        }

        if (class_exists(BaseScrambleServiceProvider::class)) {
            $this->app->register(ScrambleServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDate();
        $this->configureEloquent();
        $this->configureGates();
        $this->configureJsonResources();
        $this->configureSanctum();
        $this->configureLogViewer();
        $this->configureSessionHandler();
        $this->configureEnvPath();
        $this->configureHttpClientUserAgent();

        if ($this->app->runningUnitTests()) {
            static::registerCleanupCallbacks();
        }
    }

    private function configureDate()
    {
        Date::use(CarbonImmutable::class);
    }

    private function configureEloquent()
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'tenant' => Tenant::class,
            'director' => Director::class,
            'project' => Project::class,
            'shot' => Shot::class,
            'keyframe' => Keyframe::class,
            'generation' => Generation::class,
            'media' => Media::class,
            'upload' => Upload::class,
        ]);

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes();
    }

    /**
     * Admin users and public directors are different principals with different
     * rules. Policies for admin live in App\Models\Policies, policies for the
     * public app in App\Models\Policies\Public. Which set applies is decided
     * by who is authenticated on the current request.
     */
    private function configureGates()
    {
        Gate::guessPolicyNamesUsing(function (string $modelClass): string {
            $namespace = Auth::user() instanceof Director
                ? 'App\\Models\\Policies\\Public\\'
                : 'App\\Models\\Policies\\';

            return $namespace . class_basename($modelClass) . 'Policy';
        });
    }

    private function configureJsonResources()
    {
        JsonResource::withoutWrapping();
    }

    private function configureSanctum()
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    private function configureLogViewer()
    {
        Gate::define('viewLogViewer', function (?User $user) {
            return $user?->can('admin.logs.view');
        });
    }

    private function configureSessionHandler()
    {
        $this->app['session']->extend('database', function (Application $app) {
            $connection = $app['db']->connection(config('session.connection'));
            $table = config('session.table', 'sessions');
            $minutes = config('session.lifetime');

            return new DatabaseSessionManager(
                $connection,
                $table,
                $minutes,
                $app
            );
        });
    }

    private function configureEnvPath()
    {
        \putenv('PATH=' . config('blueprint.path'));
    }

    private function configureHttpClientUserAgent()
    {
        $userAgent = vsprintf('Mozilla/5.0 (KHTML, like Gecko) (compatible; %s; +%s)', [
            config('app.title'),
            config('app.url'),
        ]);

        Http::globalRequestMiddleware(fn($request) => $request->withHeader('User-Agent', $userAgent));
    }
}
