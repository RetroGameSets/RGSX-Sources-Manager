// ==UserScript==
// @name         RomHeaven Switch Link Exporter
// @namespace    https://retrogamesets.fr/
// @version      1.0.0
// @description  Exporte les liens fixes de la page /switch (format: name|title_id|url)
// @match        https://romheaven.com/switch*
// @run-at       document-idle
// @grant        GM_setClipboard
// ==/UserScript==

(function () {
  'use strict';

  const BUTTON_ID = 'rgsx-rh-export-btn';
  const STATUS_ID = 'rgsx-rh-export-status';

  function ensureZipName(name) {
    const trimmed = String(name || '').trim();
    if (!trimmed) {
      return 'game.zip';
    }
    return /\.zip$/i.test(trimmed) ? trimmed : `${trimmed}.zip`;
  }

  function getBundleUrl() {
    const fromPerf = performance
      .getEntriesByType('resource')
      .map((r) => r.name)
      .find((u) => /\/noodl_bundles\/.+\.json$/i.test(u));
    return fromPerf || '';
  }

  async function parseRowsFromBundle(bundleUrl) {
    const raw = await (await fetch(bundleUrl, { credentials: 'same-origin' })).text();
    const match = raw.match(/"csv":"([\s\S]*?)"\}\,"ports"/);

    if (!match) {
      throw new Error('Bloc CSV introuvable dans le bundle.');
    }

    const csv = match[1]
      .replace(/\\r\\n/g, '\n')
      .replace(/\\"/g, '"')
      .replace(/\\\\/g, '\\');

    const lines = csv.split('\n').filter(Boolean);
    const rows = [];

    for (const line of lines) {
      if (line.startsWith('filename,base_title_id,')) {
        continue;
      }

      const parts = line.split(',', 3);
      if (parts.length < 3) {
        continue;
      }

      const name = parts[0].trim();
      const nameWithExt = ensureZipName(name);
      const titleId = parts[1].trim();

      if (!/^010[0-9A-F]{13}$/.test(titleId)) {
        continue;
      }

      const url =
        'https://dl.romheaven.com/' +
        titleId +
        '.zip?filename=' +
        encodeURIComponent(nameWithExt);

      rows.push({ name: nameWithExt, titleId, url });
    }

    return rows;
  }

  function formatOutput(rows) {
    return rows.map((r) => `${r.name}|${r.titleId}|${r.url}`).join('\n');
  }

  async function copyOutput(text) {
    if (typeof GM_setClipboard === 'function') {
      GM_setClipboard(text, { type: 'text', mimetype: 'text/plain' });
      return;
    }
    await navigator.clipboard.writeText(text);
  }

  function downloadOutput(text) {
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
    a.href = url;
    a.download = `romheaven-switch-links-${stamp}.txt`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  function setStatus(message, isError) {
    const el = document.getElementById(STATUS_ID);
    if (!el) {
      return;
    }
    el.textContent = message;
    el.style.color = isError ? '#ff8080' : '#8ff58f';
  }

  async function runExport() {
    try {
      setStatus('Extraction en cours...', false);
      const bundleUrl = getBundleUrl();
      if (!bundleUrl) {
        throw new Error('Bundle introuvable. Rechargez la page puis relancez.');
      }

      const rows = await parseRowsFromBundle(bundleUrl);
      if (!rows.length) {
        throw new Error('Aucun lien extrait.');
      }

      const output = formatOutput(rows);
      await copyOutput(output);
      downloadOutput(output);
      setStatus(`OK: ${rows.length} liens copies + fichier telecharge.`, false);
      console.log('RomHeaven export termine:', rows.length, 'liens');
    } catch (error) {
      console.error(error);
      setStatus(`Erreur: ${error.message || error}`, true);
    }
  }

  function createUi() {
    if (document.getElementById(BUTTON_ID)) {
      return;
    }

    const wrap = document.createElement('div');
    wrap.style.position = 'fixed';
    wrap.style.right = '14px';
    wrap.style.bottom = '14px';
    wrap.style.zIndex = '999999';
    wrap.style.display = 'flex';
    wrap.style.flexDirection = 'column';
    wrap.style.gap = '6px';
    wrap.style.alignItems = 'flex-end';

    const btn = document.createElement('button');
    btn.id = BUTTON_ID;
    btn.textContent = 'Export Switch Links';
    btn.style.border = '1px solid #2d2d2d';
    btn.style.background = '#111';
    btn.style.color = '#fff';
    btn.style.padding = '8px 10px';
    btn.style.borderRadius = '8px';
    btn.style.cursor = 'pointer';
    btn.style.fontSize = '12px';
    btn.style.fontFamily = 'monospace';
    btn.addEventListener('click', runExport);

    const status = document.createElement('div');
    status.id = STATUS_ID;
    status.textContent = 'Pret';
    status.style.fontSize = '11px';
    status.style.fontFamily = 'monospace';
    status.style.background = 'rgba(0,0,0,0.7)';
    status.style.color = '#8ff58f';
    status.style.padding = '4px 6px';
    status.style.borderRadius = '6px';
    status.style.maxWidth = '300px';

    wrap.appendChild(btn);
    wrap.appendChild(status);
    document.body.appendChild(wrap);
  }

  createUi();
})();
