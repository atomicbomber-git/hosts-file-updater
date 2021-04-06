<?php


namespace App\Exceptions;


class InvalidServerUrlException extends ApplicationException
{
    public static function from(string $serverUrl): self
    {
        return new self(
            sprintf("The server URL \"%s\" is not valid.", $serverUrl)
        );
    }
}