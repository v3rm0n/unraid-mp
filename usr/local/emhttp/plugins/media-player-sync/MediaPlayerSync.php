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
          <div id="playerManagedState" class="mps-managed-state"></div>
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
          <div id="syncPreview" class="mps-sync-preview"></div>
          <input type="button" id="adoptLibrary" value="Adopt From Unraid" class="mps-danger">
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
    expandedFolders: new Set(),
    syncStatus: {},
    selectedStatus: {},
    managed: null
  };

  const playerSelect = document.getElementById('playerSelect');
  const playerInfo = document.getElementById('playerInfo');
  const playerManagedState = document.getElementById('playerManagedState');
  const shareSelect = document.getElementById('shareSelect');
  const folderTree = document.getElementById('folderTree');
  const folderBreadcrumb = document.getElementById('folderBreadcrumb');
  const selectedList = document.getElementById('selectedList');
  const syncLog = document.getElementById('syncLog');
  const toast = document.getElementById('toast');
  const musicRoot = document.getElementById('musicRoot');
  const syncPreview = document.getElementById('syncPreview');
  const adoptLibraryButton = document.getElementById('adoptLibrary');

  function canonicalKey(share, folder) {
    return `${share}/${folder}`;
  }

  function resetStatusState() {
    state.syncStatus = {};
    state.selectedStatus = {};
    state.managed = null;
    updateFolderSyncIndicators();
    renderSelected();
    renderSyncPreview();
  }

  function showToast(message, ok = true) {
    toast.textContent = message;
    toast.className = ok ? 'mps-toast ok' : 'mps-toast err';
    setTimeout(() => {
      toast.className = 'mps-toast';
    }, 3000);
  }

  function buildPayloadForm(payload) {
    const form = new URLSearchParams();
    form.append('payload', JSON.stringify(payload));
    form.append('csrf_token', csrf_token);
    return form;
  }

  async function api(action, method = 'GET', body = null, query = '', timeoutMs = 30000) {
    const opts = { method };
    const suffix = query ? `&${query}` : '';
    const needsCsrfQuery = method === 'POST'
      && !(body instanceof URLSearchParams)
      && !(body instanceof FormData)
      && typeof csrf_token !== 'undefined'
      && csrf_token;
    const csrfSuffix = needsCsrfQuery ? `&csrf_token=${encodeURIComponent(csrf_token)}` : '';
    const url = `${apiBase}?action=${encodeURIComponent(action)}${suffix}${csrfSuffix}`;
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
      playerManagedState.textContent = '';
      updateToggleButton();
      return;
    }
    playerInfo.textContent = `Device: ${player.path} | UUID: ${player.uuid || 'n/a'} | Mount: ${player.mountpoint || 'not mounted'}`;
    if (!player.mounted) {
      playerManagedState.textContent = 'Mount a player to preview sync changes.';
    } else if (state.managed === true) {
      playerManagedState.textContent = 'Managed by plugin: deselected managed folders can be removed on sync.';
    } else if (state.managed === false) {
      playerManagedState.textContent = 'Unmanaged player: sync will add missing folders only (no removals yet).';
    } else {
      playerManagedState.textContent = 'Loading player sync state...';
    }
    updateToggleButton();
  }

  function renderSelected() {
    selectedList.innerHTML = '';
    for (const s of state.selected) {
      const opt = document.createElement('option');
      opt.value = `${s.share}:${s.folder}`;
      const key = canonicalKey(s.share, s.folder);
      const status = state.selectedStatus[key];
      const suffix = status === 'keep' ? ' [On device]' : status === 'add' ? ' [Will add]' : '';
      opt.textContent = `${s.share}/${s.folder}${suffix}`;
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

  function renderSyncPreview(preview = null) {
    const id = playerSelect.value;
    const player = state.players.find((p) => p.id === id);
    if (!player || !player.mounted) {
      syncPreview.textContent = 'Preview unavailable: mount a player to see add/remove status.';
      updatePlayerInfo();
      return;
    }
    if (!preview) {
      syncPreview.textContent = 'Loading sync preview...';
      updatePlayerInfo();
      return;
    }

    const managedText = state.managed ? 'Managed' : 'Unmanaged';
    const keep = preview.summary?.keep || 0;
    const add = preview.summary?.add || 0;
    const remove = preview.summary?.remove || 0;
    const selected = preview.summary?.selected || 0;
    syncPreview.textContent = `${managedText} | Selected: ${selected} | On device: ${keep} | To add: ${add} | To remove: ${remove}`;
    updatePlayerInfo();
  }

  async function loadSyncPreview(silent = true) {
    const playerId = playerSelect.value;
    if (!playerId) {
      resetStatusState();
      return;
    }

    const player = state.players.find((p) => p.id === playerId);
    if (!player || !player.mounted) {
      resetStatusState();
      return;
    }

    renderSyncPreview(null);
    try {
      const payload = {
        uuid: playerId,
        musicRoot: musicRoot.value.trim() || 'Music',
        selectedFolders: state.selected,
        csrf_token: csrf_token
      };
      const json = await api('getSyncPreview', 'POST', buildPayloadForm(payload));
      state.managed = !!json.managed;
      state.selectedStatus = {};
      for (const entry of json.selected || []) {
        state.selectedStatus[entry.key] = entry.state;
      }
      renderSelected();
      renderSyncPreview(json);
    } catch (err) {
      if (!silent) {
        showToast(`Sync preview failed: ${err.message}`, false);
      }
      syncPreview.textContent = `Preview unavailable: ${err.message}`;
    }
  }

  async function refreshCurrentFolderStatuses(silent = true) {
    const cacheKey = `${state.currentShare}:${state.currentPath}`;
    const folders = state.folderCache[cacheKey] || [];
    if (folders.length > 0) {
      await checkSyncStatusForFolders(folders, silent);
    } else {
      updateFolderSyncIndicators();
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
    state.folderCache = {};
    state.expandedFolders.clear();
    state.syncStatus = {};
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
    checkSyncStatusForFolders(folders);
  }

  async function checkSyncStatusForFolders(folders, silent = true) {
    if (!state.currentShare || folders.length === 0) return;
    const share = state.currentShare;
    
    const playerId = playerSelect.value;
    if (!playerId) return;
    
    const player = state.players.find(p => p.id === playerId);
    if (!player || !player.mounted) return;
    
    const folderPaths = folders.map(f => f.relative);
    
    try {
      const payload = {
        uuid: playerId,
        share: share,
        folders: folderPaths,
        musicRoot: musicRoot.value.trim() || 'Music',
        selectedFolders: state.selected,
        csrf_token: csrf_token
      };
      const json = await api('checkSyncStatus', 'POST', buildPayloadForm(payload));
      
      if (json.ok && json.statuses) {
        state.managed = !!json.managed;
        Object.entries(json.statuses).forEach(([relative, status]) => {
          state.syncStatus[canonicalKey(share, relative)] = status;
        });
        updateFolderSyncIndicators();
        updatePlayerInfo();
      }
    } catch (err) {
      if (!silent) {
        showToast(`Folder status check failed: ${err.message}`, false);
      }
    }
  }

  function updateFolderSyncIndicators() {
    folderTree.querySelectorAll('.mps-folder-row').forEach(row => {
      const path = row.dataset.path;
      const status = path ? state.syncStatus[canonicalKey(state.currentShare, path)] : 'none';
      row.classList.remove('synced', 'status-keep', 'status-add', 'status-remove', 'status-external');
      if (status === 'keep') {
        row.classList.add('synced', 'status-keep');
      } else if (status === 'add') {
        row.classList.add('status-add');
      } else if (status === 'remove') {
        row.classList.add('status-remove');
      } else if (status === 'external') {
        row.classList.add('status-external');
      }
    });
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
        // Clear the tree and reload from this level
        folderTree.innerHTML = '';
        state.expandedFolders.clear();
        loadFolderTree(el.dataset.path);
      });
    });
  }

  function renderFolderTree(folders, parentPath) {
    // Always clear and rebuild the tree view for the current level
    folderTree.innerHTML = '';
    
    const container = document.createElement('div');
    container.className = 'mps-folder-list';
    container.dataset.level = parentPath ? parentPath.split('/').length : 0;
    
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
      
      html += `<label class="mps-folder-label"><input type="checkbox" value="${folder.relative}"> ${escapeHtml(folder.name)}</label>`;
      row.innerHTML = html;
      container.appendChild(row);
      
      // If this folder was previously expanded, render its children inline
      if (folder.hasChildren && isExpanded) {
        const childContainer = document.createElement('div');
        childContainer.className = 'mps-folder-children';
        childContainer.dataset.parent = folder.relative;
        renderSubfolderList(childContainer, folder.relative);
        container.appendChild(childContainer);
      }
    }
    
    folderTree.appendChild(container);
    attachFolderListeners(container);
  }

  function renderSubfolderList(container, parentPath) {
    const cacheKey = `${state.currentShare}:${parentPath}`;
    const folders = state.folderCache[cacheKey];
    if (!folders) return;
    
    for (const folder of folders) {
      const row = document.createElement('div');
      row.className = 'mps-folder-row mps-folder-child';
      row.dataset.path = folder.relative;
      
      const isExpanded = state.expandedFolders.has(folder.relative);
      
      let html = '';
      if (folder.hasChildren) {
        html += `<span class="mps-folder-toggle ${isExpanded ? 'expanded' : ''}" data-path="${folder.relative}">${isExpanded ? '−' : '+'}</span>`;
      } else {
        html += `<span class="mps-folder-spacer"></span>`;
      }
      
      html += `<label class="mps-folder-label"><input type="checkbox" value="${folder.relative}"> ${escapeHtml(folder.name)}</label>`;
      row.innerHTML = html;
      container.appendChild(row);
      
      if (folder.hasChildren && isExpanded) {
        const childContainer = document.createElement('div');
        childContainer.className = 'mps-folder-children';
        childContainer.dataset.parent = folder.relative;
        renderSubfolderList(childContainer, folder.relative);
        container.appendChild(childContainer);
      }
    }
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
    
    const childContainer = document.createElement('div');
    childContainer.className = 'mps-folder-children';
    childContainer.dataset.parent = path;
    
    for (const folder of folders) {
      const row = document.createElement('div');
      row.className = 'mps-folder-row mps-folder-child';
      row.dataset.path = folder.relative;
      
      const isExpanded = state.expandedFolders.has(folder.relative);
      
      let html = '';
      if (folder.hasChildren) {
        html += `<span class="mps-folder-toggle ${isExpanded ? 'expanded' : ''}" data-path="${folder.relative}">${isExpanded ? '−' : '+'}</span>`;
      } else {
        html += `<span class="mps-folder-spacer"></span>`;
      }
      
      html += `<label class="mps-folder-label"><input type="checkbox" value="${folder.relative}"> ${escapeHtml(folder.name)}</label>`;
      row.innerHTML = html;
      childContainer.appendChild(row);
      
      if (folder.hasChildren && isExpanded) {
        const nestedContainer = document.createElement('div');
        nestedContainer.className = 'mps-folder-children';
        nestedContainer.dataset.parent = folder.relative;
        renderSubfolderList(nestedContainer, folder.relative);
        childContainer.appendChild(nestedContainer);
      }
    }
    
    container.appendChild(childContainer);
    attachFolderListeners(childContainer);
    
    // Check sync status for newly loaded subfolders
    checkSyncStatusForFolders(folders);
  }

  function attachFolderListeners(container) {
    container.querySelectorAll('.mps-folder-toggle').forEach(toggle => {
      toggle.addEventListener('click', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        const path = toggle.dataset.path;
        const isExpanded = state.expandedFolders.has(path);
        const row = toggle.closest('.mps-folder-row');
        
        if (isExpanded) {
          state.expandedFolders.delete(path);
          toggle.classList.remove('expanded');
          toggle.textContent = '+';
          // Remove the child container that follows this row
          const childContainer = row.nextElementSibling;
          if (childContainer && childContainer.classList.contains('mps-folder-children')) {
            childContainer.remove();
          }
        } else {
          state.expandedFolders.add(path);
          toggle.classList.add('expanded');
          toggle.textContent = '−';
          // Load and insert children after this row
          const parentContainer = document.createElement('div');
          parentContainer.className = 'mps-folder-children';
          parentContainer.dataset.parent = path;
          row.insertAdjacentElement('afterend', parentContainer);
          await loadSubfolders(path, parentContainer);
        }
      });
    });
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  async function loadSettings() {
    const res = await api('getSettings');
    musicRoot.value = res.settings.musicRoot || 'Music';
    state.selected = Array.isArray(res.settings.selectedFolders) ? res.settings.selectedFolders : [];
    resetStatusState();
    renderSelected();
    await loadPlayers(res.settings.lastPlayerId || '');
    await loadSyncPreview();
  }

  async function saveSettings() {
    const payload = {
      musicRoot: musicRoot.value.trim() || 'Music',
      selectedFolders: state.selected,
      lastPlayerId: playerSelect.value || '',
      csrf_token: csrf_token
    };
    await api('saveSettings', 'POST', buildPayloadForm(payload));
    await loadSyncPreview();
    await refreshCurrentFolderStatuses();
    showToast('Settings saved');
  }

  function updateToggleButton() {
    const id = playerSelect.value;
    const player = state.players.find((p) => p.id === id);
    const btn = document.getElementById('toggleMount');
    if (!player) {
      btn.value = 'Mount';
      btn.disabled = true;
      adoptLibraryButton.disabled = true;
      return;
    }
    btn.disabled = false;
    adoptLibraryButton.disabled = !player.mounted;
    if (player.mounted) {
      btn.value = 'Unmount';
    } else {
      btn.value = 'Mount';
    }
  }

  async function adoptLibrary() {
    const id = playerSelect.value;
    if (!id) {
      showToast('Select a player first', false);
      return;
    }

    const player = state.players.find((p) => p.id === id);
    if (!player || !player.mounted) {
      showToast('Mount the player before adopting', false);
      return;
    }

    const musicRootValue = musicRoot.value.trim() || 'Music';
    let preview;
    try {
      preview = await api('getAdoptPreview', 'POST', buildPayloadForm({
        uuid: id,
        musicRoot: musicRootValue,
        csrf_token: csrf_token
      }));
    } catch (err) {
      showToast(`Preview failed: ${err.message}`, false);
      return;
    }

    const summary = preview.summary || {};
    const msg = [
      'Adopt from Unraid will delete unmatched content on the player.',
      `Files to delete: ${summary.deleteFiles || 0}`,
      `Directories to delete: ${summary.deleteDirs || 0}`,
      `Folder roots to manage: ${summary.adoptFolders || 0}`,
      '',
      'Continue?'
    ].join('\n');
    if (!window.confirm(msg)) {
      return;
    }

    syncLog.textContent = 'Running adoption cleanup...';
    let result;
    try {
      result = await api('adoptLibrary', 'POST', buildPayloadForm({
        uuid: id,
        musicRoot: musicRootValue,
        csrf_token: csrf_token
      }), '', 0);
    } catch (err) {
      syncLog.textContent = `Error: ${err.message}`;
      showToast(`Adoption failed: ${err.message}`, false);
      return;
    }

    const lines = [];
    lines.push(`Deleted files: ${result.deletedFiles || 0}`);
    lines.push(`Deleted directories: ${result.deletedDirs || 0}`);
    lines.push(`Adopted folder roots: ${result.adoptedFolders || 0}`);
    if (Array.isArray(result.errors) && result.errors.length > 0) {
      lines.push('Errors:');
      for (const e of result.errors) lines.push(`- ${e}`);
    }
    lines.push('');
    lines.push('Log tail:');
    for (const row of result.logTail || []) lines.push(row);
    lines.push('');
    lines.push(`Full log: ${result.logFile}`);
    syncLog.textContent = lines.join('\n');

    if (result.settings && Array.isArray(result.settings.selectedFolders)) {
      state.selected = result.settings.selectedFolders;
      musicRoot.value = result.settings.musicRoot || musicRootValue;
      renderSelected();
    }

    await loadPlayers(id);
    state.syncStatus = {};
    await loadSyncPreview(false);
    await refreshCurrentFolderStatuses(false);
    if (Array.isArray(result.errors) && result.errors.length > 0) {
      showToast('Adoption finished with errors', false);
    } else {
      showToast('Adoption complete');
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
    state.syncStatus = {};
    await loadSyncPreview();
    await refreshCurrentFolderStatuses();
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

    state.syncStatus = {};
    await loadSyncPreview();
    await refreshCurrentFolderStatuses();
  }

  document.getElementById('refreshPlayers').addEventListener('click', async () => {
    await loadPlayers(playerSelect.value);
    await loadSyncPreview();
    await refreshCurrentFolderStatuses();
  });
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
  document.getElementById('adoptLibrary').addEventListener('click', adoptLibrary);
  playerSelect.addEventListener('change', async () => {
    state.syncStatus = {};
    state.selectedStatus = {};
    state.managed = null;
    updateFolderSyncIndicators();
    renderSelected();
    updatePlayerInfo();
    await loadSyncPreview();
    await refreshCurrentFolderStatuses();
  });

  musicRoot.addEventListener('change', async () => {
    await loadSyncPreview();
    await refreshCurrentFolderStatuses();
  });

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
    loadSyncPreview();
    refreshCurrentFolderStatuses();
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
    loadSyncPreview();
    refreshCurrentFolderStatuses();
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
