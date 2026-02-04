<?php

declare(strict_types=1);

namespace App\Support\Admin\Server;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class Database implements Arrayable
{
    private function getStats()
    {
        $config = DB::connection('tenant')->getConfig();

        if ($config['driver'] !== 'mysql' || empty($config['database'])) {
            return [];
        }

        $result = $this->getDbConnection()
            ->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', '=', $config['database'])
            ->selectRaw('SUM(table_rows) as rows_quantity')
            ->selectRaw('SUM(data_length + index_length) as size')
            ->groupBy('TABLE_SCHEMA')
            ->first();

        return [
            'rows' => (int) $result->rows_quantity,
            'size' => (int) $result->size,
        ];
    }

    private function getVariables()
    {
        $list = [
            'character_set_client' => 'Character set (client)',
            'performance_schema' => 'Performance schema',
            'slow_query_log' => 'Slow query log',
            'sql_mode' => 'SQL mode',
            'version' => 'Version',
        ];

        $results = DB::connection('tenant')
            ->getPdo()
            ->query('SHOW VARIABLES')
            ->fetchAll();

        $results = collect($results)
            ->pluck('Value', 'Variable_name')
            ->filter(fn($item, $key) => array_key_exists($key, $list))
            ->map(function ($item, $key) use ($list) {
                if ($key == 'sql_mode') {
                    $item = collect(explode(',', $item))->implode(PHP_EOL);
                }

                return (object) [
                    'title' => Arr::get($list, $key),
                    'value' => $item,
                ];
            });

        return $results;
    }

    private function getDbConnection()
    {
        static $connection;

        if (! isset($connection)) {
            $config = DB::getConfig();

            Arr::forget($config, 'prefix');
            Config::set('database.connections.global', $config);

            $connection = DB::connection('global');
        }

        return $connection;
    }

    public function toArray()
    {
        return [
            /** @var array{rows:int,size:int} */
            'stats' => $this->getStats(),
            /** @var array{title:string,value:string}[] */
            'variables' => $this->getVariables(),
        ];
    }
}
