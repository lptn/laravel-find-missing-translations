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
        $baseJsonPath = $pathToLocates . \DIRECTORY_SEPARATOR . $baseLocale . '.json';
        $hasBaseDirectory = File::isDirectory($baseLocaleDirectoryPath);
        if (! $hasBaseDirectory && ! File::isFile($baseJsonPath)) {
            throw new DirectoryNotFoundException("Base locale {$baseLocale} has neither a directory nor a JSON file in {$pathToLocates}.");
        }

        $onlyLocales = $this->option('only');
        $onlyLocalesArray = is_string($onlyLocales) ? explode(',', $onlyLocales) : [];
        $excludeLocales = $this->option('exclude');
        $excludeLocalesArray = is_string($excludeLocales) ? explode(',', $excludeLocales) : [];

        $locales = $this->getLocales($pathToLocates);
        $comparedLocales = array_values(array_filter(
            $locales,
            fn(string $locale): bool => $locale !== $baseLocale
                && (count($onlyLocalesArray) === 0 || in_array($locale, $onlyLocalesArray, true))
                && ! in_array($locale, $excludeLocalesArray, true),
        ));

        if ($hasBaseDirectory) {
            $baseTranslations = $this->loadTranslations($baseLocaleDirectoryPath);

            foreach ($comparedLocales as $currentLocale) {
                $this->info("Comparing {$baseLocale} to {$currentLocale}.", 'v');

                $this->compareLanguages($pathToLocates, $baseTranslations, $currentLocale);
            }
        }

        $this->compareJsonTranslations($pathToLocates, $baseLocale, $comparedLocales);

        $this->reportUnknownLocales('only', $onlyLocalesArray, $locales);
        $this->reportUnknownLocales('exclude', $excludeLocalesArray, $locales);

        $this->info('Successfully compared all languages.');

        return $this->exitCode;
    }

    /**
     * Report locales that were named in --only or --exclude but do not exist
     *
     * A typo in either option silently changes what is compared, so it fails the run.
     *
     * @param list<string> $requestedLocales
     * @param list<string> $existingLocales
     */
    private function reportUnknownLocales(string $option, array $requestedLocales, array $existingLocales): void
    {
        $unknownLocales = array_values(array_diff($requestedLocales, $existingLocales));
        if (count($unknownLocales) === 0) {
            return;
        }

        $this->exitCode = self::FAILURE;

        $this->error("The following locales of --{$option} are missing:", 'quiet');
        $this->table(['locale'], array_map(static fn(string $locale): array => [$locale], $unknownLocales));
    }

    /**
     * Compare the PHP group files of one locale to the base locale
     *
     * A locale that exists as a JSON file only has no group file at all, so every group
     * key of the base locale falls back. That is reported as a missing directory.
     *
     * @param array<string, array<string, string|array<string, string>>> $baseTranslations Filename to its translations
     */
    private function compareLanguages(string $langPath, array $baseTranslations, string $languageName): void
    {
        $languagePath = $langPath . \DIRECTORY_SEPARATOR . $languageName;

        if (! File::isDirectory($languagePath)) {
            $this->exitCode = self::FAILURE;

            $this->error("{$languageName} directory is missing.", 'quiet');

            return;
        }

        $languageFiles = $this->getFilenames($languagePath);

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
     * @param list<string> $comparedLocales
     */
    private function compareJsonTranslations(string $langPath, string $baseLocale, array $comparedLocales): void
    {
        $baseJsonFileName = $baseLocale . '.json';
        $baseJsonPath = $langPath . \DIRECTORY_SEPARATOR . $baseJsonFileName;
        if (! File::isFile($baseJsonPath)) {
            return;
        }

        $baseTranslations = $this->readJsonFile($baseJsonPath);

        foreach ($comparedLocales as $currentLocale) {
            $jsonFileName = $currentLocale . '.json';

            $this->info("Comparing {$baseJsonFileName} to {$jsonFileName}.", 'v');

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
     * A locale declared in one format has not translated the other format at all, so both
     * comparisons walk this set and report the format that is absent. The same set answers
     * whether a locale named in --only or --exclude exists.
     *
     * @return list<string>
     */
    private function getLocales(string $langPath): array
    {
        $locales = [];

        foreach (File::files($langPath) as $fileInfo) {
            if ($fileInfo->getExtension() === 'json') {
                $locales[] = $fileInfo->getBasename('.json');
            }
        }

        /** @var list<string> $directories */
        $directories = File::directories($langPath);

        foreach ($directories as $directory) {
            $localeDirectoryName = basename($directory);
            if ($localeDirectoryName !== self::VENDOR_DIRNAME) {
                $locales[] = $localeDirectoryName;
            }
        }

        return array_values(array_unique($locales));
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
