document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('wsb-root');
  const timer = document.getElementById('refresh-timer');
  const chartDataNode = document.getElementById('wsb-chart-data');
  const modal = document.getElementById('wsb-modal');
  const modalClose = document.getElementById('wsb-modal-close');
  const modalTitle = document.getElementById('wsb-modal-title');
  const modalBody = document.getElementById('wsb-modal-body');
  const globalTimeframeSelect = document.getElementById('wsb-global-timeframe');
  const refreshToggleBtn = document.getElementById('wsb-refresh-toggle');
  const filterButtons = document.querySelectorAll('.wsb-filter-btn');
  const columnsToggleBtn = document.getElementById('wsb-columns-toggle');
  const columnsPanel = document.getElementById('wsb-columns-panel');
  const columnCheckboxes = document.querySelectorAll('[data-column-toggle]');

  if (!root || !timer || !modal || !modalBody || !modalTitle) {
    return;
  }

  initSparkTooltips();
  initColumnVisibilityControls();

  const TIMEFRAMES = [
    { value: '1h', label: '1h' },
    { value: '3h', label: '3h' },
    { value: '6h', label: '6h' },
    { value: '12h', label: '12h' },
    { value: '1d', label: '1d' },
    { value: '2d', label: '2d' },
    { value: '1w', label: '1 semana' },
    { value: '2w', label: '2 semanas' },
    { value: '1m', label: '1 mes' }
  ];

  let chartData = {};
  if (chartDataNode) {
    try {
      chartData = JSON.parse(chartDataNode.textContent || '{}');
    }
    catch (e) {
      chartData = {};
    }
  }

  const refreshInterval = parseInt(root.dataset.refreshInterval || '0', 10);
  const autoRefreshStorageKey = 'wsb:autoRefreshEnabled';
  let remaining = refreshInterval;
  let refreshTimerId = null;
  let refreshInProgress = false;
  let autoRefreshEnabled = refreshInterval > 0;
  let activeRowData = null;
  let activeTimeframe = globalTimeframeSelect ? String(globalTimeframeSelect.value || '1h') : '1h';
  let requestToken = 0;

  try {
    const savedAutoRefresh = window.localStorage.getItem(autoRefreshStorageKey);
    if (savedAutoRefresh !== null) {
      autoRefreshEnabled = savedAutoRefresh === '1' && refreshInterval > 0;
    }
  }
  catch (e) {
    // Ignore localStorage failures.
  }

  updateAutoRefreshUi();

  try {
    const savedTimeframe = String(window.localStorage.getItem('wsb:timeframe') || '');
    if (globalTimeframeSelect && savedTimeframe) {
      const validOption = Array.from(globalTimeframeSelect.options).some((opt) => opt.value === savedTimeframe);
      if (validOption) {
        globalTimeframeSelect.value = savedTimeframe;
        activeTimeframe = savedTimeframe;
      }
    }
  }
  catch (e) {
    // Ignore localStorage failures.
  }

  if (refreshInterval > 0) {
    const refreshGuardMs = Math.max(3000, Math.min(30000, refreshInterval * 500));

    try {
      const lastRefreshAt = Number(window.sessionStorage.getItem('wsb:lastRefreshAt') || '0');
      const elapsed = Date.now() - lastRefreshAt;

      if (Number.isFinite(lastRefreshAt) && elapsed >= 0 && elapsed < refreshGuardMs) {
        // If page was just reloaded, restart countdown instead of reloading again immediately.
        remaining = refreshInterval;
      }
    }
    catch (e) {
      // Ignore sessionStorage failures.
    }

    refreshTimerId = window.setInterval(() => {
      if (modal.classList.contains('open')) {
        return;
      }

      if (!autoRefreshEnabled) {
        timer.textContent = 'OFF';
        return;
      }

      if (refreshInProgress) {
        return;
      }

      remaining -= 1;
      timer.textContent = String(remaining);

      if (remaining <= 0) {
        triggerScheduledRefresh();
      }
    }, 1000);
  }
  else {
    timer.textContent = 'OFF';
  }

  if (refreshToggleBtn) {
    refreshToggleBtn.addEventListener('click', () => {
      if (refreshInterval <= 0) {
        return;
      }

      autoRefreshEnabled = !autoRefreshEnabled;

      if (autoRefreshEnabled && remaining <= 0) {
        remaining = refreshInterval;
      }

      if (!autoRefreshEnabled) {
        timer.textContent = 'OFF';
      }
      else {
        timer.textContent = String(Math.max(1, remaining));
      }

      try {
        window.localStorage.setItem(autoRefreshStorageKey, autoRefreshEnabled ? '1' : '0');
      }
      catch (e) {
        // Ignore localStorage failures.
      }

      updateAutoRefreshUi();
    });
  }

  filterButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const filter = btn.getAttribute('data-filter') || 'all';

      filterButtons.forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');

      applyTableFilter(filter);
    });
  });

  if (globalTimeframeSelect) {
    globalTimeframeSelect.addEventListener('change', () => {
      const selected = String(globalTimeframeSelect.value || '1h');
      activeTimeframe = selected;

      try {
        window.localStorage.setItem('wsb:timeframe', selected);
      }
      catch (e) {
        // Ignore localStorage failures.
      }

      if (activeRowData && modal.classList.contains('open')) {
        loadAndRenderSeries(activeRowData, activeTimeframe);
      }

      reloadForGlobalTimeframe(selected);
    });
  }

  document.querySelectorAll('.wsb-row-clickable').forEach((row) => {
    row.addEventListener('click', async () => {
      const rowId = row.getAttribute('data-row-id') || '';
      if (!rowId || !chartData[rowId]) {
        return;
      }

      activeRowData = chartData[rowId];
      activeTimeframe = globalTimeframeSelect ? String(globalTimeframeSelect.value || '1h') : '1h';

      modalTitle.textContent = activeRowData.title || 'Series';
      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');

      await loadAndRenderSeries(activeRowData, activeTimeframe);
    });
  });

  if (modalClose) {
    modalClose.addEventListener('click', closeModal);
  }

  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeModal();
    }
  });

  function closeModal() {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    activeRowData = null;
  }

  async function loadAndRenderSeries(rowData, timeframe) {
    const myToken = ++requestToken;
    renderLoading(timeframe);

    const payload = await fetchSeries(rowData, timeframe);
    if (myToken !== requestToken) {
      return;
    }

    if (!payload) {
      renderError(timeframe);
      return;
    }

    renderCharts(payload, timeframe);
  }

  async function fetchSeries(rowData, timeframe) {
    if (!rowData) {
      return null;
    }

    // Instant data path for 1h (preloaded server-side to avoid empty charts).
    if (timeframe === '1h') {
      const preload = {
        timeframe,
        rsp: Array.isArray(rowData.rsp) ? rowData.rsp : [],
        time: Array.isArray(rowData.time) ? rowData.time : [],
        download: Array.isArray(rowData.download) ? rowData.download : []
      };

      if (preload.rsp.length > 0 || preload.time.length > 0 || preload.download.length > 0) {
        return preload;
      }
    }

    if (!rowData._cache) {
      rowData._cache = {};
    }

    if (rowData._cache[timeframe]) {
      return rowData._cache[timeframe];
    }

    const params = new URLSearchParams({
      action: 'web.scenario.status.board',
      mode: 'history',
      timeframe,
      hostid: String(rowData.hostid || ''),
      scenario: String(rowData.scenario || ''),
      step: String(rowData.step || ''),
      rsp_itemid: String(rowData.rsp_itemid || ''),
      time_itemid: String(rowData.time_itemid || ''),
      download_itemid: String(rowData.download_itemid || '')
    });

    const endpoint = `${window.location.pathname}?${params.toString()}`;

    try {
      const response = await fetch(endpoint, {
        method: 'GET',
        headers: { 'Accept': 'application/json' }
      });

      if (!response.ok) {
        return null;
      }

      const data = await response.json();
      rowData._cache[timeframe] = data;
      return data;
    }
    catch (e) {
      if (Array.isArray(rowData.time) || Array.isArray(rowData.download)) {
        return {
          timeframe,
          rsp: Array.isArray(rowData.rsp) ? rowData.rsp : [],
          time: Array.isArray(rowData.time) ? rowData.time : [],
          download: Array.isArray(rowData.download) ? rowData.download : []
        };
      }

      return null;
    }
  }

  function renderLoading(timeframe) {
    modalBody.innerHTML = '';
    modalBody.appendChild(createTimeframeControls(timeframe, true));

    const loading = document.createElement('div');
    loading.className = 'wsb-chart-empty';
    loading.textContent = 'Cargando historico...';
    modalBody.appendChild(loading);
  }

  function renderError(timeframe) {
    modalBody.innerHTML = '';
    modalBody.appendChild(createTimeframeControls(timeframe, false));

    const error = document.createElement('div');
    error.className = 'wsb-chart-empty';
    error.textContent = 'No se pudo cargar el historico para este rango.';
    modalBody.appendChild(error);
  }

  function renderCharts(payload, timeframe) {
    modalBody.innerHTML = '';
    modalBody.appendChild(createTimeframeControls(timeframe, false));

    const rspSeries = Array.isArray(payload.rsp) ? payload.rsp : [];
    modalBody.appendChild(renderSeries('Response Time', payload.time || [], '#59d995', 'ms', rspSeries));
    modalBody.appendChild(renderSeries('Download Speed', payload.download || [], '#7fd4ff', 'KB/s', rspSeries));
  }

  function createTimeframeControls(active, disabled) {
    const wrap = document.createElement('div');
    wrap.className = 'wsb-timeframe-wrap';

    TIMEFRAMES.forEach((item) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `wsb-timeframe-btn${item.value === active ? ' active' : ''}`;
      btn.textContent = item.label;
      btn.disabled = disabled;

      btn.addEventListener('click', async () => {
        if (disabled || !activeRowData || item.value === activeTimeframe) {
          return;
        }

        activeTimeframe = item.value;
        if (globalTimeframeSelect) {
          globalTimeframeSelect.value = activeTimeframe;
        }
        await loadAndRenderSeries(activeRowData, activeTimeframe);
      });

      wrap.appendChild(btn);
    });

    return wrap;
  }

  function renderSeries(title, rawSeries, color, unit, rspSeriesRaw) {
    const wrap = document.createElement('div');
    wrap.className = 'wsb-chart-block';

    const titleEl = document.createElement('h4');
    titleEl.className = 'wsb-chart-title';
    titleEl.textContent = title;
    wrap.appendChild(titleEl);

    if (!Array.isArray(rawSeries) || rawSeries.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'wsb-chart-empty';
      empty.textContent = 'Sin historico disponible';
      wrap.appendChild(empty);
      return wrap;
    }

    const series = rawSeries
      .map((p) => ({ clock: Number(p.clock), value: Number(p.value) }))
      .filter((p) => Number.isFinite(p.clock) && Number.isFinite(p.value))
      .sort((a, b) => a.clock - b.clock);

    if (series.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'wsb-chart-empty';
      empty.textContent = 'Sin datos numericos';
      wrap.appendChild(empty);
      return wrap;
    }

    const values = series.map((p) => p.value);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const avg = values.reduce((a, b) => a + b, 0) / values.length;

    const width = 760;
    const height = 240;
    const leftMargin = 58;
    const rightMargin = 14;
    const topMargin = 12;
    const bottomMargin = 34;
    const drawW = width - leftMargin - rightMargin;
    const drawH = height - topMargin - bottomMargin;

    const toX = (i) => leftMargin + (drawW * (i / Math.max(series.length - 1, 1)));
    const toY = (v) => {
      const ratio = max === min ? 0.5 : ((v - min) / (max - min));
      return topMargin + (drawH * (1 - ratio));
    };

    const points = series.map((p, i) => `${toX(i)},${toY(p.value)}`).join(' ');

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('class', 'wsb-chart-svg');

    const chartStartClock = series[0].clock;
    const chartEndClock = series[series.length - 1].clock;

    paintStatusIntervals(svg, rspSeriesRaw, chartStartClock, chartEndClock, {
      leftMargin,
      topMargin,
      drawW,
      drawH
    });

    const gridLines = 5;
    for (let i = 0; i < gridLines; i++) {
      const ratio = i / (gridLines - 1);
      const y = topMargin + (drawH * ratio);
      const yValue = max - ((max - min) * ratio);

      const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
      line.setAttribute('x1', String(leftMargin));
      line.setAttribute('x2', String(leftMargin + drawW));
      line.setAttribute('y1', String(y));
      line.setAttribute('y2', String(y));
      line.setAttribute('stroke', '#123343');
      line.setAttribute('stroke-width', '1');
      line.setAttribute('stroke-dasharray', '3 4');
      svg.appendChild(line);

      const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      label.setAttribute('x', '8');
      label.setAttribute('y', String(y + 4));
      label.setAttribute('class', 'wsb-chart-axis-label');
      label.textContent = formatNumber(yValue);
      svg.appendChild(label);
    }

    const avgY = toY(avg);
    const avgLine = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    avgLine.setAttribute('x1', String(leftMargin));
    avgLine.setAttribute('x2', String(leftMargin + drawW));
    avgLine.setAttribute('y1', String(avgY));
    avgLine.setAttribute('y2', String(avgY));
    avgLine.setAttribute('class', 'wsb-chart-avg-line');
    svg.appendChild(avgLine);

    const avgLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    avgLabel.setAttribute('x', String(leftMargin + drawW - 4));
    avgLabel.setAttribute('y', String(avgY - 4));
    avgLabel.setAttribute('text-anchor', 'end');
    avgLabel.setAttribute('class', 'wsb-chart-avg-label');
    avgLabel.textContent = `AVG ${formatNumber(avg)}`;
    svg.appendChild(avgLabel);

    const xStart = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    xStart.setAttribute('x', String(leftMargin));
    xStart.setAttribute('y', String(topMargin + drawH + 20));
    xStart.setAttribute('class', 'wsb-chart-x-label');
    xStart.textContent = new Date(series[0].clock * 1000).toLocaleString();
    svg.appendChild(xStart);

    const xEnd = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    xEnd.setAttribute('x', String(leftMargin + drawW - 2));
    xEnd.setAttribute('y', String(topMargin + drawH + 20));
    xEnd.setAttribute('text-anchor', 'end');
    xEnd.setAttribute('class', 'wsb-chart-x-label');
    xEnd.textContent = new Date(series[series.length - 1].clock * 1000).toLocaleString();
    svg.appendChild(xEnd);

    const linePath = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    linePath.setAttribute('points', points);
    linePath.setAttribute('fill', 'none');
    linePath.setAttribute('stroke', color);
    linePath.setAttribute('stroke-width', '3');
    linePath.setAttribute('stroke-linecap', 'round');
    linePath.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(linePath);

    const cursorLine = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    cursorLine.setAttribute('class', 'wsb-chart-cursor-line');
    cursorLine.setAttribute('visibility', 'hidden');
    svg.appendChild(cursorLine);

    const cursorDot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    cursorDot.setAttribute('r', '4');
    cursorDot.setAttribute('fill', color);
    cursorDot.setAttribute('stroke', '#ffffff');
    cursorDot.setAttribute('stroke-width', '1');
    cursorDot.setAttribute('visibility', 'hidden');
    svg.appendChild(cursorDot);

    const overlay = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    overlay.setAttribute('x', String(leftMargin));
    overlay.setAttribute('y', String(topMargin));
    overlay.setAttribute('width', String(drawW));
    overlay.setAttribute('height', String(drawH));
    overlay.setAttribute('fill', 'transparent');
    overlay.style.cursor = 'crosshair';
    svg.appendChild(overlay);

    const cursorLabelBg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    cursorLabelBg.setAttribute('class', 'wsb-chart-cursor-label-bg');
    cursorLabelBg.setAttribute('rx', '4');
    cursorLabelBg.setAttribute('visibility', 'hidden');
    svg.appendChild(cursorLabelBg);

    const cursorLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    cursorLabel.setAttribute('class', 'wsb-chart-cursor-label');
    cursorLabel.setAttribute('visibility', 'hidden');
    svg.appendChild(cursorLabel);

    const pointInfo = document.createElement('div');
    pointInfo.className = 'wsb-chart-point';
    pointInfo.textContent = 'Pasa el raton por la linea para ver el valor exacto';

    overlay.addEventListener('mousemove', (event) => {
      const bounds = svg.getBoundingClientRect();
      const relX = ((event.clientX - bounds.left) / bounds.width) * width;
      const clampedX = Math.max(leftMargin, Math.min(leftMargin + drawW, relX));
      const ratio = (clampedX - leftMargin) / drawW;
      const index = Math.max(0, Math.min(series.length - 1, Math.round(ratio * (series.length - 1))));

      const p = series[index];
      const px = toX(index);
      const py = toY(p.value);

      cursorLine.setAttribute('x1', String(px));
      cursorLine.setAttribute('x2', String(px));
      cursorLine.setAttribute('y1', String(topMargin));
      cursorLine.setAttribute('y2', String(topMargin + drawH));
      cursorLine.setAttribute('visibility', 'visible');

      cursorDot.setAttribute('cx', String(px));
      cursorDot.setAttribute('cy', String(py));
      cursorDot.setAttribute('visibility', 'visible');

      const labelText = `${formatNumber(p.value)} ${unit}`;
      const approxWidth = Math.max(48, labelText.length * 7 + 10);
      let lx = px + 8;
      let ly = py - 18;

      if (lx + approxWidth > (leftMargin + drawW)) {
        lx = px - approxWidth - 8;
      }
      if (ly < topMargin + 2) {
        ly = py + 18;
      }

      cursorLabel.textContent = labelText;
      cursorLabel.setAttribute('x', String(lx + 5));
      cursorLabel.setAttribute('y', String(ly + 12));
      cursorLabel.setAttribute('visibility', 'visible');

      cursorLabelBg.setAttribute('x', String(lx));
      cursorLabelBg.setAttribute('y', String(ly));
      cursorLabelBg.setAttribute('width', String(approxWidth));
      cursorLabelBg.setAttribute('height', '18');
      cursorLabelBg.setAttribute('visibility', 'visible');

      pointInfo.textContent = `${new Date(p.clock * 1000).toLocaleString()} | valor ${formatNumber(p.value)} ${unit}`;
    });

    overlay.addEventListener('mouseleave', () => {
      cursorLine.setAttribute('visibility', 'hidden');
      cursorDot.setAttribute('visibility', 'hidden');
      cursorLabel.setAttribute('visibility', 'hidden');
      cursorLabelBg.setAttribute('visibility', 'hidden');
      pointInfo.textContent = 'Pasa el raton por la linea para ver el valor exacto';
    });

    wrap.appendChild(svg);

    const latest = values[values.length - 1];
    const info = document.createElement('div');
    info.className = 'wsb-chart-info';
    info.textContent = `min ${formatNumber(min)} ${unit} | avg ${formatNumber(avg)} ${unit} | max ${formatNumber(max)} ${unit} | ultimo ${formatNumber(latest)} ${unit}`;
    wrap.appendChild(info);
    wrap.appendChild(pointInfo);

    return wrap;
  }

  function formatNumber(value) {
    if (!Number.isFinite(value)) {
      return '-';
    }

    if (Math.abs(value) >= 100) {
      return value.toFixed(0);
    }

    if (Math.abs(value) >= 10) {
      return value.toFixed(1);
    }

    return value.toFixed(2);
  }

  function classifyCodeBucket(code) {
    if (!Number.isFinite(code) || code <= 0) {
      return 'unknown';
    }
    if (code >= 200 && code < 300) {
      return 'ok';
    }
    if (code >= 300 && code < 400) {
      return 'redirect';
    }
    if (code >= 400 && code < 500) {
      return 'client';
    }
    if (code >= 500) {
      return 'server';
    }
    return 'unknown';
  }

  function statusFillColor(status) {
    if (status === 'server') {
      return 'rgba(215, 91, 91, 0.20)';
    }
    if (status === 'client') {
      return 'rgba(231, 151, 77, 0.18)';
    }
    if (status === 'redirect') {
      return 'rgba(223, 187, 73, 0.15)';
    }
    if (status === 'unknown') {
      return 'rgba(111, 130, 151, 0.18)';
    }
    return '';
  }

  function paintStatusIntervals(svg, rspSeriesRaw, chartStartClock, chartEndClock, area) {
    if (!Array.isArray(rspSeriesRaw) || rspSeriesRaw.length === 0) {
      return;
    }

    const rspSeries = rspSeriesRaw
      .map((p) => ({ clock: Number(p.clock), value: Number(p.value) }))
      .filter((p) => Number.isFinite(p.clock) && Number.isFinite(p.value))
      .sort((a, b) => a.clock - b.clock);

    if (rspSeries.length === 0 || chartEndClock <= chartStartClock) {
      return;
    }

    const toXClock = (clock) => {
      const ratio = (clock - chartStartClock) / (chartEndClock - chartStartClock);
      const clamped = Math.max(0, Math.min(1, ratio));
      return area.leftMargin + (area.drawW * clamped);
    };

    for (let i = 0; i < rspSeries.length; i++) {
      const cur = rspSeries[i];
      const next = rspSeries[i + 1];

      const intervalStart = Math.max(cur.clock, chartStartClock);
      const intervalEnd = Math.min(next ? next.clock : chartEndClock, chartEndClock);
      if (intervalEnd <= intervalStart) {
        continue;
      }

      const status = classifyCodeBucket(cur.value);
      const fill = statusFillColor(status);
      if (!fill) {
        continue;
      }

      const x1 = toXClock(intervalStart);
      const x2 = toXClock(intervalEnd);

      const band = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
      band.setAttribute('x', String(x1));
      band.setAttribute('y', String(area.topMargin));
      band.setAttribute('width', String(Math.max(1, x2 - x1)));
      band.setAttribute('height', String(area.drawH));
      band.setAttribute('fill', fill);
      svg.appendChild(band);
    }
  }

  function initSparkTooltips() {
    const bars = document.querySelectorAll('.spark-bar[data-tooltip]');
    if (!bars.length) {
      return;
    }

    let tooltip = document.querySelector('.wsb-spark-tooltip');
    if (!tooltip) {
      tooltip = document.createElement('div');
      tooltip.className = 'wsb-spark-tooltip';
      document.body.appendChild(tooltip);
    }

    const show = (event) => {
      const target = event.currentTarget;
      const text = target.getAttribute('data-tooltip') || '';
      if (!text) {
        return;
      }

      tooltip.textContent = text;
      tooltip.classList.add('visible');
      move(event);
    };

    const hide = () => {
      tooltip.classList.remove('visible');
    };

    const move = (event) => {
      const x = event.clientX + 14;
      const y = event.clientY + 14;
      tooltip.style.left = `${x}px`;
      tooltip.style.top = `${y}px`;
    };

    bars.forEach((bar) => {
      bar.addEventListener('mouseenter', show);
      bar.addEventListener('mousemove', move);
      bar.addEventListener('mouseleave', hide);
    });
  }

  function applyTableFilter(filter) {
    const groupCards = document.querySelectorAll('.wsb-group-card');

    groupCards.forEach((card) => {
      let visibleRows = 0;
      const rows = card.querySelectorAll('.wsb-row-clickable');

      rows.forEach((row) => {
        const bucket = row.getAttribute('data-bucket') || '0';
        const status = row.getAttribute('data-status') || 'unknown';
        const show = filter === 'all'
          || (filter === 'unknown' && status === 'unknown')
          || bucket === filter;
        row.style.display = show ? '' : 'none';

        if (show) {
          visibleRows += 1;
        }
      });

      card.style.display = visibleRows > 0 ? '' : 'none';
    });
  }

  function triggerScheduledRefresh() {
    if (!autoRefreshEnabled) {
      return;
    }

    if (refreshInProgress) {
      return;
    }

    refreshInProgress = true;

    if (refreshTimerId !== null) {
      window.clearInterval(refreshTimerId);
      refreshTimerId = null;
    }

    timer.textContent = '0';

    try {
      window.sessionStorage.setItem('wsb:lastRefreshAt', String(Date.now()));
    }
    catch (e) {
      // Ignore sessionStorage failures.
    }

    const url = new URL(window.location.href);
    url.searchParams.delete('mode');
    window.location.replace(url.toString());
  }

  function updateAutoRefreshUi() {
    if (!refreshToggleBtn) {
      return;
    }

    if (refreshInterval <= 0) {
      refreshToggleBtn.textContent = 'Auto-refresh: N/A';
      refreshToggleBtn.disabled = true;
      refreshToggleBtn.classList.remove('is-on');
      refreshToggleBtn.classList.add('is-off');
      refreshToggleBtn.setAttribute('aria-pressed', 'false');
      return;
    }

    refreshToggleBtn.disabled = false;
    refreshToggleBtn.textContent = autoRefreshEnabled ? 'Auto-refresh: ON' : 'Auto-refresh: OFF';
    refreshToggleBtn.classList.toggle('is-on', autoRefreshEnabled);
    refreshToggleBtn.classList.toggle('is-off', !autoRefreshEnabled);
    refreshToggleBtn.setAttribute('aria-pressed', autoRefreshEnabled ? 'true' : 'false');
  }

  function initColumnVisibilityControls() {
    if (!columnCheckboxes.length) {
      return;
    }

    const storageKey = 'wsb:columns';
    let savedConfig = {};

    try {
      savedConfig = JSON.parse(window.localStorage.getItem(storageKey) || '{}');
    }
    catch (e) {
      savedConfig = {};
    }

    columnCheckboxes.forEach((checkbox) => {
      const col = checkbox.getAttribute('data-column-toggle') || '';
      if (!col) {
        return;
      }

      if (Object.prototype.hasOwnProperty.call(savedConfig, col)) {
        checkbox.checked = !!savedConfig[col];
      }

      applyColumnVisibility(col, checkbox.checked);

      checkbox.addEventListener('change', () => {
        applyColumnVisibility(col, checkbox.checked);
        persistColumns(storageKey);
      });
    });

    if (!columnsToggleBtn || !columnsPanel) {
      return;
    }

    columnsToggleBtn.addEventListener('click', () => {
      const isOpen = !columnsPanel.hasAttribute('hidden');
      if (isOpen) {
        columnsPanel.setAttribute('hidden', 'hidden');
        columnsToggleBtn.setAttribute('aria-expanded', 'false');
      }
      else {
        columnsPanel.removeAttribute('hidden');
        columnsToggleBtn.setAttribute('aria-expanded', 'true');
      }
    });

    document.addEventListener('click', (event) => {
      if (columnsPanel.hasAttribute('hidden')) {
        return;
      }

      const target = event.target;
      if (target === columnsPanel || columnsPanel.contains(target) || target === columnsToggleBtn) {
        return;
      }

      columnsPanel.setAttribute('hidden', 'hidden');
      columnsToggleBtn.setAttribute('aria-expanded', 'false');
    });
  }

  function applyColumnVisibility(column, visible) {
    if (!column) {
      return;
    }

    const cells = document.querySelectorAll(`[data-col="${column}"]`);
    cells.forEach((cell) => {
      cell.style.display = visible ? '' : 'none';
    });
  }

  function persistColumns(storageKey) {
    const payload = {};
    columnCheckboxes.forEach((checkbox) => {
      const col = checkbox.getAttribute('data-column-toggle') || '';
      if (!col) {
        return;
      }

      payload[col] = checkbox.checked;
    });

    try {
      window.localStorage.setItem(storageKey, JSON.stringify(payload));
    }
    catch (e) {
      // Ignore localStorage failures.
    }
  }

  function reloadForGlobalTimeframe(timeframe) {
    const url = new URL(window.location.href);
    url.searchParams.set('timeframe', timeframe);
    if (!url.searchParams.get('action')) {
      url.searchParams.set('action', 'web.scenario.status.board');
    }
    url.searchParams.delete('mode');
    window.location.href = url.toString();
  }
});
