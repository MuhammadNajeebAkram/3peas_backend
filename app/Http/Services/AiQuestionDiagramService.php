<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiQuestionDiagramService
{
    public function validate(?array $diagram, array $sources): void
    {
        if ($diagram === null) {
            return;
        }
        Validator::make($diagram, [
            'kind' => ['required', Rule::in(['drawing', 'source_crop'])],
            'alt' => ['required', 'string', 'max:500'],
            'source_id' => ['present', 'nullable', 'string'],
            'crop' => ['present', 'array', 'max:4'],
            'crop.*' => ['numeric', 'between:0,1'],
            'elements' => ['present', 'array', 'max:60'],
            'elements.*' => ['array:type,x1,y1,x2,y2,label'],
            'elements.*.type' => ['required', Rule::in(['line', 'arrow', 'rectangle', 'ellipse', 'text'])],
            'elements.*.x1' => ['required', 'numeric', 'between:0,1000'],
            'elements.*.y1' => ['required', 'numeric', 'between:0,1000'],
            'elements.*.x2' => ['required', 'numeric', 'between:0,1000'],
            'elements.*.y2' => ['required', 'numeric', 'between:0,1000'],
            'elements.*.label' => ['present', 'nullable', 'string', 'max:80', 'regex:/^[\x20-\x7e]*$/'],
        ])->validate();
        if ($diagram['kind'] === 'source_crop') {
            $source = collect($sources)->firstWhere('id', $diagram['source_id']);
            if (! $source || ! str_starts_with($source['mime'], 'image/') || count($diagram['crop']) !== 4 || ! array_is_list($diagram['crop'])) {
                $this->invalid('A crop requires an uploaded image source and [left, top, width, height]. PDF figures must be supplied as image sources or redrawn.');
            }
            [$x, $y, $w, $h] = $diagram['crop'];
            if ($w <= 0 || $h <= 0 || $x + $w > 1.000001 || $y + $h > 1.000001 || $diagram['elements'] !== []) {
                $this->invalid('The crop must fit inside the source image and cannot contain drawing elements.');
            }
        } elseif ($diagram['elements'] === [] || $diagram['crop'] !== [] || $diagram['source_id'] !== null) {
            $this->invalid('A drawing requires elements, no source_id, and an empty crop.');
        }
    }

    public function render(array $diagram, array $sources): string
    {
        $this->validate($diagram, $sources);
        abort_unless(extension_loaded('gd'), 503, 'Diagram rendering requires the PHP GD extension.');
        if ($diagram['kind'] === 'source_crop') {
            $source = collect($sources)->firstWhere('id', $diagram['source_id']);
            $original = @imagecreatefromstring(Storage::disk($source['disk'])->get($source['path']));
            if (! $original) {
                $this->invalid('The source image could not be decoded.');
            }
            [$x, $y, $w, $h] = $diagram['crop'];
            $image = imagecrop($original, ['x' => (int) floor($x * imagesx($original)), 'y' => (int) floor($y * imagesy($original)),
                'width' => max(1, (int) floor($w * imagesx($original))), 'height' => max(1, (int) floor($h * imagesy($original)))]);
            imagedestroy($original);
            if (! $image) {
                $this->invalid('The source crop could not be rendered.');
            }
        } else {
            $image = imagecreatetruecolor(800, 600);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            $ink = imagecolorallocate($image, 25, 35, 45);
            imagesetthickness($image, 2);
            foreach ($diagram['elements'] as $element) {
                $x1 = (int) round($element['x1'] * .799);
                $y1 = (int) round($element['y1'] * .599);
                $x2 = (int) round($element['x2'] * .799);
                $y2 = (int) round($element['y2'] * .599);
                switch ($element['type']) {
                    case 'line':
                    case 'arrow':
                        imageline($image, $x1, $y1, $x2, $y2, $ink);
                        if ($element['type'] === 'arrow') {
                            $angle = atan2($y2 - $y1, $x2 - $x1);
                            foreach ([-.5, .5] as $offset) {
                                imageline($image, $x2, $y2, (int) ($x2 - 12 * cos($angle + $offset)), (int) ($y2 - 12 * sin($angle + $offset)), $ink);
                            }
                        }
                        break;
                    case 'rectangle':
                        imagerectangle($image, min($x1, $x2), min($y1, $y2), max($x1, $x2), max($y1, $y2), $ink);
                        break;
                    case 'ellipse':
                        imageellipse($image, (int) (($x1 + $x2) / 2), (int) (($y1 + $y2) / 2), max(1, abs($x2 - $x1)), max(1, abs($y2 - $y1)), $ink);
                        break;
                    case 'text':
                        imagestring($image, 5, $x1, $y1, $element['label'] ?? '', $ink);
                        break;
                }
            }
        }
        ob_start();
        try {
            imagepng($image);

            return ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['diagram' => $message]);
    }
}
