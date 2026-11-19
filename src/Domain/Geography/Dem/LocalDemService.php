<?php

declare(strict_types=1);

namespace App\Domain\Geography\Dem;

use App\Infrastructure\ValueObject\String\KernelProjectDir;

final class LocalDemService
{
    private ?string $filePath = null;
    private bool $initialized = false;
    private bool $available = false;

    private float $minLon = 0.0;
    private float $maxLon = 0.0;
    private float $minLat = 0.0;
    private float $maxLat = 0.0;
    private int $width = 0;
    private int $height = 0;

    public function __construct(
        private readonly KernelProjectDir $projectDir,
    ) {
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        $this->filePath = rtrim((string) $this->projectDir, '/').'/storage/files/dem/palghar_30m.bin';

        if (!file_exists($this->filePath) || filesize($this->filePath) < 40) {
            $this->available = false;
            return;
        }

        $fp = @fopen($this->filePath, 'rb');
        if ($fp === false) {
            $this->available = false;
            return;
        }

        $header = fread($fp, 40);
        fclose($fp);

        if ($header === false || strlen($header) < 40) {
            $this->available = false;
            return;
        }

        $hdr = unpack('dmin_lon/dmax_lon/dmin_lat/dmax_lat/lwidth/lheight', $header);
        if ($hdr === false) {
            $this->available = false;
            return;
        }

        $this->minLon = (float) $hdr['min_lon'];
        $this->maxLon = (float) $hdr['max_lon'];
        $this->minLat = (float) $hdr['min_lat'];
        $this->maxLat = (float) $hdr['max_lat'];
        $this->width = (int) $hdr['width'];
        $this->height = (int) $hdr['height'];

        $this->available = ($this->width > 0 && $this->height > 0);
    }

    public function isAvailable(): bool
    {
        $this->initialize();
        return $this->available;
    }

    public function covers(float $lat, float $lon): bool
    {
        $this->initialize();
        if (!$this->available) {
            return false;
        }

        return $lat >= $this->minLat && $lat <= $this->maxLat && $lon >= $this->minLon && $lon <= $this->maxLon;
    }

    public function getElevation(float $lat, float $lon): ?float
    {
        $batch = $this->lookupBatch([[$lat, $lon]]);
        return $batch[0] ?? null;
    }

    /**
     * @param array<int, array{0: float|int, 1: float|int}|array{float|int, float|int}> $coordinates
     * @return array<int, ?float>
     */
    public function lookupBatch(array $coordinates): array
    {
        $this->initialize();
        if (!$this->available || empty($coordinates) || $this->filePath === null) {
            return array_fill(0, count($coordinates), null);
        }

        $fp = @fopen($this->filePath, 'rb');
        if ($fp === false) {
            return array_fill(0, count($coordinates), null);
        }

        $results = [];
        $width = $this->width;
        $height = $this->height;
        $minLon = $this->minLon;
        $maxLon = $this->maxLon;
        $minLat = $this->minLat;
        $maxLat = $this->maxLat;

        $lonSpan = $maxLon - $minLon;
        $latSpan = $maxLat - $minLat;

        foreach ($coordinates as $pt) {
            if (!isset($pt[0], $pt[1])) {
                $results[] = null;
                continue;
            }

            $lat = (float) $pt[0];
            $lon = (float) $pt[1];

            if ($lat < $minLat || $lat > $maxLat || $lon < $minLon || $lon > $maxLon) {
                $results[] = null;
                continue;
            }

            $colFloat = ($lon - $minLon) / $lonSpan * ($width - 1);
            $rowFloat = ($maxLat - $lat) / $latSpan * ($height - 1);

            $x0 = (int) floor($colFloat);
            $x1 = min($width - 1, $x0 + 1);
            $y0 = (int) floor($rowFloat);
            $y1 = min($height - 1, $y0 + 1);

            $dx = $colFloat - $x0;
            $dy = $rowFloat - $y0;

            // Header offset: 40 bytes; each float32 is 4 bytes
            $offset00 = 40 + ($y0 * $width + $x0) * 4;
            $offset10 = 40 + ($y0 * $width + $x1) * 4;
            $offset01 = 40 + ($y1 * $width + $x0) * 4;
            $offset11 = 40 + ($y1 * $width + $x1) * 4;

            fseek($fp, $offset00);
            $raw00 = fread($fp, 4);
            fseek($fp, $offset10);
            $raw10 = fread($fp, 4);
            fseek($fp, $offset01);
            $raw01 = fread($fp, 4);
            fseek($fp, $offset11);
            $raw11 = fread($fp, 4);

            if ($raw00 === false || $raw10 === false || $raw01 === false || $raw11 === false ||
                strlen($raw00) < 4 || strlen($raw10) < 4 || strlen($raw01) < 4 || strlen($raw11) < 4) {
                $results[] = null;
                continue;
            }

            $v00 = unpack('f', $raw00)[1] ?? 0.0;
            $v10 = unpack('f', $raw10)[1] ?? 0.0;
            $v01 = unpack('f', $raw01)[1] ?? 0.0;
            $v11 = unpack('f', $raw11)[1] ?? 0.0;

            $top = $v00 * (1.0 - $dx) + $v10 * $dx;
            $bot = $v01 * (1.0 - $dx) + $v11 * $dx;
            $elevation = $top * (1.0 - $dy) + $bot * $dy;

            $results[] = round((float) $elevation, 2);
        }

        fclose($fp);
        return $results;
    }

    /**
     * @param float[] $elevations
     * @return float[]
     */
    public function smoothElevations(array $elevations, int $windowRadius = 2): array
    {
        $n = count($elevations);
        if ($n === 0) {
            return [];
        }

        if ($n <= 3) {
            return array_map(fn ($e) => round((float) $e, 1), $elevations);
        }

        $effectiveRadius = ($n <= 6) ? 1 : $windowRadius;

        $smoothed = [];
        for ($i = 0; $i < $n; $i++) {
            $window = [];
            for ($k = max(0, $i - $effectiveRadius); $k <= min($n - 1, $i + $effectiveRadius); $k++) {
                $window[] = (float) $elevations[$k];
            }
            $smoothed[] = round(array_sum($window) / count($window), 1);
        }

        return $smoothed;
    }

    /**
     * Compute verified climbing elevation gain with hysteresis
     *
     * @param float[] $elevations
     */
    public function computeElevationGain(array $elevations, float $hysteresisMeters = 1.5): float
    {
        if (empty($elevations)) {
            return 0.0;
        }

        $gain = 0.0;
        $ref = (float) $elevations[0];

        foreach ($elevations as $alt) {
            $alt = (float) $alt;
            $diff = $alt - $ref;
            if ($diff >= $hysteresisMeters) {
                $gain += $diff;
                $ref = $alt;
            } elseif ($ref - $alt >= $hysteresisMeters) {
                $ref = $alt;
            }
        }

        return round($gain, 1);
    }
}
