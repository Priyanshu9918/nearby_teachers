'use strict';

const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '../.env') });
const { createChatServer } = require('./server');

async function main() {
  let publisher;
  let subscriber;
  const chat = createChatServer({
    secret: process.env.SOCKET_IO_SHARED_SECRET,
    apiUrl: process.env.SOCKET_IO_LARAVEL_URL || 'http://157.245.125.79/nearby_teachers/public/',
    origins: (process.env.SOCKET_IO_CORS_ORIGINS || 'http://157.245.125.79')
      .split(',').map((origin) => origin.trim()).filter(Boolean),
    isReady: () => !process.env.SOCKET_IO_REDIS_URL || (publisher?.isReady && subscriber?.isReady),
  });
  if (process.env.SOCKET_IO_REDIS_URL) {
    const { createClient } = require('redis');
    const { createAdapter } = require('@socket.io/redis-adapter');
    publisher = createClient({ url: process.env.SOCKET_IO_REDIS_URL });
    subscriber = publisher.duplicate();
    for (const client of [publisher, subscriber]) {
      client.on('error', () => process.stderr.write('Socket.IO Redis connection error\n'));
    }
    await Promise.all([publisher.connect(), subscriber.connect()]);
    chat.io.adapter(createAdapter(publisher, subscriber));
  }
  const port = Number(process.env.SOCKET_IO_PORT || 6001);
  const host = process.env.SOCKET_IO_HOST || '127.0.0.1';
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('Invalid SOCKET_IO_PORT');
  chat.server.listen(port, host, () => {
    process.stdout.write(`Socket.IO listening on ${host}:${port}\n`);
  });
  chat.server.on('error', () => {
    process.stderr.write('Unable to start Socket.IO listener\n');
    process.exit(1);
  });
  let stopping = false;
  const stop = async () => {
    if (stopping) return;
    stopping = true;
    await chat.close();
    await Promise.all([publisher?.quit(), subscriber?.quit()]);
  };
  process.on('SIGTERM', stop);
  process.on('SIGINT', stop);
}

main().catch(() => {
  process.stderr.write('Socket.IO startup failed; check the server configuration.\n');
  process.exit(1);
});
