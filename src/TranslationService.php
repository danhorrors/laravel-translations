<?php

namespace danhorrors\Translations;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\File;

class TranslationService
{
    protected $langPath;

    public function __construct()
    {
        // Assumes a typical Laravel structure.
        $this->langPath = resource_path('lang');
        echo "[TranslationService] Using language path: {$this->langPath}\n";
    }
    /**
     * Scan language directories and extract translations.
     */
    public function scanLanguageFiles($targetFile = null)
    {
        $languages = array_filter(scandir($this->langPath), function ($dir) {
            return is_dir($this->langPath . '/' . $dir) && !in_array($dir, ['.', '..']);
        });

        $translations = [];
        foreach ($languages as $lang) {
            $directory = $this->langPath . '/' . $lang;
            $files = glob($directory . '/*.php');

            foreach ($files as $filePath) {
                $filename = basename($filePath, '.php');
                if ($targetFile && $filename !== $targetFile) {
                    continue;
                }
                $data = include $filePath;
                $flatData = $this->flattenArray($data, $filename);
                foreach ($flatData as $key => $value) {
                    $translations[$filename][$key][$lang] = $value;
                }
            }
        }
        return $translations;
    }

    /**
     * Export translations to the specified format.
     */
    public function export($format = 'csv', $outputFile = 'translations.csv', $file = null)
    {
        echo "[Export] Starting export in format '{$format}'...\n";
        echo "[Export] Target file filter: " . ($file ?? 'None (export all)') . "\n";

        // If exporting CSV and using the default filename, append a timestamp.
        if ($format === 'csv' && $outputFile === 'translations.csv') {
            $timestamp = date('Ymd_His');
            $outputFile = "translations_{$timestamp}.csv";
            echo "[Export] Default CSV filename detected. Changed to include timestamp: $outputFile\n";
        }

        $translations = $this->scanLanguageFiles($file);
        echo "[Export] Scan complete. Found " . count($translations) . " translation file(s).\n";

        $languages = $this->extractLanguages($translations);
        echo "[Export] Languages found: " . implode(', ', $languages) . "\n";

        $fullOutput = storage_path($outputFile);
        echo "[Export] Full output file path: $fullOutput\n";

        switch ($format) {
            case 'json':
                File::put($fullOutput, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo "[Export] JSON file written.\n";
                break;
            case 'xlsx':
                $this->createExcel($translations, $languages, $fullOutput);
                echo "[Export] Excel file created.\n";
                break;
            case 'xml':
                $this->createXML($translations, $fullOutput);
                echo "[Export] XML file created.\n";
                break;
            default:
                $this->createCSV($translations, $languages, $fullOutput);
                echo "[Export] CSV file created.\n";
        }

        echo "[Export] Export completed: $outputFile\n";
    }


    /**
     * Import translations from a file in the specified format.
     */
    public function import($format = 'csv', $inputFile = 'translations.csv')
    {
        echo "[Import] Starting import in format '{$format}'...\n";
        $filePath = storage_path($inputFile);
        echo "[Import] Reading file: $filePath\n";

        if (!File::exists($filePath)) {
            throw new \Exception("File not found: $inputFile");
        }

        // Convert file contents into $translations array
        switch ($format) {
            case 'json':
                $translations = json_decode(File::get($filePath), true);
                echo "[Import] JSON data read successfully.\n";
                break;
            case 'xlsx':
                $translations = $this->readExcel($filePath);
                echo "[Import] Excel data read successfully.\n";
                break;
            case 'xml':
                $translations = $this->readXML($filePath);
                echo "[Import] XML data read successfully.\n";
                break;
            default:
                // CSV
                $translations = $this->readCSV($filePath);
                echo "[Import] CSV data read successfully.\n";
        }

        // Merge the CSV-based keys into our existing files
        $this->mergeCSVTranslationsIntoPhp($translations);

        echo "[Import] Import completed: $inputFile\n";
    }
    /**
     * Merge the CSV translations into existing PHP translation files.
     *
     * @param array $translations The data from CSV (or other format) in the structure:
     *   [
     *       'some_file' => [
     *           'some.nested.key' => [
     *               'en' => 'Hello',
     *               'fr' => 'Bonjour',
     *               ...
     *           ],
     *           'another_key' => [
     *               'en' => 'Goodbye',
     *               'fr' => 'Au revoir',
     *               ...
     *           ],
     *       ],
     *       'another_file' => [...],
     *   ]
     */
    protected function mergeCSVTranslationsIntoPhp(array $translations)
    {
        echo "[Update] Merging CSV data into existing PHP translation files...\n";

        // 1) Group everything by filename -> language -> [dotKey => value]
        //    So we can do one read/write per file+language.
        //    $filesToLang = [
        //        'some_file' => [
        //            'en' => [ 'some.nested.key' => 'Hello', 'another_key' => 'Goodbye' ],
        //            'fr' => [ 'some.nested.key' => 'Bonjour', 'another_key' => 'Au revoir' ],
        //        ],
        //        'another_file' => [...]
        //    ];
        $filesToLang = [];

        foreach ($translations as $fileName => $keys) {
            // $keys = [ 'some.nested.key' => [ 'en' => 'Hello', 'fr' => 'Bonjour'], ...]
            foreach ($keys as $dotKey => $langValues) {
                // $langValues = [ 'en' => 'Hello', 'fr' => 'Bonjour' ]
                foreach ($langValues as $lang => $value) {
                    $filesToLang[$fileName][$lang][$dotKey] = $value;
                }
            }
        }

        // 2) For each file/language group, read the existing file, merge the dot-keys, and write back out
        foreach ($filesToLang as $fileName => $langMap) {
            foreach ($langMap as $lang => $dotKeys) {
                // dotKeys => [ 'some.nested.key' => 'Hello', 'another_key' => 'Goodbye', ... ]

                // 2a) Read existing
                $phpFilePath = $this->langPath . '/' . $lang . '/' . $fileName . '.php';
                $existing = $this->readPhpTranslations($phpFilePath);

                // 2b) Merge each CSV key into $existing
                foreach ($dotKeys as $dotKey => $value) {
                    $existing = $this->expandDotNotation($dotKey, $value, $existing);
                }

                // 2c) Write final merged array to disk
                $this->savePhpTranslations($phpFilePath, $existing);

                echo "[Update] Merged $fileName for [$lang], updated ".count($dotKeys)." key(s).\n";
            }
        }

        echo "[Update] All CSV-based keys have been merged into the existing files.\n";
    }
    /**
     * Safely read a PHP translation file into an array.
     */
    protected function readPhpTranslations($phpFilePath)
    {
        if (file_exists($phpFilePath)) {
            // Safely include
            return include $phpFilePath;
        }

        // If it doesn't exist yet, return empty array so we can build one
        return [];
    }

    /**
     * Save an array to a PHP translation file.
     */
    protected function savePhpTranslations($phpFilePath, array $data)
    {
        // Ensure directory exists
        if (!File::isDirectory(dirname($phpFilePath))) {
            File::makeDirectory(dirname($phpFilePath), 0755, true);
        }

        // Write the file
        $content = "<?php\n\nreturn " . var_export($data, true) . ";\n";
        file_put_contents($phpFilePath, $content);

        echo "[Save] Wrote: $phpFilePath\n";
    }
    /**
     * Insert $value into $base array at the path indicated by $dotKey
     * (e.g. "some.nested.key" => [ 'some' => [ 'nested' => [ 'key' => $value ] ] ])
     */
    protected function expandDotNotation($dotKey, $value, array $base = [])
    {
        $keys = explode('.', $dotKey);
        $current = &$base;
        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }
        $current = $value;

        return $base;
    }

