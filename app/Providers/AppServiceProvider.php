<?php

namespace App\Providers;

use App\Services\DisplayTime;
use App\Services\PortAllocator;
use App\Services\ServerProcesses;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fresh per request (and per test), so a saved setting shows on the next page.
        $this->app->scoped(DisplayTime::class);
        // Per request: the listening ports are read once, and ports already handed out stay taken.
        $this->app->scoped(ServerProcesses::class);
        $this->app->scoped(PortAllocator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with((string) request()->header('X-Forwarded-Proto'), 'https')) {
            URL::forceScheme('https');
        }

        config([
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:32768'],
        ]);

        // Dates in the timezone and format from Settings → General: @datetime($at), @date, @time, @ago.
        foreach (['datetime', 'date', 'time', 'ago'] as $method) {
            Blade::directive($method, fn (string $expression): string => '<?php echo e(app(\\'.DisplayTime::class.'::class)->'.$method.'('.$expression.')); ?>');
        }
    }
}
