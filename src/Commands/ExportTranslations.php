<?php

namespace danhorrors\Translations\Commands;

use Illuminate\Console\Command;
use danhorrors\Translations\TranslationService;

class ExportTranslations extends Command
{
    protected $signature = 'translations:export {format=csv} {output=translations.csv} {--file=}';
    protected $description = 'Export Laravel translations to a specified format';

    public function handle(TranslationService $service)
    {
        $format = $this->argument('format');
        $output = $this->argument('output');
        $file = $this->option('file');
        $this->info("Starting export: format={$format}, output={$output}, file filter: " . ($file ?? 'none'));
        $service->export($format, $output, $file);
        $this->info('✅ Translations exported successfully!');
    }
}
