<?php
/** @var array $data */

$rows = $data['rows'] ?? [];
$chartData = $data['chartData'] ?? [];
$refreshInterval = (int)($data['refreshInterval'] ?? 60);
$selectedTimeframe = (string)($data['selectedTimeframe'] ?? '1h');
$generatedAt = $data['generatedAt'] ?? '-';
$summary = $data['summary'] ?? ['ok' => 0, 'redirect' => 0, 'client' => 0, 'server' => 0, 'unknown' => 0];
$debug = $data['debug'] ?? ['webitems_total' => 0, 'rsp_mapped' => 0, 'time_mapped' => 0, 'download_mapped' => 0];

echo '<link rel="stylesheet" href="modules/WebScenarioStatusBoard/assets/styles.css">';
echo '<script src="modules/WebScenarioStatusBoard/assets/scripts.js"></script>';

echo '<div id="wsb-root" data-refresh-interval="' . (int)$refreshInterval . '">';
echo '<h1 class="wsb-title">Web Scenario Status Board</h1>';
echo '<div class="wsb-meta">';
echo '<span>Generado: ' . htmlspecialchars($generatedAt) . '</span>';
echo '<span>Refresco: <strong id="refresh-timer">' . (int)$refreshInterval . '</strong>s</span>';
echo '</div>';

echo '<div class="wsb-toolbar">';
echo '  <label for="wsb-global-timeframe">Intervalo grafica:</label>';
echo '  <select id="wsb-global-timeframe" class="wsb-global-timeframe">';
$timeframeOptions = [
    '1h' => '1h',
    '3h' => '3h',
    '6h' => '6h',
    '12h' => '12h',
    '1d' => '1d',
    '2d' => '2d',
    '1w' => '1 semana',
    '2w' => '2 semanas',
    '1m' => '1 mes'
];

foreach ($timeframeOptions as $value => $label) {
    $selectedAttr = $value === $selectedTimeframe ? ' selected' : '';
    echo '    <option value="' . htmlspecialchars($value) . '"' . $selectedAttr . '>' . htmlspecialchars($label) . '</option>';
}
echo '  </select>';
echo '  <button type="button" id="wsb-refresh-toggle" class="wsb-refresh-toggle" aria-pressed="true">Auto-refresh: ON</button>';
echo '  <div class="wsb-columns-config">';
echo '    <button type="button" id="wsb-columns-toggle" class="wsb-columns-toggle" aria-expanded="false">Columnas</button>';
echo '    <div id="wsb-columns-panel" class="wsb-columns-panel" hidden>';
echo '      <label class="wsb-columns-item"><input type="checkbox" data-column-toggle="host" checked> Host</label>';
echo '      <label class="wsb-columns-item"><input type="checkbox" data-column-toggle="tags" checked> Etiquetas</label>';
echo '      <label class="wsb-columns-item"><input type="checkbox" data-column-toggle="scenario" checked> Scenario</label>';
echo '      <label class="wsb-columns-item"><input type="checkbox" data-column-toggle="step" checked> Step</label>';
echo '      <label class="wsb-columns-item"><input type="checkbox" data-column-toggle="url" checked> URL</label>';
echo '      <label class="wsb-columns-item"><input type="checkbox" data-column-toggle="uptime" checked> Uptime</label>';
echo '      <label class="wsb-columns-item"><input type="checkbox" data-column-toggle="status" checked> Status</label>';
echo '      <label class="wsb-columns-item"><input type="checkbox" data-column-toggle="lastcheck" checked> Last Check</label>';
echo '    </div>';
echo '  </div>';
echo '</div>';

echo '<div class="wsb-summary">';
echo '<span class="chip chip-ok">2xx: ' . (int)$summary['ok'] . '</span>';
echo '<span class="chip chip-redirect">3xx: ' . (int)$summary['redirect'] . '</span>';
echo '<span class="chip chip-client">4xx: ' . (int)$summary['client'] . '</span>';
echo '<span class="chip chip-server">5xx: ' . (int)$summary['server'] . '</span>';
echo '<span class="chip chip-unknown">Unknown: ' . (int)$summary['unknown'] . '</span>';
echo '</div>';

echo '<div class="wsb-filter-wrap">';
echo '  <button type="button" class="wsb-filter-btn active" data-filter="all">Todos</button>';
echo '  <button type="button" class="wsb-filter-btn" data-filter="200">200</button>';
echo '  <button type="button" class="wsb-filter-btn" data-filter="300">300</button>';
echo '  <button type="button" class="wsb-filter-btn" data-filter="400">400</button>';
echo '  <button type="button" class="wsb-filter-btn" data-filter="500">500</button>';
echo '  <button type="button" class="wsb-filter-btn" data-filter="unknown">Unknown</button>';
echo '</div>';

echo '<div class="wsb-debug">';
echo 'items: ' . (int)$debug['webitems_total'];
echo ' | rsp: ' . (int)$debug['rsp_mapped'];
echo ' | time: ' . (int)$debug['time_mapped'];
echo ' | download: ' . (int)$debug['download_mapped'];
echo '</div>';

if (empty($rows)) {
    echo '<p class="wsb-empty">No se encontraron pasos de Web Scenarios para los grupos consultados.</p>';
    echo '</div>';
    return;
}

// Group rows by host group to render one table per group.
$groupedRows = [];
foreach ($rows as $row) {
    $groupKey = $row['group'] ?? 'Sin grupo';
    if (!isset($groupedRows[$groupKey])) {
        $groupedRows[$groupKey] = [];
    }
    $groupedRows[$groupKey][] = $row;
}

