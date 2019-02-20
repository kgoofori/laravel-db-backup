<?php

namespace Witty\LaravelDbBackup\Commands;

use Aws\Laravel\AwsFacade as AWS;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Witty\LaravelDbBackup\Commands\Helpers\BackupFile;
use Witty\LaravelDbBackup\Commands\Helpers\BackupHandler;
use Witty\LaravelDbBackup\Commands\Helpers\Encrypt;
use League\Flysystem\Filesystem;
use Spatie\Dropbox\Client;
use Spatie\FlysystemDropbox\DropboxAdapter;

/**
 * Class BackupCommand
 * @package Witty\LaravelDbBackup\Commands
 */
class BackupCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $name = 'db:backup';
    protected $description = 'Backup the default database to `storage/dumps`';
    protected $filePath;
    protected $fileName;

    /**
     * @return void
     */
    public function handle()
    {
        return $this->fire();
    }

    /**
     * @return void
     */
    public function fire()
    {
        $database = $this->getDatabase($this->input->getOption('database'));

        $this->checkDumpFolder();

        //----------------
        $dbfile = new BackupFile($this->argument('filename'), $database, $this->getDumpsPath());
        $this->filePath = $dbfile->path();
        $this->fileName = $dbfile->name();

        $status = $database->dump($this->filePath);
        $handler = new BackupHandler($this->colors);

        // Error
        //----------------
        if ($status !== true) {
            return $this->line($handler->errorResponse($status));
        }

        // Compression
        //----------------
        if ($this->isCompressionEnabled()) {
            $this->compress();
            $this->fileName .= ".gz";
            $this->filePath .= ".gz";
        }
        // Encrypting
        //----------------
        if ($this->option('encrypt')) {
            if (!Encrypt::encryptFile($this->filePath)) {
                return $this->line('Encrypt returned false result');
            }
        }

        // Save to dropbox
        //----------------
        if ($this->option('dropbox')){

            $accessToken = Config::get('db-backup.dropbox.accessToken');
            $appSecret = Config::get('db-backup.dropbox.appSecret');
            $prefix = Config::get('db-backup.dropbox.prefix');

            $client = new Client($accessToken);
            $adapter = new DropboxAdapter($client);
            $filesystem = new Filesystem($adapter);

            $content = file_get_contents($this->filePath);
            $filesystem->put($prefix.'/'.$this->fileName,$content);

        }

        // Save dump name to db
        //----------------
        if ($this->option('save-dump-name')){
            Dump::create([
                'file' => $this->filePath,
                'file_name' => $this->fileName,
                'prefix' =>  Config::get('db-backup.dropbox.prefix',null),
                'encrypted' => $this->option('encrypt'),
                'created_at' => Carbon::now()->timestamp
            ]);
        }

        $this->line($handler->dumpResponse($this->argument('filename'), $this->filePath, $this->fileName));

        // S3 Upload
        //----------------
        if ($this->option('upload-s3')) {
            $this->uploadS3();
            $this->line($handler->s3DumpResponse());

            if ($this->option('keep-only-s3')) {
                File::delete($this->filePath);
                $this->line($handler->localDumpRemovedResponse());
            }
        }
    }

    /**
     * Perform Gzip compression on file
     *
     * @return boolean
     */
    protected function compress()
    {
        $command = sprintf('gzip -9 %s', $this->filePath);

        return $this->console->run($command);
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['filename', InputArgument::OPTIONAL, 'Filename or -path for the dump.'],
        ];
    }

    /**
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['database', null, InputOption::VALUE_OPTIONAL, 'The database connection to backup'],
            ['upload-s3', 'u', InputOption::VALUE_REQUIRED, 'Upload the dump to your S3 bucket'],
            ['keep-only-s3', true, InputOption::VALUE_NONE, 'Delete the local dump after upload to S3 bucket'],
            ['dropbox', false, InputOption::VALUE_NONE, 'Save dump to dropbox'],
            ['encrypt', false, InputOption::VALUE_NONE, 'Encrypt dump'],
            ['save-dump-name', false, InputOption::VALUE_NONE, 'Save dump name to DB']
        ];
    }

    /**
     * @return void
     */
    protected function checkDumpFolder()
    {
        $dumpsPath = $this->getDumpsPath();

        if (!is_dir($dumpsPath)) {
            mkdir($dumpsPath);
        }
    }

    /**
     * @return void
     */
    protected function uploadS3()
    {
        $bucket = $this->option('upload-s3');
        $s3 = AWS::get('s3');
        $s3->putObject([
            'Bucket' => $bucket,
            'Key' => $this->getS3DumpsPath() . '/' . $this->fileName,
            'SourceFile' => $this->filePath,
        ]);
    }

    /**
     * @return string
     */
    protected function getS3DumpsPath()
    {
        $default = 'dumps';

        return Config::get('db-backup.s3.path', $default);
    }
}
