<link rel="stylesheet" href="/plugins/media-player-sync/plugin.css">

<div class="mps-wrap">
  <table class="tablesorter mps-panel">
    <thead>
      <tr>
        <th>
          <strong><em>Media Player</em></strong>
          <span class="mps-head-note">Choose a FAT32 player and mount or unmount it.</span>
        </th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <dl>
            <dt>Detected Player:</dt>
            <dd>
              <select id="playerSelect" class="mps-input"></select>
              <input type="button" id="refreshPlayers" value="Refresh">
              <input type="button" id="mountPlayer" value="Mount">
              <input type="button" id="unmountPlayer" value="Unmount">
            </dd>
          </dl>
          <div id="playerInfo" class="mps-info"></div>
        </td>
      </tr>
    </tbody>
  </table>

  <table class="tablesorter mps-panel">
    <thead>
      <tr>
        <th>
          <strong><em>Source Selection</em></strong>
          <span class="mps-head-note">Select share folders to sync to the player.</span>
        </th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <dl>
            <dt>Device Music Root:</dt>
            <dd>
              <input id="musicRoot" type="text" class="narrow" value="Music" maxlength="64">
              <input type="button" id="saveSettings" value="Save Selection">
            </dd>
          </dl>

          <div class="mps-grid">
            <div>
              <div class="mps-col-title">Shares</div>
              <select id="shareSelect" size="10"></select>
              <div class="mps-actions"><input type="button" id="loadFolders" value="Load Folders"></div>
            </div>
            <div>
              <div class="mps-col-title">Folders In Share</div>
              <select id="folderSelect" size="10" multiple></select>
              <div class="mps-actions"><input type="button" id="addSelection" value="Add Selected"></div>
            </div>
            <div>
              <div class="mps-col-title">Selected For Sync</div>
              <select id="selectedList" size="10" multiple></select>
              <div class="mps-actions"><input type="button" id="removeSelection" value="Remove Selected"></div>
            </div>
          </div>
        </td>
      </tr>
    </tbody>
  </table>

  <table class="tablesorter mps-panel">
    <thead>
      <tr>
        <th>
          <strong><em>Sync</em></strong>
          <span class="mps-head-note">Copy only missing files and prune unselected managed folders.</span>
        </th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <input type="button" id="startSync" value="Sync Now">
          <pre id="syncLog"></pre>
        </td>
      </tr>
    </tbody>
  </table>

  <div id="toast" class="mps-toast"></div>
</div>

