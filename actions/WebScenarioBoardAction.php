<?php

namespace Modules\WebScenarioStatusBoard\Actions;

use CController;
use CControllerResponseData;

class WebScenarioBoardAction extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return true;
    }

    protected function doAction(): void {
        $config = $this->loadConfig();

        if ($config === null) {
            echo 'No se pudo cargar config.json.';
            return;
        }

        $apiUrl = $config['apiUrl'] ?? '';
        $apiToken = $config['apiToken'] ?? '';
        $refreshInterval = (int)($config['refreshIntervalSeconds'] ?? 60);

        if ($apiUrl === '' || $apiToken === '') {
            echo 'Config incompleta: revisa apiUrl y apiToken en el modulo.';
            return;
        }

        if (((string)($_REQUEST['mode'] ?? '')) === 'history') {
            $this->respondHistory($apiUrl, $apiToken);
            return;
        }

        $groupIdsMacro = $this->resolveGroupMacroName((string)($config['groupIdsMacro'] ?? '{$GROUPIDS}'));
        $groupids = $this->getConfiguredGroupIds($apiUrl, $apiToken, $groupIdsMacro);
        if (empty($groupids)) {
            echo 'No se encontraron grupos para consultar.';
            return;
        }

        $groups = $this->zabbixApiRequest($apiUrl, $apiToken, 'hostgroup.get', [
            'output' => ['groupid', 'name'],
            'groupids' => $groupids
        ]);

        $groupNameById = [];
        foreach ($groups as $group) {
            $groupNameById[$group['groupid']] = $group['name'];
        }

        $hosts = $this->zabbixApiRequest($apiUrl, $apiToken, 'host.get', [
            'output' => ['hostid', 'name'],
            'selectHostGroups' => ['groupid', 'name'],
            'groupids' => $groupids,
            'filter' => ['status' => 0]
        ]);

        if (empty($hosts)) {
            $this->setResponse(new CControllerResponseData([
                'rows' => [],
                'refreshInterval' => $refreshInterval,
                'generatedAt' => date('Y-m-d H:i:s'),
                'summary' => ['ok' => 0, 'redirect' => 0, 'client' => 0, 'server' => 0, 'unknown' => 0]
            ]));
            return;
        }

        $hostIds = array_column($hosts, 'hostid');
        $hostNameById = [];
        $hostGroupsByHostId = [];

        foreach ($hosts as $host) {
            $hostNameById[$host['hostid']] = $host['name'];
            $hostGroupsByHostId[$host['hostid']] = $host['hostgroups'] ?? [];
        }

        $scenarios = $this->zabbixApiRequest($apiUrl, $apiToken, 'httptest.get', [
            'output' => ['httptestid', 'name', 'hostid', 'status'],
            'hostids' => $hostIds,
            'selectSteps' => ['name', 'no', 'url'],
            'filter' => ['status' => 0]
        ]);

        if (empty($scenarios)) {
            $this->setResponse(new CControllerResponseData([
                'rows' => [],
                'refreshInterval' => $refreshInterval,
                'generatedAt' => date('Y-m-d H:i:s'),
                'summary' => ['ok' => 0, 'redirect' => 0, 'client' => 0, 'server' => 0, 'unknown' => 0]
            ]));
            return;
        }

        $webItems = $this->zabbixApiRequest($apiUrl, $apiToken, 'item.get', [
            'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'status', 'value_type'],
            'hostids' => $hostIds,
            'webitems' => true,
            'filter' => ['status' => 0]
        ]);

        $metricsByHostScenarioStep = $this->buildWebMetricsMap($webItems);
        $debug = [
            'webitems_total' => count($webItems),
            'rsp_mapped' => 0,
            'time_mapped' => 0,
            'download_mapped' => 0
        ];

        foreach ($metricsByHostScenarioStep as $byScenario) {
            foreach ($byScenario as $byStep) {
                foreach ($byStep as $metricSet) {
                    if (isset($metricSet['rspcode'])) {
                        $debug['rsp_mapped']++;
                    }
                    if (isset($metricSet['time'])) {
                        $debug['time_mapped']++;
                    }
                    if (isset($metricSet['download'])) {
                        $debug['download_mapped']++;
                    }
                }
            }
        }

        $rows = [];
        $chartData = [];
        $summary = ['ok' => 0, 'redirect' => 0, 'client' => 0, 'server' => 0, 'unknown' => 0];

        $rspItemDefs = [];
        $timeItemDefs = [];
        $downloadItemDefs = [];

        foreach ($scenarios as $scenario) {
            $hostId = $scenario['hostid'];
            $hostName = $hostNameById[$hostId] ?? ('Host ' . $hostId);
            $groupName = $this->resolveMainGroupName($hostGroupsByHostId[$hostId] ?? [], $groupNameById);

            $steps = $scenario['steps'] ?? [];
            usort($steps, static function(array $a, array $b): int {
                return ((int)$a['no']) <=> ((int)$b['no']);
            });

            foreach ($steps as $step) {
                $stepName = $step['name'] ?? '';
                $scenarioName = $scenario['name'];

                $stepMetrics = $this->resolveStepMetrics($metricsByHostScenarioStep, $hostId, $scenarioName, $stepName);
                $rspItem = $stepMetrics['rspcode'] ?? null;
                $timeItem = $stepMetrics['time'] ?? null;
                $downloadItem = $stepMetrics['download'] ?? null;

                $httpCode = null;
                $lastClock = 0;
                if ($rspItem !== null) {
                    $httpCode = is_numeric($rspItem['lastvalue'] ?? null) ? (int)$rspItem['lastvalue'] : null;
                    $lastClock = (int)($rspItem['lastclock'] ?? 0);

                    $rspItemDefs[$rspItem['itemid']] = [
                        'value_type' => (int)$rspItem['value_type']
                    ];
                }

                if ($timeItem !== null) {
                    $timeItemDefs[$timeItem['itemid']] = [
                        'value_type' => (int)$timeItem['value_type']
                    ];
                }

                if ($downloadItem !== null) {
                    $downloadItemDefs[$downloadItem['itemid']] = [
                        'value_type' => (int)$downloadItem['value_type']
                    ];
                }

                $status = $this->classifyHttpCode($httpCode);
                $summary[$status]++;

                $rowId = sha1($hostId . '|' . $scenarioName . '|' . $stepName);

                // Expand common host macros in step URL (e.g. {HOST.NAME})
                $rawUrl = $step['url'] ?? '-';
                $expandedUrl = $rawUrl;
                if (!empty($expandedUrl) && $expandedUrl !== '-') {
                    $expandedUrl = str_ireplace('{HOST.NAME}', $hostName, $expandedUrl);
                    // fallback: replace other HOST.NAME variants
                    $expandedUrl = str_ireplace(['{HOSTNAME}', '{HOST.NAME1}', '{HOST.NAME2}'], $hostName, $expandedUrl);
                }

                $rows[] = [
                    'rowid' => $rowId,
                    'hostid' => (string)$hostId,
                    'group' => $groupName,
                    'host' => $hostName,
                    'scenario' => $scenarioName,
                    'step' => $stepName,
                    'url' => $expandedUrl,
                    'code' => $httpCode,
                    'status' => $status,
                    'lastcheck' => $lastClock > 0 ? date('Y-m-d H:i:s', $lastClock) : '-',
                    'rsp_itemid' => $rspItem['itemid'] ?? null,
                    'time_itemid' => $timeItem['itemid'] ?? null,
                    'download_itemid' => $downloadItem['itemid'] ?? null,
                    'spark' => []
                ];
            }
        }

        $timeFrom = time() - 3600;
        $rspHistory = $this->loadHistorySeriesBatch($apiUrl, $apiToken, $rspItemDefs, $timeFrom, 30);
        $timeHistory = $this->loadHistorySeriesBatch($apiUrl, $apiToken, $timeItemDefs, $timeFrom, 120);
        $downloadHistory = $this->loadHistorySeriesBatch($apiUrl, $apiToken, $downloadItemDefs, $timeFrom, 120);

        foreach ($rows as &$row) {
            $spark = [];

            $rspItemId = $row['rsp_itemid'];
            if ($rspItemId !== null && isset($rspHistory[$rspItemId])) {
                foreach ($rspHistory[$rspItemId] as $point) {
                    $code = is_numeric($point['value']) ? (int)$point['value'] : null;
                    $spark[] = [
                        'status' => $this->classifyHttpCode($code),
                        'clock' => (int)($point['clock'] ?? 0),
                        'code' => $code
                    ];
                }
            }

            $row['spark'] = $spark;

            $timeSeries = [];
            $timeItemId = $row['time_itemid'];
            if ($timeItemId !== null && isset($timeHistory[$timeItemId])) {
                foreach ($timeHistory[$timeItemId] as $point) {
                    $timeSeries[] = [
                        'clock' => (int)$point['clock'],
                        'value' => (float)$point['value']
                    ];
                }
            }

            $downloadSeries = [];
            $downloadItemId = $row['download_itemid'];
            if ($downloadItemId !== null && isset($downloadHistory[$downloadItemId])) {
                foreach ($downloadHistory[$downloadItemId] as $point) {
                    $downloadSeries[] = [
                        'clock' => (int)$point['clock'],
                        'value' => (float)$point['value']
                    ];
                }
            }

            $rspSeries = [];
            if ($rspItemId !== null && isset($rspHistory[$rspItemId])) {
                foreach ($rspHistory[$rspItemId] as $point) {
                    $rspSeries[] = [
                        'clock' => (int)$point['clock'],
                        'value' => is_numeric($point['value']) ? (int)$point['value'] : null
                    ];
                }
            }

            $chartData[$row['rowid']] = [
                'title' => $row['host'] . ' / ' . $row['scenario'] . ' / ' . $row['step'],
                'hostid' => (string)($row['hostid'] ?? ''),
                'scenario' => (string)($row['scenario'] ?? ''),
                'step' => (string)($row['step'] ?? ''),
                'rsp_itemid' => $row['rsp_itemid'] ?? null,
                'time_itemid' => $row['time_itemid'] ?? null,
                'download_itemid' => $row['download_itemid'] ?? null,
                'rsp' => $rspSeries,
                'time' => $timeSeries,
                'download' => $downloadSeries
            ];

            unset($row['hostid'], $row['rsp_itemid'], $row['time_itemid'], $row['download_itemid']);
        }
        unset($row);

        usort($rows, static function(array $a, array $b): int {
            return [$a['group'], $a['host'], $a['scenario'], $a['step']] <=> [$b['group'], $b['host'], $b['scenario'], $b['step']];
        });

        $this->setResponse(new CControllerResponseData([
            'rows' => $rows,
            'chartData' => $chartData,
            'refreshInterval' => max(10, $refreshInterval),
            'generatedAt' => date('Y-m-d H:i:s'),
            'summary' => $summary,
            'debug' => $debug
        ]));
    }

    private function loadConfig(): ?array {
        $configPath = dirname(__DIR__) . '/config.json';

        if (!is_file($configPath)) {
            return null;
        }

        $raw = file_get_contents($configPath);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function getConfiguredGroupIds(string $apiUrl, string $apiToken, string $macroName): array {
        $macro = $this->zabbixApiRequest($apiUrl, $apiToken, 'usermacro.get', [
            'globalmacro' => true,
            'output' => ['macro', 'value'],
            'filter' => ['macro' => $macroName]
        ]);

        $groupids = [];
        if (!empty($macro) && !empty($macro[0]['value'])) {
            $parts = explode(',', (string)$macro[0]['value']);
            foreach ($parts as $part) {
                $clean = trim($part);
                if ($clean !== '') {
                    $groupids[] = $clean;
                }
            }
        }

        if (!empty($groupids)) {
            return array_values(array_unique($groupids));
        }

        $allGroups = $this->zabbixApiRequest($apiUrl, $apiToken, 'hostgroup.get', [
            'output' => ['groupid']
        ]);

        if (empty($allGroups)) {
            return [];
        }

        return array_values(array_unique(array_column($allGroups, 'groupid')));
    }

    private function resolveGroupMacroName(string $macroName): string {
        $macroName = trim($macroName);

        if ($macroName === '') {
            return '{$GROUPIDS}';
        }

        // Allow flexible input like GROUPIDS or $GROUPIDS and normalize to {$GROUPIDS}.
        if (!preg_match('/^\{\$[^}]+\}$/', $macroName)) {
            $macroName = trim($macroName, '{}');
            $macroName = ltrim($macroName, '$');
            if ($macroName === '') {
                return '{$GROUPIDS}';
            }
            $macroName = '{$' . $macroName . '}';
        }

        return $macroName;
    }

    private function buildWebMetricsMap(array $items): array {
        $map = [];

        foreach ($items as $item) {
            $key = $item['key_'] ?? '';
            $name = $item['name'] ?? '';
            $hostId = $item['hostid'] ?? null;

            if ($hostId === null) {
                continue;
            }

            $parsed = $this->parseWebMetric($key, $name);
            if ($parsed === null) {
                continue;
            }

            $scenarioName = $parsed['scenario'];
            $stepName = $parsed['step'];
            $metricType = $parsed['metric'];

            $scenarioKey = $this->normalizeLookupKey($scenarioName);
            $stepKey = $this->normalizeLookupKey($stepName);

            if ($scenarioKey === '' || $stepKey === '') {
                continue;
            }

            if (!isset($map[$hostId][$scenarioKey][$stepKey][$metricType])) {
                $map[$hostId][$scenarioKey][$stepKey][$metricType] = $item;
                continue;
            }

            $existingClock = (int)($map[$hostId][$scenarioKey][$stepKey][$metricType]['lastclock'] ?? 0);
            $newClock = (int)($item['lastclock'] ?? 0);

            if ($newClock >= $existingClock) {
                $map[$hostId][$scenarioKey][$stepKey][$metricType] = $item;
            }
        }

        return $map;
    }

    private function parseWebMetric(string $key, string $name): ?array {
        if ($key !== '') {
            if (strpos($key, 'web.test.rspcode[') === 0) {
                $parts = $this->parseWebKeyParams($key);
                if (count($parts) >= 2) {
                    return ['metric' => 'rspcode', 'scenario' => $parts[0], 'step' => $parts[1]];
                }
            }

            if (strpos($key, 'web.test.time[') === 0) {
                $parts = $this->parseWebKeyParams($key);
                if (count($parts) >= 2) {
                    return ['metric' => 'time', 'scenario' => $parts[0], 'step' => $parts[1]];
                }
            }

            if (strpos($key, 'web.test.in[') === 0) {
                $parts = $this->parseWebKeyParams($key);
                if (count($parts) >= 2) {
                    return ['metric' => 'download', 'scenario' => $parts[0], 'step' => $parts[1]];
                }
            }
        }

        if ($name !== '') {
            $name = trim($name);

            if (preg_match('/^Response code for step\s+"([^"]+)"\s+of scenario\s+"([^"]+)"\s*[\.:]?$/i', $name, $m)) {
                return ['metric' => 'rspcode', 'scenario' => $this->normalizeWebParam($m[2]), 'step' => $this->normalizeWebParam($m[1])];
            }

            if (preg_match('/^Response time for step\s+"([^"]+)"\s+of scenario\s+"([^"]+)"\s*[\.:]?$/i', $name, $m)) {
                return ['metric' => 'time', 'scenario' => $this->normalizeWebParam($m[2]), 'step' => $this->normalizeWebParam($m[1])];
            }

            if (preg_match('/^Download speed for step\s+"([^"]+)"\s+of scenario\s+"([^"]+)"\s*[\.:]?$/i', $name, $m)) {
                return ['metric' => 'download', 'scenario' => $this->normalizeWebParam($m[2]), 'step' => $this->normalizeWebParam($m[1])];
            }
        }

        return null;
    }

    private function parseWebKeyParams(string $key): array {
        $start = strpos($key, '[');
        $end = strrpos($key, ']');

        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $inside = substr($key, $start + 1, $end - $start - 1);
        $params = [];
        $current = '';
        $inQuotes = false;
        $escaped = false;

        $len = strlen($inside);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inside[$i];

            if ($escaped) {
                $current .= $ch;
                $escaped = false;
                continue;
            }

            if ($ch === '\\') {
                $escaped = true;
                continue;
            }

            if ($ch === '"') {
                $inQuotes = !$inQuotes;
                continue;
            }

            if ($ch === ',' && !$inQuotes) {
                $params[] = $this->normalizeWebParam($current);
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $params[] = $this->normalizeWebParam($current);

        return $params;
    }

    private function normalizeWebParam(string $value): string {
        $value = trim($value);
        $value = trim($value, '"\'');
        $value = str_replace('\\,', ',', $value);
        return trim($value);
    }

    private function normalizeLookupKey(string $value): string {
        $value = $this->normalizeWebParam($value);
        return strtolower(trim($value));
    }

    private function resolveStepMetrics(array $metricsMap, string $hostId, string $scenarioName, string $stepName): array {
        if (!isset($metricsMap[$hostId])) {
            return [];
        }

        $scenarioKey = $this->normalizeLookupKey($scenarioName);
        $stepKey = $this->normalizeLookupKey($stepName);

        if ($scenarioKey !== '' && $stepKey !== '' && isset($metricsMap[$hostId][$scenarioKey][$stepKey])) {
            return $metricsMap[$hostId][$scenarioKey][$stepKey];
        }

        // Fallback: if only one step exists in the scenario, use it.
        if ($scenarioKey !== '' && isset($metricsMap[$hostId][$scenarioKey]) && count($metricsMap[$hostId][$scenarioKey]) === 1) {
            return reset($metricsMap[$hostId][$scenarioKey]) ?: [];
        }

        return [];
    }

    private function respondHistory(string $apiUrl, string $apiToken): void {
        $timeframe = (string)($_REQUEST['timeframe'] ?? '1h');
        $seconds = $this->timeframeToSeconds($timeframe);

        $timeItemId = (string)($_REQUEST['time_itemid'] ?? '');
        $downloadItemId = (string)($_REQUEST['download_itemid'] ?? '');
        $rspItemId = (string)($_REQUEST['rsp_itemid'] ?? '');

        if (($timeItemId === '' || !ctype_digit($timeItemId)) || ($downloadItemId === '' || !ctype_digit($downloadItemId)) || ($rspItemId === '' || !ctype_digit($rspItemId))) {
            $resolved = $this->resolveMetricIdsFromRequest($apiUrl, $apiToken);
            if ($timeItemId === '' || !ctype_digit($timeItemId)) {
                $timeItemId = $resolved['time_itemid'] ?? '';
            }
            if ($downloadItemId === '' || !ctype_digit($downloadItemId)) {
                $downloadItemId = $resolved['download_itemid'] ?? '';
            }
            if ($rspItemId === '' || !ctype_digit($rspItemId)) {
                $rspItemId = $resolved['rsp_itemid'] ?? '';
            }
        }

        $requestedIds = [];
        if ($timeItemId !== '' && ctype_digit($timeItemId)) {
            $requestedIds[] = $timeItemId;
        }
        if ($downloadItemId !== '' && ctype_digit($downloadItemId)) {
            $requestedIds[] = $downloadItemId;
        }
        if ($rspItemId !== '' && ctype_digit($rspItemId)) {
            $requestedIds[] = $rspItemId;
        }

        if (empty($requestedIds)) {
            $this->respondJson([
                'timeframe' => $timeframe,
                'time' => [],
                'download' => [],
                'rsp' => []
            ]);
            return;
        }

        $itemDefs = $this->getItemDefsByIds($apiUrl, $apiToken, $requestedIds);
        $seriesByItem = $this->loadHistorySeriesBatch($apiUrl, $apiToken, $itemDefs, time() - $seconds, 360);

        // Fallback for installations with sparse history/time windows.
        foreach ($requestedIds as $itemId) {
            if (!isset($seriesByItem[$itemId]) || empty($seriesByItem[$itemId])) {
                $valueType = isset($itemDefs[$itemId]['value_type']) ? (int)$itemDefs[$itemId]['value_type'] : 3;
                $seriesByItem[$itemId] = $this->loadSingleItemFallbackSeries($apiUrl, $apiToken, $itemId, $valueType, 120);
            }
        }

        $timeSeries = [];
        if ($timeItemId !== '' && isset($seriesByItem[$timeItemId])) {
            foreach ($seriesByItem[$timeItemId] as $point) {
                $timeSeries[] = [
                    'clock' => (int)$point['clock'],
                    'value' => (float)$point['value']
                ];
            }
        }

        $downloadSeries = [];
        if ($downloadItemId !== '' && isset($seriesByItem[$downloadItemId])) {
            foreach ($seriesByItem[$downloadItemId] as $point) {
                $downloadSeries[] = [
                    'clock' => (int)$point['clock'],
                    'value' => (float)$point['value']
                ];
            }
        }

        $rspSeries = [];
        if ($rspItemId !== '' && isset($seriesByItem[$rspItemId])) {
            foreach ($seriesByItem[$rspItemId] as $point) {
                $rspSeries[] = [
                    'clock' => (int)$point['clock'],
                    'value' => is_numeric($point['value']) ? (int)$point['value'] : null
                ];
            }
        }

        $this->respondJson([
            'timeframe' => $timeframe,
            'time' => $timeSeries,
            'download' => $downloadSeries,
            'rsp' => $rspSeries
        ]);
    }

    private function loadSingleItemFallbackSeries(string $apiUrl, string $apiToken, string $itemId, int $valueType, int $limit): array {
        if (!in_array($valueType, [0, 3], true)) {
            return [];
        }

        // First fallback: load latest points without time_from.
        $history = $this->zabbixApiRequest($apiUrl, $apiToken, 'history.get', [
            'output' => ['itemid', 'clock', 'value'],
            'history' => $valueType,
            'itemids' => [$itemId],
            'sortfield' => 'clock',
            'sortorder' => 'DESC',
            'limit' => max(20, $limit)
        ]);

        if (!empty($history)) {
            $history = array_reverse($history);
            $series = [];
            foreach ($history as $point) {
                $series[] = [
                    'clock' => (int)($point['clock'] ?? 0),
                    'value' => $point['value'] ?? null
                ];
            }
            return $series;
        }

        // Last fallback: use item lastvalue/lastclock as a one-point series.
        $item = $this->zabbixApiRequest($apiUrl, $apiToken, 'item.get', [
            'output' => ['itemid', 'lastclock', 'lastvalue'],
            'itemids' => [$itemId],
            'limit' => 1
        ]);

        if (empty($item)) {
            return [];
        }

        $last = $item[0];
        $clock = (int)($last['lastclock'] ?? 0);
        $value = $last['lastvalue'] ?? null;

        if ($clock <= 0 || !is_numeric($value)) {
            return [];
        }

        return [[
            'clock' => $clock,
            'value' => $value
        ]];
    }

    private function resolveMetricIdsFromRequest(string $apiUrl, string $apiToken): array {
        $hostId = (string)($_REQUEST['hostid'] ?? '');
        $scenario = $this->normalizeWebParam((string)($_REQUEST['scenario'] ?? ''));
        $step = $this->normalizeWebParam((string)($_REQUEST['step'] ?? ''));

        if ($hostId === '' || !ctype_digit($hostId) || $scenario === '' || $step === '') {
            return [];
        }

        $webItems = $this->zabbixApiRequest($apiUrl, $apiToken, 'item.get', [
            'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'status', 'value_type'],
            'hostids' => [$hostId],
            'webitems' => true,
            'filter' => ['status' => 0]
        ]);

        if (empty($webItems)) {
            return [];
        }

        $metricsMap = $this->buildWebMetricsMap($webItems);
        $stepMetrics = $this->resolveStepMetrics($metricsMap, $hostId, $scenario, $step);

        return [
            'time_itemid' => isset($stepMetrics['time']['itemid']) ? (string)$stepMetrics['time']['itemid'] : '',
            'download_itemid' => isset($stepMetrics['download']['itemid']) ? (string)$stepMetrics['download']['itemid'] : '',
            'rsp_itemid' => isset($stepMetrics['rspcode']['itemid']) ? (string)$stepMetrics['rspcode']['itemid'] : ''
        ];
    }

    private function timeframeToSeconds(string $timeframe): int {
        $map = [
            '1h' => 3600,
            '3h' => 10800,
            '6h' => 21600,
            '12h' => 43200,
            '1d' => 86400,
            '2d' => 172800,
            '1w' => 604800,
            '2w' => 1209600,
            '1m' => 2592000
        ];

        return $map[$timeframe] ?? 3600;
    }

    private function respondJson(array $payload): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function getItemDefsByIds(string $apiUrl, string $apiToken, array $itemIds): array {
        if (empty($itemIds)) {
            return [];
        }

        $items = $this->zabbixApiRequest($apiUrl, $apiToken, 'item.get', [
            'output' => ['itemid', 'value_type'],
            'itemids' => array_values(array_unique($itemIds))
        ]);

        $defs = [];
        foreach ($items as $item) {
            $itemId = (string)$item['itemid'];
            $defs[$itemId] = ['value_type' => (int)$item['value_type']];
        }

        return $defs;
    }

    private function loadHistorySeriesBatch(string $apiUrl, string $apiToken, array $itemDefs, int $timeFrom, int $limitPerItem): array {
        if (empty($itemDefs)) {
            return [];
        }

        $groupedItemIds = [
            0 => [],
            3 => []
        ];

        foreach ($itemDefs as $itemId => $def) {
            $valueType = (int)($def['value_type'] ?? 3);
            if (!in_array($valueType, [0, 3], true)) {
                continue;
            }

            $groupedItemIds[$valueType][] = (string)$itemId;
        }

        $result = [];

        foreach ($groupedItemIds as $historyType => $itemIds) {
            if (empty($itemIds)) {
                continue;
            }

            $history = $this->zabbixApiRequest($apiUrl, $apiToken, 'history.get', [
                'output' => ['itemid', 'clock', 'value'],
                'history' => $historyType,
                'itemids' => $itemIds,
                'time_from' => $timeFrom,
                'sortfield' => 'clock',
                'sortorder' => 'DESC',
                'limit' => min(200000, max(500, count($itemIds) * $limitPerItem * 2))
            ]);

            if (empty($history)) {
                continue;
            }

            foreach ($history as $point) {
                $itemId = (string)($point['itemid'] ?? '');
                if ($itemId === '') {
                    continue;
                }

                if (!isset($result[$itemId])) {
                    $result[$itemId] = [];
                }

                if (count($result[$itemId]) >= $limitPerItem) {
                    continue;
                }

                $result[$itemId][] = [
                    'clock' => (int)($point['clock'] ?? 0),
                    'value' => $point['value'] ?? null
                ];
            }
        }

        foreach ($result as $itemId => $series) {
            $result[$itemId] = array_reverse($series);
        }

        return $result;
    }

    private function resolveMainGroupName(array $groups, array $groupNameById): string {
        foreach ($groups as $group) {
            $groupId = $group['groupid'] ?? null;
            if ($groupId !== null && isset($groupNameById[$groupId])) {
                return $groupNameById[$groupId];
            }
            if (!empty($group['name'])) {
                return $group['name'];
            }
        }

        return 'Sin grupo';
    }

    private function classifyHttpCode(?int $code): string {
        if ($code === null || $code <= 0) {
            return 'unknown';
        }

        if ($code >= 200 && $code < 300) {
            return 'ok';
        }

        if ($code >= 300 && $code < 400) {
            return 'redirect';
        }

        if ($code >= 400 && $code < 500) {
            return 'client';
        }

        if ($code >= 500) {
            return 'server';
        }

        return 'unknown';
    }

    private function zabbixApiRequest(string $apiUrl, string $apiToken, string $method, array $params): array {
        $request = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1
        ];

        $response = $this->makeApiRequest($apiUrl, $request, $apiToken);

        if ($response === null) {
            return [];
        }

        if (isset($response['error'])) {
            echo 'Error API (' . $method . '): ' . json_encode($response['error']);
            return [];
        }

        return $response['result'] ?? [];
    }

    private function makeApiRequest(string $apiUrl, array $request, string $apiToken): ?array {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            echo 'Error cURL: ' . curl_error($ch);
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        if ($httpCode !== 200 || $raw === false) {
            echo 'Error HTTP API: ' . $httpCode;
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
