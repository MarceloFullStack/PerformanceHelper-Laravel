<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PerformanceHelper
{
    protected static $startTimes = [];
    protected static $currentBlockName;
    protected static $sql;
    protected static $ignoredConnections = [];
    protected static $ignoredSchemas = [];
    protected static $sqlLogs = [];

    protected static $elapsedTimes = []; // Adicione esta linha
    protected static $fullResult;
    public function __construct()
    {
        if (config('app.debug')) {
            DB::listen(function ($query) {
                self::logSql($query);
            });
        }
    }



    public static function start($blockName, $sql = false, array $ignoredConnections = [], array $ignoredSchemas = [], $fullResult = false)
    {
        self::$fullResult = $fullResult;

        self::$currentBlockName = $blockName;
        self::$sql = $sql;
        self::$ignoredConnections = $ignoredConnections;
        self::$ignoredSchemas = $ignoredSchemas;
        self::$startTimes[$blockName] = microtime(true);

        if ($sql) {
            DB::listen(function ($query) {
                self::logSql($query);
            });
        }
    }

public static function end()
{
    $blockName = self::$currentBlockName;

    if (!isset(self::$startTimes[$blockName])) {
        return;
    }

    $elapsedTime = microtime(true) - self::$startTimes[$blockName];
    self::logPerformance($blockName, $elapsedTime);

    // Armazena o tempo decorrido na propriedade estática
    self::$elapsedTimes[$blockName] = $elapsedTime * 1000;

    // Adie a geração do relatório até que o script seja encerrado
    register_shutdown_function(function () {
        PerformanceHelper::generateReport();
    });
    die();
}


    protected static function logPerformance($blockName, $elapsedTime)
    {
        $formattedDate = strftime('%d/%m/%Y %H:%M:%S', time());
        $elapsedTimeMs = number_format($elapsedTime * 1000, 3);

        $performanceLog = sprintf(
            "[%s] %s %s s (%s ms)",
            $formattedDate,
            str_pad("{$blockName}:", 30, '='),
            number_format($elapsedTime, 6),
            $elapsedTimeMs
        );

        Log::info($performanceLog);
    }

    protected static function logSql($query)
    {
        $connectionName = $query->connection->getDatabaseName();
        $schemaName = $query->connection->getConfig('schema');

        if (in_array($connectionName, self::$ignoredConnections) || in_array($schemaName, self::$ignoredSchemas)) {
            return;
        }

        $sqlExecutionTimeMs = number_format($query->time, 3);
        $sqlExecutionTimeSec = number_format($query->time / 1000, 6); // Convertendo de milissegundos para segundos

        // Substituir os '?' pelos valores reais
        $sql = $query->sql;
        if (!empty($query->bindings)) {
            foreach ($query->bindings as $binding) {
                $sql = preg_replace('/\?/', is_numeric($binding) ? $binding : "'{$binding}'", $sql, 1);
            }
        }

        self::$sqlLogs[] = [
            'connection' => $connectionName,
            'schema' => $schemaName,
            'sql' => $sql,
            'executionTimeMs' => $sqlExecutionTimeMs,
            'executionTimeSec' => $sqlExecutionTimeSec,
        ];

        $separator = str_repeat('-', 80);

        $sqlLog = sprintf(
            "%s\nConexão: %s\nSchema: %s\nTempo de Execução (ms): %s\nSQL: %s\n%s",
            $separator,
            $connectionName,
            $schemaName,
            $sqlExecutionTimeMs,
            $sql,
            $separator
        );

        Log::info($sqlLog);
    }



    protected static function generateReport()
    {
        // Sort by execution time in descending order
        usort(self::$sqlLogs, function ($a, $b) {
            return $b['executionTimeMs'] <=> $a['executionTimeMs'];
        });

        $maxBarLength = 50; // Adjust as needed

        $html = '<html><head><title>Relatório de Desempenho</title>';

        $html .= '<style>';
        $html .= 'table { border-collapse: collapse; margin: auto; }';
        $html .= 'th { text-align: center; padding: 10px; border: 1px solid #ddd; background-color: #f3f3f3; }';
        $html .= 'td { padding: 5px 10px; border: 1px solid #ddd; }';
        $html .= '.even { background-color: #f9f9f9; }';
        $html .= '.odd { background-color: #fff; }';
        $html .= '.bg-red-700 { background-color: #dc3545; }';
        $html .= '</style>';
        $html .= '</head><body>';
        $html .= '<h1 style="text-align: center;">Relatório de Desempenho</h1>';
        if (!empty(self::$elapsedTimes)) {
            $html .= '<h2 style="text-align: center;">Tempos Decorridos</h2>';
            $html .= '<ul style="text-align: center; list-style-type: none; padding-left: 0;">';
            foreach (self::$elapsedTimes as $blockName => $elapsedTime) {
                $html .= sprintf("<li>%s: %s ms</li>", $blockName, number_format($elapsedTime, 0, ',', '.'));
            }
            $html .= '</ul>';
        }

        if (self::$sql) {
            $html .= '<table>';
            $html .= '<thead><tr><th>#</th><th>Tempo (ms)</th><th>Banco</th><th>Schema</th><th>SQL</th></tr></thead>';
            $html .= '<tbody>';

            $totalTime = 0;

            foreach (self::$sqlLogs as $index => $sqlLog) {
                $totalTime += $sqlLog['executionTimeMs'];

                $html .= sprintf(
                    "<tr %s><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>",
                    ($index % 2 == 0 ? 'class="even"' : 'class="odd"'),
                    $index + 1,
                    $sqlLog['executionTimeMs'],
                    $sqlLog['connection'],
                    $sqlLog['schema'],
                    $sqlLog['sql'] = str_replace('"', '', $sqlLog['sql'])
                );
            }

            $totalTimeMs = number_format($totalTime, 2, ',', '.');
            $totalTimeSec = number_format($totalTime / 1000, 3, ',', '');
            $html .= "<p style='text-align: center;'><strong>Tempo total consultas:</strong> {$totalTimeSec} s ({$totalTimeMs} ms)</p>";
            $html .= '</tbody></table>';
        }

        $html .= '</body></html>';

        // Send response to browser or Postman
        $response = new \Symfony\Component\HttpFoundation\Response($html, 200, [
            'Content-Type' => 'text/html',
        ]);
        $response->send();
    }
}