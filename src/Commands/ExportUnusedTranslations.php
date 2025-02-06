<?php

namespace danhorrors\Translations\Commands;

use Illuminate\Console\Command;
use danhorrors\Translations\TranslationService;

class ExportUnusedTranslations extends Command
{
    protected $signature = 'translations:export-unused {output=unused_translations.csv} {--file=}';
    protected $description = 'Export translation keys that are defined but not used in views to a CSV file';

    public function handle(TranslationService $service)
    {
        $output = $this->argument('output');
        $file = $this->option('file');
        $this->info("Starting unused translations export: output={$output}, file filter: " . ($file ?? 'none'));
        $service->exportUnused($output, $file);
        $this->info('✅ Unused translations exported successfully!');
    }
}
