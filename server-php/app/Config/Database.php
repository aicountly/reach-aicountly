<?php

namespace Config;

use CodeIgniter\Database\Config;

class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    public string $defaultGroup = 'default';

    public array $default = [
        'DSN'      => '',
        'hostname' => '',
        'username' => '',
        'password' => '',
        'database' => '',
        'DBDriver' => 'Postgre',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => true,
        'charset'  => 'utf8',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 5432,
        'schema'   => 'public',
    ];

    public array $tests = [
        'DSN'      => '',
        'hostname' => '127.0.0.1',
        'username' => '',
        'password' => '',
        'database' => '',
        'DBDriver' => 'Postgre',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => true,
        'charset'  => 'utf8',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 5432,
        'schema'   => 'public',
    ];

    public function __construct()
    {
        parent::__construct();

        // DB credentials come exclusively from .env — never hardcoded.
        $this->default['hostname'] = env('DB_HOST', '127.0.0.1');
        $this->default['port']     = (int) env('DB_PORT', '5432');
        $this->default['database'] = env('DB_NAME', 'aicountly_reach');
        $this->default['username'] = env('DB_USER', '');
        $this->default['password'] = env('DB_PASS', '');

        // Test DB group — read from phpunit.xml.dist env or environment overrides.
        // Never falls back to the production connection.
        $this->tests['hostname'] = env('database.tests.hostname', env('TEST_DB_HOST', '127.0.0.1'));
        $this->tests['port']     = (int) env('database.tests.port',     env('TEST_DB_PORT', '5432'));
        $this->tests['database'] = env('database.tests.database', env('TEST_DB_NAME', 'aicountly_reach_test'));
        $this->tests['username'] = env('database.tests.username', env('TEST_DB_USER', ''));
        $this->tests['password'] = env('database.tests.password', env('TEST_DB_PASS', ''));
        $driver                  = env('database.tests.DBDriver', env('TEST_DB_DRIVER', 'Postgre'));
        $this->tests['DBDriver'] = $driver;
        if ($driver === 'SQLite3') {
            $this->tests['database'] = env('database.tests.database', ':memory:');
            unset($this->tests['hostname'], $this->tests['port']);
            $this->tests['foreignKeys'] = true;
        }

        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}
