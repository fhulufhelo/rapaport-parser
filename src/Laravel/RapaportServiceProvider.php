<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Laravel;

use Fhulufhelo\Rapaport\RapaportParser;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class RapaportServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(RapaportParser::class, static function (): RapaportParser {
            return RapaportParser::make();
        });

        $this->app->alias(RapaportParser::class, 'rapaport.parser');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExtractCommand::class]);
        }
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [RapaportParser::class, 'rapaport.parser'];
    }
}
