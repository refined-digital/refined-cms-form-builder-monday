<?php

namespace RefinedDigital\Monday\Commands;

use Illuminate\Console\Command;

class Install extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'refinedCMS:install-form-builder-monday';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Installs the form builder monday.com module';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->updateEnvFile();
        $this->publishConfig();
        $this->info('monday.com Form Builder has been successfully installed');
    }

    protected function updateEnvFile()
    {
        $env = app()->environmentFilePath();
        $file = file_get_contents($env);

        // only add the keys that aren't already present
        $vars = ['MONDAY_TOKEN', 'MONDAY_ERROR_EMAIL'];
        $missing = array_filter($vars, fn ($var) => ! preg_match('/^'.$var.'=/m', $file));

        if ($missing) {
            $file .= "\n\n".implode("\n", array_map(fn ($var) => $var.'=', $missing));
            file_put_contents($env, $file);
        }
    }

    protected function publishConfig()
    {
        \Artisan::call('vendor:publish', [
            '--tag' => 'monday-config',
        ]);
    }
}
