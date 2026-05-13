<?php

namespace Photobooth;

use Photobooth\Logger\NamedLogger;
use Photobooth\Service\LoggerService;

/**
 * Class PhotoboothCapture
 */
class PhotoboothCapture
{
    public string $style;
    public string $fileName;
    public string $tmpFile;
    public string $collageSubFile;
    public int $collageNumber;
    public int $collageLimit;
    public string $demoFolder = __DIR__ . '/../resources/img/demo/';
    public string $flipImage = 'off';
    public string $captureCmd;
    public NamedLogger $logger;
    public int $debugLevel = 1;

    /**
     * PhotoboothCapture constructor.
     */
    public function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('main');
        $this->logger->debug(self::class);
    }

    /**
     * Capture a demo image.
     */
    public function captureDemo(): void
    {
        $this->logger->debug('Capture Demo', [
            'demoFolder' => $this->demoFolder
        ]);
        $demoFolder = $this->demoFolder;
        $scannedFiles = scandir($demoFolder);
        if ($scannedFiles === false) {
            $this->logger->error('Failed to scan demo folder for images!');
            throw new \RuntimeException('Failed to scan demo folder for images!');
        }
        $devImg = array_diff($scannedFiles, ['.', '..']);
        if (empty($devImg)) {
            $this->logger->error('Demo folder is empty', ['demoFolder' => $demoFolder]);
            throw new \RuntimeException('Demo folder contains no images.');
        }
        if (!copy($demoFolder . $devImg[array_rand($devImg)], $this->tmpFile)) {
            $this->logger->error('Failed to copy demo image to tmp file', ['tmpFile' => $this->tmpFile]);
            throw new \RuntimeException('Failed to copy demo image to tmp file.');
        }
    }

    /**
     * Capture an image from canvas data.
     * @param string $data
     */
    public function captureCanvas($data): void
    {
        $this->logger->debug('Capture Canvas');
        $parts = explode(';', (string) $data, 2);
        if (count($parts) !== 2 || !str_contains($parts[1], ',')) {
            throw new \RuntimeException('Invalid canvas data URI.');
        }
        [, $payload] = explode(',', $parts[1], 2);
        $decoded = base64_decode($payload, true);
        if ($decoded === false || $decoded === '') {
            throw new \RuntimeException('Failed to decode canvas image data.');
        }
        if (file_put_contents($this->tmpFile, $decoded) === false) {
            throw new \RuntimeException('Failed to write canvas image to tmp file.');
        }

        if ($this->flipImage === 'off') {
            return;
        }

        $imageHandler = new Image();
        $im = $imageHandler->createFromImage($this->tmpFile);
        if (!$im instanceof \GdImage) {
            throw new \RuntimeException('Failed to create image resource from tmp image.');
        }
        $imageHandler->debugLevel = $this->debugLevel;
        $imageHandler->jpegQuality = 100;
        switch ($this->flipImage) {
            case 'flip-horizontal':
                imageflip($im, IMG_FLIP_HORIZONTAL);
                break;
            case 'flip-vertical':
                imageflip($im, IMG_FLIP_VERTICAL);
                break;
            case 'flip-both':
                imageflip($im, IMG_FLIP_BOTH);
                break;
            default:
                break;
        }
        $imageHandler->saveJpeg($im, $this->tmpFile);
        unset($im);
    }

    /**
     * Capture an image or video using a command.
     */
    public function captureWithCmd(): void
    {
        $this->logger->debug('Capture with CMD', [
            'cmd' => $this->captureCmd,
            'tmpFile' => $this->tmpFile,
        ]);
        //gphoto must be executed in a dir with write permission for other commands we stay in the api dir
        if (substr($this->captureCmd, 0, strlen('gphoto')) === 'gphoto') {
            chdir(dirname($this->tmpFile));
        }
        // Substitute the tmp file path for the literal "%s" placeholder.
        // Using str_replace (instead of sprintf) keeps other "%" characters
        // in the command template intact — sprintf would otherwise treat
        // anything like "%Y" or "%d" in a user-customized command as a
        // format directive and either error out or produce wrong output.
        if (substr_count($this->captureCmd, '%s') === 0) {
            throw new \RuntimeException(
                'Capture command template is missing the "%s" placeholder for the output file path.'
            );
        }
        $cmd = str_replace('%s', $this->tmpFile, $this->captureCmd);
        $cmd .= ' 2>&1'; //Redirect stderr to stdout, otherwise error messages get lost.

        $output = [];
        $returnValue = 0;
        exec($cmd, $output, $returnValue);

        // Always log a non-zero return code so failures are visible regardless of loglevel.
        if ($returnValue !== 0) {
            $this->logger->error('Capture command returned a non-zero exit code.', [
                'cmd' => $cmd,
                'returnValue' => $returnValue,
                'output' => $output,
            ]);
            if ($this->style === 'video') {
                // Clean up partial video artifacts so a follow-up capture starts fresh.
                exec('rm -f ' . escapeshellarg($this->tmpFile) . '*');
                throw new \RuntimeException(sprintf(
                    'Capture command failed (exit %d): %s',
                    $returnValue,
                    implode("\n", $output)
                ));
            }
        }

        if ($this->style === 'video') {
            $i = 0;
            $processingTime = 300;
            while ($i < $processingTime) {
                clearstatcache(true, $this->tmpFile);
                if (file_exists($this->tmpFile)) {
                    break;
                }
                $i++;
                usleep(100000);
            }
        }

        clearstatcache(true, $this->tmpFile);
        if (!file_exists($this->tmpFile)) {
            $this->logger->error('Capture produced no output file.', [
                'cmd' => $cmd,
                'returnValue' => $returnValue,
                'output' => $output,
            ]);
            if ($this->style === 'video') {
                exec('rm -f ' . escapeshellarg($this->tmpFile) . '*');
            }
            throw new \RuntimeException(sprintf(
                'Capture produced no output file (exit %d): %s',
                $returnValue,
                implode("\n", $output) ?: 'no output'
            ));
        }
    }

    /**
     * Return information about the successful capture process
     */
    public function returnData(): array
    {
        if ($this->style === 'collage') {
            $data = [
                'success' => 'collage',
                'file' => $this->fileName,
                'collage_file' => $this->collageSubFile,
                'current' => $this->collageNumber,
                'limit' => $this->collageLimit,
            ];
        } else {
            $data = [
                'success' => $this->style,
                'file' => $this->fileName
            ];
        }
        $this->logger->debug('returnData', $data);
        return $data;
    }
}
