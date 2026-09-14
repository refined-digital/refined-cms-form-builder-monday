<?php

namespace RefinedDigital\Monday\Module\Providers;

use Illuminate\Support\ServiceProvider;
use RefinedDigital\CMS\Modules\Core\Aggregates\FormBuilderIntegrationAggregate;
use RefinedDigital\Monday\Commands\Install;
use RefinedDigital\Monday\Module\Classes\Process;

class FormBuilderMondayServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/monday.php', 'monday');

        // registered in register() so anything reading the aggregate during boot sees it
        app(FormBuilderIntegrationAggregate::class)->register('monday', [
            'name'        => 'monday.com',
            'description' => 'Create an item on a monday.com board from each submission',
            'icon'        => $this->icon(),
            'processor'   => Process::class,
            // token lives in .env and column ids come from each field's Merge Field;
            // the board is the only thing that differs per form
            'settings'    => [
                ['name' => 'board_id', 'label' => 'Board ID', 'type' => 'text', 'required' => true],
                ['name' => 'group_id', 'label' => 'Group ID', 'type' => 'text', 'required' => false],
                [
                    'name'   => 'clear_cache',
                    'label'  => 'Column cache',
                    'type'   => 'action',
                    'button' => 'Clear cache',
                    'note'   => 'Column types are cached for a day. Clear after changing the board\'s columns.',
                ],
            ],
            'sortable'    => false,
        ]);
    }

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../Config/monday.php' => config_path('monday.php'),
        ], 'monday-config');

        try {
            if ($this->app->runningInConsole()) {
                if (\DB::connection()->getDatabaseName() && !file_exists(config_path('monday.php'))) {
                    $this->commands([
                        Install::class,
                    ]);
                }
            }
        } catch (\Exception $e) {}
    }

    /**
     * monday.com glyph (inline SVG, currentColor) shown in the integrations panel.
     */
    protected function icon(): string
    {
        return '<svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">'
            .'<path d="M3.3 18.5a2.3 2.3 0 0 1-2-3.5l4.2-6.6a2.3 2.3 0 1 1 3.9 2.5L5.2 17.4a2.3 2.3 0 0 1-1.9 1.1Zm7.3 0a2.3 2.3 0 0 1-2-3.5l4.2-6.6a2.3 2.3 0 1 1 3.9 2.5l-4.2 6.5a2.3 2.3 0 0 1-1.9 1.1Z"/>'
            .'<circle cx="20.4" cy="16.2" r="2.3"/>'
            .'</svg>';
    }
}