<script>
  const apiBase = '/plugins/media-player-sync/api.php';
  const state = {
    players: [],
    selected: []
  };

  const playerSelect = document.getElementById('playerSelect');
  const playerInfo = document.getElementById('playerInfo');
  const shareSelect = document.getElementById('shareSelect');
  const folderSelect = document.getElementById('folderSelect');
  const selectedList = document.getElementById('selectedList');
  const syncLog = document.getElementById('syncLog');
  const toast = document.getElementById('toast');
  const musicRoot = document.getElementById('musicRoot');

  function showToast(message, ok = true) {
    toast.textContent = message;
    toast.className = ok ? 'mps-toast ok' : 'mps-toast err';
    setTimeout(() => {
      toast.className = 'mps-toast';
    }, 3000);
  }

  async function api(action, method = 'GET', body = null, query = '', timeoutMs = 30000) {
    const opts = { method };
    const suffix = query ? `&${query}` : '';
    const url = `${apiBase}?action=${encodeURIComponent(action)}${suffix}`;
    const controller = timeoutMs > 0 ? new AbortController() : null;
    let timeoutId = null;
    if (controller) {
      opts.signal = controller.signal;
      timeoutId = setTimeout(() => controller.abort(), timeoutMs);
    }
    if (method === 'POST' && body && !(body instanceof FormData)) {
      opts.headers = { 'Content-Type': 'application/json' };
      opts.body = JSON.stringify(body);
    } else if (method === 'POST' && body instanceof FormData) {
      opts.body = body;
    }
    let res;
    let raw;
    try {
      res = await fetch(url, opts);
      raw = await res.text();
    } catch (err) {
      if (err && err.name === 'AbortError') {
        throw new Error(`Request timed out after ${Math.round(timeoutMs / 1000)}s`);
      }
      throw err;
    } finally {
      if (timeoutId) clearTimeout(timeoutId);
    }
    let json;
    try {
      json = JSON.parse(raw);
    } catch (err) {
      throw new Error(`Unexpected response: ${raw.slice(0, 180)}`);
    }
    if (!res.ok || !json.ok) {
      let message = json.error || 'Request failed';
      if (json.logFile) {
        message += ` (log: ${json.logFile})`;
      }
      if (Array.isArray(json.logTail) && json.logTail.length) {
        message += ` | ${json.logTail.join(' | ')}`;
      }
      throw new Error(message);
    }
    return json;
  }

  function renderPlayers(lastId = '') {
    playerSelect.innerHTML = '';
    for (const p of state.players) {
      const label = p.label ? p.label : p.path;
      const opt = document.createElement('option');
      opt.value = p.id;
      opt.textContent = `${label} (${p.size || 'unknown'})${p.mounted ? ' [mounted]' : ''}`;
      if (lastId && p.id === lastId) {
        opt.selected = true;
      }
      playerSelect.appendChild(opt);
    }
    updatePlayerInfo();
  }

  function updatePlayerInfo() {
    const id = playerSelect.value;
    const player = state.players.find((p) => p.id === id);
    if (!player) {
      playerInfo.textContent = 'No FAT32 player found.';
      return;
    }
    playerInfo.textContent = `Device: ${player.path} | UUID: ${player.uuid || 'n/a'} | Mount: ${player.mountpoint || 'not mounted'}`;
  }

  function renderSelected() {
    selectedList.innerHTML = '';
    for (const s of state.selected) {
      const opt = document.createElement('option');
      opt.value = `${s.share}:${s.folder}`;
      opt.textContent = `${s.share}/${s.folder}`;
      selectedList.appendChild(opt);
    }
  }

  async function loadPlayers(lastId = '') {
    const res = await api('listPlayers');
    state.players = res.players;
    renderPlayers(lastId);
  }

  async function loadShares() {
    const res = await api('listShares');
    shareSelect.innerHTML = '';
    for (const share of res.shares) {
      const opt = document.createElement('option');
      opt.value = share;
      opt.textContent = share;
      shareSelect.appendChild(opt);
    }
  }

  async function loadFolders() {
    const share = shareSelect.value;
    if (!share) {
      showToast('Select a share first', false);
      return;
    }
    let json;
    try {
      json = await api('listFolders', 'GET', null, `share=${encodeURIComponent(share)}`);
    } catch (err) {
      showToast(err.message, false);
      return;
    }
    folderSelect.innerHTML = '';
    for (const folder of json.folders) {
      const opt = document.createElement('option');
      opt.value = folder;
      opt.textContent = folder;
      folderSelect.appendChild(opt);
    }
  }

  async function loadSettings() {
    const res = await api('getSettings');
    musicRoot.value = res.settings.musicRoot || 'Music';
    state.selected = Array.isArray(res.settings.selectedFolders) ? res.settings.selectedFolders : [];
    renderSelected();
    await loadPlayers(res.settings.lastPlayerId || '');
  }

  async function saveSettings() {
    const payload = {
      musicRoot: musicRoot.value.trim() || 'Music',
      selectedFolders: state.selected,
      lastPlayerId: playerSelect.value || '',
      csrf_token: csrf_token
    };
    await api('saveSettings', 'POST', payload);
    showToast('Settings saved');
  }

  async function mountSelected() {
    const id = playerSelect.value;
    if (!id) {
      showToast('Select a player first', false);
      return;
    }
    showToast('Mounting player...');
    const data = new FormData();
    data.append('uuid', id);
    data.append('csrf_token', csrf_token);
    let json;
    try {
      json = await api('mount', 'POST', data);
    } catch (err) {
      showToast(`Mount failed: ${err.message}`, false);
      return;
    }
    showToast(json.message || 'Mounted');
    await loadPlayers(id);
  }

  async function unmountSelected() {
    const id = playerSelect.value;
    if (!id) {
      showToast('Select a player first', false);
      return;
    }
    showToast('Unmounting player...');
    const data = new FormData();
    data.append('uuid', id);
    data.append('csrf_token', csrf_token);
    let json;
    try {
      json = await api('unmount', 'POST', data);
    } catch (err) {
      showToast(`Unmount failed: ${err.message}`, false);
      return;
    }
    showToast(json.message || 'Unmounted');
    await loadPlayers(id);
  }

  async function syncNow() {
    await saveSettings();

    const id = playerSelect.value;
    if (!id) {
      showToast('Select a player first', false);
      return;
    }

    syncLog.textContent = 'Running sync...';
    const data = new FormData();
    data.append('uuid', id);
    data.append('csrf_token', csrf_token);
    let json;
    try {
      json = await api('sync', 'POST', data, '', 0);
    } catch (err) {
      syncLog.textContent = `Error: ${err.message}`;
      showToast(`Sync failed: ${err.message}`, false);
      return;
    }

    const lines = [];
    lines.push(`Copied files: ${json.copiedFiles}`);
    lines.push(`Removed unselected folders: ${json.removedDirs}`);
    if (Array.isArray(json.errors) && json.errors.length > 0) {
      lines.push('Errors:');
      for (const e of json.errors) lines.push(`- ${e}`);
    }
    lines.push('');
    lines.push('Log tail:');
    for (const row of json.logTail || []) lines.push(row);
    lines.push('');
    lines.push(`Full log: ${json.logFile}`);
    syncLog.textContent = lines.join('\n');

    if (Array.isArray(json.errors) && json.errors.length > 0) {
      showToast('Sync finished with errors', false);
    } else {
      showToast('Sync complete');
    }
  }

  document.getElementById('refreshPlayers').addEventListener('click', () => loadPlayers(playerSelect.value));
  document.getElementById('mountPlayer').addEventListener('click', mountSelected);
  document.getElementById('unmountPlayer').addEventListener('click', unmountSelected);
  document.getElementById('loadFolders').addEventListener('click', loadFolders);
  document.getElementById('saveSettings').addEventListener('click', async () => {
    try {
      await saveSettings();
    } catch (err) {
      showToast(err.message, false);
    }
  });
  document.getElementById('startSync').addEventListener('click', syncNow);
  playerSelect.addEventListener('change', updatePlayerInfo);

  document.getElementById('addSelection').addEventListener('click', () => {
    const share = shareSelect.value;
    const selectedOptions = Array.from(folderSelect.selectedOptions);
    if (!share || selectedOptions.length === 0) {
      showToast('Pick share and folder(s)', false);
      return;
    }

    const existing = new Set(state.selected.map((x) => `${x.share}:${x.folder}`));
    for (const opt of selectedOptions) {
      const key = `${share}:${opt.value}`;
      if (!existing.has(key)) {
        state.selected.push({ share, folder: opt.value });
      }
    }
    state.selected.sort((a, b) => `${a.share}/${a.folder}`.localeCompare(`${b.share}/${b.folder}`));
    renderSelected();
  });

  document.getElementById('removeSelection').addEventListener('click', () => {
    const removed = new Set(Array.from(selectedList.selectedOptions).map((o) => o.value));
    state.selected = state.selected.filter((x) => !removed.has(`${x.share}:${x.folder}`));
    renderSelected();
  });

  (async function init() {
    try {
      await loadShares();
      await loadSettings();
    } catch (err) {
      showToast(err.message, false);
    }
  })();
</script>
