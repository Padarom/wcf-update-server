<?php

namespace Padarom\Thunderstorm\Console\Commands;

use Exception;
use DOMDocument;
use Padarom\Thunderstorm\PackageImporter;
use Padarom\Thunderstorm\DOMWrapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\ProcessUtils;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\PhpExecutableFinder;

class ImportUploads extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'import:uploads';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports packages from the configured upload directory';

    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function fire()
    {
        $uploadPath = env('UPLOAD_DIR', 'uploads');

        // Use UPLOAD_DIR either as an absolute path, or relative to the base path
        if (!($path = realpath($uploadPath))) {
            $path = base_path($uploadPath);
        }

        $importedSomething = false;
        foreach (scandir($path) as $file) {
            if (in_array($file, ['.', '..', '.gitignore', '.gitkeep'])) continue;

            $version = $this->import($file, $path);
            if ($version) {
                $importedSomething = true;
                $identifier    = $version->package->identifier;
                $versionNumber = $version->name;

                $this->info("Imported \"<comment>$identifier</comment>\" (@ $versionNumber)");

                // Make sure the directory exists
                if (!file_exists(dirname($version->storagePath))) {
                    mkdir(dirname($version->storagePath));
                }
                rename($path . '/' . $file, $version->storagePath);
            }
        }
        
        if ($importedSomething) {
            if (Cache::has('xml.renderedPath')) {
                unlink(Cache::pull('xml.renderedPath'));
            }
        } else {
            $this->info('No files found to import.');
        }
    }

    protected function import($file, $path)
    {
        try {
            // Read the package.xml without unzipping the archive
            $fullpath = $path . '/' . $file . '/package.xml';
            $package = file_get_contents('phar://' . $fullpath);

            $data = [];
            $dom = new DOMDocument();
            $dom->loadXML($package);
            $dom = new DOMWrapper($dom);

            $importer = new PackageImporter($dom);
            $importer->run();

            return $importer->getVersion();
        } catch (\Exception $e) {
            $this->error("The file \"$file\" is not a valid WCF package archive.");
        }

        return false;
    }
}
