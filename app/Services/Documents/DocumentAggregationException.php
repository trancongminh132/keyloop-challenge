<?php

namespace App\Services\Documents;

use RuntimeException;

class DocumentAggregationException extends RuntimeException
{
    public function __construct(
        private readonly array $sources,
        private readonly array $warnings,
    ) {
        parent::__construct('All document sources failed.');
    }

    public function sources(): array
    {
        return $this->sources;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }
}
