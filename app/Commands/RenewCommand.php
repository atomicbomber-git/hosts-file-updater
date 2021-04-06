<?php

namespace App\Commands;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class RenewCommand extends Command
{
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

    public array $hosts = [];
    public array $domainNames = [];

    public int $lineIndex = -1;
    public array $fileLines = [];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $userOS = PHP_OS_FAMILY;

        $this->info("Your current OS family is {$userOS}.");

        if (PHP_OS_FAMILY !== "Linux") {
            $this->error("This program can only be used on Linux so far, sorry.");
            return 1;
        }

        $hostsFilePath = "/etc/hosts";

        $this->info("Checking hosts file at {$hostsFilePath}...");

        if (! file_exists($hostsFilePath)) {
            $this->error("Hosts file path could not be found at {$hostsFilePath}.");
            return 1;
        }

        if (! is_writable($hostsFilePath)) {
            $this->error("Hosts file path at {$hostsFilePath} is not writable. Try re-running this command as root / admin.");
            return 1;
        }

        $this->info("Loading hosts file into memory...");

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
                $toBeRenewedList = $this->getDomainsFromAnswer($answer);
            }

            $this->info(sprintf("Here are the domains that you want to renew: %s", join(', ', $toBeRenewedList)));
            $continue = (mb_strtolower($this->ask("Continue? (y/n)")[0]) ?? 'n') === 'y';
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
                $response = Http::get(env("HOST") . "/?domain={$domain}");
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

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule)
    {
        // $schedule->command(static::class)->everyMinute();
    }

    /**
     * @param mixed $answer
     * @return array
     */
    private function getDomainsFromAnswer(mixed $answer): array
    {
        return array_values(
            array_filter(
                array_keys($this->domainNames),
                fn(string $domainName) => Str::is($answer, $domainName),
            )
        );
    }
}
