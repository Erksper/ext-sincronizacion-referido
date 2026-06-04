<?php
namespace Espo\Modules\SincronizacionReferido\Handlers;

use Espo\ORM\EntityManager;
use Espo\Modules\SincronizacionReferido\Utils\StringUtils;
use Espo\Modules\SincronizacionReferido\Traits\Loggable;
use PDO;

class PropiedadHandler
{
    use Loggable;

    private EntityManager $entityManager;

    // Campos numéricos (float con 2 decimales)
    private array $numericFields = [
        'comision',
        'precioEnContrato',
        'precioVenta',
        'precioRenta',
        'precioCierre',
        'm2T',
        'm2C',
        'edad',
    ];

    // Campos booleanos
    private array $booleanFields = [
        'enInternet',
        'compartidoConC21',
        'referidoConC21',
    ];

    // Campos de dirección (se limpian de puntuación)
    private array $addressFields = [
        'calle',
        'numero',
        'municipio',
        'urbanizacion',
        'ciudad',
        'estado',
        'pais',
        'infoExtraPrecio',
    ];

    // Campos URL (sin normalización adicional)
    private array $urlFields = [
        'linkPublico',
        'link21Online',
    ];

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function syncPropiedades(
        PDO    $pdo,
        string $syncType,
        string $configId,
        array  &$summary
    ): void {
        $startTime = microtime(true);

        $whereClause = '';
        if ($syncType === 'anual') {
            $whereClause = 'WHERE fechaModificacion >= DATE_SUB(NOW(), INTERVAL 12 MONTH)';
        }

        $sqlCount = "SELECT COUNT(*) as total FROM propiedades {$whereClause}";
        $stmtCount = $pdo->prepare($sqlCount);
        $stmtCount->execute();
        $totalRegistros = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

        if ($totalRegistros == 0) {
            $this->log('info', 'Propiedades', null, 'Sincronización', 'success',
                'No hay propiedades para sincronizar', $configId);
            return;
        }

        $pageSize    = 1000;
        $totalPaginas = ceil($totalRegistros / $pageSize);

        $this->log('info', 'Propiedades', null, 'Sincronización', 'success',
            "Procesando {$totalRegistros} propiedades en {$totalPaginas} páginas", $configId);

        $sqlBase = "SELECT
            id, idAfiliados, fechaAlta, fechaModificacion,
            tipoOperacion, tipoPropiedad, subtipoPropiedad,
            tipoDeContrato, status, idAsesorExclusiva,
            comision, precioEnContrato, monedaEnContrato,
            calle, numero, colonia, colonia2, municipio, estado, pais,
            precioVenta, precioRenta, infoExtraPrecio, moneda,
            enInternet, m2T, m2C, edad,
            clave, tipoCierre, fechaCV, fechaEstimadaCierre, fechaCierre,
            idAsesorCierre, operacionCompartida, idAfiliadosCompartida,
            idClientesComprador, idClientesVendedor,
            compartidoCon, compartidoConC21, referidoConC21,
            idAfiliadosReferida, precioCierre
        FROM propiedades
        {$whereClause}
        ORDER BY id
        LIMIT ? OFFSET ?";

        $stmt = $pdo->prepare($sqlBase);

        $procesadas = 0;

        for ($pagina = 0; $pagina < $totalPaginas; $pagina++) {
            $offset = $pagina * $pageSize;

            $stmt->bindValue(1, $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();

            $propiedades = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (($pagina + 1) % 5 == 0 || $pagina == 0 || $pagina == $totalPaginas - 1) {
                $this->log('info', 'Propiedades', null, 'Progreso', 'success',
                    "Página " . ($pagina + 1) . "/{$totalPaginas} - " .
                    "Procesadas: {$procesadas}/{$totalRegistros}", $configId);
            }

            foreach ($propiedades as $propiedadExterna) {
                $procesadas++;
                try {
                    $this->syncPropiedad($propiedadExterna, $configId, $summary, $pdo);
                } catch (\Exception $e) {
                    $summary['propiedades']['errors']++;
                    $idProp = $propiedadExterna['id'] ?? 'Unknown';
                    $this->log('error', 'Propiedades', $idProp, "ID {$idProp}", 'error',
                        "Error: " . $e->getMessage(), $configId);
                }
            }

            if ($pagina < $totalPaginas - 1) {
                sleep(2);
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        $this->log('info', 'Propiedades', null, 'Resumen Final', 'success',
            "Creadas: {$summary['propiedades']['created']} | " .
            "Actualizadas: {$summary['propiedades']['updated']} | " .
            "Sin cambios: {$summary['propiedades']['no_changes']} | " .
            "Omitidas: {$summary['propiedades']['skipped']} | " .
            "Errores: {$summary['propiedades']['errors']} | " .
            "Tiempo: {$elapsed}s", $configId);
    }

    // -------------------------------------------------------------------------
    // Lógica interna
    // -------------------------------------------------------------------------

    private function syncPropiedad(
        array  $propiedadExterna,
        string $configId,
        array  &$summary,
        PDO    $pdo
    ): void {
        if (!$this->validatePropiedadData($propiedadExterna, $summary, $configId)) {
            return;
        }

        $propiedadId = (string)$propiedadExterna['id'];
        $propiedad   = $this->entityManager->getEntityById('Propiedades', $propiedadId);

        if (!$propiedad) {
            $this->createPropiedad($propiedadExterna, $propiedadId, $configId, $summary, $pdo);
        } else {
            $this->updatePropiedad($propiedad, $propiedadExterna, $configId, $summary, $pdo);
        }
    }

    private function validatePropiedadData(array $propiedadExterna, array &$summary, string $configId): bool
    {
        $camposObligatorios = [
            'id'                => 'ID',
            'idAfiliados'       => 'Oficina (idAfiliados)',
            'fechaAlta'         => 'Fecha de Alta',
            'tipoOperacion'     => 'Tipo de Operación',
            'tipoPropiedad'     => 'Tipo de Propiedad',
            'subtipoPropiedad'  => 'Subtipo de Propiedad',
            'tipoDeContrato'    => 'Tipo de Contrato',
            'status'            => 'Estado',
            'idAsesorExclusiva' => 'Asesor Exclusiva',
        ];

        foreach ($camposObligatorios as $campo => $nombre) {
            $valor = $propiedadExterna[$campo] ?? null;
            if ($valor === null || (is_string($valor) && trim($valor) === '')) {
                $id = $propiedadExterna['id'] ?? 'Unknown';
                $summary['propiedades']['skipped']++;
                $this->log('info', 'Propiedades', $id, "ID {$id}", 'warning',
                    "Propiedad omitida: falta campo '{$nombre}'", $configId);
                return false;
            }
        }

        return true;
    }

    private function createPropiedad(
        array  $propiedadExterna,
        string $propiedadId,
        string $configId,
        array  &$summary,
        PDO    $pdo
    ): void {
        $propiedadData = $this->preparePropiedadData($propiedadExterna);

        if (!$propiedadData) {
            $summary['propiedades']['skipped']++;
            return;
        }

        try {
            $propiedad = $this->entityManager->getNewEntity('Propiedades');
            $propiedad->set('id', $propiedadId);
            $propiedad->set($propiedadData);

            $teamIds = $this->getValidTeamIds($propiedadData['assignedUserId']);
            if (!empty($teamIds)) {
                $propiedad->set('teamsIds', $teamIds);
            }

            $this->entityManager->saveEntity($propiedad);
            
            // Descargar y guardar la foto principal
            $fotoAttachmentId = $this->downloadAndSavePropertyImage($pdo, $propiedadId);
            if ($fotoAttachmentId) {
                $propiedad->set('fotoPrincipalId', $fotoAttachmentId);
                $this->entityManager->saveEntity($propiedad);
            }

            $summary['propiedades']['created']++;
            $this->log('created', 'Propiedades', $propiedadId, $propiedadData['name'], 'success',
                'Propiedad creada' . ($fotoAttachmentId ? ' (con foto)' : ' (sin foto)'), $configId);

        } catch (\Exception $e) {
            $summary['propiedades']['errors']++;
            $this->log('error', 'Propiedades', $propiedadId, "ID {$propiedadId}", 'error',
                'Error al crear: ' . $e->getMessage(), $configId);
            throw $e;
        }
    }

    private function updatePropiedad(
        $propiedad,
        array  $propiedadExterna,
        string $configId,
        array  &$summary,
        PDO    $pdo
    ): void {
        $propiedadData = $this->preparePropiedadData($propiedadExterna);

        if (!$propiedadData) {
            return;
        }

        $changes     = [];
        $needsUpdate = false;

        // Comparar campos de datos (excepto equipos)
        foreach ($propiedadData as $field => $newValue) {
            if ($field === 'teamsIds') {
                continue;
            }
            $currentValue = $propiedad->get($field);
            $currentNorm  = $this->normalizeValue($currentValue, $field);
            $newNorm      = $this->normalizeValue($newValue, $field);

            if ($currentNorm !== $newNorm) {
                $propiedad->set($field, $newValue);
                $needsUpdate = true;
                $changes[]   = $field;
            }
        }

        // Verificar si la foto cambió
        $currentFotoId = $propiedad->get('fotoPrincipalId');
        $newFotoId = $this->downloadAndSavePropertyImage($pdo, $propiedad->getId());
        
        if ($newFotoId && $newFotoId !== $currentFotoId) {
            $propiedad->set('fotoPrincipalId', $newFotoId);
            $needsUpdate = true;
            $changes[] = "fotoPrincipal";
        }

        // Manejo de equipos
        $oldTeamIds = $this->getCurrentTeamIds($propiedad);
        $newTeamIds = $this->getValidTeamIds($propiedadData['assignedUserId']);
        $teamsChanged = $this->teamListsDiffer($oldTeamIds, $newTeamIds);

        if ($teamsChanged) {
            $oldTeamsDesc = $this->getTeamDescriptions($oldTeamIds);
            $newTeamsDesc = $this->getTeamDescriptions($newTeamIds);
            $changes[] = "equipos (de [{$oldTeamsDesc}] a [{$newTeamsDesc}])";
            $propiedad->set('teamsIds', $newTeamIds);
            $needsUpdate = true;
        }

        if ($needsUpdate) {
            try {
                $this->entityManager->saveEntity($propiedad);
                $summary['propiedades']['updated']++;
                $this->log('updated', 'Propiedades', $propiedad->getId(), $propiedadData['name'], 'success',
                    'Propiedad actualizada: ' . implode(', ', $changes), $configId);
            } catch (\Exception $e) {
                $summary['propiedades']['errors']++;
                $this->log('error', 'Propiedades', $propiedad->getId(), $propiedadData['name'], 'error',
                    'Error al actualizar: ' . $e->getMessage(), $configId);
                throw $e;
            }
        } else {
            $summary['propiedades']['no_changes']++;
        }
    }

    /**
     * Obtiene y descarga la foto principal de una propiedad desde la tabla 'fotos'
     */
    private function downloadAndSavePropertyImage(PDO $pdo, string $propiedadId): ?string
    {
        try {
            $sql = "SELECT large FROM fotos WHERE idPropiedades = :propiedadId AND orden = 1 LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':propiedadId' => $propiedadId]);
            $foto = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$foto || empty($foto['large'])) {
                return null;
            }
            
            $fotoPath = $foto['large'];
            $url = "https://venezuela.21online.lat/" . ltrim($fotoPath, '/');
            $imageContent = @file_get_contents($url);
            
            if ($imageContent === false || strlen($imageContent) === 0) {
                return null;
            }
            
            $fileInfo = pathinfo($fotoPath);
            $extension = strtolower($fileInfo['extension'] ?? 'jpg');
            $fileName = $fileInfo['basename'] ?? 'property_' . $propiedadId . '.' . $extension;
            
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($extension, $allowedExtensions)) {
                return null;
            }
            
            $attachment = $this->entityManager->getNewEntity('Attachment');
            $attachment->set([
                'name' => $fileName,
                'type' => $this->getImageMimeType($extension),
                'role' => 'Attachment',
                'size' => strlen($imageContent),
                'relatedType' => 'Propiedades',
                'relatedId' => $propiedadId,
                'field' => 'fotoPrincipal'
            ]);
            
            $this->entityManager->saveEntity($attachment);
            
            $filePath = "data/upload/" . $attachment->getId();
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            
            if (file_put_contents($filePath, $imageContent) === false) {
                $this->entityManager->removeEntity($attachment);
                return null;
            }
            
            return $attachment->getId();
            
        } catch (\Exception $e) {
            $this->log('error', 'Propiedades', $propiedadId, "ID {$propiedadId}", 'error',
                "Error descargando foto: " . $e->getMessage(), null);
            return null;
        }
    }

    private function getImageMimeType(string $extension): string
    {
        return [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ][$extension] ?? 'image/jpeg';
    }

    private function preparePropiedadData(array $ext): ?array
    {
        $tipoOperacion = $ext['tipoOperacion'] ?? 'N/A';
        $tipoPropiedad = $ext['tipoPropiedad'] ?? 'N/A';
        $urbanizacion  = $ext['colonia2'] ?? $ext['colonia'] ?? 'Sin especificar';
        $name          = "{$tipoOperacion} - {$tipoPropiedad} - {$urbanizacion}";

        $fechaAlta   = !empty($ext['fechaAlta']) ? $ext['fechaAlta'] : date('Y-m-d H:i:s');
        $propiedadId = (string)$ext['id'];

        $data = [
            'name'              => $name,
            'idOficinaId'       => (string)$ext['idAfiliados'],
            'fechaAlta'         => $fechaAlta,
            'tipoOperacion'     => $ext['tipoOperacion'],
            'tipoPropiedad'     => $ext['tipoPropiedad'],
            'subTipoPropiedad'  => $ext['subtipoPropiedad'],
            'tipoDeContrato'    => $ext['tipoDeContrato'],
            'status'            => $ext['status'],
            'idAsesorExclusivaId' => (string)$ext['idAsesorExclusiva'],
            'assignedUserId'    => (string)$ext['idAsesorExclusiva'],
            'linkPublico'       => 'https://www.century21.com.ve/v/resultados/ordenado-por_relevancia/por_' . $propiedadId,
            'link21Online'      => 'https://venezuela.21online.lat/propiedades/editar/' . $propiedadId,
        ];

        $camposOpcionalesOriginales = [
            'fechaModificacion' => 'fechaModificacion',
            'comision'          => 'comision',
            'precioEnContrato'  => 'precioEnContrato',
            'monedaEnContrato'  => 'monedaEnContrato',
            'calle'             => 'calle',
            'numero'            => 'numero',
            'municipio'         => 'colonia',
            'urbanizacion'      => 'colonia2',
            'ciudad'            => 'municipio',
            'estado'            => 'estado',
            'pais'              => 'pais',
            'precioVenta'       => 'precioVenta',
            'precioRenta'       => 'precioRenta',
            'infoExtraPrecio'   => 'infoExtraPrecio',
            'moneda'            => 'moneda',
            'enInternet'        => 'enInternet',
            'm2T'               => 'm2T',
            'm2C'               => 'm2C',
            'edad'              => 'edad',
        ];

        foreach ($camposOpcionalesOriginales as $campoEspo => $campo21) {
            if (isset($ext[$campo21]) && $ext[$campo21] !== '') {
                $data[$campoEspo] = $ext[$campo21];
            }
        }

        $nuevosCampos = [
            'clave'                  => 'clave',
            'tipoCierre'             => 'tipoCierre',
            'fechaCV'                => 'fechaCV',
            'fechaEstimadaCierre'    => 'fechaEstimadaCierre',
            'fechaCierre'            => 'fechaCierre',
            'operacionCompartida'    => 'operacionCompartida',
            'compartidoCon'          => 'compartidoCon',
        ];

        foreach ($nuevosCampos as $campoEspo => $campoExt) {
            if (isset($ext[$campoExt]) && $ext[$campoExt] !== '') {
                $data[$campoEspo] = $ext[$campoExt];
            }
        }

        $data['compartidoConC21'] = !empty($ext['compartidoConC21']);
        $data['referidoConC21']   = !empty($ext['referidoConC21']);

        if (isset($ext['precioCierre']) && $ext['precioCierre'] !== '') {
            $data['precioCierre'] = $ext['precioCierre'];
        }

        if (!empty($ext['idAsesorCierre'])) {
            $data['idAsesorCierreId'] = (string)$ext['idAsesorCierre'];
        }
        if (!empty($ext['idAfiliadosCompartida'])) {
            $data['idAfiliadosCompartidaId'] = (string)$ext['idAfiliadosCompartida'];
        }
        if (!empty($ext['idAfiliadosReferida'])) {
            $data['idAfiliadosReferidaId'] = (string)$ext['idAfiliadosReferida'];
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Helpers de equipos
    // -------------------------------------------------------------------------

    private function getValidTeamIds(string $assignedUserId): array
    {
        if (empty($assignedUserId)) {
            return [];
        }

        $asesor = $this->entityManager->getEntityById('User', $assignedUserId);
        if (!$asesor) {
            return [];
        }

        $teamIds = [];
        
        $teams = $asesor->get('teams');
        if ($teams) {
            foreach ($teams as $team) {
                $id = $team->getId();
                if ($this->teamExists($id)) {
                    $teamIds[] = (string)$id;
                }
            }
        }

        $defaultTeamId = $asesor->get('defaultTeamId');
        if ($defaultTeamId && $this->teamExists($defaultTeamId)) {
            $defaultIdStr = (string)$defaultTeamId;
            if (!in_array($defaultIdStr, $teamIds, true)) {
                $teamIds[] = $defaultIdStr;
            }
        }

        return array_unique($teamIds);
    }

    private function teamExists(string $teamId): bool
    {
        $team = $this->entityManager->getEntityById('Team', $teamId);
        return $team !== null;
    }

    private function getCurrentTeamIds($propiedad): array
    {
        $currentTeams = $propiedad->get('teams');
        $ids = [];
        if ($currentTeams) {
            foreach ($currentTeams as $team) {
                $ids[] = (string)$team->getId();
            }
        }
        return array_unique($ids);
    }

    private function teamListsDiffer(array $list1, array $list2): bool
    {
        $list1 = array_unique(array_map('strval', $list1));
        $list2 = array_unique(array_map('strval', $list2));
        sort($list1);
        sort($list2);
        return $list1 !== $list2;
    }

    private function getTeamDescriptions(array $teamIds): string
    {
        if (empty($teamIds)) {
            return 'ninguno';
        }
        
        $descriptions = [];
        foreach ($teamIds as $id) {
            $team = $this->entityManager->getEntityById('Team', $id);
            if ($team) {
                $name = $team->get('name');
                $descriptions[] = "{$name} ({$id})";
            } else {
                $descriptions[] = "ID:{$id} (desconocido)";
            }
        }
        return implode(', ', $descriptions);
    }

    // -------------------------------------------------------------------------
    // Normalización de valores
    // -------------------------------------------------------------------------

    private function normalizeValue($value, string $field): string
    {
        if ($value === null) {
            return '';
        }

        if (in_array($field, $this->booleanFields)) {
            return $value ? '1' : '0';
        }

        if (in_array($field, $this->numericFields)) {
            return number_format((float)$value, 2, '.', '');
        }

        if (in_array($field, $this->addressFields)) {
            return StringUtils::normalizeAddress($value);
        }

        if (in_array($field, $this->urlFields)) {
            return trim((string)$value);
        }

        return StringUtils::normalize($value);
    }
}