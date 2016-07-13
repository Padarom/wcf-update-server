<?php

namespace Padarom\UpdateServer\Console\Commands;

use Exception;
use DOMDocument;
use Padarom\UpdateServer\Models\Package;
use Padarom\UpdateServer\Models\LocalizedTag;
use Padarom\UpdateServer\Models\PackageVersion;
use Padarom\UpdateServer\DOMWrapper;
use Illuminate\Console\Command;
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
    protected $description = 'Serve the application on the PHP development server';

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

        $files = [];
        foreach (scandir($path) as $file) {
            if (in_array($file, ['.', '..', '.gitignore', '.gitkeep'])) continue;

            $files[] = $this->import($file, $path);
        }

        if (count($files)) {
            foreach ($files as $file) {
                if ($file) {
                    $name = $file['identifier'];
                    $version = $file['version'];
                    $this->info("Imported \"<comment>$name</comment>\" (@ $version)");
                }
            }

            return;
        }
        
        $this->info('No files found to import.');
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

            $identifier = $dom->getElementAttribute('package', 'name');
            $version = $dom->getElementValue('version');

            $this->savePackageVersion($path . '/' . $file, $identifier, $version, $dom);

            return compact('identifier', 'version');
        } catch (\Exception $e) {
            $this->error("The file \"$file\" is not a valid WCF package archive.");
            dd($e->getMessage());
        }

        return false;
    }

    protected function savePackageVersion($path, $identifier, $version, DOMWrapper $dom)
    {
        // Move the file into the storage/packages directory
        $storagePath = storage_path('packages/' . $identifier);
        if (!file_exists($storagePath)) {
            mkdir($storagePath);
        }
        //rename($path, $storagePath . '/' . $version . '.tar');

        // Set the package's author and the author's URL
        $package = $this->getPackage($identifier);
        $package->author    = $dom->getElementValue('author');
        $package->authorurl = $dom->getElementValue('authorurl');
        $package->save();

        // Truncate the language tags for this package
        $package->localizedTags()->delete();

        // Get the packagenames and packagedescriptions in all languages
        $tags = ['packagename' => 'name', 'packagedescription' => 'description'];
        foreach ($tags as $xml => $db) {
            foreach ($dom->getElements($xml) as $element) {
                $localizedTag = new LocalizedTag([
                    'tag' => $db,
                    'text' => $element->getElementValue($element),
                    'language' => $element->getElementAttribute($element, 'language'),
                ]);
                $localizedTag->package()->associate($package);
                $localizedTag->save();
            }
        }

        $packageVersion = PackageVersion::where('package_id', $package->id)->where('name', $version)->first();
        if (!$packageVersion) {
            $packageVersion = new PackageVersion([
                'name' => $version,
                'license' => 'free',
            ]);
            $packageVersion->package()->associate($package);
        }

        $packageVersion->timestamp = time();
        $packageVersion->save();
    }

    /**
     * Retrieves the stored package with the given identifier or creates a new one.
     * 
     * @param string $identifier The identifier for the package to be retrieved
     * @return Package
     */
    protected function getPackage($identifier)
    {
        $package = Package::withIdentifier($identifier);
        if (!$package) {
            $package = new Package(['identifier' => $identifier]);
        }

        return $package;
    }
}
