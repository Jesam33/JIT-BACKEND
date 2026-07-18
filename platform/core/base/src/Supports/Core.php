<?php

namespace Botble\Base\Supports;

use Botble\Base\Exceptions\MissingCURLExtensionException;
use Botble\Base\Exceptions\MissingZipExtensionException;
use Botble\Base\Facades\BaseHelper;
use Botble\Base\Services\ClearCacheService;
use Botble\Base\Supports\ValueObjects\CoreProduct;
use Botble\Setting\Facades\Setting;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToWriteFile;
use Throwable;
use ZipArchive;

/**
 * DO NOT MODIFY THIS FILE.
 *
 * @readonly
 */
final class Core
{
    private string $basePath;

    private string $coreDataFilePath;

    private string $version = '1.0.0';

    private string $minimumPhpVersion = '8.2.0';

    protected static array $coreFileData = [];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly Filesystem $files
    ) {
        $this->basePath = base_path();
        $this->coreDataFilePath = core_path('core.json');

        $this->parseDataFromCoreDataFile();
    }

    public static function make(): self
    {
        return app(self::class);
    }
	
	public function version(): string
    {
        return $this->version;
    }

    public function minimumPhpVersion(): string
    {
        return $this->minimumPhpVersion;
    }

    public function cleanUp(): void
    {
        $this->cleanCaches();
    }

    public function logError(Exception|Throwable $exception): void
    {
        BaseHelper::logError($exception);
    }

    private function publishPaths(): array
    {
        return IlluminateServiceProvider::pathsToPublish(null, 'cms-public');
    }

    public function publishAssets(string $path): void
    {
        foreach ($this->publishPaths() as $from => $to) {
            if (! Str::contains($from, $path)) {
                continue;
            }

            try {
                $this->files->ensureDirectoryExists(dirname($to));
                $this->files->copyDirectory($from, $to);
            } catch (Throwable $exception) {
                $this->logError($exception);
            }
        }
    }

    private function parseDataFromCoreDataFile(): void
    {
        if (! $this->files->exists($this->coreDataFilePath)) {
            return;
        }

        $data = $this->getCoreFileData();

        $this->version = Arr::get($data, 'version', $this->version);
        $this->minimumPhpVersion = Arr::get($data, 'minimumPhpVersion', $this->minimumPhpVersion);
    }

    public function getCoreFileData(): array
    {
        if (self::$coreFileData) {
            return self::$coreFileData;
        }

        if ($this->cache->has('core_file_data') && $coreData = $this->cache->get('core_file_data')) {
            self::$coreFileData = $coreData;

            return $coreData;
        }

        return $this->getCoreFileDataFromDisk();
    }

    private function getCoreFileDataFromDisk(): array
    {
        try {
            $data = json_decode($this->files->get($this->coreDataFilePath), true) ?: [];

            self::$coreFileData = $data;

            $this->cache->forever('core_file_data', $data);

            return $data;
        } catch (FileNotFoundException) {
            return [];
        }
    }

    private function getClientIpAddress(): string
    {
        return Helper::getIpFromThirdParty();
    }

    private function parseProductUpdateResponse(Response $response): CoreProduct|false
    {
        $data = $response->json();

        if ($response->ok() && Arr::get($data, 'status')) {
            return new CoreProduct(
                Arr::get($data, 'update_id'),
                Arr::get($data, 'version'),
                Carbon::createFromFormat('Y-m-d', Arr::get($data, 'release_date')),
                trim((string) Arr::get($data, 'summary')),
                trim((string) Arr::get($data, 'changelog')),
                (bool) Arr::get($data, 'has_sql')
            );
        }

        return false;
    }
}
