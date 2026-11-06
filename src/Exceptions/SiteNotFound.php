<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** No site serves the request host (unmatched => abort). Renders as a 404. */
final class SiteNotFound extends NotFoundHttpException
{
    public function __construct(public readonly string $host)
    {
        parent::__construct('No site serves this host.');
    }
}
