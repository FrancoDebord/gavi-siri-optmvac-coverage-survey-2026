<?php

namespace App\Services\Connectors;

use App\Models\DataSource;
use App\Services\Dictionary\DictionaryLoader;
use App\Services\Import\SubmissionNormalizer;
use InvalidArgumentException;

class ConnectorFactory
{
    public function __construct(
        private readonly SubmissionNormalizer $normalizer,
        private readonly DictionaryLoader $dictionaryLoader,
    ) {}

    public function make(DataSource $source): DataSourceConnector
    {
        return match ($source->type) {
            DataSource::TYPE_ODK => new OdkCentralConnector($source, $this->normalizer, $this->dictionaryLoader),
            DataSource::TYPE_KOBO => new KoboConnector($source, $this->normalizer, $this->dictionaryLoader),
            default => throw new InvalidArgumentException("Type de source inconnu : {$source->type}"),
        };
    }
}
