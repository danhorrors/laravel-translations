<?php

namespace danhorrors\Translations\Commands;

use Illuminate\Console\Command;
use danhorrors\Translations\TranslationService;

class ImportTranslations extends Command
{
    protected $signature = 'translations:import {format=csv} {input=translations.csv}';
    protected $description = 'Import translations from a specified format';

    public function handle(TranslationService $service)
    {
        $format = $this->argument('format');
        $input = $this->argument('input');
        $this->info("Starting import: format={$format}, input={$input}");
        $service->import($format, $input);
        $this->info('✅ Translations imported successfully!');
    }
}
