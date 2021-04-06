<?php


namespace App\Exceptions;


class InvalidHostsFileException extends ApplicationException
{
    public static function from(string $hostsFilePath)
    {
        return new self(sprintf("The hosts file path at '%s' either does not exist or is not writable.", $hostsFilePath));
    }
}