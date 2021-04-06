<?php


namespace App\Exceptions;


class OperatingSystemNotSupportedException extends ApplicationException
{
    protected $message = "This operating system is not supported.";
}