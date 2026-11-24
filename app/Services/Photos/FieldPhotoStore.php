<?php

namespace App\Services\Photos;

use App\Models\FieldPhoto;
use App\Models\PhotoProject;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Enregistrement d'une photo de terrain : fichier original sur le disque privé, date de prise de vue et position GPS
 * lues dans l'EXIF (JPEG) si présentes, vignette JPEG (640 px, orientation corrigée) pour la galerie.
 */
class FieldPhotoStore
{
    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const THUMB_SIZE = 640;

    /**
     * @param  array{team_id?: ?int, village_id?: ?int, author_name?: ?string, place?: ?string, caption?: ?string}  $meta
     * @param  ?string  $clientTakenAt  date du fichier côté appareil (photo réduite avant l'envoi : EXIF perdu)
     */
    public function store(PhotoProject $project, UploadedFile $file, array $meta, ?string $ip = null, ?string $clientTakenAt = null): FieldPhoto
    {
        $mime = $file->getMimeType();
        $ext = self::MIMES[$mime] ?? 'jpg';
        $dir = 'field-photos/'.$project->id.'/'.now()->format('Y-m');
        $name = (string) Str::uuid();
        $path = $file->storeAs($dir, "{$name}.{$ext}", FieldPhoto::DISK);
        $absolute = Storage::disk(FieldPhoto::DISK)->path($path);

        $exif = $mime === 'image/jpeg' && function_exists('exif_read_data') ? (@exif_read_data($absolute) ?: []) : [];
        [$width, $height] = @getimagesize($absolute) ?: [null, null];
        $gps = self::gps($exif);

        return FieldPhoto::create([
            'photo_project_id' => $project->id,
            'team_id' => $meta['team_id'] ?? null,
            'village_id' => $meta['village_id'] ?? null,
            'author_name' => $meta['author_name'] ?? null,
            'place' => $meta['place'] ?? null,
            'caption' => $meta['caption'] ?? null,
            'path' => $path,
            'thumb_path' => $this->thumbnail($absolute, $mime, (int) ($exif['Orientation'] ?? 1), "{$dir}/thumbs/{$name}.jpg"),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'taken_at' => self::takenAt($exif) ?? self::clientDate($clientTakenAt),
            'latitude' => $gps[0] ?? null,
            'longitude' => $gps[1] ?? null,
            'uploader_ip' => $ip,
        ]);
    }

    /** Vignette JPEG ; null si l'image ne peut pas être lue (la galerie affiche alors l'original). */
    private function thumbnail(string $absolute, string $mime, int $orientation, string $thumbPath): ?string
    {
        if (! function_exists('imagecreatefromjpeg')) {
            return null;
        }
        try {
            $image = match ($mime) {
                'image/png' => @imagecreatefrompng($absolute),
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : false,
                default => @imagecreatefromjpeg($absolute),
            };
            if (! $image) {
                return null;
            }
            $image = match ($orientation) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            };
            $thumb = imagescale($image, min(self::THUMB_SIZE, imagesx($image)));
            Storage::disk(FieldPhoto::DISK)->makeDirectory(dirname($thumbPath));
            $ok = imagejpeg($thumb, Storage::disk(FieldPhoto::DISK)->path($thumbPath), 80);
            imagedestroy($image);
            imagedestroy($thumb);

            return $ok ? $thumbPath : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function takenAt(array $exif): ?CarbonImmutable
    {
        $value = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
        if (! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y:m:d H:i:s', trim($value)) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function clientDate(?string $value): ?CarbonImmutable
    {
        if (! $value || ! is_numeric($value)) {
            return null;
        }
        $date = CarbonImmutable::createFromTimestampMs((int) $value, config('app.timezone'));

        // Date plausible seulement (pas dans le futur, pas avant 2020).
        return $date->year >= 2020 && $date->lessThanOrEqualTo(now()->addDay()) ? $date : null;
    }

    /** @return array{0: float, 1: float}|null */
    private static function gps(array $exif): ?array
    {
        if (! isset($exif['GPSLatitude'], $exif['GPSLongitude'])) {
            return null;
        }
        $toDegrees = function (array $parts): ?float {
            $values = array_map(function ($part) {
                [$num, $den] = array_pad(explode('/', (string) $part), 2, 1);

                return (float) $den ? (float) $num / (float) $den : 0.0;
            }, $parts);

            return count($values) === 3 ? $values[0] + $values[1] / 60 + $values[2] / 3600 : null;
        };
        $lat = $toDegrees((array) $exif['GPSLatitude']);
        $lng = $toDegrees((array) $exif['GPSLongitude']);
        if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0)) {
            return null;
        }

        return [
            round(($exif['GPSLatitudeRef'] ?? 'N') === 'S' ? -$lat : $lat, 7),
            round(($exif['GPSLongitudeRef'] ?? 'E') === 'W' ? -$lng : $lng, 7),
        ];
    }
}