    /**
     * Export only the missing translations to a CSV file.
     * A translation is considered missing if at least one language is empty or not set.
     */
    public function exportMissing($outputFile = 'missing_translations.csv', $targetFile = null)
    {
        echo "[Missing] Starting missing translations export...\n";
        $translations = $this->scanLanguageFiles($targetFile);
        $languages = $this->extractLanguages($translations);
        echo "[Missing] Languages found: " . implode(', ', $languages) . "\n";

        $missing = [];
        $totalMissingCount = 0;
        foreach ($translations as $file => $keys) {
            foreach ($keys as $key => $vals) {
                $isMissing = false;
                foreach ($languages as $lang) {
                    if (!isset($vals[$lang]) || trim($vals[$lang]) === '') {
                        $isMissing = true;
                        break;
                    }
                }
                if ($isMissing) {
                    $missing[$file][$key] = $vals;
                    $totalMissingCount++;
                }
            }
        }
        echo "[Missing] Total missing translation rows: " . $totalMissingCount . "\n";

        $fullOutput = storage_path($outputFile);
        echo "[Missing] Writing missing translations to CSV at: $fullOutput\n";
        $handle = fopen($fullOutput, 'w');
        fputcsv($handle, array_merge(['File', 'Key'], $languages));
        $rowCount = 0;
        foreach ($missing as $file => $keys) {
            foreach ($keys as $key => $vals) {
                $row = [$file, $key];
                foreach ($languages as $lang) {
                    $row[] = isset($vals[$lang]) ? $vals[$lang] : '';
                }
                fputcsv($handle, $row);
                $rowCount++;
            }
        }
        fclose($handle);
        echo "[Missing] Wrote $rowCount row(s) of missing translations to CSV.\n";
    }

