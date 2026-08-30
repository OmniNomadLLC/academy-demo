<?php

namespace App\Support\Reporting\Pdf;

class SimplePdfDocument
{
    protected float $width;
    protected float $height;

    /** @var array<int, string> */
    protected array $pages = [];

    /** @var array<int, string> */
    protected array $currentCommands = [];

    protected bool $pageActive = false;

    protected array $fontMap = [
        'regular' => 'F1',
        'bold' => 'F2',
    ];

    /**
     * @var array<string, array{data: string, width: int, height: int, colorSpace: string, filter: string, bitsPerComponent: int, mask?: array|null}>
     */
    protected array $xObjects = [];

    public function __construct(float $width = 612.0, float $height = 792.0)
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function addPage(): void
    {
        if ($this->pageActive) {
            $this->pages[] = implode("\n", $this->currentCommands) . "\n";
        }

        $this->currentCommands = [];
        $this->pageActive = true;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function text(float $x, float $y, string $text, string $font = 'regular', float $size = 12, array $color = [0.0, 0.0, 0.0]): void
    {
        $fontKey = $this->fontMap[$font] ?? $this->fontMap['regular'];
        $pdfY = $this->transformY($y);

        $commands = [
            $this->fillColor($color),
            'BT',
            sprintf('/%s %.2f Tf', $fontKey, $size),
            sprintf('1 0 0 1 %.2f %.2f Tm', $x, $pdfY),
            sprintf('(%s) Tj', $this->escape($text)),
            'ET',
        ];

        $this->appendMany($commands);
    }

    public function filledRect(float $x, float $y, float $width, float $height, array $color): void
    {
        $commands = [
            $this->fillColor($color),
            sprintf('%.2f %.2f %.2f %.2f re f', $x, $this->transformRectY($y, $height), $width, $height),
        ];

        $this->appendMany($commands);
    }

    public function rect(float $x, float $y, float $width, float $height, array $color, float $lineWidth = 1.0): void
    {
        $commands = [
            $this->strokeColor($color),
            sprintf('%.2f w', $lineWidth),
            sprintf('%.2f %.2f %.2f %.2f re S', $x, $this->transformRectY($y, $height), $width, $height),
        ];

        $this->appendMany($commands);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $color, float $lineWidth = 1.0): void
    {
        $commands = [
            $this->strokeColor($color),
            sprintf('%.2f w', $lineWidth),
            sprintf('%.2f %.2f m', $x1, $this->transformY($y1)),
            sprintf('%.2f %.2f l', $x2, $this->transformY($y2)),
            'S',
        ];

        $this->appendMany($commands);
    }

    /**
     * @param array<int, array{0: float, 1: float}> $points
     */
    public function polyline(array $points, array $color, float $lineWidth = 1.0): void
    {
        if (count($points) < 2) {
            return;
        }

        $commands = [
            $this->strokeColor($color),
            sprintf('%.2f w', $lineWidth),
        ];

        [$firstX, $firstY] = $points[0];
        $commands[] = sprintf('%.2f %.2f m', $firstX, $this->transformY($firstY));

        foreach (array_slice($points, 1) as [$x, $y]) {
            $commands[] = sprintf('%.2f %.2f l', $x, $this->transformY($y));
        }

        $commands[] = 'S';

        $this->appendMany($commands);
    }

    public function image(string $name, float $x, float $y, float $width, float $height): void
    {
        if (! $this->hasImage($name)) {
            return;
        }

        $commands = [
            'q',
            sprintf('%.2f 0 0 %.2f %.2f %.2f cm', $width, $height, $x, $this->transformRectY($y, $height)),
            sprintf('/%s Do', $name),
            'Q',
        ];

        $this->appendMany($commands);
    }

    public function registerImage(
        string $name,
        string $data,
        int $width,
        int $height,
        string $colorSpace = '/DeviceRGB',
        string $filter = '/DCTDecode',
        ?array $mask = null,
        int $bitsPerComponent = 8
    ): void
    {
        $this->xObjects[$name] = [
            'data' => $data,
            'width' => $width,
            'height' => $height,
            'colorSpace' => $colorSpace,
            'filter' => $filter,
            'bitsPerComponent' => $bitsPerComponent,
            'mask' => $mask,
        ];
    }

    public function hasImage(string $name): bool
    {
        return isset($this->xObjects[$name]);
    }

    public function output(): string
    {
        $this->finalizePages();

        $catalogNum = 1;
        $pagesNum = 2;
        $fontRegularNum = 3;
        $fontBoldNum = 4;
        $nextObj = 5;
        $objects = [];

        $imageObjectNumbers = [];
        foreach ($this->xObjects as $name => $image) {
            $maskObjNum = null;
            if (! empty($image['mask'])) {
                $mask = $image['mask'];
                $maskObjNum = $nextObj++;
                $filterPart = ! empty($mask['filter']) ? sprintf(' /Filter %s', $mask['filter']) : '';
                $objects[$maskObjNum] = sprintf(
                    "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent %d%s /Decode [0 1] /Length %d >>\nstream\n%s\nendstream",
                    $mask['width'],
                    $mask['height'],
                    $mask['bitsPerComponent'] ?? 8,
                    $filterPart,
                    strlen($mask['data']),
                    $mask['data'],
                );
            }

            $objNum = $nextObj++;
            $imageObjectNumbers[$name] = $objNum;
            $maskPart = $maskObjNum ? sprintf(' /SMask %d 0 R', $maskObjNum) : '';
            $objects[$objNum] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent %d /Filter %s /Length %d%s >>\nstream\n%s\nendstream",
                $image['width'],
                $image['height'],
                $image['colorSpace'],
                $image['bitsPerComponent'] ?? 8,
                $image['filter'],
                strlen($image['data']),
                $maskPart,
                $image['data'],
            );
        }

        if (empty($this->pages)) {
            $this->pages[] = '';
        }

        $pageEntries = [];
        foreach ($this->pages as $content) {
            $contentObj = $nextObj++;
            $pageObj = $nextObj++;

            $pageEntries[] = [
                'content' => $content,
                'contentObj' => $contentObj,
                'pageObj' => $pageObj,
            ];
        }

        $totalObjects = $nextObj - 1;

        $objects[$catalogNum] = "<< /Type /Catalog /Pages {$pagesNum} 0 R >>";
        $kids = implode(' ', array_map(fn ($entry) => $entry['pageObj'] . ' 0 R', $pageEntries));
        $objects[$pagesNum] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', $kids, count($pageEntries));
        $objects[$fontRegularNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$fontBoldNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        foreach ($pageEntries as $entry) {
            $length = strlen($entry['content']);
            $objects[$entry['contentObj']] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", $length, $entry['content']);

            $resourceParts = [
                sprintf('/Font << /F1 %d 0 R /F2 %d 0 R >>', $fontRegularNum, $fontBoldNum),
                '/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]',
            ];

            if (! empty($imageObjectNumbers)) {
                $xEntries = [];
                foreach ($imageObjectNumbers as $name => $objNum) {
                    $xEntries[] = sprintf('/%s %d 0 R', $name, $objNum);
                }
                $resourceParts[] = sprintf('/XObject << %s >>', implode(' ', $xEntries));
            }

            $resources = '<< ' . implode(' ', $resourceParts) . ' >>';

            $objects[$entry['pageObj']] = sprintf(
                '<< /Type /Page /Parent %1$d 0 R /MediaBox [0 0 %2$.2f %3$.2f] /Resources %4$s /Contents %5$d 0 R >>',
                $pagesNum,
                $this->width,
                $this->height,
                $resources,
                $entry['contentObj'],
            );
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        $position = strlen($pdf);

        for ($i = 1; $i <= $totalObjects; $i++) {
            $offsets[$i] = $position;
            $objectContent = $objects[$i] ?? '<<>>';
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $i, $objectContent);
            $position = strlen($pdf);
        }

        $xrefPosition = $position;
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $totalObjects + 1);
        for ($i = 1; $i <= $totalObjects; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= sprintf(
            "trailer << /Size %d /Root %d 0 R >>\nstartxref\n%d\n%%EOF",
            $totalObjects + 1,
            $catalogNum,
            $xrefPosition,
        );

        return $pdf;
    }

    protected function appendMany(array $commands): void
    {
        foreach ($commands as $command) {
            $this->append($command);
        }
    }

    protected function append(string $command): void
    {
        if (! $this->pageActive) {
            $this->addPage();
        }

        $this->currentCommands[] = $command;
    }

    protected function finalizePages(): void
    {
        if ($this->pageActive) {
            $this->pages[] = implode("\n", $this->currentCommands) . "\n";
            $this->currentCommands = [];
            $this->pageActive = false;
        }
    }

    protected function transformY(float $y): float
    {
        return $this->height - $y;
    }

    protected function transformRectY(float $y, float $height): float
    {
        return $this->height - $y - $height;
    }

    protected function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text);
    }

    protected function fillColor(array $rgb): string
    {
        return sprintf('%.3f %.3f %.3f rg', $rgb[0], $rgb[1], $rgb[2]);
    }

    protected function strokeColor(array $rgb): string
    {
        return sprintf('%.3f %.3f %.3f RG', $rgb[0], $rgb[1], $rgb[2]);
    }
}
