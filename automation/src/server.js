'use strict';

require('dotenv').config();

const express = require('express');
const { SessionManager } = require('./sessionManager');

const PORT = process.env.PORT || 4790;
const TOKEN = process.env.AUTOMATION_TOKEN;

if (!TOKEN || TOKEN === 'change-me') {
  console.warn('[automation] WARNING: AUTOMATION_TOKEN is unset or left at the example value.');
}

const manager = new SessionManager();
const app = express();
app.use(express.json());

app.use((req, res, next) => {
  const header = req.get('authorization') || '';
  const provided = header.startsWith('Bearer ') ? header.slice(7) : null;

  if (!TOKEN || provided !== TOKEN) {
    return res.status(401).json({ error: 'unauthorized' });
  }

  next();
});

app.post('/sessions', async (req, res) => {
  const { panelUrl, username, password } = req.body || {};

  if (!panelUrl || !username || !password) {
    return res.status(400).json({ error: 'panelUrl, username and password are required' });
  }

  try {
    const session = await manager.startLogin({ panelUrl, username, password });
    res.status(201).json(session.toJSON());
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.get('/sessions/:id', (req, res) => {
  const session = manager.get(req.params.id);
  if (!session) return res.status(404).json({ error: 'not_found' });
  res.json(session.toJSON());
});

app.get('/sessions/:id/frame.jpg', (req, res) => {
  const session = manager.get(req.params.id);
  if (!session || !session.frame) return res.status(204).end();

  res.set('Content-Type', 'image/jpeg');
  res.set('Cache-Control', 'no-store');
  res.send(session.frame);
});

app.post('/sessions/:id/input', async (req, res) => {
  const result = await manager.forwardInput(req.params.id, req.body || {});
  if (!result.ok) return res.status(400).json(result);
  res.json(result);
});

app.delete('/sessions/:id', (req, res) => {
  manager.remove(req.params.id);
  res.status(204).end();
});

app.listen(PORT, () => {
  console.log(`[automation] listening on :${PORT}`);
});