    /**
     * Export unused translations to a CSV file.
     * Unused translations are those keys defined in your translation files but not found in any view.
     */
    public function exportUnused($outputFile = 'unused_translations.csv', $targetFile = null)
    {
        echo "[Unused] Starting export of unused translations...\n";
        $translations = $this->scanLanguageFiles($targetFile);
        echo "[Unused] Scanned translations. Found " . count($translations) . " file(s).\n";

        // Build a list of all defined translation keys.
        $allKeys = [];
        foreach ($translations as $file => $keys) {
            foreach ($keys as $fullKey => $data) {
                $allKeys[] = $fullKey;
            }
        }
        $allKeys = array_unique($allKeys);
        echo "[Unused] Total translation keys found: " . count($allKeys) . "\n";

        // Scan views for translation keys.
        $usedKeys = $this->scanViewsForTranslationKeys();
        echo "[Unused] Total used translation keys found in views: " . count($usedKeys) . "\n";

        // Unused keys are those defined but not used.
        $unusedKeys = array_diff($allKeys, $usedKeys);
        echo "[Unused] Total unused translation keys: " . count($unusedKeys) . "\n";

        // Build unused translations array.
        $unusedTranslations = [];
        foreach ($translations as $file => $keys) {
            foreach ($keys as $fullKey => $data) {
                if (in_array($fullKey, $unusedKeys)) {
                    $unusedTranslations[$file][$fullKey] = $data;
                }
            }
        }

        $fullOutput = storage_path($outputFile);
        echo "[Unused] Writing unused translations to CSV at: $fullOutput\n";
        $handle = fopen($fullOutput, 'w');
        $languages = $this->extractLanguages($translations);
        fputcsv($handle, array_merge(['File', 'Key'], $languages));
        $rowCount = 0;
        foreach ($unusedTranslations as $file => $keys) {
            foreach ($keys as $fullKey => $data) {
                $row = [$file, $fullKey];
                foreach ($languages as $lang) {
                    $row[] = isset($data[$lang]) ? $data[$lang] : '';
                }
                fputcsv($handle, $row);
                $rowCount++;
            }
        }
        fclose($handle);
        echo "[Unused] Wrote $rowCount row(s) of unused translations to CSV.\n";
    }

