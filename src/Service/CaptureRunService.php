<?php

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;
use Photobooth\Image;
use Photobooth\PhotoboothCapture;

class CaptureRunService
{
    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function run(array $request, array &$config): array
    {
        if (!isset($request['style'])) {
            throw new \Exception('No style provided');
        }

        // Validate collageLimit before letting it influence subsequent logic:
        // a malformed or out-of-range value previously silently overwrote the
        // configured limit and could let an out-of-bounds collageNumber slip
        // through the later "Collage consists only of …" check.
        if (isset($request['collageLimit'])) {
            if (!is_numeric($request['collageLimit'])) {
                throw new \Exception('Invalid collage limit provided.');
            }
            $limit = (int) $request['collageLimit'];
            if ($limit < 1 || $limit > 99) {
                throw new \Exception('Collage limit out of range (1-99).');
            }
            $config['collage']['limit'] = $limit;
        }

        if (
            !empty($request['file']) &&
            (
                preg_match('/^[a-z0-9_]+\.jpg$/', (string) $request['file']) ||
                preg_match('/^[a-z0-9_]+\.(mp4)$/', (string) $request['file'])
            )
        ) {
            $file = (string) $request['file'];
        } else {
            $style = (string) $request['style'];
            $file = $style === 'video'
                ? Image::createNewFilename($config['picture']['naming'], '.mp4')
                : Image::createNewFilename($config['picture']['naming']);
            if ($config['database']['file'] != 'db') {
                $file = $config['database']['file'] . '_' . $file;
            }
        }

        $filename_tmp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $file;

        $captureHandler = new PhotoboothCapture();
        $captureHandler->debugLevel = $config['dev']['loglevel'];
        $captureHandler->fileName = $file;
        $captureHandler->tmpFile = $filename_tmp;

        switch ((string) $request['style']) {
            case 'photo':
                $captureHandler->style = 'image';
                break;
            case 'collage':
                if (!is_numeric($request['collageNumber'] ?? null)) {
                    throw new \Exception('No or invalid collage number provided.');
                }

                $number = (int) $request['collageNumber'];

                if ($number < 0 || $number >= (int) $config['collage']['limit']) {
                    throw new \Exception('Collage consists only of ' . $config['collage']['limit'] . ' pictures');
                }

                $captureHandler->collageSubFile = substr($file, 0, -4) . '-' . $number . '.jpg';
                $captureHandler->tmpFile = substr($filename_tmp, 0, -4) . '-' . $number . '.jpg';
                $captureHandler->style = 'collage';
                $captureHandler->collageNumber = $number;
                $captureHandler->collageLimit = (int) $config['collage']['limit'];
                break;
            case 'chroma':
                $captureHandler->style = 'chroma';
                break;
            case 'custom':
                $captureHandler->style = 'image';
                break;
            case 'video':
                $captureHandler->style = 'video';
                break;
            default:
                throw new \Exception('Invalid style provided.');
        }

        // Move a stale tmp file (from a previous aborted capture) out of the
        // way *after* the style-specific path has been resolved — for collage
        // this is the per-photo subfile, not the base filename. Leaving an
        // old file in place would let the post-capture file_exists() check
        // return a stale success.
        if (file_exists($captureHandler->tmpFile)) {
            $extension = pathinfo($captureHandler->tmpFile, PATHINFO_EXTENSION) === 'mp4' ? '.mp4' : '.jpg';
            $random = Image::createNewFilename('random', $extension);
            $filename_random = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $random;
            if (!@rename($captureHandler->tmpFile, $filename_random) && !@unlink($captureHandler->tmpFile)) {
                throw new \RuntimeException(
                    'Could not move stale tmp file out of the way: ' . $captureHandler->tmpFile
                );
            }
        }

        if ((string) $request['style'] === 'video') {
            $captureHandler->captureCmd = $config['commands']['take_video'];
            $captureHandler->captureWithCmd();
        } elseif ($config['dev']['demo_images']) {
            $captureHandler->captureDemo();
        } elseif ($config['preview']['mode'] === 'device_cam' && $config['preview']['camTakesPic']) {
            if (!isset($request['canvasimg'])) {
                throw new \Exception('No canvas data provided!');
            }
            $captureHandler->flipImage = $config['preview']['flip'];
            $captureHandler->captureCanvas((string) $request['canvasimg']);
        } else {
            if ((string) $request['style'] === 'custom') {
                $captureHandler->captureCmd = $config['commands']['take_custom'];
            } elseif ((string) $request['style'] === 'collage' && !empty($config['commands']['take_collage'])) {
                $captureHandler->captureCmd = $config['commands']['take_collage'];
            } else {
                $captureHandler->captureCmd = $config['commands']['take_picture'];
            }
            $captureHandler->captureWithCmd();
        }

        return $captureHandler->returnData();
    }
}
