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
              <input type="button" id="toggleMount" value="Mount">
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
              <div class="mps-actions"><input type="button" id="loadFolders" value="Browse Folders"></div>
            </div>
            <div>
              <div class="mps-col-title">Folders In Share</div>
              <div class="mps-breadcrumb" id="folderBreadcrumb"></div>
              <div id="folderTree" class="mps-folder-tree"></div>
              <div class="mps-actions">
                <input type="button" id="addSelection" value="Add Selected">
                <input type="button" id="selectAllVisible" value="Select All Visible">
              </div>
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
    selected: [],
    currentShare: '',
    currentPath: '',
    folderCache: {},
    expandedFolders: new Set()
  };

  const playerSelect = document.getElementById('playerSelect');
  const playerInfo = document.getElementById('playerInfo');
  const shareSelect = document.getElementById('shareSelect');
  const folderTree = document.getElementById('folderTree');
  const folderBreadcrumb = document.getElementById('folderBreadcrumb');
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
    if (method === 'POST' && body) {
      if (body instanceof URLSearchParams) {
        opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
        opts.body = body.toString();
      } else if (body instanceof FormData) {
        opts.body = body;
      } else {
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(body);
      }
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
      updateToggleButton();
      return;
    }
    playerInfo.textContent = `Device: ${player.path} | UUID: ${player.uuid || 'n/a'} | Mount: ${player.mountpoint || 'not mounted'}`;
    updateToggleButton();
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
    state.currentShare = share;
    state.currentPath = '';
    state.expandedFolders.clear();
    await loadFolderTree('');
  }

  async function loadFolderTree(path) {
    if (!state.currentShare) return;
    
    const cacheKey = `${state.currentShare}:${path}`;
    let folders = state.folderCache[cacheKey];
    
    if (!folders) {
      try {
        const json = await api('listFolders', 'GET', null, 
          `share=${encodeURIComponent(state.currentShare)}&path=${encodeURIComponent(path)}`);
        folders = json.folders;
        state.folderCache[cacheKey] = folders;
      } catch (err) {
        showToast(err.message, false);
        return;
      }
    }
    
    state.currentPath = path;
    renderFolderTree(folders, path);
    renderBreadcrumb(path);
  }

  function renderBreadcrumb(path) {
    if (!state.currentShare) {
      folderBreadcrumb.innerHTML = '';
      return;
    }
    
    const parts = path ? path.split('/') : [];
    let html = `<span class="mps-crumb-root" data-path="">${state.currentShare}</span>`;
    let currentPath = '';
    
    for (const part of parts) {
      currentPath = currentPath ? `${currentPath}/${part}` : part;
      html += ` / <span class="mps-crumb-part" data-path="${currentPath}">${part}</span>`;
    }
    
    folderBreadcrumb.innerHTML = html;
    
    folderBreadcrumb.querySelectorAll('.mps-crumb-root, .mps-crumb-part').forEach(el => {
      el.addEventListener('click', () => {
        loadFolderTree(el.dataset.path);
      });
    });
  }

  function renderFolderTree(folders, parentPath) {
    const indent = parentPath ? (parentPath.split('/').length * 20) : 0;
    
    if (!parentPath) {
      folderTree.innerHTML = '';
    }
    
    const container = document.createElement('div');
    container.className = 'mps-folder-list';
    container.style.marginLeft = `${indent}px`;
    
    for (const folder of folders) {
      const row = document.createElement('div');
      row.className = 'mps-folder-row';
      row.dataset.path = folder.relative;
      
      const isExpanded = state.expandedFolders.has(folder.relative);
      
      let html = '';
      if (folder.hasChildren) {
        html += `<span class="mps-folder-toggle ${isExpanded ? 'expanded' : ''}" data-path="${folder.relative}">${isExpanded ? '−' : '+'}</span>`;
      } else {
        html += `<span class="mps-folder-spacer"></span>`;
      }
      
      html += `<label class="mps-folder-label"><input type="checkbox" value="${folder.relative}"> ${folder.name}</label>`;
      row.innerHTML = html;
      container.appendChild(row);
      
      if (folder.hasChildren && isExpanded) {
        loadSubfolders(folder.relative, container);
      }
    }
    
    if (!parentPath) {
      folderTree.appendChild(container);
    }
    
    attachFolderListeners(container);
  }

  async function loadSubfolders(path, container) {
    const cacheKey = `${state.currentShare}:${path}`;
    let folders = state.folderCache[cacheKey];
    
    if (!folders) {
      try {
        const json = await api('listFolders', 'GET', null,
          `share=${encodeURIComponent(state.currentShare)}&path=${encodeURIComponent(path)}`);
        folders = json.folders;
        state.folderCache[cacheKey] = folders;
      } catch (err) {
        return;
      }
    }
    
    const indent = path.split('/').length * 20;
    const subContainer = document.createElement('div');
    subContainer.className = 'mps-folder-list';
    subContainer.style.marginLeft = `${indent}px`;
    subContainer.dataset.parent = path;
    
    for (const folder of folders) {
      const row = document.createElement('div');
      row.className = 'mps-folder-row';
      row.dataset.path = folder.relative;
      
      const isExpanded = state.expandedFolders.has(folder.relative);
      
      let html = '';
      if (folder.hasChildren) {
        html += `<span class="mps-folder-toggle ${isExpanded ? 'expanded' : ''}" data-path="${folder.relative}">${isExpanded ? '−' : '+'}</span>`;
      } else {
        html += `<span class="mps-folder-spacer"></span>`;
      }
      
      html += `<label class="mps-folder-label"><input type="checkbox" value="${folder.relative}"> ${folder.name}</label>`;
      row.innerHTML = html;
      subContainer.appendChild(row);
      
      if (folder.hasChildren && isExpanded) {
        loadSubfolders(folder.relative, subContainer);
      }
    }
    
    container.appendChild(subContainer);
    attachFolderListeners(subContainer);
  }

  function attachFolderListeners(container) {
    container.querySelectorAll('.mps-folder-toggle').forEach(toggle => {
      toggle.addEventListener('click', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        const path = toggle.dataset.path;
        const isExpanded = state.expandedFolders.has(path);
        
        if (isExpanded) {
          state.expandedFolders.delete(path);
          toggle.classList.remove('expanded');
          toggle.textContent = '+';
          const subContainer = container.querySelector(`div[data-parent="${path}"]`);
          if (subContainer) {
            subContainer.remove();
          }
        } else {
          state.expandedFolders.add(path);
          toggle.classList.add('expanded');
          toggle.textContent = '−';
          await loadSubfolders(path, toggle.closest('.mps-folder-list'));
        }
      });
    });
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

  function updateToggleButton() {
    const id = playerSelect.value;
    const player = state.players.find((p) => p.id === id);
    const btn = document.getElementById('toggleMount');
    if (!player) {
      btn.value = 'Mount';
      btn.disabled = true;
      return;
    }
    btn.disabled = false;
    if (player.mounted) {
      btn.value = 'Unmount';
    } else {
      btn.value = 'Mount';
    }
  }

  async function toggleMount() {
    const id = playerSelect.value;
    if (!id) {
      showToast('Select a player first', false);
      return;
    }
    const player = state.players.find((p) => p.id === id);
    if (!player) {
      showToast('Player not found', false);
      return;
    }

    const data = new URLSearchParams();
    data.append('uuid', id);
    data.append('csrf_token', csrf_token);

    if (player.mounted) {
      showToast('Unmounting player...');
      let json;
      try {
        json = await api('unmount', 'POST', data);
      } catch (err) {
        showToast(`Unmount failed: ${err.message}`, false);
        return;
      }
      showToast(json.message || 'Unmounted');
    } else {
      showToast('Mounting player...');
      let json;
      try {
        json = await api('mount', 'POST', data);
      } catch (err) {
        showToast(`Mount failed: ${err.message}`, false);
        return;
      }
      showToast(json.message || 'Mounted');
    }
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
    const data = new URLSearchParams();
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
  document.getElementById('toggleMount').addEventListener('click', toggleMount);
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
    const share = state.currentShare || shareSelect.value;
    const checked = folderTree.querySelectorAll('input[type="checkbox"]:checked');
    if (!share || checked.length === 0) {
      showToast('Pick share and folder(s)', false);
      return;
    }

    const existing = new Set(state.selected.map((x) => `${x.share}:${x.folder}`));
    for (const cb of checked) {
      const folder = cb.value;
      const key = `${share}:${folder}`;
      if (!existing.has(key)) {
        state.selected.push({ share, folder });
        existing.add(key);
      }
    }
    state.selected.sort((a, b) => `${a.share}/${a.folder}`.localeCompare(`${b.share}/${b.folder}`));
    renderSelected();
    showToast(`Added ${checked.length} folder(s)`);
  });

  document.getElementById('selectAllVisible').addEventListener('click', () => {
    const checkboxes = folderTree.querySelectorAll('input[type="checkbox"]');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => cb.checked = !allChecked);
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
      updateToggleButton();
    } catch (err) {
      showToast(err.message, false);
    }
  })();
</script>
