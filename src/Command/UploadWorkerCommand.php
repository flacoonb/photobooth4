<?php

declare(strict_types=1);

namespace Photobooth\Command;

use Photobooth\Enum\FolderEnum;
use Photobooth\Service\ConfigurationService;
use Photobooth\Service\LoggerService;
use Photobooth\Service\RemoteStorageService;
use Photobooth\Service\UploadQueueService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'photobooth:upload:worker',
    description: 'Processes the async FTP/SFTP upload queue'
)]
class UploadWorkerCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Processes the async FTP/SFTP upload queue');
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Process one job and exit');
        $this->addOption('poll-interval', null, InputOption::VALUE_REQUIRED, 'Seconds between queue polls', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = LoggerService::getInstance()->getLogger('uploadworker');
        $queue = UploadQueueService::getInstance();
        $once = (bool) $input->getOption('once');
        $pollInterval = (int) $input->getOption('poll-interval');

        $output->writeln('Upload worker started.');
        $logger->info('Upload worker started');

        // Reset any stale in_progress jobs from previous crashed runs.
        $queue->resetStaleJobs();

        // Best-effort cleanup of long-completed entries so the SQLite DB
        // does not grow unbounded over weeks of operation.
        try {
            $queue->purgeCompleted(7 * 24 * 60);
        } catch (\Throwable $purgeError) {
            $logger->warning('Queue purge failed', ['error' => $purgeError->getMessage()]);
        }

        // Graceful shutdown via SIGTERM/SIGINT so systemd restarts and
        // Ctrl+C don't leave a job stuck in_progress. Falls back silently
        // if pcntl isn't available (non-CLI SAPI or compile-time omission).
        $shouldStop = false;
        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stopHandler = function (int $signo) use (&$shouldStop, $logger, $output): void {
                $logger->info('Upload worker received shutdown signal', ['signal' => $signo]);
                $output->writeln('Shutdown signal received, finishing current job and exiting.');
                $shouldStop = true;
            };
            pcntl_signal(SIGTERM, $stopHandler);
            pcntl_signal(SIGINT, $stopHandler);
            pcntl_signal(SIGHUP, $stopHandler);
        }

        $setupDone = false;
        $lastConfigHash = '';
        $purgeCounter = 0;

        while (!$shouldStop) {
            $job = $queue->claimNext();

            if ($job === null) {
                if ($once) {
                    $output->writeln('No pending jobs, exiting.');

                    return Command::SUCCESS;
                }
                // Occasional cleanup pass during idle ticks (every ~5 min
                // assuming default 5s poll interval).
                if (++$purgeCounter >= 60) {
                    $purgeCounter = 0;
                    try {
                        $queue->purgeCompleted(7 * 24 * 60);
                    } catch (\Throwable $purgeError) {
                        $logger->warning('Queue purge failed', ['error' => $purgeError->getMessage()]);
                    }
                }
                sleep($pollInterval);
                continue;
            }

            $jobId = (int) $job['id'];
            $imageFile = (string) $job['image_file'];
            $thumbFile = (string) $job['thumb_file'];
            $remoteFilename = (string) $job['remote_filename'];

            $output->writeln('Processing job #' . $jobId . ': ' . $imageFile . ' -> ' . $remoteFilename);
            $logger->info('Processing upload job', ['id' => $jobId, 'image' => $imageFile, 'remote' => $remoteFilename]);

            try {
                // Reload config from disk and create fresh RemoteStorageService per job
                // to pick up any admin panel config changes while worker is running.
                ConfigurationService::getInstance()->load();
                $configHash = md5(serialize(ConfigurationService::getInstance()->getConfiguration()['ftp']));
                if ($configHash !== $lastConfigHash) {
                    $setupDone = false;
                    $lastConfigHash = $configHash;
                }

                $remoteStorage = new RemoteStorageService();

                // Setup remote directories and webpage once per config version.
                if (!$setupDone) {
                    $remoteStorage->ensureDirectoriesExist();
                    $remoteStorage->createWebpage();
                    $setupDone = true;
                    $logger->info('Remote setup completed.');
                }

                $imagePath = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $imageFile;
                $thumbPath = FolderEnum::THUMBS->absolute() . DIRECTORY_SEPARATOR . $thumbFile;

                if (!file_exists($imagePath)) {
                    throw new \RuntimeException('Image file not found: ' . $imagePath);
                }

                $imageContents = file_get_contents($imagePath);
                if ($imageContents === false) {
                    throw new \RuntimeException('Failed to read image file: ' . $imagePath);
                }

                $remoteStorage->write($remoteStorage->getStoragePath('images/' . $remoteFilename), $imageContents);

                if (file_exists($thumbPath)) {
                    $thumbContents = file_get_contents($thumbPath);
                    if ($thumbContents !== false) {
                        $remoteStorage->write($remoteStorage->getStoragePath('thumbs/' . $remoteFilename), $thumbContents);
                    }
                }

                $queue->markCompleted($jobId);
                $logger->info('Upload completed', ['id' => $jobId, 'remote' => $remoteFilename]);
                $output->writeln('Job #' . $jobId . ' completed successfully.');
            } catch (\Throwable $e) {
                $setupDone = false;
                try {
                    $queue->markFailed($jobId, $e->getMessage());
                } catch (\Throwable $persistError) {
                    $logger->error('Could not persist failure status', [
                        'id' => $jobId,
                        'error' => $persistError->getMessage(),
                    ]);
                }
                $output->writeln('Job #' . $jobId . ' failed: ' . $e->getMessage());
                $logger->error('Upload job failed', [
                    'id' => $jobId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }

            if ($once) {
                return Command::SUCCESS;
            }
        }

        $logger->info('Upload worker exiting cleanly');
        return Command::SUCCESS;
    }
}
