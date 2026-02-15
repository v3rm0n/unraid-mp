<link rel="stylesheet" href="/plugins/media-player-sync/plugin.css">

<div class="mps-wrap">
  <div class="mps-card mps-how">
    <div class="mps-how-title">How It Works</div>
    <ol class="mps-how-list">
      <li>Mount a FAT32 player so the plugin can read and write folder content.</li>
      <li>Choose one or more source shares, browse folders, and add them to the sync selection.</li>
      <li>Sync preview compares selected folders against the device and shows what is already present, missing, or removable.</li>
      <li>Sync copies only missing files with rsync and removes deselected folders only when they are plugin-managed.</li>
      <li>Adopt Existing aligns the device with /mnt/user content and converts the player into managed mode.</li>
    </ol>
  </div>

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
              <input type="button" id="refreshPlayers" value="Refresh" class="mps-btn mps-btn-neutral">
              <input type="button" id="toggleMount" value="Mount" class="mps-btn mps-btn-primary">
            </dd>
          </dl>
          <div id="playerInfo" class="mps-info"></div>
          <div id="playerManagedState" class="mps-managed-state"></div>
          <div id="diskSpaceContainer" class="mps-disk-space" style="display: none;">
            <div class="mps-disk-space-header">
              <span class="mps-disk-space-label">Storage</span>
              <span class="mps-disk-space-text" id="diskSpaceText"></span>
            </div>
            <div class="mps-progress-bar-bg">
              <div class="mps-progress-bar-fill" id="diskSpaceBar"></div>
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
          <strong><em>Music Selection</em></strong>
          <span class="mps-head-note">Choose source shares and folders to sync.</span>
        </th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <div class="mps-grid">
            <div>
              <div class="mps-col-title">Shares</div>
              <select id="shareSelect" size="10"></select>
              <div class="mps-info">Selecting a share automatically loads folders.</div>
            </div>
            <div>
              <div class="mps-col-title mps-folder-head">
                <span>Folders In Share</span>
                <span id="selectionSummary" class="mps-selection-summary"></span>
              </div>
              <div class="mps-breadcrumb" id="folderBreadcrumb"></div>
              <div id="folderTree" class="mps-folder-tree"></div>
              <div class="mps-actions">
                <input type="button" id="selectAllVisible" value="Select All" class="mps-btn mps-btn-neutral">
              </div>
              <div id="addCandidatesBlock" class="mps-candidate-block mps-add-candidates" style="display: none;">
                <div class="mps-candidate-title mps-add-title">Will add on sync</div>
                <ul id="addCandidatesList" class="mps-candidate-list mps-add-list"></ul>
              </div>
              <div id="removalCandidatesBlock" class="mps-removal-candidates" style="display: none;">
                <div class="mps-removal-title">Will remove on sync (managed)</div>
                <ul id="removalCandidatesList" class="mps-removal-list"></ul>
              </div>
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
          <div id="syncPreview" class="mps-card mps-sync-preview"></div>
          <div class="mps-status-legend">Status: On device (keep) | Missing (add) | Managed only (remove)</div>
          <div id="syncIndicator" class="mps-sync-indicator" style="display: none;">
            <span class="mps-spinner"></span>
            <span class="mps-sync-text">Sync in progress...</span>
          </div>
          <input type="button" id="adoptLibrary" value="Adopt Existing" class="mps-btn mps-btn-danger">
          <input type="button" id="startSync" value="Sync Now" class="mps-btn mps-btn-primary">
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
    lastBrowseShare: '',
    currentShare: '',
    currentPath: '',
    folderCache: {},
    expandedFolders: new Set(),
    syncStatus: {},
    selectedStatus: {},
    addCandidates: [],
    removalCandidates: [],
    managed: null,
    syncPolling: null,
    isSyncing: false
  };

  const playerSelect = document.getElementById('playerSelect');
  const playerInfo = document.getElementById('playerInfo');
  const playerManagedState = document.getElementById('playerManagedState');
  const shareSelect = document.getElementById('shareSelect');
  const folderTree = document.getElementById('folderTree');
  const folderBreadcrumb = document.getElementById('folderBreadcrumb');
  const selectionSummary = document.getElementById('selectionSummary');
  const addCandidatesBlock = document.getElementById('addCandidatesBlock');
  const addCandidatesList = document.getElementById('addCandidatesList');
  const removalCandidatesBlock = document.getElementById('removalCandidatesBlock');
  const removalCandidatesList = document.getElementById('removalCandidatesList');
  const selectAllVisibleButton = document.getElementById('selectAllVisible');
  const syncLog = document.getElementById('syncLog');
  const toast = document.getElementById('toast');
  const syncPreview = document.getElementById('syncPreview');
  const adoptLibraryButton = document.getElementById('adoptLibrary');
  const syncIndicator = document.getElementById('syncIndicator');
  const startSyncButton = document.getElementById('startSync');
  let selectionSaveTimer = null;
  let previewRefreshTimer = null;

  function canonicalKey(share, folder) {
    return `${share}/${folder}`;
  }

  function pathDepth(path) {
    return path.split('/').length;
  }

  function isSameOrChildPath(candidate, base) {
    return candidate === base || candidate.startsWith(`${base}/`);
  }

  function isAncestorPath(candidate, descendant) {
    return descendant.startsWith(`${candidate}/`);
  }

  function hasSelectedDescendant(share, folder) {
    return state.selected.some((entry) => entry.share === share && isAncestorPath(folder, entry.folder));
  }

  function normalizeSelectedFolders() {
    const sorted = [...state.selected].sort((a, b) => {
      const depthDiff = pathDepth(b.folder) - pathDepth(a.folder);
      if (depthDiff !== 0) {
        return depthDiff;
      }
      return canonicalKey(a.share, a.folder).localeCompare(canonicalKey(b.share, b.folder));
    });

    const normalized = [];
    for (const entry of sorted) {
      const overlapsDeeper = normalized.some((kept) => kept.share === entry.share && isSameOrChildPath(kept.folder, entry.folder));
      if (!overlapsDeeper) {
        normalized.push(entry);
      }
    }

    normalized.sort((a, b) => canonicalKey(a.share, a.folder).localeCompare(canonicalKey(b.share, b.folder)));
    state.selected = normalized;
  }

  function renderBrowseShares(shares) {
    const previous = state.lastBrowseShare || shareSelect.value;
    shareSelect.innerHTML = '';
    for (const share of shares) {
      const opt = document.createElement('option');
      opt.value = share;
      opt.textContent = share;
      shareSelect.appendChild(opt);
    }

    if (shares.length === 0) {
      state.currentShare = '';
      return;
    }

    if (previous && shares.includes(previous)) {
      shareSelect.value = previous;
    } else {
      shareSelect.selectedIndex = 0;
    }
    state.lastBrowseShare = shareSelect.value;
  }

  function resetStatusState() {
    state.syncStatus = {};
    state.selectedStatus = {};
    state.addCandidates = [];
    state.removalCandidates = [];
    state.managed = null;
    updateFolderSyncIndicators();
    renderSelectionSummary();
    renderSyncPreview();
    updateAdoptVisibility();
  }

  function isFolderSelected(share, folder) {
    return state.selected.some((entry) => entry.share === share && entry.folder === folder);
  }

  function setFolderSelected(share, folder, selected) {
    const index = state.selected.findIndex((entry) => entry.share === share && entry.folder === folder);
    if (selected && index === -1) {
      const hasDescendants = hasSelectedDescendant(share, folder);
      if (!hasDescendants) {
        state.selected.push({ share, folder });
      }

      state.selected = state.selected.filter((entry) => {
        if (entry.share !== share) {
          return true;
        }
        if (entry.folder === folder) {
          return true;
        }
        return !isAncestorPath(entry.folder, folder);
      });
    } else if (!selected && index !== -1) {
      state.selected.splice(index, 1);
    }

    normalizeSelectedFolders();
  }

  function sortSelectedFolders() {
    state.selected.sort((a, b) => `${a.share}/${a.folder}`.localeCompare(`${b.share}/${b.folder}`));
  }

  function renderAddCandidates() {
    const candidates = state.addCandidates || [];
    addCandidatesList.innerHTML = '';
    if (candidates.length === 0) {
      addCandidatesBlock.style.display = 'none';
      return;
    }

    for (const candidate of candidates) {
      const item = document.createElement('li');
      item.textContent = candidate.key;
      addCandidatesList.appendChild(item);
    }
    addCandidatesBlock.style.display = '';
  }

  function renderRemovalCandidates() {
    const candidates = state.removalCandidates || [];
    removalCandidatesList.innerHTML = '';
    if (candidates.length === 0) {
      removalCandidatesBlock.style.display = 'none';
      return;
    }

    for (const candidate of candidates) {
      const item = document.createElement('li');
      item.textContent = candidate.key;
      removalCandidatesList.appendChild(item);
    }
    removalCandidatesBlock.style.display = '';
  }

  function renderSelectionSummary() {
    const total = state.selected.length;
    const share = state.currentShare || shareSelect.value || '';
    const inShare = share ? state.selected.filter((entry) => entry.share === share).length : 0;
    if (share) {
      selectionSummary.textContent = `Selected: ${inShare} in this share (${total} total)`;
    } else {
      selectionSummary.textContent = `Selected: ${total}`;
    }
    renderAddCandidates();
    renderRemovalCandidates();
  }

  function updateSelectAllButtonLabel() {
    const checkboxes = folderTree.querySelectorAll('input[type="checkbox"]');
    if (checkboxes.length === 0) {
      selectAllVisibleButton.value = 'Select All';
      selectAllVisibleButton.disabled = true;
      return;
    }
    selectAllVisibleButton.disabled = false;
    const allChecked = Array.from(checkboxes).every((checkbox) => checkbox.checked);
    selectAllVisibleButton.value = allChecked ? 'Deselect All' : 'Select All';
  }

  function queueSelectionSave() {
    if (selectionSaveTimer) {
      clearTimeout(selectionSaveTimer);
    }
    selectionSaveTimer = setTimeout(async () => {
      try {
        await saveSettings({ silent: true, skipFolderReload: true });
      } catch (err) {
        showToast(`Save failed: ${err.message}`, false);
      }
    }, 180);
  }

  function queuePreviewRefresh() {
    if (previewRefreshTimer) {
      clearTimeout(previewRefreshTimer);
    }
    previewRefreshTimer = setTimeout(async () => {
      try {
        await loadSyncPreview(true);
        await refreshCurrentFolderStatuses(true);
      } catch (err) {
      }
    }, 80);
  }

  function updateAdoptVisibility() {
    const id = playerSelect.value;
    const player = state.players.find((p) => p.id === id);
    const visible = !!player && player.mounted && state.managed === false;
    adoptLibraryButton.style.display = visible ? '' : 'none';
    adoptLibraryButton.disabled = !visible;
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
      hideDiskSpace();
      return;
    }
    playerInfo.textContent = `Device: ${player.path} | UUID: ${player.uuid || 'n/a'} | Mount: ${player.mountpoint || 'not mounted'}`;
    if (!player.mounted) {
      playerManagedState.textContent = 'Mount a player to preview sync changes.';
      hideDiskSpace();
    } else if (state.managed === true) {
      playerManagedState.textContent = 'Managed by plugin: deselected managed folders can be removed on sync.';
    } else if (state.managed === false) {
      playerManagedState.textContent = 'Unmanaged player: sync will add missing folders only (no removals yet).';
    } else {
      playerManagedState.textContent = 'Loading player sync state...';
    }
    if (player.mounted && player.diskSpace) {
      renderDiskSpace(player.diskSpace);
    }
    updateToggleButton();
  }

  function renderDiskSpace(diskSpace) {
    const container = document.getElementById('diskSpaceContainer');
    const textEl = document.getElementById('diskSpaceText');
    const barEl = document.getElementById('diskSpaceBar');

    if (!diskSpace || typeof diskSpace !== 'object') {
      hideDiskSpace();
      return;
    }

    const { used, free, usedPercent } = diskSpace;
    const total = used + free;

    // Format bytes for display
    const usedStr = formatBytes(used);
    const totalStr = formatBytes(total);
    const freeStr = formatBytes(free);

    textEl.textContent = `${usedStr} / ${totalStr} (${freeStr} free)`;

    barEl.style.width = `${usedPercent}%`;

    // Remove all color classes
    barEl.classList.remove('mps-space-high', 'mps-space-medium', 'mps-space-low');

    // Add color class based on used space (inverse of free space)
    if (usedPercent < 50) {
      barEl.classList.add('mps-space-high');
    } else if (usedPercent < 80) {
      barEl.classList.add('mps-space-medium');
    } else {
      barEl.classList.add('mps-space-low');
    }

    container.style.display = 'block';
  }

  function hideDiskSpace() {
    const container = document.getElementById('diskSpaceContainer');
    container.style.display = 'none';
  }

  function formatBytes(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
      value /= 1024;
      unitIndex++;
    }

    return value.toFixed(2) + ' ' + units[unitIndex];
  }

  async function loadPlayers(lastId = '') {
    const res = await api('listPlayers');
    state.players = res.players;
    renderPlayers(lastId);
  }

  async function loadShares() {
    const res = await api('listShares');
    const shares = Array.isArray(res.shares) ? res.shares : [];
    renderBrowseShares(shares);
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
        selectedFolders: state.selected,
        csrf_token: csrf_token
      };
      const json = await api('getSyncPreview', 'POST', buildPayloadForm(payload));
      state.managed = !!json.managed;
      state.selectedStatus = {};
      for (const entry of json.selected || []) {
        state.selectedStatus[entry.key] = entry.state;
      }
      state.addCandidates = json.addCandidates || [];
      state.removalCandidates = json.removeCandidates || [];
      renderSelectionSummary();
      renderSyncPreview(json);
      updateAdoptVisibility();
    } catch (err) {
      if (!silent) {
        showToast(`Sync preview failed: ${err.message}`, false);
      }
      syncPreview.textContent = `Preview unavailable: ${err.message}`;
      updateAdoptVisibility();
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
    state.lastBrowseShare = share;
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
        updateAdoptVisibility();
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
      row.classList.remove('synced', 'status-keep', 'status-add', 'status-remove', 'status-external', 'status-partial');
      if (status === 'keep') {
        row.classList.add('synced', 'status-keep');
      } else if (status === 'add') {
        row.classList.add('status-add');
      } else if (status === 'remove') {
        row.classList.add('status-remove');
      } else if (status === 'external') {
        row.classList.add('status-external');
      }

      if (state.currentShare && path && hasSelectedDescendant(state.currentShare, path) && !isFolderSelected(state.currentShare, path)) {
        row.classList.add('status-partial');
      }

      const checkbox = row.querySelector('input[type="checkbox"]');
      if (!checkbox) {
        return;
      }

      checkbox.indeterminate = false;
      if (!state.currentShare || !path) {
        return;
      }

      if (isFolderSelected(state.currentShare, path)) {
        checkbox.checked = true;
        return;
      }

      if (hasSelectedDescendant(state.currentShare, path)) {
        checkbox.checked = false;
        checkbox.indeterminate = true;
      } else {
        checkbox.checked = false;
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
      
      const checked = isFolderSelected(state.currentShare, folder.relative) ? ' checked' : '';
      html += `<label class="mps-folder-label"><input type="checkbox" value="${folder.relative}"${checked}> ${escapeHtml(folder.name)}</label>`;
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
    updateSelectAllButtonLabel();
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
      
      const checked = isFolderSelected(state.currentShare, folder.relative) ? ' checked' : '';
      html += `<label class="mps-folder-label"><input type="checkbox" value="${folder.relative}"${checked}> ${escapeHtml(folder.name)}</label>`;
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
      
      const checked = isFolderSelected(state.currentShare, folder.relative) ? ' checked' : '';
      html += `<label class="mps-folder-label"><input type="checkbox" value="${folder.relative}"${checked}> ${escapeHtml(folder.name)}</label>`;
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
    updateSelectAllButtonLabel();
    
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

    container.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        const share = state.currentShare || shareSelect.value;
        if (!share) {
          return;
        }
        setFolderSelected(share, checkbox.value, checkbox.checked);
        checkbox.checked = isFolderSelected(share, checkbox.value);
        checkbox.indeterminate = !checkbox.checked && hasSelectedDescendant(share, checkbox.value);
        sortSelectedFolders();
        renderSelectionSummary();
        updateFolderSyncIndicators();
        updateSelectAllButtonLabel();
        queuePreviewRefresh();
        queueSelectionSave();
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
    state.lastBrowseShare = typeof res.settings.lastBrowseShare === 'string' ? res.settings.lastBrowseShare : '';
    state.selected = Array.isArray(res.settings.selectedFolders) ? res.settings.selectedFolders : [];
    normalizeSelectedFolders();
    sortSelectedFolders();
    resetStatusState();
    renderSelectionSummary();
    await loadPlayers(res.settings.lastPlayerId || '');
    await loadSyncPreview();

    if (shareSelect.value) {
      await loadFolders();
    }
  }

  async function saveSettings(options = {}) {
    const silent = !!options.silent;
    const skipFolderReload = !!options.skipFolderReload;
    const payload = {
      selectedFolders: state.selected,
      lastPlayerId: playerSelect.value || '',
      lastBrowseShare: shareSelect.value || '',
      csrf_token: csrf_token
    };
    await api('saveSettings', 'POST', buildPayloadForm(payload));
    if (shareSelect.value && !skipFolderReload) {
      await loadFolders();
    }
    await loadSyncPreview();
    await refreshCurrentFolderStatuses();
    if (!silent) {
      showToast('Settings saved');
    }
  }

  function updateToggleButton() {
    const id = playerSelect.value;
    const player = state.players.find((p) => p.id === id);
    const btn = document.getElementById('toggleMount');
    if (!player) {
      btn.value = 'Mount';
      btn.disabled = true;
      updateAdoptVisibility();
      return;
    }
    btn.disabled = false;
    if (player.mounted) {
      btn.value = 'Unmount';
    } else {
      btn.value = 'Mount';
    }
    updateAdoptVisibility();
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

    let preview;
    try {
      preview = await api('getAdoptPreview', 'POST', buildPayloadForm({
        uuid: id,
        csrf_token: csrf_token
      }));
    } catch (err) {
      showToast(`Preview failed: ${err.message}`, false);
      return;
    }

    const summary = preview.summary || {};
    const msg = [
      'Adopt existing will delete unmatched content on the player.',
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
      sortSelectedFolders();
      renderSelectionSummary();
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
      if (json.diskSpace) {
        renderDiskSpace(json.diskSpace);
      }
    }
    await loadPlayers(id);
    state.syncStatus = {};
    await loadSyncPreview();
    await refreshCurrentFolderStatuses();
  }

  function setSyncing(isSyncing) {
    state.isSyncing = isSyncing;
    if (isSyncing) {
      syncIndicator.style.display = 'flex';
      startSyncButton.disabled = true;
      startSyncButton.value = 'Syncing...';
    } else {
      syncIndicator.style.display = 'none';
      startSyncButton.disabled = false;
      startSyncButton.value = 'Sync Now';
    }
  }

  async function pollSyncStatus() {
    try {
      const json = await api('getSyncStatus');
      const status = json.status || {};

      if (!status.running) {
        setSyncing(false);
        if (state.syncPolling) {
          clearInterval(state.syncPolling);
          state.syncPolling = null;
        }

        const result = status.result || {};
        const errors = result.errors || [];
        const success = result.ok === true;

        if (success && errors.length === 0) {
          syncLog.textContent = 'Sync completed successfully. Refreshing status...';
          showToast('Sync complete');
        } else if (success && errors.length > 0) {
          const errorText = errors.join('\n');
          syncLog.textContent = 'Sync completed with warnings:\n' + errorText;
          showToast('Sync completed with warnings', false);
        } else {
          const errorText = errors.join('\n') || 'Unknown error';
          syncLog.textContent = 'Sync failed:\n' + errorText;
          showToast('Sync failed: ' + (errors[0] || 'Unknown error'), false);
        }

        state.syncStatus = {};
        await loadSyncPreview();
        await refreshCurrentFolderStatuses();
        return;
      }

      const logTail = status.logTail || [];
      if (logTail.length > 0) {
        syncLog.textContent = logTail.join('\n');
      }
    } catch (err) {
      console.error('Sync status poll failed:', err);
    }
  }

  async function syncNow() {
    await saveSettings({ silent: true });

    const id = playerSelect.value;
    if (!id) {
      showToast('Select a player first', false);
      return;
    }

    setSyncing(true);
    syncLog.textContent = 'Starting sync...';

    const data = new URLSearchParams();
    data.append('uuid', id);
    data.append('csrf_token', csrf_token);

    try {
      const json = await api('sync', 'POST', data);
      syncLog.textContent = `${json.message || 'Sync started'}\nLog: ${json.logFile || 'N/A'}`;

      state.syncPolling = setInterval(pollSyncStatus, 2000);
    } catch (err) {
      setSyncing(false);
      syncLog.textContent = `Error: ${err.message}`;
      showToast(`Sync failed: ${err.message}`, false);
    }
  }

  document.getElementById('refreshPlayers').addEventListener('click', async () => {
    await loadPlayers(playerSelect.value);
    await loadSyncPreview();
    await refreshCurrentFolderStatuses();
  });
  document.getElementById('toggleMount').addEventListener('click', toggleMount);
  shareSelect.addEventListener('change', () => {
    state.lastBrowseShare = shareSelect.value || '';
    if (shareSelect.value) {
      loadFolders();
    }
  });

  document.getElementById('startSync').addEventListener('click', syncNow);
  document.getElementById('adoptLibrary').addEventListener('click', adoptLibrary);
  playerSelect.addEventListener('change', async () => {
    state.syncStatus = {};
    state.selectedStatus = {};
    state.managed = null;
    updateFolderSyncIndicators();
    renderSelectionSummary();
    updatePlayerInfo();
    await loadSyncPreview();
    await refreshCurrentFolderStatuses();
  });

  document.getElementById('selectAllVisible').addEventListener('click', () => {
    const checkboxes = folderTree.querySelectorAll('input[type="checkbox"]');
    const share = state.currentShare || shareSelect.value;
    if (!share || checkboxes.length === 0) {
      return;
    }
    const allChecked = Array.from(checkboxes).every((checkbox) => checkbox.checked);
    checkboxes.forEach((checkbox) => {
      checkbox.checked = !allChecked;
      setFolderSelected(share, checkbox.value, checkbox.checked);
      checkbox.checked = isFolderSelected(share, checkbox.value);
      checkbox.indeterminate = !checkbox.checked && hasSelectedDescendant(share, checkbox.value);
    });
    sortSelectedFolders();
    renderSelectionSummary();
    updateFolderSyncIndicators();
    updateSelectAllButtonLabel();
    queuePreviewRefresh();
    queueSelectionSave();
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
