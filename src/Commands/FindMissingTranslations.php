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
        $baseLocaleFiles = $this->getFilenames($baseLocaleDirectoryPath);

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

            $this->compareLanguages($baseLocaleDirectoryPath, $baseLocaleFiles, $currentLocaleDirectoryPath, $languageFiles, $currentLocale);
        }

        if (count($onlyLocalesArray) > 0) {
            $locales = array_map(static fn(string $currentLocaleDirectoryPath): string => basename($currentLocaleDirectoryPath), $localeDirectories);
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
     * @param list<string> $baseLanguageFiles Filenames
     * @param list<string> $languageFiles Filenames
     */
    private function compareLanguages(string $baseLanguagePath, array $baseLanguageFiles, string $languagePath, array $languageFiles, string $languageName): void
    {
        foreach ($baseLanguageFiles as $languageFile) {
            /** @var array<string, string|array<string, string>> $baseLanguageFile */
            $baseLanguageFile = File::getRequire("{$baseLanguagePath}/{$languageFile}");

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
     * Compare array keys recursively
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

            if (is_array($value) && is_array($secondArray[$key])) {
                $outputDiff = array_merge($outputDiff, $this->arrayDiffRecursive($value, $secondArray[$key], $fullKey));
            }
        }

        return $outputDiff;
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
