(() => {
  'use strict';
  const state = JSON.parse(document.getElementById('admin-state').textContent);
  const message = document.getElementById('admin-message');
  const bulletinForm = document.getElementById('bulletin-form');
  const bulletinField = name => document.getElementById(`bulletin-${name}`);
  const resetBulletinForm = () => {
    bulletinForm.reset();
    bulletinField('id').value = '';
    const today = new Date();
    bulletinField('date').value = new Date(today.getTime() - today.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  };
  const button = (label, action) => { const node = document.createElement('button'); node.type = 'button'; node.textContent = label; node.addEventListener('click', action); return node; };
  async function request(action = '', body = null) {
    const response = await fetch(`admin-api.php${action ? `?action=${encodeURIComponent(action)}` : ''}`, {
      method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: body ? { 'Content-Type': 'application/json', 'X-AOV-CSRF': state.csrf } : {},
      body: body ? JSON.stringify(body) : undefined
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Administration is unavailable');
    return data;
  }
  async function mutate(action, body) {
    try { message.textContent = ''; render(await request(action, body)); }
    catch (error) { message.textContent = error.message; }
  }
  function render(data) {
    const users = document.getElementById('admin-users'); users.replaceChildren();
    for (const user of data.users) {
      const row = document.createElement('div'); row.className = 'row';
      const detail = document.createElement('div'); detail.className = 'detail';
      const label = document.createElement('strong'); label.textContent = `${user.username} #${user.id}`;
      const info = document.createElement('small'); info.textContent = ` · ${user.email} · ${Number(user.status) === 5 ? 'Suspended' : 'Active'} · Recovery email ${Number(user.recovery_email_verified) ? 'verified' : 'unverified'}`;
      detail.append(label, info); row.append(detail);
      if (Number(user.status) === 5) row.append(button('Restore', () => mutate('restore', { id: Number(user.id) })));
      else row.append(button('Suspend', () => mutate('suspend', { id: Number(user.id) })));
      row.append(button('Rename', () => { const name = prompt('New screen name', user.username); if (name !== null) mutate('rename', { id: Number(user.id), name: name.trim() }); }));
      users.append(row);
    }
    const bulletins = document.getElementById('admin-bulletins'); bulletins.replaceChildren();
    for (const post of [...(data.bulletins || [])].sort((a, b) => String(b.date).localeCompare(String(a.date)))) {
      const row = document.createElement('div'); row.className = 'row';
      const detail = document.createElement('div'); detail.className = 'detail';
      const title = document.createElement('strong'); title.textContent = post.title;
      const info = document.createElement('small'); info.textContent = ` · ${post.date} · ${post.status}`;
      const summary = document.createElement('p'); summary.textContent = post.summary;
      detail.append(title, info, summary); row.append(detail);
      row.append(button('Edit', () => {
        for (const name of ['id', 'title', 'date', 'summary', 'body', 'image', 'status']) bulletinField(name).value = post[name] || '';
        bulletinForm.scrollIntoView({ block: 'start' });
        bulletinField('title').focus();
      }));
      row.append(button(post.status === 'published' ? 'Move to draft' : 'Publish', () =>
        mutate('bulletin_save', { ...post, status: post.status === 'published' ? 'draft' : 'published' })));
      bulletins.append(row);
    }
    const rooms = document.getElementById('admin-rooms'); rooms.replaceChildren();
    for (const room of data.rooms) {
      const row = document.createElement('div'); row.className = 'row';
      const label = document.createElement('span'); label.className = 'detail'; label.textContent = `${room.category || 'General'} · ${room.name} · ${Number(room.enabled) ? 'Enabled' : 'Disabled'}`; row.append(label);
      if (Number(room.id) !== 0) row.append(button(Number(room.enabled) ? 'Disable' : 'Enable', () => mutate('room_toggle', { id: Number(room.id) })));
      rooms.append(row);
    }
    const events = document.getElementById('admin-events'); events.replaceChildren();
    const reports = document.getElementById('admin-mail-reports'); reports.replaceChildren();
    if (!data.mailReports.length) reports.textContent = 'No open mail reports.';
    for (const report of data.mailReports) {
      const row = document.createElement('div'); row.className = 'row';
      const detail = document.createElement('div'); detail.className = 'detail';
      const heading = document.createElement('strong'); heading.textContent = `${report.reason}: ${report.sender} → ${report.reporter} · ${report.subject}`;
      const body = document.createElement('p'); body.textContent = report.body;
      detail.append(heading, body);
      row.append(detail, button('Close report', () => mutate('mail_report_close', { id: Number(report.id) })));
      reports.append(row);
    }
    const channels = document.getElementById('admin-channels'); channels.replaceChildren();
    for (const [id, url] of Object.entries(data.channels)) {
      const form = document.createElement('form'); form.className = 'row';
      const label = document.createElement('label'); label.textContent = id;
      const input = document.createElement('input'); input.type = 'url'; input.required = true; input.value = url; input.className = 'destination';
      const save = document.createElement('button'); save.type = 'submit'; save.textContent = 'Save';
      form.append(label, input, save);
      form.addEventListener('submit', event => { event.preventDefault(); mutate('channel_set', { id, url: input.value.trim() }); });
      channels.append(form);
    }
    for (const item of data.events) { const row = document.createElement('div'); row.className = 'row'; row.textContent = `${new Date(Number(item.at) * 1000).toLocaleString()} · ${item.username || 'Former member'} · ${item.event}`; events.append(row); }
  }
  document.getElementById('room-form').addEventListener('submit', event => {
    event.preventDefault(); const input = document.getElementById('room-name'); mutate('room_add', { name: input.value.trim(), category: document.getElementById('room-category').value.trim() }); input.value = '';
  });
  bulletinForm.addEventListener('submit', async event => {
    event.preventDefault();
    const post = Object.fromEntries(['id', 'title', 'date', 'summary', 'body', 'image', 'status']
      .map(name => [name, bulletinField(name).value]));
    try {
      message.textContent = '';
      render(await request('bulletin_save', post));
      resetBulletinForm();
      message.textContent = 'AOV post saved.';
    } catch (error) { message.textContent = error.message; }
  });
  document.getElementById('bulletin-cancel').addEventListener('click', resetBulletinForm);
  resetBulletinForm();
  request().then(render).catch(error => { message.textContent = error.message; });
})();
