<?php

namespace Tests;

use App\Services\Dashboard\ClusterTeams;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Dossier d'exports propre au test : les fichiers réels de storage/app/private/exports ne sont jamais touchés. */
    protected string $exportsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exportsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gavi-tests-exports-'.Str::random(10);
        config(['gavi.exports_path' => $this->exportsPath]);
        ClusterTeams::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->exportsPath);
        parent::tearDown();
    }
}
