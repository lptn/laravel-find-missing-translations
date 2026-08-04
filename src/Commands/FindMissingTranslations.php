<?php

declare(strict_types=1);

namespace Diglabby\FindMissingTranslations\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;

/**
 * @api
 *
 * Inspired by https://github.com/VetonMuhaxhiri/Laravel-find-missing-translations
 */
class FindMissingTranslations extends Command
{
    /**
     * Package translation overrides live in "lang/vendor/{package}/{locale}", so this directory holds no locales itself.
     */
    private const string VENDOR_DIRNAME = 'vendor';

    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'translations:missing
        {--dir= : Relative path of lang directory, e.g. "/lang", a directory that contains all supported locales. Defaults to the application lang path}
        {--base= : Base locale, e.g. "en". All other locales are compared to this locale}
        {--only= : Only compare specified locales, e.g. "be,de,es,fr". All other locales are ignored}
        {--exclude= : Exclude specified locales, e.g. "be,de,es,fr". All other locales are compared}';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Helps developers to finding words which are not translated, by comparing one base locale to others.';

    private int $exitCode = self::SUCCESS;

    public function handle(): int
    {
        $directoryOption = $this->option('dir');
        $defaultLangPath = $this->laravel->langPath();

        $pathToLocates = match (true) {
            $directoryOption === null && File::isDirectory($defaultLangPath) => $defaultLangPath,
            $directoryOption === null => throw new DirectoryNotFoundException("Default lang directory {$defaultLangPath} does not exist."),
            File::isDirectory($directoryOption) => $directoryOption,
            File::isDirectory(base_path($directoryOption)) => base_path($directoryOption),
            default => throw new DirectoryNotFoundException("Specified resource directory {$directoryOption} does not exist."),
        };

        $baseLocale = $this->option('base') ?? config('app.locale');
        $baseLocaleDirectoryPath = $pathToLocates . \DIRECTORY_SEPARATOR . $baseLocale;
        if (! File::isDirectory($baseLocaleDirectoryPath)) {
            throw new DirectoryNotFoundException("Base locale directory {$baseLocaleDirectoryPath} does not exist.");
        }

        $onlyLocales = $this->option('only');
        $onlyLocalesArray = is_string($onlyLocales) ? explode(',', $onlyLocales) : [];
        $excludeLocales = $this->option('exclude');
        $excludeLocalesArray = is_string($excludeLocales) ? explode(',', $excludeLocales) : [];

        /** @var list<string> $allDirectories */
        $allDirectories = File::directories($pathToLocates);
        $localeDirectories = array_values(array_filter(
            $allDirectories,
            static fn(string $directory): bool => basename($directory) !== self::VENDOR_DIRNAME,
        ));
        $baseTranslations = $this->loadTranslations($baseLocaleDirectoryPath);

        foreach ($localeDirectories as $currentLocaleDirectoryPath) {
            $languageFiles = $this->getFilenames($currentLocaleDirectoryPath);
            $currentLocale = basename($currentLocaleDirectoryPath);

            $isDirectoryForBaseLocale = $baseLocale === $currentLocale;
            if ($isDirectoryForBaseLocale) {
                continue;
            }
            if (count($onlyLocalesArray) > 0 && ! in_array($currentLocale, $onlyLocalesArray, true)) {
                continue;
            }
            if (in_array($currentLocale, $excludeLocalesArray, true)) {
                continue;
            }

            $this->info("Comparing {$baseLocale} to {$currentLocale}.", 'v');

            $this->compareLanguages($baseTranslations, $currentLocaleDirectoryPath, $languageFiles, $currentLocale);
        }

        $locales = array_map(static fn(string $currentLocaleDirectoryPath): string => basename($currentLocaleDirectoryPath), $localeDirectories);

        $this->compareJsonTranslations($pathToLocates, $baseLocale, $locales, $onlyLocalesArray, $excludeLocalesArray);

        if (count($onlyLocalesArray) > 0) {
            $localesMissing = array_values(array_diff($onlyLocalesArray, $locales));
            if (count($localesMissing) > 0) {
                $this->error('The following locales are missing:', 'quiet');
                $this->table(['locale'], array_map(static fn($locale) => [$locale], $localesMissing));
            }
        }

        $this->info('Successfully compared all languages.');

        return $this->exitCode;
    }

    /**
     * @param array<string, array<string, string|array<string, string>>> $baseTranslations Filename to its translations
     * @param list<string> $languageFiles Filenames
     */
    private function compareLanguages(array $baseTranslations, string $languagePath, array $languageFiles, string $languageName): void
    {
        foreach ($baseTranslations as $languageFile => $baseLanguageFile) {
            if (! in_array($languageFile, $languageFiles, true)) {
                $this->exitCode = self::FAILURE;

                $this->comment("Comparing translations in {$languageFile}.", 'v');
                $this->error("{$languageName}/{$languageFile} file is missing.", 'quiet');

                continue;
            }
            /** @var array<string, string|array<string, string>> $secondLanguageFile */
            $secondLanguageFile = File::getRequire("{$languagePath}/{$languageFile}");

            $missingKeys = $this->arrayDiffRecursive($baseLanguageFile, $secondLanguageFile);

            if (count($missingKeys) > 0) {
                $this->exitCode = self::FAILURE;

                $this->error("Found missing translations in /{$languageName}/{$languageFile}:", 'quiet');

                $missingKeyInfo = [];
                foreach ($missingKeys as $missingKey) {
                    $missingKeyInfo[] = [$languageName, $languageFile, $missingKey];
                }

                $this->table(['locale', 'file', 'key'], $missingKeyInfo);
            }
        }
    }

