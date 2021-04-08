<?php

namespace App\Commands;

use App\Exceptions\ApplicationException;
use App\Exceptions\InvalidHostsFileException;
use App\Exceptions\InvalidServerUrlException;
use App\Exceptions\OperatingSystemNotSupportedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use SplFileObject;

class RenewHostsFileCommand extends Command
{
    public array $hosts = [];
    public array $domainNames = [];
    public int $lineIndex = -1;
    public Collection $lines;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'renew {--all}';
    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Renew IP addresses of provided hosts';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $this->validateOSFamily();
            $serverUrl = $this->getServerUrl();
            /* TODO: Allow customization */
            $hostsFilePath = $this->getHostsFilePath();
        } catch (ApplicationException $exception) {
            $this->error($exception->getMessage());
            return 1;
        }


        $file = new SplFileObject($hostsFilePath);


        $this->lines = new Collection();

        while (!$file->eof()) {
            $line = trim($file->fgets());

            // Skip empty lines
            if ((mb_strlen($line) === 0) || ($line[0] === '#')) {
                $this->lines->push($line);
                continue;
            }

            // Real lines
            $parts = preg_split("/ +/ui", $line);
            $this->lines->push(
                new \App\Support\Entry(
                    $parts[0],
                    new Collection(array_slice($parts, 1))
                )
            );
        }
        // Unset the file to call __destruct(), closing the file handle.
        $file = null;

        /** @var Collection | \App\Support\Entry[] $entries */
        $entries = $this->lines
            ->filter(fn(string|\App\Support\Entry $line) => $line instanceof \App\Support\Entry)
            ->filter(fn(\App\Support\Entry $entry) => $entry->ipAddress !== "127.0.0.1")
            ->filter(fn(\App\Support\Entry $entry) => !filter_var($entry->ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6));

        $progressBar = $this->output->createProgressBar($entries->sum(fn(\App\Support\Entry $entry) => $entry->domains->count()));

        foreach ($entries as $index => $entry) {
            $domainToAddressMap = new Collection();

            foreach ($entry->domains as $domain) {
                $progressBar->setMessage("Obtaining IP address for {$domain}.");

                try {
                    $response = Http::get($serverUrl . "?domain=$domain");
                    $data = $response->json();

                    if (
                        ($data["status"] === 200) &&
                        (($data["ip_addresses"][0] ?? null) !== '127.0.0.1')
                    ) {
                        $domainToAddressMap[$domain] = $data["ip_addresses"][0];
                    } else {
                        $domainToAddressMap[$domain] = $entry->ipAddress;
                    }
                } catch (ConnectionException $connectionException) {
                    $this->error("Failed to get the IP address of {$domain}.");
                    // No-op
                }

                $progressBar->advance();
            }

            /** @var Collection | Collection[] $groups */
            $groups = $domainToAddressMap->groupBy(null, true);

            if ($groups->count() === 1) {
                /** @var Collection $firstGroup */
                $firstGroup = $groups->pop();
                $entry->ipAddress = $firstGroup->first();
            } else {
                $entryGroup = new \App\Support\EntryGroup(new Collection);

                foreach ($groups as $ipAddress => $domains) {
                    $entryGroup->entries->push(new \App\Support\Entry($ipAddress, new Collection($domains->keys())));
                }

                $this->lines[$index] = $entryGroup;
            }
        }

        $progressBar->finish();

        $hostsFile = fopen($hostsFilePath, "w");
        foreach ($this->lines as $line) {
            fwrite($hostsFile, ($line instanceof \App\Support\Renderable ? $line->render() : $line) . "\n");
        }
        fclose($hostsFile);

        return 0;
    }

    private function validateOSFamily(): void
    {
        /* TODO: Test on Windows */
        if (PHP_OS_FAMILY !== "Linux") {
            throw new OperatingSystemNotSupportedException();
        }
    }

    private function getServerUrl(): string
    {
        $host = config("app.host");

        if ($host === null) {
            throw InvalidServerUrlException::from($host);
        }

        return rtrim($host, '/');
    }

    /**
     * @return string
     */
    private function getHostsFilePath(): string
    {
        $path = "/etc/hosts";

        if (!file_exists($path) || !is_writable($path)) {
            throw InvalidHostsFileException::from($path);
        }
        return $path;
    }

}