foreach ($groupedRows as $groupName => $rowsInGroup) {
    echo '<div class="wsb-group-card">';
    echo '<h3 class="wsb-group-title">' . htmlspecialchars($groupName) . ' (' . count($rowsInGroup) . ')</h3>';
    echo '<div class="wsb-table-wrap">';
    echo '<table class="wsb-table">';
    echo '<thead><tr>';
    echo '<th data-col="host">Host</th>';
    echo '<th data-col="tags">Etiquetas</th>';
    echo '<th data-col="scenario">Scenario</th>';
    echo '<th data-col="step">Step</th>';
    echo '<th data-col="url">URL</th>';
    echo '<th data-col="uptime">Uptime</th>';
    echo '<th data-col="status">Status</th>';
    echo '<th data-col="lastcheck">Last Check</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($rowsInGroup as $row) {
    $code = $row['code'];
    $status = $row['status'] ?? 'unknown';
    $statusLabel = 'Unknown';

    if ($status === 'ok') {
        $statusLabel = 'OK';
    }
    elseif ($status === 'redirect') {
        $statusLabel = 'Redirect';
    }
    elseif ($status === 'client') {
        $statusLabel = 'Client Error';
    }
    elseif ($status === 'server') {
        $statusLabel = 'Server Error';
    }

    $statusBucket = '0';
    if (is_numeric($code)) {
        $statusBucket = (string)(intdiv((int)$code, 100) * 100);
    }

    echo '<tr class="wsb-row-clickable" data-row-id="' . htmlspecialchars($row['rowid']) . '" data-code="' . htmlspecialchars((string)($code ?? '')) . '" data-bucket="' . htmlspecialchars($statusBucket) . '" data-status="' . htmlspecialchars($status) . '">';
    echo '<td data-col="host">' . htmlspecialchars($row['host']) . '</td>';
    $hostTags = is_array($row['host_tags'] ?? null) ? $row['host_tags'] : [];
    echo '<td data-col="tags" class="wsb-tags">';
    if (empty($hostTags)) {
        echo '<span class="wsb-tags-empty">-</span>';
    }
    else {
        foreach ($hostTags as $tag) {
            $tagName = trim((string)($tag['tag'] ?? ''));
            if ($tagName === '') {
                continue;
            }

            $tagValue = trim((string)($tag['value'] ?? ''));
            $tagText = $tagName . ($tagValue !== '' ? ': ' . $tagValue : '');
            echo '<span class="wsb-tag-chip">' . htmlspecialchars($tagText) . '</span>';
        }
    }
    echo '</td>';
    echo '<td data-col="scenario">' . htmlspecialchars($row['scenario']) . '</td>';
    echo '<td data-col="step">' . htmlspecialchars($row['step']) . '</td>';
    $url = (string)($row['url'] ?? '-');
    if ($url !== '-' && preg_match('/^https?:\/\//i', $url)) {
        echo '<td data-col="url" class="wsb-url"><a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($url) . '</a></td>';
    }
    else {
        echo '<td data-col="url" class="wsb-url">' . htmlspecialchars($url) . '</td>';
    }
    echo '<td data-col="uptime">';
    echo '<div class="spark-wrap">';

    $spark = $row['spark'] ?? [];
    if (empty($spark)) {
        echo '<span class="spark-empty">sin historico</span>';
    }
    else {
        foreach ($spark as $sparkPoint) {
            $sparkStatus = 'unknown';
            $sparkClock = 0;
            $sparkCode = null;

            if (is_array($sparkPoint)) {
                $sparkStatus = (string)($sparkPoint['status'] ?? 'unknown');
                $sparkClock = (int)($sparkPoint['clock'] ?? 0);
                $sparkCode = isset($sparkPoint['code']) && is_numeric($sparkPoint['code']) ? (int)$sparkPoint['code'] : null;
            }
            else {
                $sparkStatus = (string)$sparkPoint;
            }

            $sparkStatusLabel = 'Unknown';
            if ($sparkStatus === 'ok') {
                $sparkStatusLabel = 'OK';
            }
            elseif ($sparkStatus === 'redirect') {
                $sparkStatusLabel = 'Redirect';
            }
            elseif ($sparkStatus === 'client') {
                $sparkStatusLabel = 'Client Error';
            }
            elseif ($sparkStatus === 'server') {
                $sparkStatusLabel = 'Server Error';
            }

            $sparkCodeText = $sparkCode !== null ? (string)$sparkCode : 'N/A';
            $sparkTimeText = $sparkClock > 0 ? date('Y-m-d H:i:s', $sparkClock) : 'Unavailable';
            $sparkTooltip = 'Status: ' . $sparkStatusLabel . ' | Code: ' . $sparkCodeText . ' | Check: ' . $sparkTimeText;

            echo '<span class="spark-bar spark-' . htmlspecialchars($sparkStatus) . '" data-tooltip="' . htmlspecialchars($sparkTooltip) . '"></span>';
        }
    }

    echo '</div>';
    echo '</td>';
    echo '<td data-col="status"><span class="status status-' . htmlspecialchars($status) . '">' . htmlspecialchars((string)($code ?? '-')) . ' ' . $statusLabel . '</span></td>';
    echo '<td data-col="lastcheck">' . htmlspecialchars($row['lastcheck']) . '</td>';
    echo '</tr>';
}

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

$json = json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($json === false) {
    $json = '{}';
}

echo '<script id="wsb-chart-data" type="application/json">' . $json . '</script>';

echo '<div id="wsb-modal" class="wsb-modal" aria-hidden="true">';
echo '  <div class="wsb-modal-card">';
echo '    <button id="wsb-modal-close" class="wsb-modal-close" type="button">x</button>';
echo '    <h3 id="wsb-modal-title" class="wsb-modal-title">Series</h3>';
echo '    <div id="wsb-modal-body" class="wsb-modal-body"></div>';
echo '  </div>';
echo '</div>';

echo '</div>';
