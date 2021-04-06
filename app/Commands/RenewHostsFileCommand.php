<?php

namespace App\Commands;

use App\Exceptions\ApplicationException;
use App\Exceptions\InvalidHostsFileException;
use App\Exceptions\InvalidServerUrlException;
use App\Exceptions\OperatingSystemNotSupportedException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class RenewHostsFileCommand extends Command
{
    public array $hosts = [];
    public array $domainNames = [];
    public int $lineIndex = -1;
    public array $fileLines = [];

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'renew {hosts?}';
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

        $fileHandle = fopen($hostsFilePath, "r");
        while (($domains = fgets($fileHandle)) !== false) {
            if (in_array($domains, [".", ".."])) continue;

            ++$this->lineIndex;
            $this->fileLines[$this->lineIndex] = $domains;

            $domains = trim($domains);
            if (mb_strlen($domains) === 0) continue;
            if (str_starts_with($domains, "#")) continue;

            $lineParts = preg_split('/ +/ui', $domains);
            if (count($lineParts) < 2) continue;
            $domainNames = array_slice($lineParts, 1);

            foreach ($domainNames as $domainName) {
                $this->domainNames[$domainName] = $this->lineIndex;
            }
        }
        fclose($fileHandle);

        do {
            $answer = $this->ask("Domain names you want to renew?");
            $toBeRenewedList = [$answer];
            if (Str::contains($answer, '*')) {
                $toBeRenewedList = $this->getDomainsFromPattern($answer);
            }

            $this->info(sprintf("Here are the domains that you want to renew:"));

            $this->table(
                ["Domain Name"],
                array_map(fn(string $domainName) => [$domainName], $toBeRenewedList)
            );

            $continue = (mb_strtolower($this->ask("Continue? (y/n)")[0] ?? 'y') === 'y');
        } while (!$continue);

        $domainNamesWithLines = [];
        foreach ($toBeRenewedList as $toBeRenewed) {
            $lineIndex = $this->domainNames[$toBeRenewed];
            $domainNamesWithLines[$lineIndex] ??= [];
            $domainNamesWithLines[$lineIndex][] = $toBeRenewed;
        }

        $resultingIpAddresses = [];
        foreach ($domainNamesWithLines as $lineIndex => $domains) {
            foreach ($domains as $domain) {
                /* TODO: Allow customization */
                $response = Http::get($serverUrl . "/?domain={$domain}");
                $data = $response->json();

                if ($data["status"] === 200) {
                    $resultingIpAddresses[$lineIndex] = $data["ip_addresses"][0];
                    break;
                }
            }
        }

        foreach ($resultingIpAddresses as $lineIndex => $resultingIpAddress) {
            $this->fileLines[$lineIndex] =
                preg_replace("/^[^ ]* /", "{$resultingIpAddress} ", $this->fileLines[$lineIndex]);
        }

        file_put_contents($hostsFilePath, implode($this->fileLines));

        $this->info("DONE!");
        return 1;
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

        return $host;
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

    /**
     * @param mixed $answer
     * @return array
     */
    private function getDomainsFromPattern(mixed $answer): array
    {
        return array_values(
            array_filter(
                array_keys($this->domainNames),
                fn(string $domainName) => Str::is($answer, $domainName),
            )
        );
    }
}
