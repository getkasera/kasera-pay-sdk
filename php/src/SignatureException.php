<?php

declare(strict_types=1);

namespace Kasera\Pay;

/** A webhook delivery that failed verification. Answer it 400, never process it. */
final class SignatureException extends \RuntimeException
{
}
