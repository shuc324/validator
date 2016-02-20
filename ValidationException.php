<?php
namespace Shuc324\Validation;

class ValidationException extends \Exception
{
    public function __construct(string $message = null, int $code = 0) {
        parent::__construct($message, $code);
    }
}
