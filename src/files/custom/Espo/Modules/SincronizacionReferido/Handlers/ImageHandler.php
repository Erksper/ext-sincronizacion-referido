<?php
namespace Espo\Modules\SincronizacionReferido\Handlers;

use Espo\ORM\EntityManager;

class ImageHandler
{
    private EntityManager $entityManager;
    private const DEFAULT_USER_USERNAME = '0';
    // Campos correctos para tipo image en EspoCRM (usan sufijo Id internamente)
    private const IMAGE_ID_FIELD   = 'cFotopId';  // lo que se lee/escribe en PHP
    private const IMAGE_PATH_FIELD = 'cFoto';     // varchar para guardar el path raw

    private ?string $defaultImageIdCache  = null;
    private bool    $defaultImageResolved = false;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Obtiene el attachment ID de la foto del usuario "0" (imagen por defecto).
     * Usa cache en memoria para no golpear la BD en cada usuario.
     */
    public function getDefaultImageId(): ?string
    {
        if ($this->defaultImageResolved) {
            return $this->defaultImageIdCache;
        }

        $this->defaultImageResolved = true;

        try {
            $defaultUser = $this->entityManager->getRDBRepository('User')
                ->where(['userName' => self::DEFAULT_USER_USERNAME])
                ->findOne();

            if (!$defaultUser) {
                $this->defaultImageIdCache = null;
                return null;
            }

            // Leer con sufijo Id (campo image)
            $imageId = $defaultUser->get(self::IMAGE_ID_FIELD);

            if (empty($imageId)) {
                $imageId = $defaultUser->get('avatarId');
            }

            $this->defaultImageIdCache = !empty($imageId) ? $imageId : null;
            return $this->defaultImageIdCache;

        } catch (\Exception $e) {
            $this->defaultImageIdCache = null;
            return null;
        }
    }

    /**
     * Descarga una imagen desde la URL externa y la guarda como Attachment en EspoCRM.
     */
    public function downloadAndSaveImage(string $fotoPath): ?string
    {
        try {
            $url          = "https://venezuela.21online.lat/" . ltrim($fotoPath, '/');
            $imageContent = @file_get_contents($url);

            if ($imageContent === false || strlen($imageContent) === 0) {
                return null;
            }

            $fileInfo  = pathinfo($fotoPath);
            $extension = strtolower($fileInfo['extension'] ?? 'jpg');
            $fileName  = $fileInfo['basename'] ?? 'avatar.' . $extension;

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($extension, $allowedExtensions)) {
                return null;
            }

            $attachment = $this->entityManager->getNewEntity('Attachment');
            $attachment->set([
                'name'        => $fileName,
                'type'        => $this->getMimeType($extension),
                'role'        => 'Attachment',
                'size'        => strlen($imageContent),
                'relatedType' => 'User',
                'field'       => 'cFotop', // nombre del campo image (sin Id)
            ]);

            $this->entityManager->saveEntity($attachment);

            $filePath = "data/upload/" . $attachment->getId();
            $dir      = dirname($filePath);

            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            if (file_put_contents($filePath, $imageContent) === false) {
                $this->entityManager->removeEntity($attachment);
                return null;
            }

            return $attachment->getId();

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Procesa la imagen de un usuario.
     *
     * CASO 1 — Tiene fotoPath en BD externa:
     *   - Si cFoto actual == fotoPath nuevo → misma foto, no hacer nada.
     *   - Si es diferente → descargar y actualizar.
     *
     * CASO 2 — No tiene fotoPath en BD externa:
     *   - Subcaso A: cFotopId == defaultImageId → correcto, nada que hacer.
     *   - Subcaso B: cFotopId null pero avatarId == defaultImageId → solo poblar cFotopId.
     *   - Subcaso C: ninguno tiene la imagen por defecto → asignar a ambos.
     *
     * @param string|null $fotoPath         Path raw desde BD externa
     * @param string|null $currentImageId   Valor de cFotopId actual
     * @param string|null $currentFotoPath  Valor de cFoto actual (path raw)
     * @param string|null $currentAvatarId  Valor de avatarId actual
     *
     * @return array{imageId: string|null, fotoPath: string|null, updated: bool, syncCFotopOnly: bool}
     */
    public function processUserImage(
        ?string $fotoPath,
        ?string $currentImageId,
        ?string $currentFotoPath = null,
        ?string $currentAvatarId = null
    ): array {
        $result = [
            'imageId'        => $currentImageId,
            'fotoPath'       => $currentFotoPath,
            'updated'        => false,
            'syncCFotopOnly' => false,
        ];

        // ── CASO 1: viene foto desde la BD externa ───────────────────────────
        if (!empty($fotoPath)) {
            // Mismo path raw → foto sin cambios
            if ($currentFotoPath !== null && $currentFotoPath === $fotoPath) {
                return $result;
            }

            $newImageId = $this->downloadAndSaveImage($fotoPath);

            if ($newImageId !== null) {
                $result['imageId']  = $newImageId;
                $result['fotoPath'] = $fotoPath;
                $result['updated']  = true;
            }

            return $result;
        }

        // ── CASO 2: no viene foto desde la BD externa ────────────────────────
        $defaultImageId = $this->getDefaultImageId();

        if ($defaultImageId === null) {
            return $result;
        }

        // Subcaso A: cFotopId ya tiene la imagen por defecto → nada que hacer
        if (!empty($currentImageId) && $currentImageId === $defaultImageId) {
            return $result;
        }

        // Subcaso B: cFotopId null pero avatarId ya tiene la imagen por defecto
        // → solo poblar cFotopId, sin tocar avatarId ni descargar
        if (empty($currentImageId) && !empty($currentAvatarId) && $currentAvatarId === $defaultImageId) {
            $result['imageId']        = $defaultImageId;
            $result['fotoPath']       = null;
            $result['updated']        = true;
            $result['syncCFotopOnly'] = true;
            return $result;
        }

        // Subcaso C: ninguno tiene la imagen por defecto → asignar a ambos
        $result['imageId']  = $defaultImageId;
        $result['fotoPath'] = null;
        $result['updated']  = true;

        return $result;
    }

    private function getMimeType(string $extension): string
    {
        return [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ][$extension] ?? 'image/jpeg';
    }
}