<?php

namespace App\Support;

/**
 * Normalises stored image references (profile photos, institute logo/cover).
 *
 * The bug this fixes: uploads used to persist `asset('storage/'.$path)`, which
 * FREEZES whatever host was active at upload time (e.g. http://localhost:8000, or
 * an http:// domain) into the database. When the frontend later loads that URL
 * from a different host, the image 404s — the "profile image doesn't show" report.
 *
 * The rule now: WRITE a relative disk path (toStorage), READ an absolute URL
 * rebuilt against the CURRENT host (url). Existing rows that still hold a baked-in
 * absolute URL are healed on read — the host is rebuilt from the /storage/ path —
 * so no data migration is needed, and the value self-corrects on its next write.
 * Genuinely external URLs (not under /storage/) are passed through untouched.
 */
class MediaUrl
{
    /**
     * The value to STORE in the column: the relative public-disk path.
     *
     * A storage asset — a bare "profile-photos/x.jpg", a "/storage/…" path, or a
     * full "https://host/storage/…" URL — collapses to the path under the disk.
     * A non-storage absolute URL (an external avatar) is preserved verbatim.
     * Empty / null → null.
     */
    public static function toStorage(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }
        if (preg_match('#/storage/(.+)$#', $value, $m)) {
            return ltrim($m[1], '/');
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value; // external URL — keep as given
        }
        return ltrim($value, '/'); // already a bare relative path
    }

    /**
     * The value to DISPLAY: an absolute URL rebuilt against the current host, so a
     * stale host baked in at upload time is corrected on read. External (non
     * /storage/) URLs pass through unchanged. Empty / null → null.
     */
    public static function url(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }
        if (preg_match('#/storage/(.+)$#', $value, $m)) {
            return asset('storage/' . ltrim($m[1], '/'));
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value; // external URL — display as given
        }
        return asset('storage/' . ltrim($value, '/')); // bare relative path
    }
}
