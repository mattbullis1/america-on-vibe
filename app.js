(() => {
  'use strict';
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const windows = new Map($$('.desktop-window').map(el => [el.dataset.window, el]));
  const windowLabels = { welcome: 'Welcome', mail: 'Mail', browser: 'Internet', chat: 'Chat', buddies: 'Buddies', im: 'Instant Message', channels: 'Channels', 'channel-view': 'Channel', settings: 'Settings', help: 'Help' };
  const channelTitles = {
    aoltoday: 'Today', computing: 'Computing', entertainment: 'Entertainment',
    families: 'Families', games: 'Games', health: 'Health', influence: 'Influence',
    interests: 'Interests', international: 'International', kidsonly: 'Kids Only',
    lifestyles: 'Lifestyles', local: 'Local', music: 'Music', news: 'News',
    people: 'People', personalfinance: 'Personal Finance', researchandlearn: 'Research & Learn',
    search: 'Search', shopping: 'Shopping', sports: 'Sports', travel: 'Travel',
    workplace: 'Workplace'
  };
  let active = 'welcome';
  let connectionPending = false;
  let connectionSound = null;
  const connectionDurationMs = 19000;
  const dialupSoundUrl = 'assets/sounds/dialup.mp3';
  const eventSoundUrls = {
    welcome: 'assets/sounds/welcome.mp3',
    goodbye: 'assets/sounds/goodbye.mp3',
    mail: 'assets/sounds/mail.mp3',
    message: 'assets/sounds/message.mp3',
    sent: 'assets/sounds/sent.mp3'
  };
  const activeEffects = new Set();
  let z = 10;
  const browserSession = { entries: [{ url: null, title: 'AOV Home' }], index: 0 };
  let bookmarks = [];
  let recentHistory = [];
  let homeUrl = '';
  const initialState = JSON.parse($('#aov-state').textContent);
  const siteNotice = $('#site-notice');
  if (siteNotice) {
    try { siteNotice.hidden = localStorage.getItem('aov_site_notice_v1') === '1'; }
    catch { /* The notice remains visible when browser storage is unavailable. */ }
    $('#site-notice-dismiss')?.addEventListener('click', () => {
      siteNotice.hidden = true;
      try { localStorage.setItem('aov_site_notice_v1', '1'); }
      catch { /* Dismissal still works for this visit. */ }
    });
  }
  const channelUrls = initialState.channels || {};
  let keywords = [];
  let keywordChoices = [];
  let activeKeywordChoice = -1;
  let currentChannel = null;
  let channelRequest = 0;
  let csrf = initialState.csrf;
  $('#recovery-setup-note').hidden = Boolean(initialState.recoveryAvailable);
  let signedIn = Boolean(initialState.loggedIn);
  let lastActivity = Date.now();
  let lastCheck = 0;
  const inactivityMs = 30 * 60 * 1000;
  let previewName = initialState.name || '';
  let chatLastId = 0;
  let chatBusy = false;
  let chatState = { userId: 0, buddies: [], blockedIds: [], online: [] };
  let imContact = '';
  const imConversations = new Map();
  const imSeen = new Set();
  let chatFiltersLoaded = false;
  let mailFolder = 'inbox';
  let mailPage = 0;
  let mailMore = false;
  let mailCurrent = null;
  let mailReplyTo = null;
  let previousUnread = null;

  async function mailApi(action, body = null, query = {}) {
    const params = new URLSearchParams({ action, ...query });
    const response = await fetch(`mail-api.php?${params}`, {
      method: body === null ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: body === null ? {} : { 'Content-Type': 'application/json', 'X-AOV-CSRF': csrf },
      body: body === null ? undefined : JSON.stringify(body)
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Mail is unavailable');
    return data;
  }

  function mailNotice(message) { $('#mail-status').textContent = message; }
  function mailUnread(count) {
    const badge = $('#mail-unread-badge');
    badge.textContent = count ? `(${count} new)` : '▾';
    badge.setAttribute('aria-label', `${count} unread messages`);
    if (previousUnread !== null && count > previousUnread && signedIn) playEffect('mail');
    previousUnread = count;
  }
  function mailView(which) {
    for (const name of ['list', 'read', 'compose', 'directory']) $(`#mail-${name}-view`).hidden = name !== which;
  }
  async function loadMailList() {
    try {
      const data = await mailApi('list', null, { folder: mailFolder, page: mailPage });
      mailMore = data.more;
      mailUnread(data.unread);
      $('#mail-folder-title').textContent = mailFolder === 'inbox' ? 'Inbox' : 'Sent Mail';
      $('#mail-prev').disabled = mailPage === 0;
      $('#mail-next').disabled = !mailMore;
      $('#mail-page').textContent = `Page ${mailPage + 1}`;
      const list = $('#mail-list'); list.replaceChildren();
      if (!data.messages.length) list.textContent = 'No messages here.';
      for (const message of data.messages) {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'mail-row';
        if (mailFolder === 'inbox' && !message.read_at) button.classList.add('unread');
        const person = mailFolder === 'inbox' ? `From: ${message.other_name}` : `To: ${message.other_name}`;
        const meta = document.createElement('span'); meta.textContent = person;
        const subject = document.createElement('strong'); subject.textContent = message.subject;
        const date = document.createElement('time'); date.textContent = new Date(Number(message.created_at) * 1000).toLocaleString();
        button.append(meta, subject, date);
        button.addEventListener('click', () => readMail(Number(message.id)));
        list.append(button);
      }
      mailView('list');
    } catch (error) { mailNotice(error.message); }
  }
  async function readMail(id) {
    try {
      const data = await mailApi('read', { id });
      mailCurrent = data.message;
      mailUnread(data.unread);
      $('#mail-read-subject').textContent = mailCurrent.subject;
      $('#mail-read-meta').textContent = `From ${mailCurrent.sender} to ${mailCurrent.recipient} · ${new Date(Number(mailCurrent.created_at) * 1000).toLocaleString()}`;
      $('#mail-read-body').textContent = mailCurrent.body;
      $('#mail-reply').hidden = mailCurrent.recipient !== previewName;
      $('#mail-report').hidden = mailCurrent.recipient !== previewName;
      mailView('read');
    } catch (error) { mailNotice(error.message); }
  }
  function composeMail(to = '', subject = '', replyTo = null) {
    mailReplyTo = replyTo;
    $('#mail-to').value = to;
    $('#mail-subject').value = subject;
    $('#mail-body').value = '';
    mailView('compose');
    openWindow('mail');
    $('#mail-to').focus();
  }
  async function searchMailMembers() {
    try {
      const data = await mailApi('directory', null, { q: $('#mail-search').value.trim() });
      const list = $('#mail-members'); list.replaceChildren();
      if (!data.members.length) list.textContent = 'No matching members.';
      for (const name of data.members) {
        const button = document.createElement('button'); button.type = 'button';
        button.textContent = `✉ ${name}`;
        button.addEventListener('click', () => composeMail(name));
        list.append(button);
      }
    } catch (error) { mailNotice(error.message); }
  }

  async function chatApi(action, body) {
    const response = await fetch(`chat-api.php?action=${encodeURIComponent(action)}`, {
      method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: body ? { 'Content-Type': 'application/json', 'X-AOV-CSRF': csrf } : {},
      body: body ? JSON.stringify(body) : undefined
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Chat is unavailable');
    return data;
  }

  function chatNotice(message) {
    $('#chat-status').textContent = message;
  }

  function chatPersonButton(name, action, label) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.setAttribute('aria-label', `${label} ${name}`);
    button.addEventListener('click', action);
    return button;
  }

  function renderBuddyList() {
    const onlineNames = new Set(chatState.online.map(person => Number(person.id)));
    const groups = new Map();
    for (const person of chatState.buddies) {
      const group = person.group_name || 'Buddies';
      if (!groups.has(group)) groups.set(group, []);
      groups.get(group).push(person);
    }
    const root = $('#buddy-groups'); root.replaceChildren();
    if (!groups.size) root.textContent = 'No buddies yet. Add a screen name above.';
    for (const [group, people] of groups) {
      const section = document.createElement('div'); section.className = 'buddy-group';
      const heading = document.createElement('strong'); heading.textContent = `${group} (${people.length})`; section.append(heading);
      for (const person of people) {
        const isOnline = onlineNames.has(Number(person.id));
        const isAway = isOnline && Number(person.is_away) === 1;
        const row = document.createElement('div'); row.className = 'buddy-row';
        const label = document.createElement('span'); label.textContent = `${isOnline ? (isAway ? '◐' : '●') : '○'} ${person.username}${isAway ? ' (Away)' : ''}`;
        if (isAway && person.away_message) label.title = person.away_message;
        row.append(label);
        row.append(chatPersonButton(person.username, () => openDirectMessage(person.username), 'IM'));
        row.append(chatPersonButton(person.username, () => composeMail(person.username), 'Mail'));
        row.append(chatPersonButton(person.username, async () => {
          const next = prompt(`Move ${person.username} to which group?`, group);
          if (next !== null) { try { await chatApi('buddy_group_set', { name: person.username, group: next.trim() }); await refreshChatState(); } catch (error) { $('#buddy-status').textContent = error.message; } }
        }, 'Group'));
        row.append(chatPersonButton(person.username, () => changeBuddy('buddy_remove', person.username), 'Remove'));
        section.append(row);
      }
      root.append(section);
    }
    const blocked = $('#buddy-blocked'); blocked.replaceChildren();
    if (!chatState.blockedUsers?.length) blocked.textContent = 'No blocked members.';
    for (const person of chatState.blockedUsers || []) {
      const row = document.createElement('div'); row.className = 'buddy-row';
      const label = document.createElement('span'); label.textContent = person.username;
      row.append(label, chatPersonButton(person.username, () => changeBuddy('unblock', person.username), 'Unblock'));
      blocked.append(row);
    }
    const presence = chatState.presence || {};
    $('#away-mode').value = Number(presence.is_away) ? 'away' : 'online';
    if (document.activeElement !== $('#away-message')) $('#away-message').value = presence.away_message || '';
    $('#my-away-status').textContent = Number(presence.is_away) ? '◐ Away' : '● Signed on';
  }

  function renderChatRooms() {
    const select = $('#chat-room');
    const selected = select.value;
    select.replaceChildren();
    const groups = new Map();
    for (const room of chatState.rooms || []) {
      const category = room.category || 'General';
      if (!groups.has(category)) {
        const group = document.createElement('optgroup'); group.label = category;
        groups.set(category, group); select.append(group);
      }
      const option = document.createElement('option');
      option.value = String(room.id); option.textContent = room.name;
      groups.get(category).append(option);
    }
    if ([...select.options].some(option => option.value === selected)) select.value = selected;
    $('#chat-invite-form').hidden = Number(select.value) !== 500000000 + chatState.userId;
  }

  async function refreshChatState() {
    if (!signedIn || document.hidden) return;
    try {
      chatState = await chatApi('state');
      renderBuddyList();
      renderChatRooms();
      if (imContact) renderImConversation();
      $('#chat-admin').hidden = !chatState.isAdmin;
      if (chatState.isAdmin && !chatFiltersLoaded) { chatFiltersLoaded = true; loadChatFilters(); }
    } catch (error) { $('#buddy-status').textContent = error.message; }
  }

  async function changeBuddy(action, name) {
    try {
      await chatApi(action, { name });
      $('#buddy-status').textContent = action === 'buddy_add' ? `${name} added.` : `${name} updated.`;
      await refreshChatState();
    } catch (error) { $('#buddy-status').textContent = error.message; }
  }

  function openDirectMessage(name) {
    imContact = name;
    openWindow('im');
    renderImConversation();
    $('#im-input').focus();
  }

  function renderImConversation() {
    $('#im-title').textContent = imContact ? `☏ Instant Message — ${imContact}` : '☏ Instant Message';
    const buddy = chatState.buddies.find(person => person.username.toLowerCase() === imContact.toLowerCase());
    $('#im-contact').textContent = buddy && Number(buddy.is_away) && chatState.online.some(person => Number(person.id) === Number(buddy.id))
      ? `${imContact} is away${buddy.away_message ? `: ${buddy.away_message}` : '.'}` : `Talking with ${imContact}`;
    const list = $('#im-messages'); list.replaceChildren();
    for (const message of imConversations.get(imContact.toLowerCase()) || []) {
      const row = document.createElement('p'); row.className = 'chat-message';
      const name = document.createElement('strong'); name.textContent = `${message.sender}: `;
      row.append(name, document.createTextNode(message.text));
      if (message.sender.toLowerCase() !== previewName.toLowerCase()) {
        row.append(chatPersonButton(message.sender, async () => {
          try { await chatApi('report', { messageId: message.id }); $('#im-status').textContent = 'Report sent to AOV moderators.'; }
          catch (error) { $('#im-status').textContent = error.message; }
        }, 'Report'));
        row.append(chatPersonButton(message.sender, () => changeBuddy('block', message.sender), 'Block'));
      }
      list.append(row);
    }
    list.scrollTop = list.scrollHeight;
  }

  function recordImMessage(id, sender, text, recipient = '') {
    if (imSeen.has(id)) return;
    imSeen.add(id);
    if (imSeen.size > 1000) imSeen.delete(imSeen.values().next().value);
    const contact = (recipient || sender).toLowerCase();
    const history = imConversations.get(contact) || [];
    history.push({ id, sender, text });
    if (history.length > 100) history.shift();
    imConversations.set(contact, history);
    if (contact === imContact.toLowerCase() && !windows.get('im').hidden) renderImConversation();
    else if (sender.toLowerCase() !== previewName.toLowerCase()) $('#im-status').textContent = `New instant message from ${sender}.`;
  }

  function decodeChatEntities(text) {
    const entities = { amp: '&', lt: '<', gt: '>', '#39': "'", quot: '"' };
    return text.replace(/&(amp|lt|gt|#39|quot);/g, entity => entities[entity.slice(1, -1)]);
  }

  function renderChatXml(xml) {
    if (xml.querySelector('parsererror')) throw new Error('Chat response could not be read');
    if (xml.querySelector('info[type="logout"]')) { showEntry('Your chat session ended. Please sign on again.'); return; }
    const people = [...xml.querySelectorAll('users > user')].filter(user => !chatState.blockedIds.includes(Number(user.getAttribute('userID'))));
    $('#online-count').textContent = String(people.length);
    const list = $('#chat-online-users'); list.replaceChildren();
    for (const person of people) {
      const name = person.textContent || '';
      const id = Number(person.getAttribute('userID'));
      const row = document.createElement('div'); row.className = 'chat-person';
      const label = document.createElement('span'); label.textContent = `● ${name}`; row.append(label);
      if (id !== chatState.userId) {
        row.append(chatPersonButton(name, () => openDirectMessage(name), 'IM'));
        row.append(chatPersonButton(name, () => changeBuddy('buddy_add', name), '+'));
        row.append(chatPersonButton(name, async () => { await changeBuddy('block', name); chatNotice(`${name} blocked.`); }, 'Block'));
      }
      list.append(row);
    }
    const messages = $('#chat-messages');
    const oldLastId = chatLastId;
    if (messages.firstElementChild?.textContent === 'Connecting to chat…') messages.replaceChildren();
    let notified = false;
    for (const message of xml.querySelectorAll('messages > message')) {
      const id = Number(message.getAttribute('id'));
      chatLastId = Math.max(chatLastId, id);
      const userId = Number(message.getAttribute('userID'));
      if (chatState.blockedIds.includes(userId)) continue;
      let body = decodeChatEntities(message.querySelector('text')?.textContent || '');
      const sender = decodeChatEntities(message.querySelector('username')?.textContent || 'Member');
      if (body.startsWith('/privmsgto ')) {
        const match = /^\/privmsgto ([A-Za-z][A-Za-z0-9_]{2,23}) (.*)$/s.exec(body);
        if (match) recordImMessage(id, sender, match[2], match[1]);
        continue;
      }
      if (body.startsWith('/privmsg ')) {
        recordImMessage(id, sender, body.slice(9));
        if (oldLastId > 0 && id > oldLastId) notified = true;
        continue;
      }
      if (body.startsWith('/') && !body.startsWith('/privmsg')) continue;
      const row = document.createElement('p'); row.className = 'chat-message';
      const author = document.createElement('strong'); author.textContent = `${sender}: `;
      row.append(author, document.createTextNode(body));
      if (userId !== chatState.userId && userId < 500000000) {
        row.append(chatPersonButton(author.textContent.trim(), async () => {
          try { await chatApi('report', { messageId: id }); chatNotice('Report sent to the AOV moderators.'); }
          catch (error) { chatNotice(error.message); }
        }, 'Report'));
      }
      messages.append(row);
      if (oldLastId > 0 && id > oldLastId && userId !== chatState.userId) notified = true;
    }
    if (notified) playEffect('message');
    while (messages.childElementCount > 100) messages.firstElementChild.remove();
    messages.scrollTop = messages.scrollHeight;
  }

  async function pollChat(text = null) {
    if (!signedIn || chatBusy || (text === null && (document.hidden || (windows.get('chat').hidden && windows.get('im').hidden && windows.get('buddies').hidden)))) return;
    chatBusy = true;
    try {
      const query = new URLSearchParams({ ajax: '1', channelID: $('#chat-room').value, lastID: String(chatLastId) });
      const response = await fetch(`chat-engine/?${query}`, {
        method: text === null ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: text === null ? { 'X-AOV-CSRF': csrf } : { 'X-AOV-CSRF': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: text === null ? undefined : new URLSearchParams({ text })
      });
      if (response.status === 401) { showEntry('Your session ended. Please sign on again.'); return; }
      if (!response.ok) throw new Error('Chat request failed');
      renderChatXml(new DOMParser().parseFromString(await response.text(), 'application/xml'));
      if (text !== null) {
        const notice = response.headers.get('X-AOV-Chat-Notice') || 'Message sent.';
        chatNotice(notice);
        if (notice === 'Message sent.') playEffect('sent');
      }
    } catch (error) { chatNotice(error.message); }
    finally { chatBusy = false; }
  }

  function setPreviewName(name) {
    previewName = name;
    $$('.sample-name').forEach(el => {
      if (el.dataset.buddy) el.dataset.buddy = name;
      el.textContent = el.dataset.buddy ? `● ${name}` : name;
    });
    $('#account-name').textContent = name;
    $('#profile-name').value = name;
  }

  async function api(action, body = {}) {
    const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-AOV-CSRF': csrf },
      body: JSON.stringify(body)
    });
    const data = await response.json();
    if (data.csrf) csrf = data.csrf;
    if (!response.ok) throw new Error(data.error || 'Please try again');
    return data;
  }

  function showEntry(message = '') {
    $('#channel-view-body').querySelector('.channel-radio-player audio')?.pause();
    signedIn = false;
    connectionPending = false;
    connectionSound?.stop();
    connectionSound = null;
    stopEffects();
    mailUnread(0);
    $('#admin-link').hidden = true;
    chatLastId = 0;
    chatState = { userId: 0, buddies: [], blockedIds: [], online: [] };
    imConversations.clear(); imSeen.clear(); imContact = '';
    $('#app').hidden = true;
    $('#connecting').hidden = true;
    $('#entry').hidden = false;
    switchEntry('login');
    $('#login-message').textContent = message;
  }

  function connectionStage(label, progress, frame) {
    $('#connection-status').textContent = label;
    $('#connection-progress').value = progress;
    $$('.connection-frame').forEach((image, index) => image.classList.toggle('active', index === frame));
  }

  function startConnection() {
    connectionPending = true;
    connectionSound?.stop();
    stopEffects();
    $('#connection-detail').textContent = 'Contacting AOV and checking your account.';
    connectionStage('Connecting to AOV…', 1, 0);
    $('#entry').hidden = true;
    $('#app').hidden = true;
    $('#connecting').hidden = false;
    connectionSound = playConnectionSound();
    requestAnimationFrame(() => { if (connectionPending) connectionStage('Authenticating…', 2, 1); });
  }

  async function showAccount(state) {
    connectionPending = false;
    signedIn = true;
    $('#admin-link').hidden = !state.isAdmin;
    lastActivity = Date.now();
    setPreviewName(state.name || 'Member');
    $('#account-email').textContent = state.email || '';
    $('#profile-zip').value = state.zipCode || '';
    loadNews();
    connectionStage('Loading preferences…', 3, 2);
    $('#connection-detail').textContent = 'Your AOV account is signed on.';
    await Promise.all([loadBrowserData(), loadMailList()]);
    $('#connection-detail').textContent = 'Finishing the modem handshake…';
    await connectionSound?.finished;
    if (!signedIn) return;
    connectionStage('Welcome to AOV!', 4, 2);
    $('#connection-detail').textContent = 'Connected.';
    await enterAov();
    if (signedIn) playEffect('welcome');
  }

  async function enterAov() {
    try {
      const response = await fetch('api.php?action=status', { credentials: 'same-origin', cache: 'no-store' });
      const state = await response.json();
      if (!state.loggedIn) { showEntry('Your session ended. Please sign on again.'); return; }
      $('#connecting').hidden = true;
      $('#app').hidden = false;
      openWindow('welcome');
    } catch { showEntry('Connection check failed. Please sign on again.'); }
  }

  function playConnectionSound() {
    // Keep the connection screen up for the full 19-second generated sound,
    // even if the browser mutes or cannot load the local clip.
    let clip = null;
    let elapsed = false;
    let playbackComplete = !$('#sound-enabled').checked || !Number($('#sound-volume').value);
    let done = false;
    let finish;
    const finished = new Promise(resolve => { finish = resolve; });
    const complete = () => {
      if (done || !elapsed || !playbackComplete) return;
      done = true;
      clearTimeout(watchdog);
      finish();
    };
    const minimum = setTimeout(() => { elapsed = true; complete(); }, connectionDurationMs);
    const watchdog = setTimeout(() => {
      clip?.pause();
      playbackComplete = true;
      complete();
    }, connectionDurationMs + 7000);
    if (!playbackComplete && window.Audio) {
      clip = new Audio(dialupSoundUrl);
      clip.volume = Number($('#sound-volume').value) / 100;
      clip.preload = 'auto';
      const playbackDone = () => { playbackComplete = true; complete(); };
      const playbackFailed = () => { clip.pause(); playbackDone(); };
      clip.addEventListener('ended', playbackDone, { once: true });
      clip.addEventListener('error', playbackFailed, { once: true });
      try { clip.play().catch(playbackFailed); } catch { playbackFailed(); }
    } else {
      playbackComplete = true;
    }
    return {
      finished,
      stop() {
        if (done) return;
        done = true;
        clearTimeout(minimum);
        clearTimeout(watchdog);
        clip?.pause();
        finish();
      }
    };
  }
  function recordActivity() {
    if (!signedIn || $('#app').hidden) return;
    lastActivity = Date.now();
    if (lastActivity - lastCheck > 120000) {
      lastCheck = lastActivity;
      fetch('api.php?action=status', { credentials: 'same-origin', cache: 'no-store' })
        .then(response => response.json())
        .then(state => { if (!state.loggedIn) showEntry('Your session ended. Please sign on again.'); })
        .catch(() => {});
    }
  }

  function stopEffects() {
    for (const clip of activeEffects) clip.pause();
    activeEffects.clear();
  }

  function playEffect(name) {
    if (!$('#sound-enabled').checked || !Number($('#sound-volume').value) || !window.Audio) return;
    const url = eventSoundUrls[name];
    if (!url) return;
    stopEffects();
    const clip = new Audio(url);
    clip.volume = Number($('#sound-volume').value) / 100;
    activeEffects.add(clip);
    let finished = false;
    const timeout = setTimeout(fail, 8000);
    function cleanup() {
      if (finished) return;
      finished = true;
      clearTimeout(timeout);
      activeEffects.delete(clip);
    }
    function fail() {
      if (finished) return;
      clip.pause();
      cleanup();
    }
    clip.addEventListener('playing', () => clearTimeout(timeout), { once: true });
    clip.addEventListener('ended', cleanup, { once: true });
    clip.addEventListener('error', fail, { once: true });
    try { clip.play().catch(fail); } catch { fail(); }
  }
  function renderTaskbar() {
    const list = $('#task-buttons');
    list.replaceChildren();
    for (const [name, el] of windows) {
      if (el.hidden) continue;
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = windowLabels[name];
      button.classList.toggle('active', name === active);
      button.addEventListener('click', () => openWindow(name));
      list.append(button);
    }
    $$('.toolbar [data-open]').forEach(button => button.setAttribute('aria-current', button.dataset.open === active ? 'page' : 'false'));
  }

  function openWindow(name) {
    const el = windows.get(name);
    if (!el) return;
    el.hidden = false;
    el.style.zIndex = String(++z);
    $$('.desktop-window').forEach(win => win.classList.toggle('active', win === el));
    active = name;
    renderTaskbar();
    if (signedIn && name === 'chat') refreshChatState().then(() => pollChat());
    if (signedIn && name === 'im') refreshChatState().then(() => pollChat());
    if (signedIn && name === 'buddies') refreshChatState().then(() => pollChat());
    if (signedIn && name === 'mail' && !$('#mail-compose-view').hidden) { /* preserve a draft */ }
    else if (signedIn && name === 'mail') loadMailList();
  }

  function minimizeWindow(name) {
    const el = windows.get(name);
    if (name === 'channel-view') el.querySelector('.channel-radio-player audio')?.pause();
    el.hidden = true;
    const next = [...windows].reverse().find(([, win]) => !win.hidden);
    if (next) openWindow(next[0]);
    else { active = ''; renderTaskbar(); }
  }

  function switchEntry(tab) {
    for (const name of ['login', 'register', 'recovery', 'reset']) $(`#${name}-form`).hidden = name !== tab;
    $('#login-tab').setAttribute('aria-selected', String(tab === 'login'));
    $('#register-tab').setAttribute('aria-selected', String(tab === 'register'));
  }

  function normalizeUrl(value, canonicalNpr = true) {
    const raw = value.trim();
    if (!raw) return null;
    try {
      const url = new URL(/^https?:\/\//i.test(raw) ? raw : `https://${raw}`);
      if (canonicalNpr && ['npr.com', 'www.npr.com', 'npr.org'].includes(url.hostname)) {
        url.protocol = 'https:';
        url.hostname = 'www.npr.org';
      }
      return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url : null;
    } catch { return null; }
  }

  function archiveTargetTimestamp() {
    const today = new Date();
    const year = today.getFullYear() - 30;
    const month = today.getMonth();
    const day = Math.min(today.getDate(), new Date(year, month + 1, 0).getDate());
    return `${year}${String(month + 1).padStart(2, '0')}${String(day).padStart(2, '0')}000000`;
  }
  function archiveDate(timestamp) {
    return `${timestamp.slice(0, 4)}-${timestamp.slice(4, 6)}-${timestamp.slice(6, 8)}`;
  }
  function isArchiveReplay(url) {
    return url.protocol === 'https:' && url.hostname === 'web.archive.org'
      && /^\/web\/\d{14}(?:id_|if_)?\/https?:\/\//.test(url.pathname);
  }
  let archiveLookupId = 0;
  function currentPage() {
    return browserSession.entries[browserSession.index];
  }

  function renderBrowserPage(launchExternal = false) {
    const page = currentPage();
    const url = page.url ? new URL(page.url) : null;
    $('#global-address').value = url?.href || '';
    $('#global-back').disabled = browserSession.index <= 0;
    $('#global-forward').disabled = browserSession.index >= browserSession.entries.length - 1;
    $('#global-bookmark').disabled = !url;
    $('#global-bookmark').textContent = url && bookmarks.some(item => item.url === url.href) ? '★' : '☆';
    $('#browser-home-page').hidden = Boolean(url);
    $('#browser-news').hidden = true;
    $('#browser-embedded').hidden = true;
    $('#browser-external').hidden = true;
    $('#news-frame').removeAttribute('src');
    if (!url) {
      $('#browser-status').textContent = 'AOV Home · Ready';
    } else if (isArchiveReplay(url)) {
      const timestamp = url.pathname.match(/^\/web\/(\d{14})/)[1];
      $('#browser-embedded').hidden = false;
      $('#embedded-title').textContent = page.title;
      $('#embedded-external').href = url.href.replace(/(\/web\/\d{14})if_\//, '$1/');
      $('#embedded-external').textContent = 'Open in Wayback ↗';
      $('#news-frame').title = `Archived ${page.title}`;
      $('#news-frame').src = url.href;
      $('#browser-status').textContent = page.archiveNote || `Wayback capture: ${archiveDate(timestamp)}. Links inside the archive may not update AOV’s address bar.`;
    } else if (url.protocol === 'https:' && url.hostname === 'wiby.me') {
      $('#browser-embedded').hidden = false;
      $('#embedded-title').textContent = page.title;
      $('#embedded-external').href = url.href;
      $('#embedded-external').textContent = 'Open on Wiby ↗';
      $('#news-frame').title = 'Wiby search in AOV browser';
      $('#news-frame').src = url.href;
      $('#browser-status').textContent = 'Wiby search in AOV. Some result websites may require a regular browser tab.';
    } else if (url.protocol === 'https:' && url.hostname === 'www.npr.org' && url.pathname === '/sections/news/') {
      $('#browser-news').hidden = false;
      $('#browser-status').textContent = 'NPR headlines · Choose a story to update the AOV address bar.';
    } else if (url.protocol === 'https:' && url.hostname === 'www.npr.org') {
      $('#browser-embedded').hidden = false;
      $('#embedded-title').textContent = page.title;
      $('#embedded-external').href = url.href;
      $('#embedded-external').textContent = 'Open on NPR ↗';
      $('#news-frame').title = 'NPR news in AOV browser';
      $('#news-frame').src = url.href;
      $('#browser-status').textContent = `Showing ${page.title} inside AOV. Links inside the article cannot update AOV’s address bar; use Open on NPR for full browsing.`;
    } else {
      $('#browser-external').hidden = false;
      $('#external-title').textContent = `${url.hostname} cannot open inside AOV`;
      $('#external-explanation').textContent = ['mattbullis.com', 'home.mattbullis.com'].includes(url.hostname)
        ? 'This website blocks embedded viewing. Tap the button below to open it in a regular browser tab.'
        : url.hostname === 'google.com' || url.hostname === 'www.google.com'
          ? 'Google blocks embedded viewing. Tap the button below to open it in a regular browser tab.'
          : 'This website is not available in AOV’s embedded view. Tap the button below to open it in a regular browser tab.';
      $('#external-link').href = url.href;
      $('#external-link').textContent = `Open ${url.hostname} in a new tab ↗`;
      if (launchExternal) {
        window.open(url.href, '_blank', 'noopener,noreferrer');
        $('#browser-status').textContent = `If the new tab was blocked, use the button to open ${url.hostname}.`;
      } else {
        $('#browser-status').textContent = `${url.hostname} opens in a normal browser tab.`;
      }
    }
  }

  function navigateBrowser(direction) {
    archiveLookupId++;
    const next = browserSession.index + direction;
    if (next < 0 || next >= browserSession.entries.length) return;
    browserSession.index = next;
    renderBrowserPage();
  }
  function appendBrowserEntry(page, launchExternal = false) {
    browserSession.entries = browserSession.entries.slice(0, browserSession.index + 1);
    browserSession.entries.push(page);
    browserSession.index = browserSession.entries.length - 1;
    renderBrowserPage(launchExternal);
  }

  let browserMutation = Promise.resolve();
  function saveBrowser(action, body) {
    browserMutation = browserMutation.catch(() => {}).then(() => api(action, body));
    return browserMutation.then(data => { updateBrowserData(data); return true; }).catch(error => { $('#browser-status').textContent = error.message; return false; });
  }
  function visit(value, title = '') {
    archiveLookupId++;
    openWindow('browser');
    const url = normalizeUrl(value);
    if (!url) { $('#browser-status').textContent = 'Enter a valid HTTP or HTTPS address.'; return; }
    const label = title || url.hostname;
    appendBrowserEntry({ url: url.href, title: label }, true);
    saveBrowser('browser_visit', { url: url.href, title: label });
  }
  async function visitArchive(value) {
    openWindow('browser');
    const url = normalizeUrl(value, false);
    if (!url) { $('#browser-status').textContent = 'Enter a valid HTTP or HTTPS address.'; return; }
    if (isArchiveReplay(url)) { visit(url.href, url.hostname); return; }
    const lookupId = ++archiveLookupId;
    const original = new URL(url.href);
    original.protocol = 'http:';
    const requested = archiveTargetTimestamp();
    let replay = `https://web.archive.org/web/${requested}if_/${original.href}`;
    let shownDate = `near ${archiveDate(requested)}`;
    let note = `Requested a Wayback capture near ${archiveDate(requested)}. The archive may select another date.`;
    $('#browser-status').textContent = `Finding a ${archiveDate(requested)} capture of ${url.hostname}…`;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 7000);
    try {
      const params = new URLSearchParams({ url: original.href, timestamp: requested });
      const response = await fetch(`https://archive.org/wayback/available?${params}`, { signal: controller.signal });
      if (!response.ok) throw new Error('Archive lookup unavailable');
      const capture = (await response.json()).archived_snapshots?.closest;
      if (capture?.available && /^\d{14}$/.test(capture.timestamp)) {
        const archivedUrl = new URL(capture.url);
        archivedUrl.protocol = 'https:';
        if (isArchiveReplay(archivedUrl)) {
          archivedUrl.pathname = archivedUrl.pathname.replace(/^(\/web\/\d{14})(?:id_|if_)?\//, '$1if_/');
          replay = archivedUrl.href;
          shownDate = archiveDate(capture.timestamp);
          note = `Wayback capture: ${archiveDate(capture.timestamp)} (requested ${archiveDate(requested)}). Links inside the archive may not update AOV’s address bar.`;
        }
      }
    } catch { /* The direct Wayback URL can still find a nearby capture. */ }
    finally { clearTimeout(timeout); }
    if (lookupId !== archiveLookupId) return;
    const title = `${url.hostname} · ${shownDate}`;
    appendBrowserEntry({ url: replay, title, archiveNote: note });
    saveBrowser('browser_visit', { url: replay, title });
  }
  function goBrowserHome() {
    archiveLookupId++;
    if (homeUrl) { visit(homeUrl, 'Home Page'); return; }
    openWindow('browser');
    appendBrowserEntry({ url: null, title: 'AOV Home' });
  }

  function renderBrowserList(selector, entries, empty) {
    const list = $(selector);
    list.replaceChildren();
    if (!entries.length) { list.textContent = empty; return; }
    for (const entry of entries) {
      const row = document.createElement('span');
      row.className = 'browser-saved-row';
      const open = document.createElement('button');
      open.type = 'button';
      open.textContent = entry.title;
      open.title = entry.url;
      open.addEventListener('click', () => visit(entry.url, entry.title));
      row.append(open);
      if (selector === '#bookmark-list') {
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = '×';
        remove.setAttribute('aria-label', `Remove ${entry.title} bookmark`);
        remove.addEventListener('click', () => saveBrowser('browser_bookmark_remove', { id: Number(entry.id) }));
        row.append(remove);
      }
      list.append(row);
    }
  }
  function updateBrowserData(data) {
    homeUrl = data.homeUrl || '';
    bookmarks = data.bookmarks || [];
    recentHistory = data.history || [];
    $('#home-url').value = homeUrl;
    renderBrowserList('#bookmark-list', bookmarks, 'No bookmarks saved.');
    renderBrowserList('#history-list', recentHistory, 'No visits saved.');
    $('#clear-history').disabled = recentHistory.length === 0;
    const page = currentPage();
    $('#global-bookmark').textContent = page.url && bookmarks.some(item => item.url === page.url) ? '★' : '☆';
  }
  async function loadBrowserData() {
    try {
      const response = await fetch('api.php?action=browser_state', { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('Browser data is unavailable');
      updateBrowserData(await response.json());
    } catch (error) { $('#browser-status').textContent = error.message; }
  }

  function makeChannels() {
    const buttonArtwork = {
      aoltoday: 'aov-today-banner.webp',
      sports: 'sports-channel-banner.webp',
      influence: 'influence-channel-banner.webp',
      travel: 'travel-channel-banner.webp',
      international: 'international-channel-banner.webp',
      personalfinance: 'personal-finance-channel-banner.webp',
      workplace: 'workplace-channel-banner.webp',
      computing: 'computing-channel-banner.webp',
      researchandlearn: 'research-and-learn-channel-banner.webp',
      entertainment: 'entertainment-channel-banner.webp',
      interests: 'interests-channel-banner.webp',
      lifestyles: 'lifestyles-channel-banner.webp',
      health: 'health-channel-banner.webp',
      families: 'families-channel-banner.webp'
    };
    const columns = {
      'channel-search': ['search'],
      'channel-left': ['aoltoday', 'news', 'sports'],
      'channel-middle': ['influence', 'travel', 'international', 'personalfinance', 'workplace', 'computing', 'researchandlearn', 'entertainment', 'music'],
      'channel-right': ['games', 'interests', 'lifestyles', 'shopping', 'health', 'families', 'kidsonly', 'local', 'digital-cities']
    };
    Object.entries(columns).forEach(([id, images]) => {
      const column = $(`#${id}`);
      images.forEach(image => {
        const title = image === 'digital-cities' ? 'Digital Cities' : channelTitles[image];
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.channel = image === 'digital-cities' ? 'local' : image;
        if (image === 'digital-cities') button.dataset.path = 'digital-cities';
        button.title = `Open ${title} channel`;
        button.setAttribute('aria-label', title);
        button.className = `channel-art-button channel-art-button--${image}`;
        const artwork = buttonArtwork[image]
          ? `assets/${buttonArtwork[image]}`
          : `assets/channels/scenes/${image}.svg`;
        button.style.setProperty('--button-art', `url("${artwork}")`);
        const label = document.createElement('span');
        label.className = 'channel-art-label';
        label.textContent = title;
        button.append(label);
        column.append(button);
      });
    });
  }

  function channelButton(label, action, value, className = '') {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.textContent = label;
    button.setAttribute(`data-${action}`, value);
    return button;
  }

  function channelNavButton(label, icon, action, value) {
    const button = channelButton(label, action, value, 'channel-nav-link');
    button.replaceChildren();
    const symbol = document.createElement('span');
    symbol.className = 'channel-nav-icon';
    symbol.setAttribute('aria-hidden', 'true');
    symbol.textContent = icon;
    const text = document.createElement('span');
    text.textContent = label;
    button.append(symbol, text);
    return button;
  }

  function channelSafeUrl(value) {
    try {
      const url = new URL(value);
      return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : null;
    } catch { return null; }
  }

  function normalizeKeyword(value) {
    return String(value || '').trim().replace(/\s+/g, ' ').toLocaleUpperCase();
  }

  function keywordMatches(value) {
    const query = normalizeKeyword(value);
    if (!query) return [];
    const matches = [];
    for (const entry of keywords) {
      const names = [entry.keyword, ...(Array.isArray(entry.aliases) ? entry.aliases : [])];
      const match = names.find(name => normalizeKeyword(name).includes(query));
      if (!match) continue;
      const rank = names.some(name => normalizeKeyword(name) === query) ? 0
        : names.some(name => normalizeKeyword(name).startsWith(query)) ? 1 : 2;
      matches.push({ entry, match, rank });
    }
    return matches.sort((a, b) => a.rank - b.rank || a.entry.keyword.localeCompare(b.entry.keyword)).slice(0, 8);
  }

  function hideKeywordSuggestions() {
    const list = $('#keyword-suggestions');
    list.hidden = true;
    list.replaceChildren();
    $('#global-address').setAttribute('aria-expanded', 'false');
    $('#global-address').removeAttribute('aria-activedescendant');
    keywordChoices = [];
    activeKeywordChoice = -1;
  }

  function renderKeywordSuggestions() {
    const input = $('#global-address');
    const list = $('#keyword-suggestions');
    keywordChoices = keywordMatches(input.value);
    activeKeywordChoice = -1;
    list.replaceChildren();
    for (const [index, choice] of keywordChoices.entries()) {
      const option = document.createElement('button');
      option.type = 'button';
      option.id = `keyword-option-${index}`;
      option.className = 'keyword-option';
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');
      const name = document.createElement('strong'); name.textContent = choice.match;
      const destination = document.createElement('span'); destination.textContent = choice.entry.label;
      option.append(name, destination);
      option.addEventListener('click', () => runKeyword(choice.entry));
      list.append(option);
    }
    list.hidden = keywordChoices.length === 0;
    input.setAttribute('aria-expanded', String(keywordChoices.length > 0));
  }

  function moveKeywordChoice(direction) {
    if (!keywordChoices.length) return;
    activeKeywordChoice = (activeKeywordChoice + direction + keywordChoices.length) % keywordChoices.length;
    const input = $('#global-address');
    $$('.keyword-option', $('#keyword-suggestions')).forEach((option, index) => {
      option.setAttribute('aria-selected', String(index === activeKeywordChoice));
    });
    const id = `keyword-option-${activeKeywordChoice}`;
    input.setAttribute('aria-activedescendant', id);
    document.getElementById(id)?.scrollIntoView({ block: 'nearest' });
  }

  async function runKeyword(entry) {
    hideKeywordSuggestions();
    $('#global-address').setCustomValidity('');
    $('#global-address').value = entry.keyword;
    if (entry.type === 'window' && Object.hasOwn(windowLabels, entry.window)) {
      openWindow(entry.window);
    } else if (entry.type === 'chatroom' && typeof entry.roomName === 'string') {
      openChatRoomByName(entry.roomName);
    } else if (entry.type === 'channel' && Object.hasOwn(channelTitles, entry.channel)) {
      await showChannel(entry.channel, Array.isArray(entry.path) ? entry.path : []);
      if (Number.isInteger(entry.section)) {
        document.getElementById(`channel-section-${entry.section}`)?.scrollIntoView({ block: 'start' });
      }
    }
  }

  function goKeywordOrAddress() {
    const input = $('#global-address');
    const raw = input.value.trim();
    if (!raw) return;
    const exact = keywords.find(entry => [entry.keyword, ...(entry.aliases || [])]
      .some(name => normalizeKeyword(name) === normalizeKeyword(raw)));
    if (exact) { runKeyword(exact); return; }
    if (/^https?:\/\/\S+$/i.test(raw) || /^[^\s@/]+\.[a-z]{2,}(?:[/:?#]|$)/i.test(raw)) {
      hideKeywordSuggestions();
      visitArchive(raw);
      return;
    }
    input.setCustomValidity('No AOV keyword matches. Choose a suggestion or enter a web address.');
    input.reportValidity();
  }

  fetch('keywords.json', { cache: 'no-store' })
    .then(response => response.ok ? response.json() : [])
    .then(entries => {
      if (!Array.isArray(entries)) return;
      keywords = entries.filter(entry => entry && typeof entry.keyword === 'string'
        && ((entry.type === 'channel' && Object.hasOwn(channelTitles, entry.channel))
          || (entry.type === 'window' && Object.hasOwn(windowLabels, entry.window))
          || (entry.type === 'chatroom' && typeof entry.roomName === 'string')));
      if (document.activeElement === $('#global-address')) renderKeywordSuggestions();
    })
    .catch(() => {});

  function localLink(label, url) {
    const button = channelButton(label + ' ↗', 'url', url);
    button.dataset.topic = label;
    return button;
  }

  async function loadLocalChannel(block, section, request) {
    const content = document.createElement('div');
    content.className = 'local-channel-content';
    content.textContent = 'Finding your local area…';
    block.append(content);
    try {
      const cityId = typeof section.city === 'string' && /^[a-z][a-z0-9-]*$/.test(section.city) ? section.city : '';
      const response = await fetch(`local.php${cityId ? `?city=${encodeURIComponent(cityId)}` : ''}`, { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (request !== channelRequest || !block.isConnected) return;
      content.replaceChildren();
      if (data.needsZip) {
        const message = document.createElement('p');
        message.textContent = 'Add your five-digit ZIP code in Settings to see your local area.';
        content.append(message);
        return;
      }
      if (!response.ok) throw new Error(data.error || 'Local information is unavailable.');
      const place = document.createElement('h4');
      place.textContent = `${data.city}, ${data.region}${data.zipCode ? ` · ${data.zipCode}` : ''}`;
      content.append(place);
      const mode = section.mode || 'home';
      if (mode === 'home' || mode === 'weather') {
        const weather = document.createElement('p');
        weather.className = 'local-weather';
        weather.textContent = data.weather
          ? `🌤️ Current weather: ${data.weather.temperatureF}°F · ${data.weather.condition}`
          : 'Current weather is temporarily unavailable.';
        content.append(weather);
        const forecast = channelSafeUrl(data.forecastUrl || `https://forecast.weather.gov/zipcity.php?inputstring=${encodeURIComponent(data.zipCode)}`);
        if (forecast) content.append(localLink('Detailed forecast and alerts', forecast));
      }
      if (mode === 'home' || mode === 'news') {
        const heading = document.createElement('h4'); heading.textContent = 'Nearby Headlines'; content.append(heading);
        const list = document.createElement('ul'); list.className = 'local-headlines';
        for (const item of data.items || []) {
          const url = channelSafeUrl(item.url); if (!url) continue;
          const row = document.createElement('li'); row.append(localLink(String(item.title || 'Local story'), url)); list.append(row);
        }
        if (!list.children.length) {
          const row = document.createElement('li'); row.textContent = 'No recent nearby headlines found.'; list.append(row);
        }
        content.append(list);
        const more = channelSafeUrl(data.newsUrl); if (more) content.append(localLink('Search for more area news', more));
        const note = document.createElement('small'); note.textContent = 'Search results may also include stories from nearby communities.'; content.append(note);
      }
      if (mode === 'home' || mode === 'places' || mode === 'resources') {
        const latitude = Number(data.latitude);
        const longitude = Number(data.longitude);
        if (Number.isFinite(latitude) && Number.isFinite(longitude)
            && latitude >= 18 && latitude <= 72 && longitude >= -170 && longitude <= -50) {
          const mapHeading = document.createElement('h4'); mapHeading.textContent = 'Your Area Map'; content.append(mapHeading);
          const map = document.createElement('iframe');
          map.className = 'local-area-map';
          map.title = `Map near ${data.city}, ${data.region}`;
          map.loading = 'lazy';
          map.referrerPolicy = 'strict-origin-when-cross-origin';
          const bbox = [longitude - 0.08, latitude - 0.05, longitude + 0.08, latitude + 0.05];
          map.src = `https://www.openstreetmap.org/export/embed.html?bbox=${encodeURIComponent(bbox.join(','))}&marker=${encodeURIComponent(`${latitude},${longitude}`)}`;
          content.append(map);
        }
        const heading = document.createElement('h4'); heading.textContent = mode === 'resources' ? 'Community Services' : 'Find Nearby'; content.append(heading);
        const grid = document.createElement('div'); grid.className = 'local-places';
        const entries = Array.isArray(section.items) && section.items.length ? section.items : [
          { label: 'City hall', query: 'city hall' }, { label: 'County offices', query: 'county offices' },
          { label: 'Libraries', query: 'public libraries' }, { label: 'Community events', query: 'community events' }
        ];
        for (const item of entries) {
          if (typeof item.query !== 'string' || typeof item.label !== 'string') continue;
          const query = `${item.query.slice(0, 80)} near ${data.placeQuery || data.zipCode}`;
          grid.append(localLink(`${item.label} on Google Maps`, `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`));
        }
        content.append(grid);
        const note = document.createElement('small');
        note.textContent = 'Map searches open in a regular browser tab. Check each listing for current hours and availability.';
        content.append(note);
      }
      const credit = document.createElement('small'); credit.className = 'local-credit';
      credit.textContent = `Weather: Open-Meteo · Location: ${data.locationSource || 'Open-Meteo'} · Headlines: Google News RSS`;
      content.append(credit);
    } catch (error) {
      if (request === channelRequest && block.isConnected) content.textContent = error.message || 'Local information is temporarily unavailable.';
    }
  }

  function addDigitalCities(config, directory) {
    if (!Array.isArray(directory.groups)) return;
    const cityLink = (group, city) => ({ type: 'channel', label: city.title,
      channel: 'local', path: ['digital-cities', group.id, city.id] });
    const groups = directory.groups.filter(group => /^[a-z][a-z0-9-]*$/.test(group.id || '') && Array.isArray(group.cities));
    const digital = { id: 'digital-cities', title: 'Digital Cities', icon: '🏙️',
      description: 'Local headlines, weather, maps, and places for cities across North America.',
      sections: groups.map(group => ({ type: 'links', title: group.title,
        items: group.cities.map(city => cityLink(group, city)) })),
      children: groups.map(group => ({ id: group.id, title: group.title, icon: group.icon || '🏙️',
        description: `Explore ${group.title} communities.`,
        sections: [{ type: 'links', title: `${group.title} Cities`,
          items: group.cities.map(city => cityLink(group, city)) }],
        children: group.cities.filter(city => /^[a-z][a-z0-9-]*$/.test(city.id || '')).map(city => ({
          id: city.id, title: city.title, icon: '📍',
          description: `Local headlines, weather, and useful places near ${city.city}, ${city.region}.`,
          sections: [{ type: 'local', mode: 'home', title: `Around ${city.title}`, city: city.id,
            items: [{ label: 'Libraries', query: 'public libraries' },
              { label: 'Parks', query: 'parks' }, { label: 'Community events', query: 'community events' },
              { label: 'Town offices', query: 'local government offices' }] }],
          children: [], crosslinks: [{ channel: 'news', label: 'News', icon: '📰' },
            { channel: 'travel', label: 'Travel', icon: '🧳' }]
        }))
      }))
    };
    config.children.push(digital);
    config.sections.push({ type: 'links', title: 'Digital Cities', navLabel: 'Cities', icon: '🏙️',
      items: [{ type: 'channel', label: 'Explore Digital Cities', channel: 'local',
        path: ['digital-cities'], image: 'assets/channels/digital-cities.svg' },
      ...groups.map(group => ({ type: 'channel', label: group.title, channel: 'local',
        path: ['digital-cities', group.id] }))] });
  }

  async function showChannel(id, path = []) {
    if (!Object.hasOwn(channelTitles, id)) return;
    $('#channel-view-body').querySelector('.channel-radio-player audio')?.pause();
    const request = ++channelRequest;
    openWindow('channel-view');
    $('#channel-view-status').textContent = 'Loading channel…';
    try {
      const response = await fetch(`channel-configs/${id}.json`, { cache: 'no-store' });
      if (!response.ok) throw new Error('Channel configuration is unavailable.');
      const config = await response.json();
      if (request !== channelRequest) return;
      if (config.id !== id || !Array.isArray(config.children) || !Array.isArray(config.sections)) throw new Error('Channel configuration is invalid.');
      if (id === 'local') {
        const citiesResponse = await fetch('channel-configs/digital-cities.json', { cache: 'no-store' });
        if (!citiesResponse.ok) throw new Error('Digital Cities directory is unavailable.');
        const directory = await citiesResponse.json();
        if (request !== channelRequest) return;
        addDigitalCities(config, directory);
      }
      let node = config;
      const cleanPath = [];
      let parentTitle = '';
      for (const slug of path.slice(0, 8)) {
        if (!Array.isArray(node.children)) break;
        const child = node.children.find(item => item.id === slug);
        if (!child) break;
        parentTitle = String(node.title || config.title);
        cleanPath.push(slug);
        node = child;
      }
      currentChannel = { id, path: cleanPath, config };
      const title = String(node.title || config.title || channelTitles[id]);
      windowLabels['channel-view'] = title;
      renderTaskbar();
      $('#channel-view-title').textContent = `▦ AOV ${title}`;
      $('#channel-view-heading').textContent = title;
      $('#channel-view-description').textContent = String(node.description || '');
      const logo = $('#channel-view-logo');
      logo.src = typeof config.logo === 'string' && /^assets\/[a-z0-9/-]+\.png$/.test(config.logo)
        ? config.logo : `assets/channels/${id}.svg`;
      logo.alt = `${channelTitles[id]} channel artwork`;
      $('.channel-view-header').classList.toggle('channel-view-header--news', id === 'news');
      $('.channel-view-header').classList.toggle('channel-view-header--sports', id === 'sports');
      $('.channel-view-header').classList.toggle('channel-view-header--today', id === 'aoltoday');
      $('.channel-view-header').classList.toggle('channel-view-header--influence', id === 'influence');
      $('.channel-view-header').classList.toggle('channel-view-header--travel', id === 'travel');
      $('.channel-view-header').classList.toggle('channel-view-header--international', id === 'international');
      $('.channel-view-header').classList.toggle('channel-view-header--canada', id === 'international' && cleanPath[0] === 'canada');
      $('.channel-view-header').classList.toggle('channel-view-header--personalfinance', id === 'personalfinance');
      $('.channel-view-header').classList.toggle('channel-view-header--workplace', id === 'workplace');
      $('.channel-view-header').classList.toggle('channel-view-header--computing', id === 'computing');
      $('.channel-view-header').classList.toggle('channel-view-header--researchandlearn', id === 'researchandlearn');
      $('.channel-view-header').classList.toggle('channel-view-header--entertainment', id === 'entertainment');
      $('.channel-view-header').classList.toggle('channel-view-header--sci-fi', id === 'entertainment' && cleanPath[0] === 'sci-fi');
      $('.channel-view-header').classList.toggle('channel-view-header--interests', id === 'interests');
      $('.channel-view-header').classList.toggle('channel-view-header--christianity-today', id === 'interests' && cleanPath[0] === 'christianity-today');
      $('.channel-view-header').classList.toggle('channel-view-header--lifestyles', id === 'lifestyles');
      $('.channel-view-header').classList.toggle('channel-view-header--health', id === 'health');
      $('.channel-view-header').classList.toggle('channel-view-header--families', id === 'families');
      $('.channel-view-header').classList.toggle('channel-view-header--local', id === 'local');
      $('.channel-view-header').classList.toggle('channel-view-header--games', id === 'games');
      $('.channel-view-header').classList.toggle('channel-view-header--shopping', id === 'shopping');
      $('.channel-view-header').classList.toggle('channel-view-header--music', id === 'music');
      $('.channel-view-content').classList.toggle('channel-view-content--sports', id === 'sports');
      $('.channel-view-content').classList.toggle('channel-view-content--today', id === 'aoltoday');
      $('.channel-view-content').classList.toggle('channel-view-content--influence', id === 'influence');
      $('.channel-view-content').classList.toggle('channel-view-content--travel', id === 'travel');
      $('.channel-view-content').classList.toggle('channel-view-content--international', id === 'international');
      $('.channel-view-content').classList.toggle('channel-view-content--personalfinance', id === 'personalfinance');
      $('.channel-view-content').classList.toggle('channel-view-content--workplace', id === 'workplace');
      $('.channel-view-content').classList.toggle('channel-view-content--computing', id === 'computing');
      $('.channel-view-content').classList.toggle('channel-view-content--researchandlearn', id === 'researchandlearn');
      $('.channel-view-content').classList.toggle('channel-view-content--entertainment', id === 'entertainment');
      $('.channel-view-content').classList.toggle('channel-view-content--interests', id === 'interests');
      $('.channel-view-content').classList.toggle('channel-view-content--lifestyles', id === 'lifestyles');
      $('.channel-view-content').classList.toggle('channel-view-content--health', id === 'health');
      $('.channel-view-content').classList.toggle('channel-view-content--families', id === 'families');
      $('.channel-view-content').classList.toggle('channel-view-content--local', id === 'local');
      $('.channel-view-content').classList.toggle('channel-view-content--games', id === 'games');
      $('.channel-view-content').classList.toggle('channel-view-content--shopping', id === 'shopping');
      $('.channel-view-content').classList.toggle('channel-view-content--music', id === 'music');
      $('.channel-view-content').classList.toggle('channel-view-content--kids-static', id === 'kidsonly');
      if (id === 'kidsonly') {
        $('#channel-parent').hidden = true;
        $('#channel-view-nav').replaceChildren();
        const body = $('#channel-view-body'); body.replaceChildren();
        const exhibit = document.createElement('div'); exhibit.className = 'kids-static-exhibit';
        const art = document.createElement('img');
        art.src = config.staticImage === 'assets/kids-only-static.webp' ? config.staticImage : 'assets/kids-only-static.webp';
        art.alt = 'Colorful 1990s inspired artwork with books, a computer, music, a rocket, and a roller skate';
        const caption = document.createElement('div'); caption.className = 'kids-static-caption';
        const heading = document.createElement('h3'); heading.textContent = 'AOV Kids Only';
        const note = document.createElement('p');
        note.textContent = 'A static nostalgia exhibit. Games, chat, and other activities are not available.';
        caption.append(heading, note); exhibit.append(art, caption); body.append(exhibit);
        $('#channel-view-status').textContent = 'Kids Only · Static nostalgia exhibit';
        return;
      }
      const parent = $('#channel-parent');
      parent.hidden = cleanPath.length === 0 || (id === 'entertainment' && cleanPath[0] === 'sci-fi');
      if (cleanPath.length) {
        parent.textContent = `← Back to ${parentTitle}`;
        parent.setAttribute('data-channel-path', cleanPath.slice(0, -1).join('/'));
      }
      const nav = $('#channel-view-nav'); nav.replaceChildren();
      const navTitle = document.createElement('h3'); navTitle.textContent = `${channelTitles[id]} Sections`; nav.append(navTitle);
      const home = channelNavButton(`${channelTitles[id]} Home`, config.icon || '🏠', 'channel-path', '');
      if (!cleanPath.length) home.setAttribute('aria-current', 'page');
      nav.append(home);
      function addChildLinks(children, prefix = [], depth = 0) {
        if (!Array.isArray(children) || depth > 4) return;
        for (const child of children) {
          if (!/^[a-z][a-z0-9-]*$/.test(child.id || '')) continue;
          const childPath = [...prefix, child.id];
          const button = channelNavButton(String(child.title || child.id), child.icon || '📁', 'channel-path', childPath.join('/'));
          if (childPath.join('/') === cleanPath.join('/')) button.setAttribute('aria-current', 'page');
          nav.append(button);
          if (id !== 'local' || childPath[0] !== 'digital-cities'
              || childPath.length === 1 && cleanPath[0] === 'digital-cities'
              || childPath.length === 2 && cleanPath[1] === childPath[1]) {
            addChildLinks(child.children, childPath, depth + 1);
          }
        }
      }
      addChildLinks(config.children);
      const body = $('#channel-view-body'); body.replaceChildren();
      const sections = Array.isArray(node.sections) ? node.sections : [];
      const chatLinks = sections.filter(section => section.type === 'links')
        .flatMap(section => Array.isArray(section.items) ? section.items : [])
        .filter(item => item.type === 'chat' && (Number.isInteger(item.room) || typeof item.roomName === 'string'));
      if (chatLinks.length) {
        const heading = document.createElement('h3'); heading.textContent = 'Chat Rooms'; nav.append(heading);
        for (const item of chatLinks) {
          const button = channelNavButton(String(item.label || 'Chat Room'), '💬', 'chat-room-link', String(item.room ?? ''));
          if (typeof item.roomName === 'string') button.dataset.chatRoomName = item.roomName;
          nav.append(button);
        }
      }
      let pageSectionCount = 0;
      for (const [sectionIndex, section] of sections.entries()) {
        const items = Array.isArray(section.items) ? section.items : [];
        if (section.type === 'links' && items.every(item => item.type === 'chat')) continue;
        const block = document.createElement('section'); block.className = 'channel-panel';
        block.id = `channel-section-${sectionIndex}`;
        const heading = document.createElement('h3'); heading.textContent = String(section.title || 'Links'); block.append(heading);
        if (pageSectionCount++ === 0) {
          const navHeading = document.createElement('h3'); navHeading.textContent = 'On This Page'; nav.append(navHeading);
        }
        nav.append(channelNavButton(String(section.navLabel || section.title || 'Links'), section.icon || '📄', 'channel-section', block.id));
        if (section.type === 'local' && id === 'local') {
          block.classList.add('channel-panel--local');
          loadLocalChannel(block, section, request);
        } else if (section.type === 'radio' && id === 'music') {
          block.classList.add('channel-panel--radio');
          const player = document.createElement('div'); player.className = 'channel-radio-player';
          const audio = document.createElement('audio'); audio.controls = true; audio.preload = 'none';
          audio.setAttribute('aria-label', 'Live radio player');
          const status = document.createElement('p'); status.setAttribute('role', 'status');
          status.textContent = 'Choose a station to listen live.';
          const stations = document.createElement('div'); stations.className = 'channel-radio-stations';
          for (const item of items) {
            let stream;
            try { stream = new URL(item.streamUrl); } catch { continue; }
            if (stream.protocol !== 'https:' || !['kexp.streamguys1.com', 'stream0.wfmu.org'].includes(stream.hostname)) continue;
            const card = document.createElement('div'); card.className = 'channel-radio-station';
            const name = document.createElement('strong'); name.textContent = String(item.label || 'Station');
            const description = document.createElement('small'); description.textContent = String(item.description || '');
            const tune = document.createElement('button'); tune.type = 'button'; tune.className = 'classic-button';
            tune.textContent = `▶ Listen to ${name.textContent}`;
            tune.addEventListener('click', async () => {
              audio.pause(); audio.src = stream.href; audio.load();
              status.textContent = `Connecting to ${name.textContent}…`;
              try { await audio.play(); status.textContent = `Playing ${name.textContent} live.`; }
              catch { status.textContent = `Could not play ${name.textContent} here. Try the station site.`; }
            });
            card.append(name, description, tune);
            const site = channelSafeUrl(item.siteUrl);
            if (site) { const link = channelButton('Station site ↗', 'url', site); link.dataset.topic = name.textContent; card.append(link); }
            stations.append(card);
          }
          audio.addEventListener('error', () => { status.textContent = 'The station stream is unavailable. Try its website.'; });
          player.append(stations, audio, status); block.append(player);
        } else if (section.type === 'bulletins') {
          block.classList.add('channel-panel--bulletins');
          const list = document.createElement('div'); list.className = 'channel-bulletin-list';
          list.textContent = 'Loading AOV posts…'; block.append(list);
          loadChannelBulletins(list, request);
        } else if (section.type === 'text') {
          block.classList.add('channel-panel--text');
          for (const paragraph of Array.isArray(section.paragraphs) ? section.paragraphs : []) {
            const text = document.createElement('p');
            text.textContent = String(paragraph);
            block.append(text);
          }
        } else if (section.type === 'samples') {
          block.classList.add('channel-panel--samples');
          const cards = document.createElement('div'); cards.className = 'channel-sample-list';
          for (const item of Array.isArray(section.items) ? section.items : []) {
            const card = document.createElement('article'); card.className = 'channel-sample-card';
            const label = document.createElement('strong'); label.textContent = String(item.label || 'Sample');
            const summary = document.createElement('p'); summary.textContent = String(item.summary || '');
            card.append(label, summary); cards.append(card);
          }
          block.append(cards);
        } else if (section.type === 'game-cards' && id === 'games') {
          block.classList.add('channel-panel--game-cards');
          const cards = document.createElement('div'); cards.className = 'game-card-grid';
          cards.textContent = 'Loading games…'; block.append(cards);
          loadGameCards(id, items, cards, request);
        } else if (section.type === 'deal-cards' && id === 'shopping') {
          block.classList.add('channel-panel--deal-cards');
          const cards = document.createElement('div'); cards.className = 'deal-card-grid';
          cards.textContent = 'Loading deals…'; block.append(cards);
          loadDealCards(id, items, cards, request);
        } else if (section.type === 'feeds') {
          block.classList.add('channel-panel--feeds');
          const feeds = items.filter(feed => /^[a-z][a-z0-9-]*$/.test(feed.id || ''));
          if (feeds.length > 1) {
            const group = document.createElement('div'); group.className = 'channel-feed channel-feed--combined';
            const list = document.createElement('ul'); list.textContent = 'Loading headlines…'; group.append(list); block.append(group);
            loadCombinedChannelFeeds(id, feeds, list, request);
          } else {
            for (const feed of feeds) {
              const group = document.createElement('div'); group.className = 'channel-feed';
              const name = document.createElement('h4'); name.textContent = feed.title || feed.id; group.append(name);
              const list = document.createElement('ul'); list.textContent = 'Loading headlines…'; group.append(list); block.append(group);
              loadChannelFeed(id, feed.id, list, request);
            }
          }
        } else {
          const list = document.createElement('div'); list.className = 'channel-link-list';
          for (const item of items) {
            const label = String(item.label || 'Open');
            if (item.type === 'chat') continue;
            if (item.type === 'channel' && Object.hasOwn(channelTitles, item.channel)) {
              const button = channelButton(label, 'channel', item.channel);
              if (Array.isArray(item.path)) button.dataset.path = item.path.join('/');
              if (id === 'local' && item.image === 'assets/channels/digital-cities.svg') {
                button.classList.add('digital-cities-button');
                const artwork = document.createElement('img');
                artwork.src = item.image;
                artwork.alt = '';
                button.replaceChildren(artwork);
                button.setAttribute('aria-label', label);
              }
              list.append(button);
            } else if (item.type === 'window' && Object.hasOwn(windowLabels, item.window)) {
              list.append(channelButton(label, 'open', item.window));
            } else {
              const url = item.type === 'destination' ? channelUrls[id] : channelSafeUrl(item.url);
              if (url) { const button = channelButton(label + ' ↗', 'url', url); button.dataset.topic = label; list.append(button); }
            }
          }
          block.append(list);
        }
        body.append(block);
      }
      if (Array.isArray(node.crosslinks) && node.crosslinks.length) {
        const heading = document.createElement('h3'); heading.textContent = 'Other Channels'; nav.append(heading);
        for (const link of node.crosslinks) {
          if (!Object.hasOwn(channelTitles, link.channel)) continue;
          const button = channelNavButton(String(link.label || channelTitles[link.channel]), link.icon || '🔗', 'channel', link.channel);
          if (Array.isArray(link.path)) button.dataset.path = link.path.join('/');
          nav.append(button);
        }
      }
      if (!body.childElementCount) body.textContent = 'Sections can be added in this channel’s JSON file.';
      $('#channel-view-status').textContent = `${title} · AOV Channels`;
    } catch (error) { if (request === channelRequest) $('#channel-view-status').textContent = error.message; }
  }

  async function fetchChannelFeed(channel, feed) {
    const response = await fetch(`channel-feed.php?channel=${encodeURIComponent(channel)}&feed=${encodeURIComponent(feed)}&v=3`, { credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Feed unavailable');
    return data;
  }

  async function loadGameCards(channel, feeds, container, request) {
    const configured = feeds.filter(feed => /^[a-z][a-z0-9-]*$/.test(feed.id || ''));
    const results = await Promise.allSettled(configured.map(feed => fetchChannelFeed(channel, feed.id)));
    if (request !== channelRequest || !container.isConnected) return;
    const games = results.flatMap(result => result.status === 'fulfilled' ? result.value.items || [] : []);
    container.replaceChildren();
    const seen = new Set();
    for (const game of games) {
      const url = channelSafeUrl(game.url);
      if (!url || seen.has(url)) continue;
      seen.add(url);
      const card = document.createElement('article'); card.className = 'game-card';
      const launch = channelButton('', 'url', url, 'game-card-launch');
      launch.setAttribute('aria-label', `View ${String(game.title || 'game')} on itch.io`);
      launch.dataset.topic = String(game.title || 'Game');
      const imageUrl = channelSafeUrl(game.image);
      if (imageUrl) {
        const image = document.createElement('img'); image.src = imageUrl;
        image.alt = ''; image.loading = 'lazy'; image.decoding = 'async';
        image.referrerPolicy = 'no-referrer';
        image.addEventListener('error', () => image.replaceWith(document.createTextNode('🎮')), { once: true });
        launch.append(image);
      } else {
        const fallback = document.createElement('span'); fallback.className = 'game-card-fallback'; fallback.textContent = '🎮'; launch.append(fallback);
      }
      const name = document.createElement('strong'); name.textContent = String(game.title || 'Game'); launch.append(name);
      const action = document.createElement('span'); action.textContent = 'View game page ↗'; launch.append(action);
      card.append(launch); container.append(card);
    }
    if (!container.childElementCount) container.textContent = 'Games are temporarily unavailable. Try again later.';
  }

  async function loadDealCards(channel, feeds, container, request) {
    const configured = feeds.filter(feed => /^[a-z][a-z0-9-]*$/.test(feed.id || ''));
    const results = await Promise.allSettled(configured.map(feed => fetchChannelFeed(channel, feed.id)));
    if (request !== channelRequest || !container.isConnected) return;
    const deals = results.flatMap(result => result.status === 'fulfilled'
      ? (result.value.items || []).map(item => ({ item, source: result.value.source || 'Deals' })) : []);
    container.replaceChildren();
    const seen = new Set();
    for (const { item, source } of deals) {
      const url = channelSafeUrl(item.url);
      if (!url || seen.has(url)) continue;
      seen.add(url);
      const card = document.createElement('article'); card.className = 'deal-card';
      const launch = channelButton('', 'url', url, 'deal-card-launch');
      const title = String(item.title || 'Deal');
      launch.setAttribute('aria-label', `View ${title} at ${source}`);
      launch.dataset.topic = title;
      const imageUrl = channelSafeUrl(item.image);
      if (imageUrl) {
        const image = document.createElement('img'); image.src = imageUrl;
        image.alt = ''; image.loading = 'lazy'; image.decoding = 'async';
        image.referrerPolicy = 'no-referrer';
        image.addEventListener('error', () => image.replaceWith(document.createTextNode('🛍️')), { once: true });
        launch.append(image);
      } else {
        const fallback = document.createElement('span'); fallback.className = 'deal-card-fallback'; fallback.textContent = '🛍️'; launch.append(fallback);
      }
      const name = document.createElement('strong'); name.textContent = title; launch.append(name);
      const sourceLabel = document.createElement('small'); sourceLabel.textContent = source; launch.append(sourceLabel);
      if (item.publishedAt && Number.isFinite(Date.parse(item.publishedAt))) {
        const published = document.createElement('time'); published.dateTime = item.publishedAt;
        published.textContent = new Date(item.publishedAt).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
        launch.append(published);
      }
      const action = document.createElement('span'); action.textContent = 'Check current offer ↗'; launch.append(action);
      card.append(launch); container.append(card);
    }
    if (!container.childElementCount) container.textContent = 'Deals are temporarily unavailable. Try again later.';
  }

  function renderChannelStories(list, stories, showSource = false) {
    list.replaceChildren();
    for (const story of stories) {
      const item = story.item || story;
      const url = channelSafeUrl(item.url); if (!url) continue;
      const row = document.createElement('li'); row.className = 'channel-story';
      const imageUrl = channelSafeUrl(item.image);
      if (imageUrl) {
        const thumbnail = document.createElement('img');
        thumbnail.src = imageUrl;
        thumbnail.alt = '';
        thumbnail.loading = 'lazy';
        thumbnail.decoding = 'async';
        thumbnail.referrerPolicy = 'no-referrer';
        thumbnail.addEventListener('error', () => thumbnail.remove(), { once: true });
        row.append(thumbnail);
      }
      const details = document.createElement('div');
      const title = String(item.title || 'Article');
      const button = channelButton(showSource ? `${title} — ${story.source}` : title, 'url', url);
      button.dataset.topic = title; details.append(button);
      if (item.publishedAt && Number.isFinite(Date.parse(item.publishedAt))) {
        const published = document.createElement('time');
        published.dateTime = item.publishedAt;
        published.textContent = new Date(item.publishedAt).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
        details.append(published);
      }
      if (item.summary) {
        const summary = document.createElement('p'); summary.textContent = String(item.summary);
        details.append(summary);
      }
      row.append(details); list.append(row);
    }
    if (!list.childElementCount) list.textContent = 'No current headlines.';
  }

  async function loadChannelFeed(channel, feed, list, request) {
    try {
      const data = await fetchChannelFeed(channel, feed);
      if (request === channelRequest) renderChannelStories(list, data.items || []);
    } catch { if (request === channelRequest) list.textContent = 'Headlines are unavailable. Try this source later.'; }
  }

  async function loadCombinedChannelFeeds(channel, feeds, list, request) {
    const results = await Promise.allSettled(feeds.map(feed => fetchChannelFeed(channel, feed.id)));
    if (request !== channelRequest) return;
    const stories = results.flatMap(result => result.status === 'fulfilled'
      ? (result.value.items || []).map(item => ({ item, source: String(result.value.source || 'News') })) : []);
    stories.sort((a, b) => (Date.parse(b.item.publishedAt || '') || 0) - (Date.parse(a.item.publishedAt || '') || 0));
    if (stories.length) renderChannelStories(list, stories, true);
    else list.textContent = 'Headlines are unavailable. Try again later.';
  }

  async function loadChannelBulletins(list, request) {
    try {
      const response = await fetch('on-aov.php', { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (request !== channelRequest) return;
      if (!response.ok) throw new Error(data.error || 'AOV posts are unavailable');
      list.replaceChildren();
      for (const post of data.posts || []) {
        const card = document.createElement('details'); card.className = 'channel-bulletin-card';
        const heading = document.createElement('summary');
        if (['assets/aov-logo.png', 'assets/aov-icon.jpg', 'assets/news-channel-logo.png'].includes(post.image)) {
          const picture = document.createElement('img'); picture.src = post.image; picture.alt = '';
          heading.append(picture);
        }
        const text = document.createElement('span');
        const title = document.createElement('strong'); title.textContent = String(post.title || 'AOV update');
        const date = document.createElement('time'); date.dateTime = String(post.date || ''); date.textContent = String(post.date || '');
        const summary = document.createElement('span'); summary.textContent = String(post.summary || '');
        text.append(title, date, summary); heading.append(text);
        const body = document.createElement('p'); body.textContent = String(post.body || '');
        card.append(heading, body); list.append(card);
      }
      if (!list.childElementCount) list.textContent = 'No AOV posts have been published yet.';
    } catch { if (request === channelRequest) list.textContent = 'AOV posts are temporarily unavailable.'; }
  }

  async function loadNews() {
    if (!signedIn) return;
    const browserList = $('#browser-news-headlines');
    fetch('news.php', { cache: 'no-store' }).then(response => response.ok ? response.json() : Promise.reject())
      .then(data => {
        browserList.replaceChildren();
        (data.items || []).forEach(item => {
          const row = document.createElement('li'); const link = document.createElement('a');
          link.textContent = item.title; link.href = item.url; link.dataset.url = item.url;
          link.target = '_blank'; link.rel = 'noopener noreferrer'; row.append(link); browserList.append(row);
        });
        if (!browserList.children.length) throw new Error('No headlines');
      }).catch(() => { const row = document.createElement('li'); row.textContent = 'NPR headlines are temporarily unavailable.'; browserList.replaceChildren(row); });
    try {
      const response = await fetch('local.php', { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('Local feed unavailable');
      const data = await response.json();
      const list = $('#news-headlines');
      list.replaceChildren();
      if (data.needsZip) {
        $('#local-weather').textContent = 'Add your ZIP code in Settings for local weather and news.';
        $('#local-news-source').textContent = 'Location needed';
        const row = document.createElement('li'); row.textContent = 'Set your ZIP code to see local headlines.'; list.append(row);
      } else {
        $('#local-news-source').textContent = `${data.city}, ${data.region}`;
        $('#local-weather').textContent = data.weather ? `${data.city} weather: ${data.weather.temperatureF}°F · ${data.weather.condition}` : 'Weather is temporarily unavailable.';
        $('#more-local-news').href = data.newsUrl;
        (data.items || []).forEach(item => {
          const row = document.createElement('li');
          const link = document.createElement('a');
          link.textContent = item.title;
          link.href = item.url;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          row.append(link);
          list.append(row);
        });
        if (!list.children.length) { const row = document.createElement('li'); row.textContent = 'No recent local headlines found. Try More local news.'; list.append(row); }
      }
    } catch {
      $('#local-weather').textContent = 'Local weather is temporarily unavailable.';
      const row = document.createElement('li'); row.textContent = 'Local headlines are temporarily unavailable.';
      $('#news-headlines').replaceChildren(row);
    }
  }

  function setupDrag() {
    $$('.desktop-window').forEach(el => {
      const handle = $('.drag-handle', el);
      if (!handle) return;
      handle.addEventListener('pointerdown', event => {
        if (event.target.closest('button') || matchMedia('(max-width: 800px)').matches || el.classList.contains('maximized')) return;
        el.style.zIndex = String(++z);
        const startX = event.clientX, startY = event.clientY;
        const rect = el.getBoundingClientRect();
        const desktopRect = $('#desktop').getBoundingClientRect();
        const initialLeft = rect.left - desktopRect.left, initialTop = rect.top - desktopRect.top;
        handle.setPointerCapture(event.pointerId);
        const move = e => {
          const left = Math.max(0, Math.min(desktopRect.width - 70, initialLeft + e.clientX - startX));
          const top = Math.max(0, Math.min(desktopRect.height - 30, initialTop + e.clientY - startY));
          el.style.left = `${left}px`;
          el.style.top = `${top}px`;
        };
        const end = () => { handle.removeEventListener('pointermove', move); handle.removeEventListener('pointerup', end); handle.removeEventListener('pointercancel', end); };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', end);
        handle.addEventListener('pointercancel', end);
      });
      el.addEventListener('pointerdown', () => { el.style.zIndex = String(++z); active = el.dataset.window; renderTaskbar(); });
    });
  }

  $('#login-tab').addEventListener('click', () => switchEntry('login'));
  $('#register-tab').addEventListener('click', () => switchEntry('register'));
  $('#forgot-link').addEventListener('click', () => switchEntry('recovery'));
  $$('[data-entry]').forEach(button => button.addEventListener('click', () => switchEntry(button.dataset.entry)));
  $('#login-form').addEventListener('submit', async event => {
    event.preventDefault();
    startConnection();
    try {
      const state = await api('login', { name: $('#screen-name').value.trim(), password: $('#preview-password').value });
      $('#preview-password').value = '';
      await showAccount(state);
    } catch (error) { $('#preview-password').value = ''; showEntry(error.message); }
  });
  $('#register-form').addEventListener('submit', async event => {
    event.preventDefault();
    startConnection();
    try {
      const state = await api('register', {
        name: $('#new-screen-name').value.trim(), email: $('#new-email').value.trim(), zipCode: $('#new-zip').value.trim(),
        password: $('#new-password').value, confirm: $('#new-password-confirm').value,
        terms: $('#accept-terms').checked, website: $('#website').value
      });
      $('#new-password').value = ''; $('#new-password-confirm').value = '';
      await showAccount(state);
    } catch (error) {
      $('#new-password').value = ''; $('#new-password-confirm').value = '';
      showEntry(error.message);
      switchEntry('register');
      $('#register-message').textContent = error.message;
    }
  });
  $('#recovery-form').addEventListener('submit', async event => {
    event.preventDefault();
    $('#recovery-message').textContent = 'Checking recovery…';
    try { const result = await api('recovery', { email: $('#recovery-email').value.trim() }); $('#recovery-message').textContent = result.message; }
    catch (error) { $('#recovery-message').textContent = error.message; }
  });
  $('#reset-form').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await api('reset', { password: $('#reset-password').value, confirm: $('#reset-confirm').value });
      $('#reset-password').value = ''; $('#reset-confirm').value = '';
      showEntry(result.message);
      history.replaceState(null, '', new URL('.', location.href));
    } catch (error) { $('#reset-message').textContent = error.message; }
  });
  $('#sign-off').addEventListener('click', async () => {
    try { await api('logout'); showEntry('You have signed off.'); playEffect('goodbye'); }
    catch (error) { $('#login-message').textContent = error.message; }
  });
  $('#mail-write-shortcut').addEventListener('click', () => composeMail());
  $('#mail-inbox').addEventListener('click', () => { mailFolder = 'inbox'; mailPage = 0; loadMailList(); });
  $('#mail-sent').addEventListener('click', () => { mailFolder = 'sent'; mailPage = 0; loadMailList(); });
  $('#mail-compose').addEventListener('click', () => composeMail());
  $('#mail-directory').addEventListener('click', () => { mailView('directory'); searchMailMembers(); });
  $('#mail-search').addEventListener('input', () => searchMailMembers());
  $('#mail-prev').addEventListener('click', () => { if (mailPage > 0) { mailPage--; loadMailList(); } });
  $('#mail-next').addEventListener('click', () => { if (mailMore) { mailPage++; loadMailList(); } });
  $('#mail-back').addEventListener('click', loadMailList);
  $('#mail-cancel').addEventListener('click', loadMailList);
  $('#mail-reply').addEventListener('click', () => {
    if (mailCurrent) composeMail(mailCurrent.sender, /^Re:/i.test(mailCurrent.subject) ? mailCurrent.subject : `Re: ${mailCurrent.subject}`.slice(0, 120), Number(mailCurrent.id));
  });
  $('#mail-archive').addEventListener('click', async () => {
    if (!mailCurrent) return;
    try { const data = await mailApi('archive', { id: Number(mailCurrent.id) }); mailUnread(data.unread); mailCurrent = null; mailNotice('Message archived.'); loadMailList(); }
    catch (error) { mailNotice(error.message); }
  });
  $('#mail-report').addEventListener('click', async () => {
    if (!mailCurrent) return;
    const reason = prompt('Report reason: Harassment, Spam, Threat, or Other');
    if (reason === null) return;
    const choice = ['Harassment', 'Spam', 'Threat', 'Other'].find(item => item.toLowerCase() === reason.trim().toLowerCase());
    if (!choice) { mailNotice('Choose Harassment, Spam, Threat, or Other.'); return; }
    try { const data = await mailApi('report', { id: Number(mailCurrent.id), reason: choice }); mailNotice(data.message); }
    catch (error) { mailNotice(error.message); }
  });
  $('#mail-compose-view').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const data = await mailApi('send', { to: $('#mail-to').value.trim(), subject: $('#mail-subject').value.trim(), body: $('#mail-body').value.trim(), replyTo: mailReplyTo });
      mailUnread(data.unread); mailReplyTo = null; mailFolder = 'sent'; mailPage = 0;
      mailNotice('Message sent.'); loadMailList();
    } catch (error) { mailNotice(error.message); }
  });
  $('#change-password-form').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await api('change_password', { current: $('#profile-current-password').value, password: $('#profile-new-password').value, confirm: $('#profile-confirm-password').value });
      $('#change-password-form').reset(); $('#profile-message').textContent = result.message;
    } catch (error) { $('#profile-message').textContent = error.message; }
  });
  $('#change-name-form').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const state = await api('change_name', { name: $('#profile-name').value.trim(), current: $('#profile-name-password').value });
      $('#profile-name-password').value = ''; setPreviewName(state.name); $('#profile-message').textContent = 'Screen name updated.';
    } catch (error) { $('#profile-name-password').value = ''; $('#profile-message').textContent = error.message; }
  });
  $('#change-zip-form').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const state = await api('change_zip', { zipCode: $('#profile-zip').value.trim() });
      $('#profile-zip').value = state.zipCode;
      $('#profile-message').textContent = 'ZIP code updated. Local news and weather are refreshing.';
      loadNews();
    } catch (error) { $('#profile-message').textContent = error.message; }
  });
  ['pointerdown', 'keydown', 'scroll'].forEach(type => document.addEventListener(type, recordActivity, { passive: true }));
  document.addEventListener('visibilitychange', () => { if (!document.hidden && signedIn) recordActivity(); });
  window.setInterval(() => {
    if (!signedIn) return;
    if (Date.now() - lastActivity >= inactivityMs) {
      fetch('api.php?action=status', { credentials: 'same-origin', cache: 'no-store' })
        .then(response => response.json()).then(state => { if (!state.loggedIn) showEntry('Signed off after 30 minutes of inactivity.'); })
        .catch(() => {});
    }
  }, 15000);
  function openChatRoomByName(roomName, roomId = '') {
    openWindow('chat');
    refreshChatState().then(() => {
      const option = [...$('#chat-room').options].find(option => roomName
        ? option.textContent.trim() === roomName : option.value === roomId);
      if (option) {
        $('#chat-room').value = option.value;
        $('#chat-room').dispatchEvent(new Event('change'));
      } else $('#chat-status').textContent = 'This room is not available yet.';
    });
  }

  document.addEventListener('click', event => {
    const open = event.target.closest('[data-open]');
    const url = event.target.closest('[data-url]');
    const close = event.target.closest('[data-close]');
    const minimize = event.target.closest('[data-minimize]');
    const maximize = event.target.closest('[data-maximize]');
    const buddy = event.target.closest('[data-buddy]');
    const channel = event.target.closest('[data-channel]');
    const channelPath = event.target.closest('[data-channel-path]');
    const channelSection = event.target.closest('[data-channel-section]');
    const chatRoomLink = event.target.closest('[data-chat-room-link]');
    if (open) openWindow(open.dataset.open);
    if (channel) {
      if (channel.dataset.channel === 'search') visit('https://wiby.me/', 'Wiby Search');
      else showChannel(channel.dataset.channel, (channel.dataset.path || '').split('/').filter(Boolean));
    }
    if (channelPath && currentChannel) showChannel(currentChannel.id, channelPath.dataset.channelPath.split('/').filter(Boolean));
    if (channelSection) document.getElementById(channelSection.dataset.channelSection)?.scrollIntoView({ block: 'start', behavior: 'auto' });
    if (chatRoomLink) {
      openChatRoomByName(chatRoomLink.dataset.chatRoomName, chatRoomLink.dataset.chatRoomLink);
    }
    if (url) { if (url.tagName === 'A') event.preventDefault(); visit(url.dataset.url, url.dataset.topic || url.textContent.trim() || 'Website'); }
    if (close) minimizeWindow(close.dataset.close);
    if (minimize) minimizeWindow(minimize.dataset.minimize);
    if (maximize) { windows.get(maximize.dataset.maximize).classList.toggle('maximized'); openWindow(maximize.dataset.maximize); }
    if (buddy) openDirectMessage(buddy.dataset.buddy);
  });
  $('#global-go').addEventListener('click', goKeywordOrAddress);
  $('#global-back').addEventListener('click', () => { openWindow('browser'); navigateBrowser(-1); });
  $('#global-forward').addEventListener('click', () => { openWindow('browser'); navigateBrowser(1); });
  $('#global-address').addEventListener('input', event => {
    event.target.setCustomValidity('');
    renderKeywordSuggestions();
  });
  $('#global-address').addEventListener('focus', renderKeywordSuggestions);
  $('#global-address').addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (!$('#keyword-suggestions').hidden) {
        event.preventDefault();
        moveKeywordChoice(event.key === 'ArrowDown' ? 1 : -1);
      }
    } else if (event.key === 'Escape') {
      hideKeywordSuggestions();
    } else if (event.key === 'Enter') {
      event.preventDefault();
      if (activeKeywordChoice >= 0 && keywordChoices[activeKeywordChoice]) runKeyword(keywordChoices[activeKeywordChoice].entry);
      else goKeywordOrAddress();
    }
  });
  document.addEventListener('pointerdown', event => {
    if (!event.target.closest('.keyword-entry')) hideKeywordSuggestions();
  });
  $('#global-home').addEventListener('click', goBrowserHome);
  $('#global-refresh').addEventListener('click', () => {
    openWindow('browser');
    const page = currentPage();
    if (!page.url) { renderBrowserPage(); return; }
    const url = new URL(page.url);
    if (url.hostname === 'www.npr.org' && url.pathname === '/sections/news/' && url.protocol === 'https:') loadNews();
    else if (isArchiveReplay(url) || (['www.npr.org', 'wiby.me'].includes(url.hostname) && url.protocol === 'https:')) $('#news-frame').src = url.href;
    else $('#browser-status').textContent = 'Use the link to refresh this site in its browser tab.';
  });
  $('#global-bookmark').addEventListener('click', () => {
    const page = currentPage();
    if (page.url) saveBrowser('browser_bookmark_add', page);
  });
  $('#clear-history').addEventListener('click', () => saveBrowser('browser_history_clear', {}));
  const saveHome = document.createElement('button');
  saveHome.type = 'button';
  saveHome.className = 'classic-button';
  saveHome.textContent = 'Save Home Page';
  $('#home-url').after(saveHome);
  const homeNote = $('#home-url').nextElementSibling.nextElementSibling;
  homeNote.textContent = 'Leave empty to use the AOV start page.';
  saveHome.addEventListener('click', () => {
    const raw = $('#home-url').value.trim();
    const url = raw ? normalizeUrl(raw) : null;
    if (raw && !url) { homeNote.textContent = 'Enter a valid HTTP or HTTPS address.'; return; }
    saveBrowser('browser_home_set', { url: url?.href || '' }).then(saved => { if (saved) homeNote.textContent = 'Home page saved. Leave empty for AOV Home.'; });
  });
  $('#chat-room').addEventListener('change', () => {
    chatLastId = 0;
    $('#chat-invite-form').hidden = Number($('#chat-room').value) !== 500000000 + chatState.userId;
    $('#chat-messages').replaceChildren();
    pollChat();
  });
  $('#chat-invite-form').addEventListener('submit', async event => {
    event.preventDefault();
    const input = $('#chat-invite-name'); const name = input.value.trim();
    if (!/^[A-Za-z][A-Za-z0-9_]{2,23}$/.test(name)) { chatNotice('Enter an online AOV screen name.'); return; }
    await pollChat(`/invite ${name}`); input.value = '';
    chatNotice(`Invitation requested for ${name}. They must be online to receive it.`);
  });
  $('#chat-form').addEventListener('submit', async event => {
    event.preventDefault();
    const input = $('#chat-input'); const message = input.value.trim();
    if (!message || chatBusy) return;
    if (message.length > 500) { chatNotice('Message is too long.'); return; }
    input.value = '';
    await pollChat(message);
  });
  $('#im-form').addEventListener('submit', async event => {
    event.preventDefault();
    const input = $('#im-input'); const message = input.value.trim();
    if (!imContact || !message || chatBusy) return;
    input.value = '';
    await pollChat(`/msg ${imContact} ${message}`);
    $('#im-status').textContent = $('#chat-status').textContent;
  });
  $('#away-form').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await chatApi('away_set', { away: $('#away-mode').value === 'away', message: $('#away-message').value.trim() });
      await refreshChatState(); $('#buddy-status').textContent = 'Status saved.';
    } catch (error) { $('#buddy-status').textContent = error.message; }
  });
  async function loadChatReports() {
    try {
      const data = await chatApi('admin_reports', {});
      const list = $('#chat-reports'); list.replaceChildren();
      if (!data.reports.length) { list.textContent = 'No open reports.'; return; }
      for (const report of data.reports) {
        const row = document.createElement('div'); row.className = 'chat-report';
        const text = document.createElement('span');
        text.textContent = `${report.reporter} reported ${report.target}: ${report.message}`;
        row.append(text, chatPersonButton(report.target, async () => {
          try { await chatApi('admin_report_close', { reportId: Number(report.id) }); await loadChatReports(); }
          catch (error) { chatNotice(error.message); }
        }, 'Close'));
        list.append(row);
      }
    } catch (error) { chatNotice(error.message); }
  }
  $('#chat-reports-refresh').addEventListener('click', loadChatReports);
  async function loadChatFilters() {
    try {
      const data = await chatApi('admin_filter_terms', {});
      const list = $('#chat-filter-list'); list.replaceChildren();
      for (const entry of data.terms) {
        const row = document.createElement('div'); row.className = 'chat-report';
        const label = document.createElement('span'); label.textContent = entry.term;
        row.append(label, chatPersonButton(entry.term, async () => {
          try { await chatApi('admin_filter_remove', { id: Number(entry.id) }); await loadChatFilters(); }
          catch (error) { chatNotice(error.message); }
        }, 'Remove'));
        list.append(row);
      }
      if (!data.terms.length) list.textContent = 'No blocked phrases configured.';
      const events = $('#chat-guard-events'); events.replaceChildren();
      const heading = document.createElement('strong'); heading.textContent = 'Recent filtered messages'; events.append(heading);
      for (const entry of data.events) {
        const row = document.createElement('p');
        row.textContent = `${new Date(Number(entry.at) * 1000).toLocaleString()} · ${entry.username || 'Former member'} · ${entry.event === 'chat_auto_mute' ? 'automatic mute' : 'message rejected'}`;
        events.append(row);
      }
    } catch (error) { chatNotice(error.message); }
  }
  $('#chat-filter-form').addEventListener('submit', async event => {
    event.preventDefault();
    const input = $('#chat-filter-term');
    try { await chatApi('admin_filter_add', { term: input.value.trim() }); input.value = ''; await loadChatFilters(); }
    catch (error) { chatNotice(error.message); }
  });
  $('#chat-unmute-form').addEventListener('submit', async event => {
    event.preventDefault();
    try { await chatApi('admin_guard_unmute', { name: $('#chat-unmute-name').value.trim() }); chatNotice('Automatic mute cleared.'); await loadChatFilters(); }
    catch (error) { chatNotice(error.message); }
  });
  $('#chat-ban-form').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const name = $('#chat-ban-name').value.trim();
      await chatApi('admin_ban', { name, minutes: Number($('#chat-ban-minutes').value) });
      chatNotice(`${name} banned from chat.`);
    } catch (error) { chatNotice(error.message); }
  });
  $('#chat-unban').addEventListener('click', async () => {
    try {
      const name = $('#chat-ban-name').value.trim();
      await chatApi('admin_unban', { name });
      chatNotice(`${name} unbanned.`);
    } catch (error) { chatNotice(error.message); }
  });
  $('#buddy-form').addEventListener('submit', async event => {
    event.preventDefault(); const input = $('#buddy-name'); const name = input.value.trim();
    if (!name) return;
    await changeBuddy('buddy_add', name); input.value = '';
  });
  window.setInterval(() => {
    if (!signedIn || document.hidden) return;
    if (!windows.get('chat').hidden || !windows.get('im').hidden) pollChat();
  }, 10000);
  window.setInterval(() => {
    if (!signedIn || document.hidden) return;
    if (windows.get('chat').hidden && windows.get('im').hidden && !windows.get('buddies').hidden) pollChat();
    if (!windows.get('chat').hidden || !windows.get('buddies').hidden || !windows.get('im').hidden) refreshChatState();
  }, 20000);
  window.setInterval(async () => {
    if (!signedIn || document.hidden) return;
    try { const data = await mailApi('unread'); mailUnread(data.unread); }
    catch (error) { if (!windows.get('mail').hidden) mailNotice(error.message); }
  }, 60000);
  function saveSoundPreference() {
    $('#entry-sound').checked = $('#sound-enabled').checked;
    try {
      localStorage.setItem('aov_sound_enabled', $('#sound-enabled').checked ? '1' : '0');
      localStorage.setItem('aov_sound_volume', $('#sound-volume').value);
    } catch { /* Sound preferences may remain in memory when storage is unavailable. */ }
  }
  try {
    $('#sound-enabled').checked = localStorage.getItem('aov_sound_enabled') !== '0';
    const savedVolume = Number(localStorage.getItem('aov_sound_volume'));
    if (Number.isInteger(savedVolume) && savedVolume >= 0 && savedVolume <= 100 && localStorage.getItem('aov_sound_volume') !== null) $('#sound-volume').value = String(savedVolume);
  } catch { $('#sound-enabled').checked = true; }
  $('#entry-sound').checked = $('#sound-enabled').checked;
  $('#volume-value').textContent = `${$('#sound-volume').value}%`;
  $('#entry-sound').addEventListener('change', event => { $('#sound-enabled').checked = event.target.checked; saveSoundPreference(); });
  $('#sound-enabled').addEventListener('change', () => { saveSoundPreference(); if (!$('#sound-enabled').checked) stopEffects(); });
  $('#sound-volume').addEventListener('input', event => { $('#volume-value').textContent = `${event.target.value}%`; saveSoundPreference(); });
  $('#replay-connection').addEventListener('click', async () => {
    $('#entry').hidden = true;
    $('#app').hidden = true;
    $('#connecting').hidden = false;
    $('#connection-detail').textContent = 'This is a connection preview. Your account is already signed on.';
    connectionStage('Welcome to AOV!', 4, 2);
    connectionSound?.stop();
    connectionSound = playConnectionSound();
    await connectionSound.finished;
    if (signedIn) await enterAov();
  });
  $('#reduced-motion').checked = matchMedia('(prefers-reduced-motion: reduce)').matches;
  function updateClock() { $('#clock').textContent = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date()); }
  updateClock(); window.setInterval(updateClock, 60000);
  renderBrowserPage(); makeChannels(); setupDrag();
  document.cookie = `aov_phase1a_preview=; Max-Age=0; Path=${new URL('.', location.href).pathname}; SameSite=Lax`;
  if (initialState.loggedIn) {
    $('#admin-link').hidden = !initialState.isAdmin;
    loadBrowserData();
    loadMailList();
    setPreviewName(initialState.name || 'Member');
    $('#account-email').textContent = initialState.email || '';
    $('#profile-zip').value = initialState.zipCode || '';
    signedIn = true;
    loadNews();
    $('#entry').hidden = true;
    $('#app').hidden = false;
    openWindow('welcome');
  } else if (new URLSearchParams(location.search).get('reset') === '1' && initialState.resetReady) {
    switchEntry('reset');
    history.replaceState(null, '', new URL('.', location.href));
  } else if (new URLSearchParams(location.search).get('reset') === 'invalid') {
    switchEntry('recovery');
    $('#recovery-message').textContent = 'Recovery link is invalid or expired.';
    history.replaceState(null, '', new URL('.', location.href));
  }
})();