    /**
     * Compare the JSON translation files that live next to the locale directories
     *
     * Laravel reads "lang/{locale}.json" for string keyed translations. Nothing is
     * compared when the base locale has no JSON file: the project does not use them.
     *
     * @param list<string> $localeDirectoryNames Locales that have a directory of PHP group files
     * @param list<string> $onlyLocales
     * @param list<string> $excludeLocales
     */
    private function compareJsonTranslations(string $langPath, string $baseLocale, array $localeDirectoryNames, array $onlyLocales, array $excludeLocales): void
    {
        $baseJsonPath = $langPath . \DIRECTORY_SEPARATOR . $baseLocale . '.json';
        if (! File::isFile($baseJsonPath)) {
            return;
        }

        $baseTranslations = $this->readJsonFile($baseJsonPath);

        foreach ($this->getJsonLocales($langPath, $localeDirectoryNames) as $currentLocale) {
            if ($currentLocale === $baseLocale) {
                continue;
            }
            if (count($onlyLocales) > 0 && ! in_array($currentLocale, $onlyLocales, true)) {
                continue;
            }
            if (in_array($currentLocale, $excludeLocales, true)) {
                continue;
            }

            $this->info("Comparing {$baseLocale} to {$currentLocale}.", 'v');

            $jsonFileName = $currentLocale . '.json';
            $jsonPath = $langPath . \DIRECTORY_SEPARATOR . $jsonFileName;

            if (! File::isFile($jsonPath)) {
                $this->exitCode = self::FAILURE;

                $this->error("{$jsonFileName} file is missing.", 'quiet');

                continue;
            }

            $missingKeys = $this->arrayDiffRecursive($baseTranslations, $this->readJsonFile($jsonPath));

            if (count($missingKeys) > 0) {
                $this->exitCode = self::FAILURE;

                $this->error("Found missing translations in /{$jsonFileName}:", 'quiet');

                $missingKeyInfo = [];
                foreach ($missingKeys as $missingKey) {
                    $missingKeyInfo[] = [$currentLocale, $jsonFileName, $missingKey];
                }

                $this->table(['locale', 'file', 'key'], $missingKeyInfo);
            }
        }
    }

    /**
     * Every locale of the project: the ones with a JSON file and the ones with a directory
     *
     * A locale that has a directory but no JSON file has translated none of the string
     * keys, so it belongs in the comparison and is reported as a missing file.
     *
     * @param list<string> $localeDirectoryNames
     * @return list<string>
     */
    private function getJsonLocales(string $langPath, array $localeDirectoryNames): array
    {
        $jsonLocales = [];

        foreach (File::files($langPath) as $fileInfo) {
            if ($fileInfo->getExtension() === 'json') {
                $jsonLocales[] = $fileInfo->getBasename('.json');
            }
        }

        return array_values(array_unique([...$jsonLocales, ...$localeDirectoryNames]));
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    private function readJsonFile(string $path): array
    {
        try {
            $decoded = json_decode(File::get($path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \JsonException("{$path} is not valid JSON: {$exception->getMessage()}", $exception->getCode(), $exception);
        }

        if (! is_array($decoded)) {
            throw new \JsonException("{$path} does not contain a JSON object of translations.");
        }

        /** @var array<string, string|array<string, string>> $decoded */
        return $decoded;
    }

    /**
     * Compare array keys recursively
     *
     * A key that holds a group in the base locale but a plain string in the compared
     * locale is reported: none of the keys of that group can be resolved there.
     *
     * @param array<array-key, string|array<string, string>> $firstArray
     * @param array<string, string|array<string, string>> $secondArray
     * @param string|null $prefix
     * @return list<string>
     * @psalm-mutation-free
     */
    private function arrayDiffRecursive(array $firstArray, array $secondArray, ?string $prefix = null): array
    {
        $outputDiff = [];

        foreach ($firstArray as $key => $value) {
            $fullKey = $prefix === null ? (string) $key : "{$prefix}.{$key}";

            if (! array_key_exists($key, $secondArray)) {
                $outputDiff[] = $fullKey;

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            if (! is_array($secondArray[$key])) {
                $outputDiff[] = $fullKey;

                continue;
            }

            $outputDiff = array_merge($outputDiff, $this->arrayDiffRecursive($value, $secondArray[$key], $fullKey));
        }

        return $outputDiff;
    }

    /**
     * Read every translation file of a locale directory
     *
     * File::getRequire() runs require(), which re-executes the file on every call, so the
     * base locale is read once here instead of once per compared locale.
     *
     * @return array<string, array<string, string|array<string, string>>> Filename to its translations
     */
    private function loadTranslations(string $directory): array
    {
        $translations = [];

        foreach ($this->getFilenames($directory) as $fileName) {
            /** @var array<string, string|array<string, string>> $fileTranslations */
            $fileTranslations = File::getRequire($directory . \DIRECTORY_SEPARATOR . $fileName);
            $translations[$fileName] = $fileTranslations;
        }

        return $translations;
    }

    /**
     * Get filenames of the directory, including the ones in nested group directories
     *
     * Laravel resolves "lang/en/nested/b.php" as the "nested/b" group, so a nested
     * file is a translation file like any other and its path is part of its name.
     *
     * @return list<string> Filenames relative to a given directory
     */
    private function getFilenames(string $directory): array
    {
        $fileNames = [];

        $filesInFolder = File::allFiles($directory);

        foreach ($filesInFolder as $fileInfo) {
            $fileNames[] = $fileInfo->getRelativePathname();
        }

        return $fileNames;
    }
}
