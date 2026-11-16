<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class CmsImage implements ValidationRule
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public const array EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif'];

    public static function mimeType(UploadedFile $file): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());

        return $mime === false ? null : $mime;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Nie udało się odczytać zdjęcia. Wybierz plik ponownie.');

            return;
        }
        if ($value->getSize() > self::MAX_BYTES) {
            $fail('Zdjęcie może mieć maksymalnie 5 MB.');

            return;
        }
        $info = @getimagesize($value->getPathname());
        if ($info === false || ! isset(self::EXTENSIONS[self::mimeType($value) ?? '']) || $info['mime'] !== self::mimeType($value)) {
            $fail('Wybierz poprawne zdjęcie JPEG, PNG, WebP lub AVIF.');

            return;
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 24000000) {
            $fail('Zdjęcie może mieć do 6000 pikseli na bok i łącznie do 24 milionów pikseli.');
        }
    }
}
