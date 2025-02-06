<?php

namespace danhorrors\Translations\Commands;

use Illuminate\Console\Command;
use danhorrors\Translations\TranslationService;

class ExportMissingTranslations extends Command
{
    protected $signature = 'translations:export-missing {output=missing_translations.csv} {--file=}';
    protected $description = 'Export missing translations (keys with missing language values) to a CSV file for later import';

    public function handle(TranslationService $service)
    {
        $output = $this->argument('output');
        $file = $this->option('file');
        $this->info("Starting missing translations export: output={$output}, file filter: " . ($file ?? 'none'));
        $service->exportMissing($output, $file);
        $this->info('✅ Missing translations exported successfully!');
    }
}
