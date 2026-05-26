<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/*
ImageUploader

QUOI : Persiste les fichiers d’images événement sur disque sous `public/uploads/events` avec redimensionnement GD.

COMMENT : Déplacement depuis `UploadedFile`, renommage aléatoire, `resizeIfNeeded` si largeur > 1600 px.

OÙ : Utilisé par `EventSubmissionController` et la destruction définitive côté admin.

POURQUOI : Contrôler taille et format des médias sans service de stockage externe.
*/

final class ImageUploader
{
    private const MAX_WIDTH = 1600;
    private const JPEG_QUALITY = 85;

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/events')] private readonly string $uploadDir,
    ) {
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0775, recursive: true);
        }
    }

    public function save(UploadedFile $file, string $prefix): string
    {
        $extension = $file->guessExtension() ?? 'jpg';
        $hash = bin2hex(random_bytes(6));
        $filename = sprintf('%s-%s.%s', $prefix, $hash, $extension);
        $destination = $this->uploadDir . DIRECTORY_SEPARATOR . $filename;

        try {
            $file->move($this->uploadDir, $filename);
        } catch (FileException $e) {
            throw new \RuntimeException('Impossible de sauvegarder le fichier : ' . $e->getMessage());
        }

        $this->resizeIfNeeded($destination);

        return '/uploads/events/' . $filename;
    }

    private function resizeIfNeeded(string $path): void
    {
        if (!extension_loaded('gd')) {
            return;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return;
        }

        [$width, $height, $type] = $info;
        if ($width <= self::MAX_WIDTH) {
            return;
        }

        $newWidth = self::MAX_WIDTH;
        $newHeight = (int) round($height * ($newWidth / $width));

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => null,
        };

        if ($source === null || $source === false) {
            return;
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($resized, $path, self::JPEG_QUALITY),
            IMAGETYPE_PNG => imagepng($resized, $path, 7),
            IMAGETYPE_WEBP => imagewebp($resized, $path, self::JPEG_QUALITY),
        };

        imagedestroy($source);
        imagedestroy($resized);
    }

    public function delete(?string $webPath): void
    {
        if ($webPath === null) {
            return;
        }
        $absolute = $this->uploadDir . DIRECTORY_SEPARATOR . basename($webPath);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
