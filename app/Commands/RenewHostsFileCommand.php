<?php

namespace App\Commands;

use App\Exceptions\ApplicationException;
use App\Exceptions\InvalidHostsFileException;
use App\Exceptions\InvalidServerUrlException;
use App\Exceptions\OperatingSystemNotSupportedException;
use App\Support\Entry;
use App\Support\EntryGroup;
use App\Support\Renderable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use SplFileObject;

class RenewHostsFileCommand extends Command
{
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
                new Entry(
                    $parts[0],
                    new Collection(array_slice($parts, 1))
                )
            );
        }
        // Unset the file to call __destruct(), closing the file handle.
        $file = null;

        /** @var Collection | Entry[] $entries */
        $entries = $this->lines
            ->filter(fn(string|Entry $line) => $line instanceof Entry)
            ->filter(fn(Entry $entry) => $entry->ipAddress !== "127.0.0.1")
            ->filter(fn(Entry $entry) => !filter_var($entry->ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6));


        $progressBar = $this->output->createProgressBar($entries->sum(fn(Entry $entry) => $entry->domains->count()));

        foreach ($entries as $index => $entry) {
            $domainToAddressMap = new Collection();

            foreach ($entry->domains as $domain) {
                try {
                    $ipAddress = $this->getIpAddressByDomain($domain);
                    $domainToAddressMap[$domain] = $ipAddress;
                } catch (ApplicationException $exception) {
                    $this->error($exception->getMessage());
                    $domainToAddressMap[$domain] = $entry->ipAddress;
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
                $entryGroup = new EntryGroup(new Collection);

                foreach ($groups as $ipAddress => $domains) {
                    $entryGroup->entries->push(new Entry($ipAddress, new Collection($domains->keys())));
                }

                $this->lines[$index] = $entryGroup;
            }
        }

        $progressBar->finish();

        $hostsFile = fopen($hostsFilePath, "w");
        foreach ($this->lines as $line) {
            fwrite($hostsFile, ($line instanceof Renderable ? $line->render() : $line) . "\n");
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

    private function getIpAddressByDomain(string $domain): string
    {
        try {
            $response = Http::get($this->resolveServerUrl() . "?domain=$domain");
            $data = $response->json();

            if (
                ($data["status"] === 200) &&
                (($data["ip_addresses"][0] ?? null) !== '127.0.0.1')
            ) {
                $ipAddress = $data["ip_addresses"][0];
            } else {
                throw new ApplicationException("Failed to obtain IP address.");
            }
        } catch (ConnectionException $connectionException) {
            throw new ApplicationException("Failed to get the IP address of {$domain}.");
        }

        return $ipAddress;
    }

    private function resolveServerUrl(): string
    {
        $host = config("app.host");

        if ($host === null) {
            throw InvalidServerUrlException::from($host);
        }

        return rtrim($host, '/');
    }
}