    /**
     * Recursively scan all view files (blade files) in resources/views for translation keys.
     */
    protected function scanViewsForTranslationKeys()
    {
        echo "[Views] Scanning view files for translation keys...\n";
        $usedKeys = [];
        $viewsPath = resource_path('views');
        $files = $this->getAllFiles($viewsPath, 'blade.php');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            // Match __(), @lang(), trans(), and trans_choice() functions
            preg_match_all('/(?:__|@lang|trans|trans_choice)\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,[^\)]*)?\)/', $content, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $key) {
                    $usedKeys[] = $key;
                }
            }
        }
        $usedKeys = array_unique($usedKeys);
        echo "[Views] Found " . count($usedKeys) . " unique translation keys in views.\n";
        return $usedKeys;
    }

    /**
     * Recursively retrieve all files in a directory that have the given extension.
     */
    protected function getAllFiles($directory, $extension = '')
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && (!$extension || substr($file->getFilename(), -strlen($extension)) === $extension)) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    /**
     * Flatten a multi-dimensional array into a flat key-value structure using dot notation.
     */
    protected function flattenArray($array, $prefix = '')
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix . '.' . $key;
            if (is_array($value)) {
                $result += $this->flattenArray($value, $newKey);
            } else {
                $result[$newKey] = $value;
            }
        }
        return $result;
    }

    /**
     * Extract available languages from the translations array.
     * Forces English ('en') to appear first if available.
     */
    protected function extractLanguages($translations)
    {
        $langs = [];
        foreach ($translations as $file => $keys) {
            foreach ($keys as $key => $trans) {
                foreach (array_keys($trans) as $lang) {
                    $langs[$lang] = true;
                }
            }
        }
        $langList = array_keys($langs);
        if (($key = array_search('en', $langList)) !== false) {
            unset($langList[$key]);
            array_unshift($langList, 'en');
        }
        echo "[Extract] Languages extracted: " . implode(', ', $langList) . "\n";
        return $langList;
    }

    /**
     * Create a CSV file from translations.
     */
    protected function createCSV($data, $languages, $outputFile)
    {
        echo "[CSV] Creating CSV file at: $outputFile\n";
        $file = fopen($outputFile, 'w');
        fputcsv($file, array_merge(['File', 'Key'], $languages));
        $rowCount = 0;
        foreach ($data as $filename => $keys) {
            foreach ($keys as $key => $translations) {
                $row = [$filename, $key];
                foreach ($languages as $lang) {
                    $row[] = $translations[$lang] ?? '';
                }
                fputcsv($file, $row);
                $rowCount++;
            }
        }
        fclose($file);
        echo "[CSV] Wrote $rowCount row(s) to CSV.\n";
    }

    /**
     * Create an Excel file from translations.
     */
    protected function createExcel($data, $languages, $outputFile)
    {
        echo "[Excel] Creating Excel file at: $outputFile\n";
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $header = array_merge(['File', 'Key'], $languages);
        $sheet->fromArray($header, null, 'A1');
        $row = 2;
        foreach ($data as $filename => $keys) {
            foreach ($keys as $key => $translations) {
                $rowData = array_merge([$filename, $key], array_map(function ($lang) use ($translations) {
                    return $translations[$lang] ?? '';
                }, $languages));
                $sheet->fromArray($rowData, null, "A{$row}");
                $row++;
            }
        }
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputFile);
        echo "[Excel] Excel file created with " . ($row - 2) . " data row(s).\n";
    }

    /**
     * Create an XML file from translations.
     */
    protected function createXML($data, $outputFile)
    {
        echo "[XML] Creating XML file at: $outputFile\n";
        $xml = new \SimpleXMLElement('<translations/>');
        foreach ($data as $file => $keys) {
            $fileElement = $xml->addChild($file);
            foreach ($keys as $key => $translations) {
                $entry = $fileElement->addChild('entry');
                $entry->addAttribute('key', $key);
                foreach ($translations as $lang => $value) {
                    $entry->addChild($lang, htmlspecialchars($value));
                }
            }
        }
        $xml->asXML($outputFile);
        echo "[XML] XML file created.\n";
    }

    /**
     * Read a CSV file and return translations.
     */
    protected function readCSV($inputFile)
    {
        echo "[CSV] Reading CSV file: $inputFile\n";
        $translations = [];
        if (($handle = fopen($inputFile, 'r')) !== false) {
            $header = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                $file = $row[0];
                $key = $row[1];
                for ($i = 2; $i < count($header); $i++) {
                    $lang = $header[$i];
                    $translations[$file][$key][$lang] = $row[$i] ?? '';
                }
            }
            fclose($handle);
        }
        echo "[CSV] Read CSV with " . count($translations) . " file(s) of translations.\n";
        return $translations;
    }

    /**
     * Read an Excel file and return translations.
     */
    protected function readExcel($inputFile)
    {
        echo "[Excel] Reading Excel file: $inputFile\n";
        $spreadsheet = IOFactory::load($inputFile);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        $translations = [];
        $header = [];
        $first = true;
        foreach ($rows as $row) {
            if ($first) {
                $header = array_values($row);
                $first = false;
                continue;
            }
            $rowData = array_values($row);
            $file = $rowData[0];
            $key = $rowData[1];
            for ($i = 2; $i < count($header); $i++) {
                $lang = $header[$i];
                $translations[$file][$key][$lang] = $rowData[$i] ?? '';
            }
        }
        echo "[Excel] Read Excel file successfully.\n";
        return $translations;
    }

    /**
     * Read an XML file and return translations.
     */
    protected function readXML($inputFile)
    {
        echo "[XML] Reading XML file: $inputFile\n";
        $xml = simplexml_load_file($inputFile);
        $translations = [];
        foreach ($xml->children() as $fileElement) {
            $file = $fileElement->getName();
            foreach ($fileElement->entry as $entry) {
                $key = (string)$entry['key'];
                foreach ($entry->children() as $langElement) {
                    $lang = $langElement->getName();
                    $translations[$file][$key][$lang] = (string)$langElement;
                }
            }
        }
        echo "[XML] XML file read successfully.\n";
        return $translations;
    }

    /**
     * Update translation PHP files with imported translations.
     */
    protected function updateTranslations($langPath, $translations)
    {
        echo "[Update] Starting update of translation files...\n";
        foreach ($translations as $file => $keys) {
            foreach ($keys as $key => $langs) {
                foreach ($langs as $lang => $value) {
                    $existing = $this->readExistingTranslations($langPath, $lang, $file);
                    if (strpos($key, '.') !== false) {
                        $keysArray = explode('.', $key);
                        $temp = &$existing;
                        foreach ($keysArray as $k) {
                            if (!isset($temp[$k])) {
                                $temp[$k] = [];
                            }
                            $temp = &$temp[$k];
                        }
                        $temp = $value;
                    } else {
                        $existing[$key] = $value;
                    }
                    $this->saveTranslationFile($langPath, $lang, $file, $existing);
                    echo "[Update] Updated '$key' for file '$file' in language '$lang'.\n";
                }
            }
        }
        echo "[Update] Completed updating translation files.\n";
    }

    /**
     * Read existing translations from a PHP file.
     */
    protected function readExistingTranslations($langPath, $lang, $file)
    {
        $phpFilePath = $langPath . '/' . $lang . '/' . $file . '.php';
        if (file_exists($phpFilePath)) {
            echo "[Read] Reading existing translations from: $phpFilePath\n";
            return include $phpFilePath;
        }
        echo "[Read] No existing file found for '$file' in language '$lang'. Creating new.\n";
        return [];
    }

    /**
     * Save the updated translation data back to the PHP file.
     */
    protected function saveTranslationFile($langPath, $lang, $file, $data)
    {
        $phpFilePath = $langPath . '/' . $lang . '/' . $file . '.php';
        $content = "<?php\n\nreturn " . var_export($data, true) . ";\n";
        file_put_contents($phpFilePath, $content);
        echo "[Save] Saved updated translations to: $phpFilePath\n";
    }
}
