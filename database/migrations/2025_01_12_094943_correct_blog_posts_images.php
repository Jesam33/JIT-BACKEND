<?php

use Botble\Media\Facades\RvMedia;
use Botble\Media\Models\MediaFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('posts') || ! Schema::hasTable('media_files')) {
            return;
        }

        $mediaFiles = MediaFile::query()
            ->select(['id', 'url', 'folder_id', 'mime_type'])
            ->whereIn('url', DB::table('posts')->pluck('image')->all())
            ->get();

        foreach ($mediaFiles as $mediaFile) {
            try {
                /**
                 * @var MediaFile $mediaFile
                 */
                RvMedia::generateThumbnails($mediaFile);
            } catch (Throwable $e) {
                logger($e);
            }
        }
    }
};
