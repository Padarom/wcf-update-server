<?php

namespace App\Console\Commands;

use Exception;
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
            if ($file == '.' || $file == '..') continue;

            $files[] = $this->import($file, $path);
        }

        if (count($files)) {
            foreach ($files as $file) {
                $this->info("Imported <comment>$file</comment> (@ 1.0.0)");
            }

            return;
        }
        
        $this->info('No files found to import.');
    }

    protected function import($file, $path)
    {
        return $file;
    }
}